<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_client_games', function (Blueprint $table) {
            $table->uuid('oauth_client_id');
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['oauth_client_id', 'game_id']);
            $table->foreign('oauth_client_id')
                ->references('id')->on('oauth_clients')
                ->cascadeOnDelete();
        });

        // Seed existing service-credit clients. Any client with the
        // client_credentials grant is bound to every active game in its
        // tenant so existing integrations keep working after this migration.
        // New clients must be bound explicitly.
        $serviceClients = DB::table('oauth_clients')
            ->where('grant_types', 'like', '%client_credentials%')
            ->get(['id', 'tenant_id']);

        foreach ($serviceClients as $client) {
            $gameQuery = DB::table('games')->where('status', 'active');
            if ($client->tenant_id) {
                $tenantGameIds = DB::table('tenant_games')
                    ->where('tenant_id', $client->tenant_id)
                    ->where('enabled', true)
                    ->pluck('game_id');
                if ($tenantGameIds->isEmpty()) {
                    continue;
                }
                $gameQuery->whereIn('id', $tenantGameIds);
            }
            $gameIds = $gameQuery->pluck('id');

            foreach ($gameIds as $gameId) {
                DB::table('oauth_client_games')->updateOrInsert(
                    ['oauth_client_id' => $client->id, 'game_id' => $gameId],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_client_games');
    }
};
