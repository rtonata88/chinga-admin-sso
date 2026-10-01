<?php

use App\Models\Tenant;
use App\Models\User;
use App\Models\Venue;
use App\Models\VoucherCode;
use Laravel\Passport\Passport;

/** Venue play: the game learns from userinfo that an account is a venue's counter float. */
test('userinfo carries the venue for a counter float and nothing extra for a player voucher', function () {
    $tenant = Tenant::factory()->create(['slug' => 'lucky-star-betting', 'name' => 'Lucky Star Betting', 'status' => 'active']);
    $venue = Venue::create(['tenant_id' => $tenant->id, 'name' => 'Tigers Bar', 'slug' => 'tigers-bar', 'address_line_1' => '1 Independence Ave', 'city' => 'Windhoek']);

    $counterUser = User::factory()->create(['tenant_id' => $tenant->id, 'user_type' => 'voucher', 'status' => 'active']);
    VoucherCode::create(['tenant_id' => $tenant->id, 'venue_id' => $venue->id, 'code' => 'TIGERS01', 'balance' => '500.00', 'currency' => 'NAD', 'status' => 'active', 'kind' => 'counter', 'user_id' => $counterUser->id]);
    Passport::actingAs($counterUser, ['openid', 'profile', 'wallet']);
    $this->getJson('/api/v1/oauth/userinfo')
        ->assertOk()
        ->assertJson(['sub' => $counterUser->uuid, 'tenant_id' => $tenant->uuid, 'account_kind' => 'counter', 'venue_id' => $venue->uuid, 'venue_name' => 'Tigers Bar']);

    $playerUser = User::factory()->create(['tenant_id' => $tenant->id, 'user_type' => 'voucher', 'status' => 'active']);
    VoucherCode::create(['tenant_id' => $tenant->id, 'venue_id' => $venue->id, 'code' => 'PLAYER01', 'balance' => '20.00', 'currency' => 'NAD', 'status' => 'active', 'user_id' => $playerUser->id]);
    Passport::actingAs($playerUser, ['openid', 'profile']);
    $this->getJson('/api/v1/oauth/userinfo')->assertOk()->assertJsonMissing(['account_kind' => 'counter'])->assertJsonMissingPath('venue_id');
});

test('a counter float shows its wallet balance, stays active after logging in, and keeps its token for a shift', function () {
    $tenant = Tenant::factory()->create(['slug' => 'lucky-star-betting', 'name' => 'Lucky Star Betting', 'status' => 'active']);
    $venue = Venue::create(['tenant_id' => $tenant->id, 'name' => 'Tigers Bar', 'slug' => 'tigers-bar', 'address_line_1' => '1 Independence Ave', 'city' => 'Windhoek']);
    $game = \App\Models\Game::factory()->fantasy()->create();
    $tenant->games()->attach($game->id, ['enabled' => true]);
    // The voucher session issues a personal access token: Passport needs its client in the test database.
    app(\Laravel\Passport\ClientRepository::class)->createPersonalAccessGrantClient('Test PAT client');
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'user_type' => 'voucher', 'status' => 'active']);
    $code = VoucherCode::create(['tenant_id' => $tenant->id, 'venue_id' => $venue->id, 'code' => 'TIGERS02', 'balance' => '1000.00', 'currency' => 'NAD', 'status' => 'active', 'kind' => 'counter', 'user_id' => $user->id]);

    $res = $this->withHeader('X-Tenant-ID', 'lucky-star-betting')
        ->postJson('/api/v1/game/session/start/voucher-web', ['game_id' => $game->uuid, 'code' => 'TIGERS02']);
    expect($res->status())->toBe(200, $res->getContent());
    expect($res->json('balance'))->toBe('1000.00');
    $code->refresh();
    expect($code->status)->toBe('active')->and($code->balance)->toBe('0.00')->and($code->displayBalance())->toBe('1000.00')->and($code->displayStatus())->toBe('active');
    $expires = \Laravel\Passport\Token::query()->where('user_id', $user->id)->latest('created_at')->first()?->expires_at;
    expect($expires?->greaterThan(now()->addHours(11)))->toBeTrue();
});
