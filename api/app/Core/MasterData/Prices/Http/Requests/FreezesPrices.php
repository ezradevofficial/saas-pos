<?php

namespace App\Core\MasterData\Prices\Http\Requests;

use App\Core\Http\ApiException;
use App\Core\MasterData\Prices\PriceAccess;
use Illuminate\Validation\Validator;

/**
 * RBAC-05 on price writes: when field rules hide prices from the user, or
 * make them read-only (the `prices` field of `item`), a request that
 * changes prices is refused before validation (422 `field_readonly`).
 * Read requests (`$edits` false) pass.
 */
trait FreezesPrices
{
    public function withValidator(Validator $validator): void
    {
        if ($this->edits && $this->user() !== null && app(PriceAccess::class)->frozen($this->user())) {
            $message = __('rbac.errors.field_readonly', ['field' => PriceAccess::FIELD]);

            throw new ApiException(422, 'field_readonly', $message, [PriceAccess::FIELD => [$message]]);
        }
    }
}
