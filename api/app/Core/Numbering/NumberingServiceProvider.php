<?php

namespace App\Core\Numbering;

use Illuminate\Support\ServiceProvider;

/** NUM-01: the registry of numbered document types and the numbering service. */
class NumberingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DocumentNumberTypes::class);
        $this->app->singleton(Numbering::class);
    }
}
