<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\MasterData\Items\Item;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Manufacturing\Boms\Boms;
use Modules\Manufacturing\Boms\Explosion;
use Modules\Manufacturing\Boms\RecipeCosting;
use Modules\Manufacturing\Http\Lists\BomList;
use Modules\Manufacturing\Http\Requests\BomActionRequest;
use Modules\Manufacturing\Http\Requests\ExplodeRequest;
use Modules\Manufacturing\Http\Requests\ListBomsRequest;
use Modules\Manufacturing\Http\Requests\PublishVersionRequest;
use Modules\Manufacturing\Http\Requests\SaveVersionRequest;
use Modules\Manufacturing\Http\Requests\ShowBomRequest;
use Modules\Manufacturing\Http\Requests\StoreBomRequest;
use Modules\Manufacturing\Http\Requests\UpdateBomRequest;
use Modules\Manufacturing\Http\Resources\BomResource;
use Modules\Manufacturing\Http\Resources\BomVersionResource;
use Modules\Manufacturing\Http\Resources\Costs;
use Modules\Manufacturing\Http\Resources\Refs;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\BomVersion;
use Modules\Manufacturing\Support\Errors;
use Modules\Manufacturing\Support\Qty;
use Modules\Manufacturing\Support\Units;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** MFG-02 to MFG-06: BOMs and recipes, their versions and explosions, by company (RBAC-04). */
class BomController
{
    public const VERSION = ['bom.company', 'bom.item', 'lines.item', 'lines.uom', 'outputUom', 'publisher'];

    public function __construct(private readonly Boms $boms) {}

    public function index(ListBomsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Bom::query()->with(BomList::RELATIONS);
        $request->applyFilters($query);
        $status = (string) $request->validated('status', 'active');

        if ($status !== 'all') {
            $status === 'active' ? $query->whereNull('archived_at') : $query->whereNotNull('archived_at');
        }

        if ($request->filled('kind')) {
            $query->where('kind', $request->validated('kind'));
        }

        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', $like)
                ->orWhereIn('item_id', fn ($items) => $items->select('id')->from('items')->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like)));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return BomResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreBomRequest $request): JsonResponse
    {
        $bom = $this->boms->create($request->validated(), $request->user());

        return BomResource::make($bom->load(['company', 'item', 'versions']))->detail()->response()->setStatusCode(201);
    }

    public function show(ShowBomRequest $request, Bom $mfgBom): BomResource
    {
        return BomResource::make($mfgBom->load(['company', 'item', 'versions']))->detail();
    }

    public function update(UpdateBomRequest $request, Bom $mfgBom): BomResource
    {
        return BomResource::make($this->boms->update($mfgBom, $request->validated())->load(['company', 'item', 'versions']))->detail();
    }

    public function archive(BomActionRequest $request, Bom $mfgBom): BomResource
    {
        return BomResource::make($this->boms->archive($mfgBom)->load(['company', 'item', 'versions']))->detail();
    }

    public function newVersion(BomActionRequest $request, Bom $mfgBom): JsonResponse
    {
        $version = $this->boms->newVersion($mfgBom, $request->validated('copy_from_id'), $request->user());

        return BomVersionResource::make($version->load(self::VERSION))->response()->setStatusCode(201);
    }

    public function version(ShowBomRequest $request, BomVersion $mfgBomVersion): BomVersionResource
    {
        return BomVersionResource::make($mfgBomVersion->load(self::VERSION));
    }

    public function saveVersion(SaveVersionRequest $request, BomVersion $mfgBomVersion): BomVersionResource
    {
        return BomVersionResource::make($this->boms->saveVersion($mfgBomVersion, $request->validated(), $request->user())->load(self::VERSION));
    }

    public function publish(PublishVersionRequest $request, BomVersion $mfgBomVersion): BomVersionResource
    {
        return BomVersionResource::make($this->boms->publish($mfgBomVersion, $request->effectiveFrom(), $request->user())->load(self::VERSION));
    }

    /** MFG-05, MFG-06: the version exploded for a quantity, with the leaves' current costs (with the permission). */
    public function explode(ExplodeRequest $request, BomVersion $mfgBomVersion, Explosion $explosion, Units $units, RecipeCosting $costing): JsonResponse
    {
        $version = $mfgBomVersion->load(['bom', 'outputUom']);
        $bom = $version->bom;
        $item = Item::query()->findOrFail($bom->item_id);
        $uomId = (string) ($request->validated('uom_id') ?? $version->output_uom_id);
        $factor = $units->factor($item, $uomId) ?? throw Errors::make(422, 'uom_not_on_item', field: 'uom_id');
        $qty = BigDecimal::of((string) ($request->validated('qty') ?? $version->output_qty));
        $at = $version->effective_from !== null && $request->at()->lessThan($version->effective_from) ? $version->effective_from : $request->at();
        $exploded = $explosion->explode($version, $qty->multipliedBy($factor), $at);
        $items = Item::query()->whereKey($this->itemIds($exploded['tree'], [...array_column($exploded['leaves'], 'item_id'), ...array_column($exploded['by_products'], 'item_id')]))
            ->with('baseUom')->get()->keyBy('id');
        $cost = Costs::inCompany($request, $bom->company_id) ? $costing->cost($bom->company_id, $exploded['leaves'], $version, $bom->item_id) : null;

        return new JsonResponse(['data' => [
            'version' => BomResource::version($version),
            'qty' => Qty::show($qty),
            'uom_id' => $uomId,
            'at' => $at->toIso8601String(),
            'tree' => $this->tree($exploded['tree'], $items),
            'leaves' => array_map(fn (array $leaf) => [
                'item' => Refs::item($items->get($leaf['item_id'])), 'uom' => Refs::uom($items->get($leaf['item_id'])?->baseUom),
                'qty_base' => Qty::show($leaf['qty_base']), 'loss_base' => Qty::show($leaf['loss_base']),
            ], $exploded['leaves']),
            'by_products' => array_map(fn (array $line) => [
                'item' => Refs::item($items->get($line['item_id'])), 'qty_base' => Qty::show($line['qty_base']), 'cost_share_percent' => Qty::show($line['cost_share_percent']),
            ], $exploded['by_products']),
            'cost' => $cost === null ? null : [
                'currency' => $cost['currency'],
                'total' => Costs::money($cost['batch_cost_minor'], $cost['currency']),
                'leaves' => array_map(fn (array $leaf) => ['item_id' => $leaf['item_id'], 'unit_cost_minor' => $leaf['unit_cost_minor'], 'cost' => Costs::money($leaf['cost_minor'], $cost['currency'])], $cost['leaves']),
                'uncosted' => $cost['uncosted'],
            ],
        ]]);
    }

    /** @return list<string> */
    private function itemIds(array $nodes, array $ids = []): array
    {
        foreach ($nodes as $node) {
            $ids[] = $node['item_id'];
            $ids = $this->itemIds($node['children'], $ids);
        }

        return array_values(array_unique($ids));
    }

    private function tree(array $nodes, $items): array
    {
        return array_map(fn (array $node) => [
            ...$node,
            'item' => Refs::item($items->get($node['item_id'])),
            'uom' => Refs::uom($items->get($node['item_id'])?->baseUom),
            'children' => $this->tree($node['children'], $items),
        ], $nodes);
    }
}
