<?php

use App\Models\Game;
use App\Models\GameSession;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Venue;
use App\Models\VoucherCode;
use App\Models\VoucherTransaction;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Concerns\ReturnsExistingOnDuplicate;
use App\Services\Venue\VoucherCodeService;
use App\Services\WalletService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kulipi Kuna hardening H1/H2: one game transaction per (wallet, session, reference, type),
 * and the voucher equivalent. A repeat or a race loser gets the first transaction back and
 * the balance moves once. The migration that adds the unique indexes refuses, and deletes
 * nothing, when duplicates already exist.
 */
const UNIQUE_REFERENCES_MIGRATION = 'database/migrations/2026_10_03_000003_unique_game_transaction_references.php';

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->game = Game::factory()->create();

    $player = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->wallet = $player->getOrCreateWallet('NAD');
    app(WalletService::class)->deposit($this->wallet, '100.00');
    $this->session = GameSession::create([
        'tenant_id' => $this->tenant->id,
        'game_id' => $this->game->id,
        'source_type' => Wallet::class,
        'source_id' => $this->wallet->id,
        'balance_start' => '100.00',
    ]);

    $venue = Venue::create(['tenant_id' => $this->tenant->id, 'name' => 'Tigers Bar', 'slug' => 'tigers-bar', 'address_line_1' => '1 Independence Ave', 'city' => 'Windhoek']);
    $voucherUser = User::factory()->create(['tenant_id' => $this->tenant->id, 'user_type' => 'voucher', 'status' => 'active']);
    $this->voucher = VoucherCode::create(['tenant_id' => $this->tenant->id, 'venue_id' => $venue->id, 'code' => 'DUPTEST1', 'balance' => '50.00', 'currency' => 'NAD', 'status' => 'active', 'user_id' => $voucherUser->id]);
    $this->voucherSession = GameSession::create([
        'tenant_id' => $this->tenant->id,
        'game_id' => $this->game->id,
        'source_type' => VoucherCode::class,
        'source_id' => $this->voucher->id,
        'balance_start' => '50.00',
    ]);
});

test('a repeated wallet credit returns the first transaction and pays once', function () {
    $service = app(WalletService::class);

    $first = $service->credit($this->wallet, '25.00', $this->session, 'kk_win_1');
    $second = $service->credit($this->wallet->fresh(), '25.00', $this->session, 'kk_win_1');

    expect($second->id)->toBe($first->id)
        ->and((string) $this->wallet->fresh()->balance)->toBe('125.00')
        ->and(WalletTransaction::where('reference', 'kk_win_1')->where('type', 'win')->count())->toBe(1);
});

test('a repeated wallet debit returns the first transaction and charges once', function () {
    $service = app(WalletService::class);

    $first = $service->debit($this->wallet, '10.00', $this->session, 'kk_bet_1');
    $second = $service->debit($this->wallet->fresh(), '10.00', $this->session, 'kk_bet_1');

    expect($second->id)->toBe($first->id)
        ->and((string) $this->wallet->fresh()->balance)->toBe('90.00')
        ->and(WalletTransaction::where('reference', 'kk_bet_1')->where('type', 'bet')->count())->toBe(1);
});

test('a repeated voucher credit returns the first transaction and pays once', function () {
    $service = app(VoucherCodeService::class);

    $first = $service->credit($this->voucher, '25.00', $this->voucherSession, 'kk_win_1');
    $second = $service->credit($this->voucher->fresh(), '25.00', $this->voucherSession, 'kk_win_1');

    expect($second->id)->toBe($first->id)
        ->and((string) $this->voucher->fresh()->balance)->toBe('75.00')
        ->and(VoucherTransaction::where('reference', 'kk_win_1')->where('type', 'win')->count())->toBe(1);
});

test('a repeated voucher debit returns the first transaction and charges once', function () {
    $service = app(VoucherCodeService::class);

    $first = $service->debit($this->voucher, '10.00', $this->voucherSession, 'kk_bet_1');
    $second = $service->debit($this->voucher->fresh(), '10.00', $this->voucherSession, 'kk_bet_1');

    expect($second->id)->toBe($first->id)
        ->and((string) $this->voucher->fresh()->balance)->toBe('40.00')
        ->and(VoucherTransaction::where('reference', 'kk_bet_1')->where('type', 'bet')->count())->toBe(1);
});

test('a retried voucher debit of the whole balance returns the first transaction, not insufficient balance', function () {
    $service = app(VoucherCodeService::class);

    $first = $service->debit($this->voucher, '50.00', $this->voucherSession, 'kk_bet_all');
    // The stale model still says 50.00, so pass a fresh one: the balance is now 0.00.
    $second = $service->debit($this->voucher->fresh(), '50.00', $this->voucherSession, 'kk_bet_all');

    expect($second->id)->toBe($first->id)
        ->and((string) $this->voucher->fresh()->balance)->toBe('0.00');
});

test('the voucher lookup is scoped to the voucher code', function () {
    $service = app(VoucherCodeService::class);
    // Another voucher's row with the same session and reference must not satisfy this voucher's credit.
    $other = VoucherCode::create(['tenant_id' => $this->tenant->id, 'venue_id' => $this->voucher->venue_id, 'code' => 'DUPTEST2', 'balance' => '0.00', 'currency' => 'NAD', 'status' => 'active']);
    $foreign = VoucherTransaction::create([
        'voucher_code_id' => $other->id, 'game_session_id' => $this->voucherSession->id, 'type' => 'win',
        'amount' => '5.00', 'balance_before' => '0.00', 'balance_after' => '5.00', 'reference' => 'kk_win_scope',
    ]);

    $tx = $service->credit($this->voucher, '5.00', $this->voucherSession, 'kk_win_scope');

    expect($tx->id)->not->toBe($foreign->id)
        ->and($tx->voucher_code_id)->toBe($this->voucher->id)
        ->and((string) $this->voucher->fresh()->balance)->toBe('55.00');
});

test('a race loser gets the winner\'s wallet credit and the balance does not move again', function () {
    // The winner of a race has already committed its row and its balance change.
    $winner = WalletTransaction::create([
        'wallet_id' => $this->wallet->id, 'game_session_id' => $this->session->id, 'type' => 'win',
        'amount' => '25.00', 'balance_before' => '100.00', 'balance_after' => '125.00', 'reference' => 'kk_win_2',
    ]);
    $before = (string) $this->wallet->fresh()->balance;

    $tx = app(WalletService::class)->credit($this->wallet, '25.00', $this->session, 'kk_win_2');

    expect($tx->id)->toBe($winner->id)
        ->and((string) $this->wallet->fresh()->balance)->toBe($before)
        ->and(WalletTransaction::where('reference', 'kk_win_2')->count())->toBe(1);
});

test('a race loser gets the winner\'s voucher debit and the balance does not move again', function () {
    $winner = VoucherTransaction::create([
        'voucher_code_id' => $this->voucher->id, 'game_session_id' => $this->voucherSession->id, 'type' => 'bet',
        'amount' => '-10.00', 'balance_before' => '60.00', 'balance_after' => '50.00', 'reference' => 'kk_bet_2',
    ]);

    $tx = app(VoucherCodeService::class)->debit($this->voucher, '10.00', $this->voucherSession, 'kk_bet_2');

    expect($tx->id)->toBe($winner->id)
        ->and((string) $this->voucher->fresh()->balance)->toBe('50.00')
        ->and(VoucherTransaction::where('reference', 'kk_bet_2')->count())->toBe(1);
});

/** The SQL a call runs, in order, so a test can see whether the lock precedes the lookup. */
function queriesDuring(callable $call): array
{
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = strtolower($q->sql);
    });
    $call();

    return $queries;
}

function firstIndexOf(array $queries, callable $match): int
{
    foreach ($queries as $i => $sql) {
        if ($match($sql)) {
            return $i;
        }
    }

    return PHP_INT_MAX;
}

test('wallet credit and debit take the wallet row lock before looking for an existing transaction', function () {
    foreach (['credit' => 'kk_win_order', 'debit' => 'kk_bet_order'] as $method => $reference) {
        $queries = queriesDuring(fn () => app(WalletService::class)->{$method}($this->wallet->fresh(), '1.00', $this->session, $reference));
        $lock = firstIndexOf($queries, fn ($sql) => str_contains($sql, 'from `wallets`') && str_contains($sql, 'for update'));
        $lookup = firstIndexOf($queries, fn ($sql) => str_starts_with($sql, 'select') && str_contains($sql, 'from `wallet_transactions`'));

        expect($lock)->toBeLessThan(PHP_INT_MAX, "{$method}: no wallet lock")
            ->and($lookup)->toBeLessThan(PHP_INT_MAX, "{$method}: no lookup")
            ->and($lock)->toBeLessThan($lookup, "{$method}: lookup ran before the lock");
    }
});

test('voucher credit and debit take the voucher row lock before looking for an existing transaction', function () {
    foreach (['credit' => 'kk_win_order', 'debit' => 'kk_bet_order'] as $method => $reference) {
        $queries = queriesDuring(fn () => app(VoucherCodeService::class)->{$method}($this->voucher->fresh(), '1.00', $this->voucherSession, $reference));
        $lock = firstIndexOf($queries, fn ($sql) => str_contains($sql, 'from `voucher_codes`') && str_contains($sql, 'for update'));
        $lookup = firstIndexOf($queries, fn ($sql) => str_starts_with($sql, 'select') && str_contains($sql, 'from `voucher_transactions`'));

        expect($lock)->toBeLessThan(PHP_INT_MAX, "{$method}: no voucher lock")
            ->and($lookup)->toBeLessThan(PHP_INT_MAX, "{$method}: no lookup")
            ->and($lock)->toBeLessThan($lookup, "{$method}: lookup ran before the lock");
    }
});

/** Exposes the shared race-loser path so it can be driven directly. */
function duplicateGuard(): object
{
    return new class
    {
        use ReturnsExistingOnDuplicate;

        public function run(callable $insert, callable $find): mixed
        {
            return $this->insertOrExisting($insert, $find);
        }
    };
}

test('the unique index rejects a second wallet game transaction and the loser is handed the winner\'s row', function () {
    $winner = WalletTransaction::create([
        'wallet_id' => $this->wallet->id, 'game_session_id' => $this->session->id, 'type' => 'win',
        'amount' => '25.00', 'balance_before' => '100.00', 'balance_after' => '125.00', 'reference' => 'kk_win_3',
    ]);

    // A request whose lookup missed goes straight to the insert: the real index must refuse it.
    $tx = duplicateGuard()->run(
        fn () => DB::transaction(fn () => WalletTransaction::create([
            'wallet_id' => $this->wallet->id, 'game_session_id' => $this->session->id, 'type' => 'win',
            'amount' => '25.00', 'balance_before' => '125.00', 'balance_after' => '150.00', 'reference' => 'kk_win_3',
        ])),
        fn () => WalletTransaction::where('wallet_id', $this->wallet->id)->where('game_session_id', $this->session->id)
            ->where('reference', 'kk_win_3')->where('type', 'win')->first(),
    );

    expect($tx->id)->toBe($winner->id)
        ->and(WalletTransaction::where('reference', 'kk_win_3')->count())->toBe(1);
});

test('the unique index rejects a second voucher game transaction and the loser is handed the winner\'s row', function () {
    $winner = VoucherTransaction::create([
        'voucher_code_id' => $this->voucher->id, 'game_session_id' => $this->voucherSession->id, 'type' => 'win',
        'amount' => '5.00', 'balance_before' => '50.00', 'balance_after' => '55.00', 'reference' => 'kk_win_3',
    ]);

    $tx = duplicateGuard()->run(
        fn () => DB::transaction(fn () => VoucherTransaction::create([
            'voucher_code_id' => $this->voucher->id, 'game_session_id' => $this->voucherSession->id, 'type' => 'win',
            'amount' => '5.00', 'balance_before' => '55.00', 'balance_after' => '60.00', 'reference' => 'kk_win_3',
        ])),
        fn () => VoucherTransaction::where('voucher_code_id', $this->voucher->id)->where('game_session_id', $this->voucherSession->id)
            ->where('reference', 'kk_win_3')->where('type', 'win')->first(),
    );

    expect($tx->id)->toBe($winner->id)
        ->and(VoucherTransaction::where('reference', 'kk_win_3')->count())->toBe(1);
});

test('the race-loser path returns the insert when it succeeds and rethrows when there is no winner to return', function () {
    expect(duplicateGuard()->run(fn () => 'inserted', fn () => 'existing'))->toBe('inserted');

    $violation = new UniqueConstraintViolationException('mysql', 'insert', [], new PDOException('Duplicate entry'));
    expect(fn () => duplicateGuard()->run(fn () => throw $violation, fn () => null))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(fn () => duplicateGuard()->run(fn () => throw new RuntimeException('Insufficient balance for bet.'), fn () => 'existing'))
        ->toThrow(RuntimeException::class, 'Insufficient balance for bet.');
});

test('the migration refuses when duplicate wallet game transactions exist, and deletes nothing', function () {
    // DDL commits implicitly in MySQL/MariaDB; RefreshDatabase notices and re-migrates for the next test.
    Artisan::call('migrate:rollback', ['--path' => UNIQUE_REFERENCES_MIGRATION]);
    expect(Schema::hasIndex('wallet_transactions', 'wallet_tx_game_reference_unique'))->toBeFalse();

    $row = [
        'wallet_id' => $this->wallet->id, 'game_session_id' => $this->session->id, 'type' => 'win',
        'amount' => '25.00', 'balance_before' => '100.00', 'balance_after' => '125.00', 'reference' => 'kk_win_dup',
    ];
    $a = WalletTransaction::create($row);
    $b = WalletTransaction::create($row);
    // Repeated references outside a game session (counter top-ups) are not game duplicates.
    $deposit = ['wallet_id' => $this->wallet->id, 'type' => 'deposit', 'amount' => '1.00', 'balance_before' => '0', 'balance_after' => '1.00', 'reference' => 'counter top-up'];
    WalletTransaction::create($deposit);
    WalletTransaction::create($deposit);

    expect(fn () => Artisan::call('migrate', ['--path' => UNIQUE_REFERENCES_MIGRATION, '--force' => true]))
        ->toThrow(RuntimeException::class, 'kk_win_dup');
    try {
        Artisan::call('migrate', ['--path' => UNIQUE_REFERENCES_MIGRATION, '--force' => true]);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('duplicate')->not->toContain('counter top-up');
    }

    expect(WalletTransaction::whereKey([$a->id, $b->id])->count())->toBe(2)
        ->and(Schema::hasIndex('wallet_transactions', 'wallet_tx_game_reference_unique'))->toBeFalse();

    // Only the rows this test created are removed.
    WalletTransaction::whereKey([$a->id, $b->id])->delete();
    Artisan::call('migrate', ['--path' => UNIQUE_REFERENCES_MIGRATION, '--force' => true]);

    expect(Schema::hasIndex('wallet_transactions', 'wallet_tx_game_reference_unique'))->toBeTrue()
        ->and(Schema::hasIndex('voucher_transactions', 'voucher_tx_game_reference_unique'))->toBeTrue();
});

test('the migration refuses when duplicate voucher game transactions exist, and deletes nothing', function () {
    Artisan::call('migrate:rollback', ['--path' => UNIQUE_REFERENCES_MIGRATION]);

    $row = [
        'voucher_code_id' => $this->voucher->id, 'game_session_id' => $this->voucherSession->id, 'type' => 'bet',
        'amount' => '-10.00', 'balance_before' => '50.00', 'balance_after' => '40.00', 'reference' => 'kk_bet_dup',
    ];
    $a = VoucherTransaction::create($row);
    $b = VoucherTransaction::create($row);

    expect(fn () => Artisan::call('migrate', ['--path' => UNIQUE_REFERENCES_MIGRATION, '--force' => true]))
        ->toThrow(RuntimeException::class, 'kk_bet_dup');
    expect(VoucherTransaction::whereKey([$a->id, $b->id])->count())->toBe(2)
        ->and(Schema::hasIndex('wallet_transactions', 'wallet_tx_game_reference_unique'))->toBeFalse()
        ->and(Schema::hasIndex('voucher_transactions', 'voucher_tx_game_reference_unique'))->toBeFalse();

    VoucherTransaction::whereKey([$a->id, $b->id])->delete();
    Artisan::call('migrate', ['--path' => UNIQUE_REFERENCES_MIGRATION, '--force' => true]);

    expect(Schema::hasIndex('voucher_transactions', 'voucher_tx_game_reference_unique'))->toBeTrue();
});
