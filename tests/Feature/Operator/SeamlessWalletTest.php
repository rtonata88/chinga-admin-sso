<?php

use App\Models\Game;
use App\Models\GameSession;
use App\Models\OperatorIntegration;
use App\Models\OperatorMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\GameSessionService;
use App\Services\Operator\OperatorPlayerResolver;
use App\Services\Operator\SeamlessWallet;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Tests\Support\FakeOperatorWallet;

/**
 * Seamless wallet mode: the operator keeps the money, the engines call the SSO game
 * wallet API exactly as before, and every stake, win and refund becomes a signed call to
 * the operator's wallet (docs/platform/seamless-wallet-spec.md).
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    app(ClientRepository::class)->createPersonalAccessGrantClient('Test PAT client');
    $this->tenant = Tenant::factory()->create(['slug' => 'orange-bw', 'currency' => 'BWP', 'country_code' => 'BW', 'status' => 'active']);
    $this->game = Game::factory()->create(['name' => 'Kulipi Kuna', 'slug' => 'kulipi-kuna', 'launch_url' => 'https://kulipi.playchinga.com']);
    $this->game->tenants()->attach($this->tenant->id, ['enabled' => true]);
    $this->integration = OperatorIntegration::create([
        'tenant_id' => $this->tenant->id, 'operator_key' => (string) Str::uuid(), 'secret' => 'op-secret',
        'wallet_mode' => 'seamless', 'wallet_base_url' => 'https://wallet.operator.test',
    ]);
    $this->op = (new FakeOperatorWallet('https://wallet.operator.test', $this->integration->operator_key, 'op-secret'))->install();
    $this->op->balances['OBW-1'] = '100.00';
    $this->player = app(OperatorPlayerResolver::class)->resolve($this->tenant, 'OBW-1', 'Kago', null, seamless: true)['user'];
    $this->sessions = app(GameSessionService::class);
});

function openSeamless(): GameSession
{
    return test()->sessions->startWalletSession(test()->player, test()->game);
}

it('opens the session at the operator and mirrors its balance, never holding money', function () {
    $session = openSeamless();
    $wallet = Wallet::where('user_id', $this->player->id)->first();

    expect($wallet->mode)->toBe('seamless')
        ->and((string) $session->balance_start)->toBe('100.00')
        ->and($this->op->endpointsCalled())->toBe(['session'])
        ->and($this->op->calls[0]['body'])->toMatchArray(['player_id' => 'OBW-1', 'session_id' => $session->uuid, 'currency' => 'BWP', 'game_id' => 'kulipi-kuna'])
        ->and($this->op->calls[0]['body'])->not->toHaveKey('session_token');
});

it('runs a stake and a win through the real game wallet API', function () {
    $code = (function () {
        $this->flushHeaders();
        $raw = json_encode(['player_id' => 'OBW-1', 'game' => 'kulipi-kuna', 'currency' => 'BWP']);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach (\App\Support\OperatorSignature::headers($this->integration->operator_key, 'op-secret', $raw) as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        parse_str((string) parse_url($this->call('POST', '/api/v1/operator/launch', [], [], [], $server, $raw)->assertOk()->json('launch_url'), PHP_URL_QUERY), $q);

        return $q['code'];
    })->call($this);
    $token = $this->postJson('/api/v1/launch/exchange', ['code' => $code])->assertOk()->json('access_token');

    $gs = $this->withToken($token)->postJson('/api/v1/game/session/start/wallet', ['game_id' => $this->game->uuid])
        ->assertOk()->assertJsonPath('balance', '100.00')->json('session_token');
    $this->flushHeaders();

    $this->withToken($gs)->postJson('/api/v1/game/debit', ['amount' => '20.00', 'reference' => 'kk_bet_7'])
        ->assertOk()->assertJsonPath('balance_after', '80.00');
    $this->withToken($gs)->postJson('/api/v1/game/debit', ['amount' => '500.00', 'reference' => 'kk_bet_8'])
        ->assertStatus(402); // the operator's insufficient_funds, as the engines already handle it
    $this->withToken($gs)->postJson('/api/v1/game/credit', ['amount' => '38.40', 'reference' => 'kk_win_7'])
        ->assertOk()->assertJsonPath('balance_after', '118.40');

    expect($this->op->balances['OBW-1'])->toBe('118.40')
        ->and(collect($this->op->calls)->firstWhere('endpoint', 'credit')['body'])->toMatchArray(['reference' => 'kk_win_7', 'debit_reference' => 'kk_bet_7', 'amount' => '38.40']);
});

it('moves money once when the same stake or win is sent twice', function () {
    $session = openSeamless();
    $a = $this->sessions->debit($session->session_token, '20.00', 'kk_bet_1');
    $b = $this->sessions->debit($session->session_token, '20.00', 'kk_bet_1');
    $c = $this->sessions->credit($session->session_token, '40.00', 'kk_win_1');
    $d = $this->sessions->credit($session->session_token, '40.00', 'kk_win_1');

    expect($a->id)->toBe($b->id)->and($c->id)->toBe($d->id)
        ->and($this->op->balances['OBW-1'])->toBe('120.00')
        ->and(collect($this->op->endpointsCalled())->countBy()->all())->toMatchArray(['debit' => 1, 'credit' => 1]);
    expect(fn () => $this->sessions->debit($session->session_token, '25.00', 'kk_bet_1'))->toThrow(RuntimeException::class, 'different amount');
});

it('refuses a stake the operator cannot cover and moves nothing', function () {
    $session = openSeamless();
    expect(fn () => $this->sessions->debit($session->session_token, '100.01', 'kk_bet_2'))->toThrow(RuntimeException::class, 'Insufficient balance');
    expect($this->op->balances['OBW-1'])->toBe('100.00')
        ->and(WalletTransaction::where('reference', 'kk_bet_2')->exists())->toBeFalse()
        ->and(OperatorMovement::where('reference', 'kk_bet_2')->value('status'))->toBe('failed');
    // A repeat of a refused stake is refused again without a second call.
    $calls = count($this->op->calls);
    expect(fn () => $this->sessions->debit($session->session_token, '100.01', 'kk_bet_2'))->toThrow(RuntimeException::class, 'Insufficient balance');
    expect(count($this->op->calls))->toBe($calls);
});

it('keeps a stake that landed even though the answer was lost (lookup, no second debit)', function () {
    $session = openSeamless();
    $this->op->failNext('debit', 'timeout', land: true);

    $tx = $this->sessions->debit($session->session_token, '20.00', 'kk_bet_3');

    expect($tx->type)->toBe('bet')
        ->and($this->op->balances['OBW-1'])->toBe('80.00')
        ->and(collect($this->op->endpointsCalled())->countBy()->all())->toMatchArray(['debit' => 1, 'lookup' => 1]);
});

it('retries a stake that did not land once, with the same reference', function () {
    $session = openSeamless();
    $this->op->failNext('debit', '503');

    $this->sessions->debit($session->session_token, '20.00', 'kk_bet_4');

    expect($this->op->balances['OBW-1'])->toBe('80.00')
        ->and(array_column(array_filter($this->op->calls, fn ($c) => $c['endpoint'] === 'debit'), 'body'))->each->toHaveKey('reference', 'kk_bet_4');
});

it('refuses a stake after two unclear answers and rolls it back, so the operator is never left with it', function () {
    $session = openSeamless();
    // Unclear, not there; retried, unclear again (it landed, unseen) and the lookup unclear too.
    $this->op->failNext('debit', 'timeout')->failNext('debit', 'timeout', land: true);
    $this->op->failNext('lookup', 'pass')->failNext('lookup', '503');

    expect(fn () => $this->sessions->debit($session->session_token, '20.00', 'kk_bet_5'))->toThrow(RuntimeException::class, 'not placed');
    expect($this->op->balances['OBW-1'])->toBe('80.00'); // it landed on the second try, unseen

    app(SeamlessWallet::class)->retryDue();
    expect($this->op->balances['OBW-1'])->toBe('100.00')
        ->and(OperatorMovement::where('kind', 'rollback')->where('reference', 'kk_bet_5')->value('status'))->toBe('ok')
        ->and(WalletTransaction::where('reference', 'kk_bet_5')->exists())->toBeFalse();
});

it('opens a fresh operator session once when the operator says the session ended', function () {
    $session = openSeamless();
    $this->op->sessionInvalidOnce = true;
    $this->sessions->debit($session->session_token, '10.00', 'kk_bet_6');
    expect($this->op->endpointsCalled())->toBe(['session', 'debit', 'session', 'debit'])
        ->and($this->op->balances['OBW-1'])->toBe('90.00');
});

it('turns a refund into a rollback of the stake, never a credit', function () {
    $session = openSeamless();
    $this->sessions->debit($session->session_token, '20.00', 'kk_bet_9');
    $tx = $this->sessions->credit($session->session_token, '20.00', 'kk_refund_9');

    expect($this->op->balances['OBW-1'])->toBe('100.00')
        ->and($this->op->ledger['debit:kk_bet_9']['state'])->toBe('cancelled')
        ->and($this->op->endpointsCalled())->not->toContain('credit')
        ->and(collect($this->op->calls)->firstWhere('endpoint', 'rollback')['body']['reference'])->toBe('kk_bet_9')
        ->and($tx->reference)->toBe('kk_refund_9');
    expect(fn () => $this->sessions->credit($session->session_token, '5.00', 'kk_refund_404'))->toThrow(RuntimeException::class, 'No placed stake');
});

it('refuses to return a stake the operator has already paid a win on', function () {
    $session = openSeamless();
    $this->sessions->debit($session->session_token, '20.00', 'kk_bet_10');
    $this->sessions->credit($session->session_token, '40.00', 'kk_win_10');
    expect(fn () => $this->sessions->credit($session->session_token, '20.00', 'kk_refund_10'))->toThrow(RuntimeException::class, 'already settled');
    expect($this->op->balances['OBW-1'])->toBe('120.00');
});

it('pays a win after the session ended, and re-sends a win the operator did not confirm until it does', function () {
    $session = openSeamless();
    $this->sessions->debit($session->session_token, '20.00', 'kk_bet_11');
    $session->end('timeout');
    $this->op->failNext('credit', 'timeout')->failNext('lookup', '503');

    // The engine is told it is paid: it is the player's money.
    $tx = $this->sessions->settlementCredit($session->session_token, '38.40', 'kk_win_11');
    expect($tx->type)->toBe('win')
        ->and($this->op->balances['OBW-1'])->toBe('80.00')
        ->and(OperatorMovement::where('reference', 'kk_win_11')->value('status'))->toBe('retrying');

    $this->travel(61)->seconds(); // the first gap is a minute, then they grow
    app(SeamlessWallet::class)->retryDue();
    expect($this->op->balances['OBW-1'])->toBe('118.40')
        ->and(OperatorMovement::where('reference', 'kk_win_11')->value('status'))->toBe('ok');
    // Settled once: another run sends nothing.
    $n = count($this->op->calls);
    $this->travel(2)->hours();
    app(SeamlessWallet::class)->retryDue();
    expect(count($this->op->calls))->toBe($n);
});

it('raises a win the operator never confirms within 24 hours', function () {
    $session = openSeamless();
    $this->sessions->debit($session->session_token, '20.00', 'kk_bet_12');
    $this->op->failNext('credit', '503', times: 100)->failNext('lookup', '503', times: 100);
    $this->sessions->credit($session->session_token, '10.00', 'kk_win_12');

    $this->travel(25)->hours();
    app(SeamlessWallet::class)->retryDue();
    expect(OperatorMovement::where('reference', 'kk_win_12')->value('status'))->toBe('abandoned');
});

it('refuses to play when the operator blocks the player', function () {
    $this->op->blocked['OBW-1'] = 'self-excluded';
    expect(fn () => openSeamless())->toThrow(RuntimeException::class, 'cannot play');
    expect(GameSession::whereNull('ended_at')->count())->toBe(0);
});

it('leaves seamless wallets out of reconciliation and the Treasury', function () {
    $session = openSeamless();
    $this->sessions->debit($session->session_token, '20.00', 'kk_bet_13');
    $this->op->balances['OBW-1'] = '500.00'; // the player deposited at the operator
    $this->sessions->getBalance($session->session_token);

    $this->artisan('wallets:reconcile')->assertSuccessful();
    $position = collect(app(\App\Services\TreasuryService::class)->position()['positions'])->firstWhere('currency', 'BWP');
    expect($position === null || bccomp((string) ($position['totals']['owed'] ?? '0'), '0', 2) === 0)->toBeTrue();
});

it('keeps a hosted player wallet with money from becoming a mirror', function () {
    $hosted = app(OperatorPlayerResolver::class)->resolve($this->tenant, 'OBW-2', null, null, seamless: false)['user'];
    app(\App\Services\WalletService::class)->deposit($hosted->getOrCreateWallet(), '10.00');
    expect(fn () => app(OperatorPlayerResolver::class)->resolve($this->tenant, 'OBW-2', null, null, seamless: true))
        ->toThrow(\App\Services\Operator\WalletModeConflict::class);
});

it('derives the debit a win, bonus or refund belongs to from the engine reference', function () {
    expect(SeamlessWallet::parseReference('vp_win_8831'))->toBe(['kind' => 'win', 'debit' => 'vp_bet_8831'])
        ->and(SeamlessWallet::parseReference('fan_bonus_1042'))->toBe(['kind' => 'bonus', 'debit' => 'fan_bet_1042'])
        ->and(SeamlessWallet::parseReference('dx_refund_77'))->toBe(['kind' => 'refund', 'debit' => 'dx_bet_77'])
        ->and(SeamlessWallet::parseReference('vp_bet_1'))->toBeNull()
        ->and(SeamlessWallet::parseReference('voucher_transfer_x'))->toBeNull();
});
