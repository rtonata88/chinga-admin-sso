<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * A player plays only on the tenant that owns their account and wallet: the login
 * proxy refuses to issue a token for another tenant's account.
 */
beforeEach(function () {
    $this->luckyStar = Tenant::factory()->create(['slug' => 'lucky-star-betting', 'name' => 'Lucky Star Betting', 'status' => 'active']);
    $this->other = Tenant::factory()->create(['slug' => 'other-operator', 'name' => 'Other Operator', 'status' => 'active']);
    $this->player = User::factory()->create(['email' => 'player@example.test', 'password' => 'secret-pass-123', 'status' => 'active', 'tenant_id' => $this->luckyStar->id]);
});

$tokenOk = fn () => Http::fake([
    '*/oauth/token' => Http::response(['access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 600, 'refresh_token' => 'ref'], 200),
]);

test('a login on another tenant\'s game is refused and names the owning operator', function () use ($tokenOk) {
    $tokenOk();
    $this->withHeaders(['X-Tenant-ID' => 'other-operator'])
        ->postJson('/api/v1/auth/login', ['client_id' => 'web', 'username' => 'player@example.test', 'password' => 'secret-pass-123'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'tenant_mismatch')
        ->assertJsonPath('tenant.slug', 'lucky-star-betting');
    Http::assertNothingSent();
});

test('a login on the owning tenant goes through, with the tenant carried into the token request', function () use ($tokenOk) {
    $tokenOk();
    $this->withHeaders(['X-Tenant-ID' => 'lucky-star-betting'])
        ->postJson('/api/v1/auth/login', ['client_id' => 'web', 'username' => 'player@example.test', 'password' => 'secret-pass-123'])
        ->assertOk()
        ->assertJsonPath('access_token', 'tok');
    Http::assertSent(fn ($request) => $request->hasHeader('X-Tenant-ID', 'lucky-star-betting'));
});

test('a wrong password on another tenant reveals nothing about where the account lives', function () {
    Http::fake(['*/oauth/token' => Http::response(['error' => 'invalid_grant', 'message' => 'Invalid credentials.'], 400)]);
    $this->withHeaders(['X-Tenant-ID' => 'other-operator'])
        ->postJson('/api/v1/auth/login', ['client_id' => 'web', 'username' => 'player@example.test', 'password' => 'wrong'])
        ->assertStatus(400)
        ->assertJsonMissingPath('tenant');
});
