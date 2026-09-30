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
