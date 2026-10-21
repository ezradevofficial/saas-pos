<?php

namespace App\Core\Marketplace\Http\Requests;

/** MKT-01: the developer portal (`core.developer.manage`, tenant scope). */
class DeveloperRequest extends MarketplaceRequest
{
    protected string $permission = self::DEVELOP;
}
