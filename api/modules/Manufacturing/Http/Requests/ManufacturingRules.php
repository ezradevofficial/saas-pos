<?php

namespace Modules\Manufacturing\Http\Requests;

use Modules\Manufacturing\Models\BomLine;

/** The shapes Manufacturing's requests share (quantities and percentages as decimal strings). */
final class ManufacturingRules
{
    public const QTY = 'regex:/^\d{1,14}(\.\d{1,6})?$/';

    public const PERCENT = 'regex:/^\d{1,3}(\.\d{1,4})?$/';

    /** @return array<string, list<mixed>> a version's output, labour and lines under $prefix (`version.` or ``) */
    public static function version(string $prefix, bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            "{$prefix}output_qty" => [$presence, 'string', self::QTY],
            "{$prefix}output_uom_id" => ['sometimes', 'nullable', 'uuid'],
            "{$prefix}labour_minutes" => ['sometimes', 'nullable', 'integer', 'between:0,1000000'],
            "{$prefix}note" => ['sometimes', 'nullable', 'string', 'max:2000'],
            "{$prefix}lines" => [$required ? 'present' : 'sometimes', 'array', 'max:200'],
            // The required field first: validated() builds each line in the order of these rules.
            "{$prefix}lines.*.item_id" => ['required', 'uuid'],
            "{$prefix}lines.*.kind" => ['sometimes', 'string', 'in:'.implode(',', BomLine::KINDS)],
            "{$prefix}lines.*.uom_id" => ['sometimes', 'nullable', 'uuid'],
            "{$prefix}lines.*.qty" => ['required', 'string', self::QTY],
            "{$prefix}lines.*.yield_percent" => ['sometimes', 'nullable', 'string', self::PERCENT],
            "{$prefix}lines.*.wastage_percent" => ['sometimes', 'nullable', 'string', self::PERCENT],
            "{$prefix}lines.*.cost_share_percent" => ['sometimes', 'nullable', 'string', self::PERCENT],
            "{$prefix}lines.*.explode" => ['sometimes', 'boolean'],
            "{$prefix}lines.*.note" => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
