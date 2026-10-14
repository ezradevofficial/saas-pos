<?php

namespace Modules\Stores\Http\Resources;

use App\Core\MasterData\Dimensions\CostCentre;
use App\Core\MasterData\Dimensions\Department;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stores\Models\Movement;
use Modules\Stores\Stock\Qty;

/**
 * STR-12: one row of a consumption report: the group (a department, cost
 * centre or month; none when grouped by item), the item, what was issued,
 * returned and consumed (issued − returned) in its base unit, and the same
 * in value when every movement of the row was valued and the reader holds
 * `stores.value.view` everywhere the row's stores are (else null).
 *
 * @mixin Movement
 */
class ConsumptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Movement $row */
        $row = $this->resource;
        $group = (string) $row->getAttribute('group_by');
        $issued = Qty::of((string) ($row->getAttribute('issued_qty') ?? '0'));
        $returned = Qty::of((string) ($row->getAttribute('returned_qty') ?? '0'));
        $complete = (int) $row->getAttribute('unvalued') === 0 && $row->getAttribute('value_currency') !== null;
        $values = $complete && $this->valuesVisible($request, (string) $row->getAttribute('locations'));
        $issuedValue = BigInteger::of((string) ($row->getAttribute('issued_value') ?? '0'));
        $returnedValue = BigInteger::of((string) ($row->getAttribute('returned_value') ?? '0'));
        $currency = $row->getAttribute('value_currency');
        $key = $row->getAttribute('group_key');

        return [
            'id' => implode(':', [$key ?? '-', $row->item_id, $row->company_id]),
            'group' => match ($group) {
                'department' => Refs::dimension($key === null ? null : self::dimension($request, Department::class, $key)),
                'cost_centre' => Refs::dimension($key === null ? null : self::dimension($request, CostCentre::class, $key)),
                'period' => $key === null ? null : ['id' => $key, 'name' => $key],
                default => null,
            },
            'company' => Refs::named($row->company),
            'item' => Refs::item($row->item),
            'unit' => Refs::uom($row->item?->baseUom),
            'issued_qty' => Qty::show($issued),
            'returned_qty' => Qty::show($returned),
            'net_qty' => Qty::show(BigDecimal::of($issued)->minus($returned)),
            'valued' => $complete,
            'issued_value' => $values ? Values::money((string) $issuedValue, $currency) : null,
            'returned_value' => $values ? Values::money((string) $returnedValue, $currency) : null,
            'net_value' => $values ? Values::money((string) $issuedValue->minus($returnedValue), $currency) : null,
        ];
    }

    public const DIMENSIONS = 'stores.consumption.dimensions';

    /**
     * Stage 7B performance review: the rows' departments or cost centres in
     * one query (kept on the request, so the page's rows share it).
     *
     * @param  iterable<Movement>  $rows
     */
    public static function preload(Request $request, iterable $rows): void
    {
        $keys = [];

        foreach ($rows as $row) {
            $group = (string) $row->getAttribute('group_by');
            $key = $row->getAttribute('group_key');

            if ($key !== null && in_array($group, ['department', 'cost_centre'], true)) {
                $keys[$group === 'department' ? Department::class : CostCentre::class][] = $key;
            }
        }

        $loaded = $request->attributes->get(self::DIMENSIONS, []);

        foreach ($keys as $class => $ids) {
            foreach ($class::query()->whereKey(array_values(array_unique($ids)))->get() as $model) {
                $loaded[$class][$model->getKey()] = $model;
            }
        }

        $request->attributes->set(self::DIMENSIONS, $loaded);
    }

    /** @param class-string<Department|CostCentre> $class */
    private static function dimension(Request $request, string $class, string $id): ?object
    {
        $loaded = $request->attributes->get(self::DIMENSIONS, []);

        if (! array_key_exists($id, $loaded[$class] ?? [])) {
            $loaded[$class][$id] = $class::query()->find($id);
            $request->attributes->set(self::DIMENSIONS, $loaded);
        }

        return $loaded[$class][$id];
    }

    /** RBAC-05: values only when the reader may see them at every store the row adds up. */
    private function valuesVisible(Request $request, string $locations): bool
    {
        foreach (array_filter(explode(',', $locations)) as $locationId) {
            if (! Values::visible($request, $locationId)) {
                return false;
            }
        }

        return true;
    }
}
