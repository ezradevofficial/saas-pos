<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Horizon runs the queue workers (config/horizon.php). Its dashboard shows
 * every tenant's job payloads, so it is open only in the `local`
 * environment. Elsewhere the `viewHorizon` gate refuses everyone: there is
 * no platform administrator yet (ADM-), and tenant Owners must never see
 * other tenants' jobs. When platform admins exist, allow them here.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null) => false);
    }
}
