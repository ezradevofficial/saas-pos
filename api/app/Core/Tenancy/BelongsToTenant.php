<?php

namespace App\Core\Tenancy;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fills tenant_id from the current context on create. RLS is the real guard;
 * this makes a missing context fail loudly in PHP (TEN-01).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::creating(function ($model) {
            if (empty($model->tenant_id)) {
                $model->tenant_id = app(TenantContext::class)->require();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
