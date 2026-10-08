<?php

namespace App\Core\MasterData\PaymentMethods\Http\Controllers;

use App\Core\Currency\Models\TenantCurrency;
use App\Core\Exports\ListExport;
use App\Core\Http\ApiException;
use App\Core\MasterData\PaymentMethods\Http\Requests\ListPaymentMethodsRequest;
use App\Core\MasterData\PaymentMethods\Http\Requests\PaymentMethodActionRequest;
use App\Core\MasterData\PaymentMethods\Http\Requests\PaymentMethodRequest;
use App\Core\MasterData\PaymentMethods\Http\Requests\ReorderPaymentMethodsRequest;
use App\Core\MasterData\PaymentMethods\Http\Requests\StorePaymentMethodRequest;
use App\Core\MasterData\PaymentMethods\Http\Requests\UpdatePaymentMethodRequest;
use App\Core\MasterData\PaymentMethods\Http\Resources\PaymentMethodResource;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\MasterData\PaymentMethods\PaymentProviders;
use App\Core\Tenancy\Archiver;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MD-04: a company's payment methods in till order. A method is switched on
 * only when it can take money: a mobile money or card method needs every
 * setting and secret of its provider (`provider_not_configured`), a cash
 * method a currency active in the tenant (`currency_not_active`). Settings
 * and secrets are merged key by key; a null value clears a key. Secrets
 * never leave the server. Archived, never deleted (TEN-06): archiving
 * switches a method off; a restored method goes to the end of the order.
 */
class PaymentMethodController
{
    public function __construct(
        private readonly Archiver $archiver,
        private readonly PaymentProviders $providers,
    ) {}

    public function index(ListPaymentMethodsRequest $request, Company $company, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->applySearch($request->applyStatus(PaymentMethod::query()->where('company_id', $company->id)), ['name' => 'name']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return PaymentMethodResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StorePaymentMethodRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();

        $method = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company, $data) {
            // Locks the company row: positions of a company are handed out one at a time.
            $this->archiver->lockActive(Company::class, $company->id);

            $method = new PaymentMethod([
                'company_id' => $company->id,
                'type' => $data['type'],
                'provider' => $data['provider'] ?? null,
                'name' => $data['name'],
                'currency' => $data['currency'] ?? null,
                'active' => $data['active'] ?? false,
                'position' => $this->nextPosition($company->id),
            ]);
            $this->applyConfig($method, $data);
            $this->assertCanBeActive($method);
            $method->save();

            return $method;
        });

        return PaymentMethodResource::make($method)->response()->setStatusCode(201);
    }

    public function show(PaymentMethodRequest $request, PaymentMethod $paymentMethod): PaymentMethodResource
    {
        return PaymentMethodResource::make($paymentMethod);
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod): PaymentMethodResource
    {
        $data = $request->validated();

        $method = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($paymentMethod, $data) {
            $method = PaymentMethod::query()->whereKey($paymentMethod->id)->lockForUpdate()->firstOrFail();
            $method->fill(array_intersect_key($data, array_flip(['name', 'currency', 'active'])));
            $this->applyConfig($method, $data);
            $this->assertCanBeActive($method);
            $method->save();

            return $method;
        });

        return PaymentMethodResource::make($method);
    }

    /** The company's active methods in the order given (every one once); archived ones follow, in their order. */
    public function reorder(ReorderPaymentMethodsRequest $request, Company $company): AnonymousResourceCollection
    {
        $ids = $request->validated('ids');

        $methods = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company, $ids) {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $all = PaymentMethod::query()->where('company_id', $company->id)->orderBy('position')->orderBy('id')->get();
            $active = $all->whereNull('archived_at');

            if (count($ids) !== $active->count() || array_diff($ids, $active->modelKeys()) !== []) {
                throw new ApiException(422, 'payment_method_order_invalid', __('core.payment_method.order_invalid'), [
                    'ids' => [__('core.payment_method.order_invalid')],
                ]);
            }

            $ordered = [...array_map(fn (string $id) => $active->firstWhere('id', $id), $ids), ...$all->whereNotNull('archived_at')->values()];

            foreach ($ordered as $index => $method) {
                $method->position = $index + 1;
                $method->save();
            }

            return collect($ordered)->take(count($ids));
        });

        return PaymentMethodResource::collection($methods);
    }

    public function archive(PaymentMethodActionRequest $request, PaymentMethod $paymentMethod): PaymentMethodResource
    {
        if (! $paymentMethod->isArchived()) {
            // An archived method takes no money: it is switched off with the same save.
            $paymentMethod->active = false;
            $paymentMethod->archive();
        }

        return PaymentMethodResource::make($paymentMethod);
    }

    public function restore(PaymentMethodActionRequest $request, PaymentMethod $paymentMethod): PaymentMethodResource
    {
        $method = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($paymentMethod) {
            $this->archiver->lockActive(Company::class, $paymentMethod->company_id);
            $method = PaymentMethod::query()->whereKey($paymentMethod->id)->firstOrFail();

            if ($method->isArchived()) {
                $method->position = $this->nextPosition($method->company_id);
                // A method that can no longer take money comes back switched off.
                $method->active = $method->active && $this->problems($method) === null;
                $method->restore();
            }

            return $method;
        });

        return PaymentMethodResource::make($method);
    }

    /** Merge the request's settings and secrets into the method's: a null value clears a key. */
    private function applyConfig(PaymentMethod $method, array $data): void
    {
        foreach (['settings', 'secrets'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $merged = array_filter(array_merge((array) ($method->{$field} ?? []), $data[$field]), fn ($value) => $value !== null && $value !== '');
            ksort($merged);
            $method->{$field} = $field === 'secrets' && $merged === [] ? null : $merged;
        }
    }

    private function assertCanBeActive(PaymentMethod $method): void
    {
        if ($method->active && ($problem = $this->problems($method)) !== null) {
            throw $problem;
        }
    }

    /** Why $method cannot take money, or null. */
    private function problems(PaymentMethod $method): ?ApiException
    {
        if ($method->needsProvider() && ($missing = $this->providers->missing($method)) !== []) {
            return new ApiException(422, 'provider_not_configured', __('core.payment_method.provider_not_configured', ['keys' => implode(', ', $missing)]), [
                'active' => [__('core.payment_method.provider_not_configured', ['keys' => implode(', ', $missing)])],
            ]);
        }

        if ($method->type === 'cash' && ! TenantCurrency::query()->where('code', $method->currency)->where('active', true)->exists()) {
            return new ApiException(422, 'currency_not_active', __('core.currency.not_active'), [
                'currency' => [__('core.currency.not_active')],
            ]);
        }

        return null;
    }

    private function nextPosition(string $companyId): int
    {
        return (int) PaymentMethod::query()->where('company_id', $companyId)->max('position') + 1;
    }
}
