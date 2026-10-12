<?php

namespace App\Http\Controllers\Api\Channels;

use App\Models\Tenant;
use App\Models\User;
use App\Support\OAuthClientGames;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A channel client (the USSD gateway, U-D11) acts only for the tenants it is bound
 * to in channel_client_tenants, and for players of those tenants.
 */
trait ChannelAuth
{
    /** @return list<int> */
    protected function boundTenantIds(Request $request): array
    {
        $client = OAuthClientGames::clientIdFromRequest($request);
        abort_if($client === null, 401, 'Unauthenticated.');

        return DB::table('channel_client_tenants')->where('oauth_client_id', $client)->pluck('tenant_id')->map(fn ($id) => (int) $id)->all();
    }

    protected function tenantFor(Request $request): Tenant
    {
        $uuid = (string) $request->input('tenant_uuid', $request->query('tenant_uuid', ''));
        $tenant = Tenant::where('uuid', $uuid)->where('status', 'active')->first();
        abort_if($tenant === null || ! in_array($tenant->id, $this->boundTenantIds($request), true), 403, 'This client cannot act for that tenant.');

        return $tenant;
    }

    protected function playerFor(Request $request, Tenant $tenant, ?string $uuid = null): User
    {
        $uuid ??= (string) $request->input('user_uuid', '');
        $user = User::where('uuid', $uuid)->where('tenant_id', $tenant->id)->first();
        abort_if($user === null, 404, 'No such player on this tenant.');

        return $user;
    }
}
