<?php

namespace App\Core\Branding;

/** BR-02: where a brand asset lives on the media disk: `tenants/{tenant}/branding/{uuid}.{ext}`. */
final class BrandAssetPath
{
    public const PATTERN = '#^tenants/([0-9a-f\-]{36})/branding/[0-9a-f\-]{36}\.(jpg|png|webp)\z#';
}
