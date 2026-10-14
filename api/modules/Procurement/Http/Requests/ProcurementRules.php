<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Modules\Procurement\Support\Places;

/**
 * Validation shared by Procurement's Form Requests (PRC-22). Ids are
 * checked to exist under row-level security (another tenant's do not);
 * whether they belong to the document's company is checked by the
 * services (LineResolver). Money is minor units as digit strings,
 * quantities decimal strings (ADR 003).
 */
final class ProcurementRules
{
    public const MINOR = 'regex:/^\d{1,18}$/';

    public const QTY = 'regex:/^\d{1,14}(\.\d{1,6})?$/';

    /** @return list<mixed> */
    public static function id(string $table, bool $required = false): array
    {
        return [$required ? 'required' : 'sometimes', ...($required ? [] : ['nullable']), 'uuid', Rule::exists($table, 'id')];
    }

    /**
     * Rules of document lines under $prefix (`lines.*.`).
     *
     * @return array<string, list<mixed>>
     */
    public static function lines(string $prefix, bool $price = false, bool $priceRequired = false, bool $tax = false, bool $dimensions = true, bool $requisitionLine = false): array
    {
        return array_filter([
            "{$prefix}item_id" => self::id('items'),
            "{$prefix}description" => ['sometimes', 'nullable', 'string', 'max:500'],
            "{$prefix}uom_id" => self::id('uoms'),
            "{$prefix}qty" => ['required', 'string', self::QTY],
            "{$prefix}unit_price_minor" => $price ? [$priceRequired ? 'required' : 'sometimes', 'string', self::MINOR] : null,
            "{$prefix}tax_code_id" => $tax ? self::id('tax_codes') : null,
            "{$prefix}cost_centre_id" => $dimensions ? self::id('cost_centres') : null,
            "{$prefix}project_id" => $dimensions ? self::id('projects') : null,
            "{$prefix}requisition_line_id" => $requisitionLine ? self::id('procurement_requisition_lines') : null,
        ]);
    }

    /** 403 unless $user holds $permission at the branch (PRC-22). */
    public static function atBranch(User $user, string $permission, string $branchId): void
    {
        if (! app(Places::class)->at($user, $permission, $branchId)) {
            throw new AuthorizationException(__('rbac.errors.forbidden'));
        }
    }

    /** 403 unless $user holds $permission at the location. */
    public static function atLocation(User $user, string $permission, string $locationId): void
    {
        if (! app(Places::class)->atLocation($user, $permission, $locationId)) {
            throw new AuthorizationException(__('rbac.errors.forbidden'));
        }
    }
}
