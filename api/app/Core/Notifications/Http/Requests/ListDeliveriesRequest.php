<?php

namespace App\Core\Notifications\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\Notifications\Channels;
use App\Core\Notifications\Http\Lists\DeliveryList;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * NOT-06: GET notification-deliveries, every delivery of the tenant
 * (`core.notification_delivery.view`, tenant-wide): `?status=` (all by
 * default), `?channel=`, `?search=` (recipient, address, subject, type),
 * `?sort` (newest first by default), pages of `?per_page` and an export
 * (DeliveryList, EXP-01).
 */
class ListDeliveriesRequest extends FormRequest
{
    use SortsAndExports;

    public function list(): ListDefinition
    {
        return new DeliveryList;
    }

    public function authorize(): bool
    {
        return $this->user()->can('core.notification_delivery.view', Scope::tenant());
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'status' => ['sometimes', 'string', Rule::in(['all', ...NotificationDelivery::STATUSES])],
            'channel' => ['sometimes', 'string', Rule::in(Channels::ALL)],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
