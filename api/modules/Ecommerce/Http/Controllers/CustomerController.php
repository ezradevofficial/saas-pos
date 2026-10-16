<?php

namespace Modules\Ecommerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Ecommerce\Http\Requests\ListRequest;
use Modules\Ecommerce\Http\Resources\Present;
use Modules\Ecommerce\Models\Customer;
use Modules\Ecommerce\Support\Access;

/** ECOM-08: online customers of the companies the user's `ecommerce.customer.view` touches. */
class CustomerController
{
    public function __construct(private readonly Access $access) {}

    public function index(ListRequest $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'ecommerce.customer.view'), 403);
        $search = trim((string) $request->validated('search', ''));
        $like = '%'.addcslashes($search, '\\%_').'%';
        $query = $this->access->byCompany(Customer::query(), $request->user(), 'ecommerce.customer.view')
            ->whereNull('archived_at')
            ->when($request->filled('company'), fn ($q) => $q->where('company_id', $request->validated('company')))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('phone', 'ilike', $like)->orWhere('email', 'ilike', $like)))
            ->orderByDesc('created_at');
        $page = $query->paginate($request->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (Customer $c) => Present::customer($c))->values(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }
}
