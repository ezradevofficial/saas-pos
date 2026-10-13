<?php

namespace App\Core\Payments\Drivers;

/** SUB-04: Afrimoney (DRC). Unavailable until its merchant API and credentials are given (docs/integrations.md). */
class AfrimoneyProvider extends UnconfiguredProvider
{
    public function name(): string
    {
        return 'afrimoney';
    }
}
