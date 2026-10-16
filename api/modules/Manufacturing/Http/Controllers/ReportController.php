<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Manufacturing\Backflush\Backflushes;
use Modules\Manufacturing\Http\Lists\BackflushList;
use Modules\Manufacturing\Http\Lists\ConsumptionList;
use Modules\Manufacturing\Http\Lists\RecipeCostingList;
use Modules\Manufacturing\Http\Lists\YieldList;
use Modules\Manufacturing\Http\Requests\ReportListRequest;
use Modules\Manufacturing\Http\Requests\RevalueRequest;
use Modules\Manufacturing\Http\Requests\SettingsRequest;
use Modules\Manufacturing\Http\Requests\StatusRequest;
use Modules\Manufacturing\Http\Requests\UpdateSettingsRequest;
use Modules\Manufacturing\Http\Resources\BackflushResource;
use Modules\Manufacturing\Http\Resources\ConsumptionResource;
use Modules\Manufacturing\Http\Resources\Costs;
use Modules\Manufacturing\Http\Resources\RecipeCostingResource;
use Modules\Manufacturing\Http\Resources\YieldResource;
use Modules\Manufacturing\Models\Backflush;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\Order;
use Modules\Manufacturing\Models\OrderMaterial;
use Modules\Manufacturing\Models\Setting;
use Modules\Manufacturing\Orders\Revalue;
use Modules\Manufacturing\Support\Errors;
use Modules\Manufacturing\Support\Places;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** MFG-06, MFG-07, MFG-17 to MFG-19, MFG-29: reports, status and a company's settings. */
class ReportController
{
    /** MFG-18: actual against standard, one row per order and material. */
    public function consumption(ReportListRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $orders = $this->orders($request);
        $query = OrderMaterial::query()->with(ConsumptionList::RELATIONS)->whereIn('order_id', $orders->select('id'));

        if ($request->filled('item')) {
            $query->where('item_id', $request->validated('item'));
        }

        $request->applySort($query);

        return $request->wantsExport() ? $export->download($request, $query) : ConsumptionResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    /** MFG-19: yield, scrap and the cost of each order. */
    public function yield(ReportListRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $this->orders($request, withItem: true)->with(YieldList::RELATIONS);
        $request->applySort($query);

        return $request->wantsExport() ? $export->download($request, $query) : YieldResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    /** MFG-07, MFG-08: backflushes at the reader's locations. */
    public function backflushes(ReportListRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Backflush::query()->with(BackflushList::RELATIONS);
        $request->applyFilters($query, dateColumn: 'occurred_at', itemColumn: null);
        $request->applyTextSearch($query, ['reference']);

        if ($request->filled('kind')) {
            $query->where('kind', $request->validated('kind'));
        }

        if ($request->filled('item')) {
            $item = $request->validated('item');
            $query->whereIn('id', fn ($q) => $q->select('backflush_id')->from('mfg_backflush_lines')->where('item_id', $item)->orWhere('sold_item_id', $item));
        }

        $request->applySort($query);

        return $request->wantsExport() ? $export->download($request, $query) : BackflushResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    /** MFG-06: what each active BOM in force costs now. */
    public function recipeCosting(ReportListRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Bom::query()->with(RecipeCostingList::RELATIONS)->whereNull('archived_at');
        $request->applyFilters($query);

        if ($request->filled('kind')) {
            $query->where('kind', $request->validated('kind'));
        }

        $request->applySort($query);

        return $request->wantsExport() ? $export->download($request, $query) : RecipeCostingResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function status(StatusRequest $request, ModuleRegistry $modules, Backflushes $backflushes): JsonResponse
    {
        return new JsonResponse(['data' => [
            'inventory_active' => $modules->isActive('inventory'),
            'pos_active' => $modules->isActive('pos'),
            'backflush_active' => $backflushes->active() && $modules->isActive('pos'),
        ]]);
    }

    public function settings(SettingsRequest $request, Company $company): JsonResponse
    {
        return $this->presentSettings($request, $company, Setting::query()->where('company_id', $company->id)->first());
    }

    public function updateSettings(UpdateSettingsRequest $request, Company $company): JsonResponse
    {
        $settings = Errors::transaction(function () use ($request, $company) {
            $settings = Setting::query()->where('company_id', $company->id)->lockForUpdate()->first() ?? new Setting(['company_id' => $company->id]);
            $settings->fill([
                'labour_rate_minor' => (string) $request->validated('labour_rate_minor'),
                'overhead_rate_minor' => (string) $request->validated('overhead_rate_minor'),
                'currency' => $company->base_currency,
                'release_approval' => (bool) $request->validated('release_approval'),
            ])->save();

            return $settings->refresh();
        });

        return $this->presentSettings($request, $company, $settings);
    }

    /** Review fix 5: a document whose value never came back from Inventory, moved and valued again (202: the value follows). */
    public function revalue(RevalueRequest $request, Revalue $revalue): JsonResponse
    {
        $revalue->run((string) $request->validated('kind'), $request->document(), $request->user());

        return new JsonResponse(['data' => ['kind' => $request->validated('kind'), 'document_id' => $request->validated('document_id'), 'requested' => true]], 202);
    }

    private function presentSettings(Request $request, Company $company, ?Setting $settings): JsonResponse
    {
        // Review fix 4, RBAC-05: the rates are costs: only for holders of cost.view or settings.edit in the company.
        $places = app(Places::class);
        $rates = $places->inCompany($request->user(), Costs::PERMISSION, $company->id) || $places->inCompany($request->user(), 'manufacturing.settings.edit', $company->id);

        return new JsonResponse(['data' => [
            'company' => ['id' => $company->id, 'name' => $company->name, 'base_currency' => $company->base_currency],
            'labour_rate_minor' => $rates ? (string) ($settings?->labour_rate_minor ?? '0') : null,
            'overhead_rate_minor' => $rates ? (string) ($settings?->overhead_rate_minor ?? '0') : null,
            'currency' => $settings?->currency ?? $company->base_currency,
            'release_approval' => (bool) ($settings?->release_approval ?? false),
        ]]);
    }

    /** The orders the consumption and yield reports cover, by the request's filters (dated by release). */
    private function orders(ReportListRequest $request, bool $withItem = false)
    {
        $query = Order::query()->whereIn('status', Order::RUNNING_OR_CLOSED);
        $request->applyFilters($query, dateColumn: 'released_at', itemColumn: $withItem ? 'item_id' : null);
        $request->applyTextSearch($query, ['number']);

        if ($request->filled('order')) {
            $query->whereKey($request->validated('order'));
        }

        return $query;
    }
}
