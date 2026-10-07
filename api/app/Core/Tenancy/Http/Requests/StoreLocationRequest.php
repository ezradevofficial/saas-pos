<?php

namespace App\Core\Tenancy\Http\Requests;

use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\Visibility;
use Illuminate\Foundation\Http\FormRequest;

/** TEN-05: a location is created at its branch's scope. */
class StoreLocationRequest extends FormRequest
{
    public const TYPES = ['outlet', 'warehouse', 'store', 'office'];

    public function authorize(): bool
    {
        /** @var Branch $branch */
        $branch = $this->route('branch');

        abort_unless(app(Visibility::class)->reaches($this->user(), 'core.location.view', $branch), 404);

        return $this->user()->can('create', [Location::class, $branch]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
        ];
    }
}
