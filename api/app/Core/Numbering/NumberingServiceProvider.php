<?php

namespace App\Core\Numbering;

use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use Illuminate\Support\ServiceProvider;

/** NUM-01: the registry of numbered document types and the numbering service. */
class NumberingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DocumentNumberTypes::class);
        $this->app->singleton(Numbering::class);
    }

    public function boot(): void
    {
        // M2: a branch, location or device code is printed in numbers; a new code must not make a
        // sequence print in another sequence's prefix space (NumberPrefixes), however it is saved.
        foreach ([Branch::class, Location::class, Device::class] as $model) {
            $model::saving(function (Branch|Location|Device $place): void {
                if ($place->isDirty('code') && ($place->exists || $place->code !== null)) {
                    app(NumberPrefixes::class)->assertPlaceCode($place);
                }
            });
        }
    }
}
