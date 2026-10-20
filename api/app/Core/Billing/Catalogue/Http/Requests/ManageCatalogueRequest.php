<?php

namespace App\Core\Billing\Catalogue\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;

/** Staff who may change the catalogue (owner, billing). Archive, restore. */
class ManageCatalogueRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.catalogue.manage';
}
