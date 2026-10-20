<?php

namespace App\Core\Billing\Catalogue\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;

/** Staff who may read the catalogue (owner, billing, support). */
class ViewCatalogueRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.catalogue.view';
}
