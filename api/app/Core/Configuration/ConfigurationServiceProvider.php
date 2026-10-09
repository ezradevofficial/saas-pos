<?php

namespace App\Core\Configuration;

use Illuminate\Support\ServiceProvider;

/**
 * Versioned configuration (LAY-06, LAY-07): the kind registry modules
 * register into (see ConfigKinds). Core registers no kinds itself; the
 * theme editor, layout designers and templates bring theirs.
 */
class ConfigurationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConfigKinds::class);
    }
}
