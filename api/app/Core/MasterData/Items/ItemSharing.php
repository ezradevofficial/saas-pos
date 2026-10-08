<?php

namespace App\Core\MasterData\Items;

use App\Core\MasterData\Sharing\MasterDataSharing;
use Illuminate\Validation\Validator;

/**
 * TEN-08: items and item categories follow the `items` sharing mode:
 * shared, no company; per company, a company.
 */
final class ItemSharing
{
    public const DATA_TYPE = 'items';

    /** Adds an error on company_id when it does not fit the mode. */
    public static function validate(Validator $validator, mixed $companyId): void
    {
        if ($validator->errors()->has('company_id')) {
            return;
        }

        $shared = app(MasterDataSharing::class)->isShared(self::DATA_TYPE);

        if ($shared && $companyId !== null) {
            $validator->errors()->add('company_id', __('core.item.company_not_allowed'));
        } elseif (! $shared && $companyId === null) {
            $validator->errors()->add('company_id', __('core.item.company_required'));
        }
    }

    /** True when $companyId fits the mode (checked again under the sharing lock). */
    public static function fits(?string $companyId): bool
    {
        return app(MasterDataSharing::class)->isShared(self::DATA_TYPE) === ($companyId === null);
    }
}
