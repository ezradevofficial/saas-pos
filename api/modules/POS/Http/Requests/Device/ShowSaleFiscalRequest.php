<?php

namespace Modules\POS\Http\Requests\Device;

use App\Core\Tenancy\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Modules\POS\Models\Sale;

/**
 * GET pos/sales/{pos_sale}/fiscal (device token): only sales made at the
 * device's own location are found; any other is 404.
 */
class ShowSaleFiscalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $device = $this->user();
        $sale = $this->route('pos_sale');

        abort_unless($device instanceof Device && $sale instanceof Sale && $sale->location_id === $device->location_id, 404);

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
