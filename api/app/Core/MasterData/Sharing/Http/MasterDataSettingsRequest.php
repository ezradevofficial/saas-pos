<?php

namespace App\Core\MasterData\Sharing\Http;

use App\Core\MasterData\CompanyReach;
use Illuminate\Foundation\Http\FormRequest;

/**
 * TEN-08: read the sharing mode of each master data type. Any user who
 * works with master data reads it (a cashier creating a customer needs to
 * know whether a company is required).
 */
class MasterDataSettingsRequest extends FormRequest
{
    public const READERS = [
        'core.master_data_settings.edit', 'core.party.view', 'core.party.create', 'core.tax.view', 'core.tax.edit',
    ];

    public function authorize(): bool
    {
        return app(CompanyReach::class)->anywhere($this->user(), self::READERS);
    }

    public function rules(): array
    {
        return [];
    }
}
