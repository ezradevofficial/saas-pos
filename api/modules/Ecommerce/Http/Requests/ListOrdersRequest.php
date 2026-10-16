<?php

namespace Modules\Ecommerce\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ecommerce\Http\Lists\OrderList;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Support\Access;

/**
 * GET ecommerce/orders (ECOM-22, EXP-01): the orders at locations where the
 * user holds `ecommerce.order.view` (RBAC-04), filtered by `?status=`,
 * `?payment_status=`, `?channel=`, `?fulfilment=`, `?store=`, `?from=` and
 * `?to=` (days in the company's time zone), `?search=` (number, name,
 * phone), sorted and paged, or exported.
 */
class ListOrdersRequest extends FormRequest
{
    use SortsAndExports;

    public function list(): ListDefinition
    {
        return new OrderList;
    }

    public function authorize(): bool
    {
        return app(Access::class)->can($this->user(), 'ecommerce.order.view');
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'status' => ['sometimes', 'string', Rule::in([...Order::STATUSES, 'all', 'open'])],
            'payment_status' => ['sometimes', 'string', Rule::in(Order::PAYMENT_STATUSES)],
            'channel' => ['sometimes', 'string', Rule::in(['web', 'phone', 'whatsapp'])],
            'fulfilment' => ['sometimes', 'string', Rule::in(['delivery', 'pickup'])],
            'store' => ['sometimes', 'uuid', Rule::exists('ecom_stores', 'id')],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function applyFilters(Builder $query): Builder
    {
        app(Access::class)->byLocation($query, $this->user(), 'ecommerce.order.view');
        $status = $this->validated('status', 'all');

        match ($status) {
            'all' => null,
            'open' => $query->whereIn('status', Order::OPEN),
            default => $query->where('status', $status),
        };

        foreach (['payment_status' => 'payment_status', 'channel' => 'channel', 'fulfilment' => 'fulfilment', 'store' => 'store_id'] as $filter => $column) {
            if ($this->filled($filter)) {
                $query->where($column, $this->validated($filter));
            }
        }

        $zone = "(select coalesce(companies.timezone, 'UTC') from companies where companies.id = ecom_orders.company_id)";

        if ($this->filled('from')) {
            $query->whereRaw("ecom_orders.placed_at >= (?::date)::timestamp at time zone {$zone}", [$this->validated('from')]);
        }

        if ($this->filled('to')) {
            $query->whereRaw("ecom_orders.placed_at < ((?::date) + 1)::timestamp at time zone {$zone}", [$this->validated('to')]);
        }

        $search = trim((string) $this->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('number', 'ilike', $like)->orWhere('contact_name', 'ilike', $like)->orWhere('contact_phone', 'ilike', $like));
        }

        return $query;
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 50);
    }
}
