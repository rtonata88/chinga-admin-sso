<?php

namespace App\Models\Scopes;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app('current_tenant');

        if ($tenant instanceof Tenant) {
            $builder->where($model->getTable() . '.tenant_id', $tenant->id);
        }
        // If no tenant context (platform admin), no filter is applied
    }
}
