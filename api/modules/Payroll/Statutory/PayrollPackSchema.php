<?php

namespace Modules\Payroll\Statutory;

use App\Core\CountryPacks\PackSchemas;
use App\Core\CountryPacks\ResearchStatus;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * ADM-04b (ADR 022): the structure of a payroll pack release, checked
 * before staff approve it: the currency, the components with their
 * effective-dated periods, the return types and payment file layouts.
 * Figures stay null until confirmed ("rate needed"); PackValidator flags a
 * confirmed period with an empty figure.
 */
final class PayrollPackSchema
{
    /**
     * CP-01: the required charges a company may mark "not applicable"
     * (`may_not_apply`), by country. CD INPP: sources conflict on its
     * rates and some companies are exempt.
     */
    public const MAY_NOT_APPLY = ['CD' => ['inpp']];

    public static function register(PackSchemas $schemas): void
    {
        $schemas->register('payroll', fn (array $content) => self::check($content), ['components' => ['code'], 'returns' => ['key']], ['components.*.periods' => null],
            ['components' => ['code', 'kind', 'method', 'periods'], 'components.*.periods' => ['effective_from', 'effective_to', 'needs_confirmation']]);
    }

    /** @param array<string, mixed> $content */
    public static function check(array $content): void
    {
        $validator = Validator::make($content, [
            'code' => ['required', 'string', 'regex:/^[A-Z]{2}\z/'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}\z/'],
            'sources' => ['present', 'array'],
            'todo' => ['present', 'array'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}\z/', 'distinct'],
            'components.*.kind' => ['required', 'string', 'max:40'],
            'components.*.method' => ['required', 'string', 'max:40'],
            'components.*.required' => ['sometimes', 'boolean'],
            'components.*.periods' => ['required', 'array', 'min:1'],
            'components.*.periods.*.effective_from' => ['required', 'date_format:Y-m-d'],
            'components.*.periods.*.effective_to' => ['present', 'nullable', 'date_format:Y-m-d'],
            'components.*.periods.*.needs_confirmation' => ['required', 'boolean'],
            'returns' => ['present', 'array'],
            'returns.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}\z/', 'distinct'],
            // CP-01: the label a period or a return is shown under (CD IRPP from 2026-01-01).
            'components.*.periods.*.display_code' => ['sometimes', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}\z/'],
            'returns.*.display' => ['sometimes', 'array'],
            'returns.*.display.*.from' => ['required', 'date_format:Y-m-d'],
            'returns.*.display.*.code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}\z/'],
            'payment_formats' => ['present', 'array'],
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException(implode(' ', $validator->errors()->all()));
        }

        foreach ($content['components'] as $component) {
            $required = (bool) ($component['required'] ?? true);

            // CP-01: a statutory charge every company owes cannot be marked "not applicable" by a company,
            // except the components the platform allows explicitly.
            if (($component['may_not_apply'] ?? false) === true && $required && in_array($component['kind'], ['contribution', 'income_tax', 'levy'], true)
                && ! in_array($component['code'], self::MAY_NOT_APPLY[$content['code']] ?? [], true)) {
                throw new InvalidArgumentException("components.{$component['code']}: may_not_apply is not allowed on a required charge outside PayrollPackSchema::MAY_NOT_APPLY.");
            }

            // A component left out while unconfirmed must be optional: a required one blocks instead.
            if (array_key_exists('unconfirmed', $component) && ($component['unconfirmed'] !== 'not_applied' || $required)) {
                throw new InvalidArgumentException("components.{$component['code']}: unconfirmed: not_applied is only allowed on a component with required false.");
            }
        }

        // CP-01: a researched period carries its confidence (high, medium or low), date and sources.
        foreach ($content['components'] as $component) {
            foreach ($component['periods'] as $period) {
                ResearchStatus::check($period, "components.{$component['code']}.{$period['effective_from']}");
            }
        }
    }
}
