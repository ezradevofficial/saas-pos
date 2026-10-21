<?php

namespace App\Core\Marketplace\Http\Requests;

/** MKT-02: uninstall an app (`core.marketplace.install`). */
class UninstallAppRequest extends MarketplaceRequest
{
    protected string $permission = self::INSTALL;
}
