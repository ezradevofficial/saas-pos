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
            'payment_formats' => ['present', 'array'],
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException(implode(' ', $validator->errors()->all()));
        }

        // CP-01: a researched period carries its confidence (high or medium), date and sources; low confidence is never entered.
        foreach ($content['components'] as $component) {
            foreach ($component['periods'] as $period) {
                ResearchStatus::check($period, "components.{$component['code']}.{$period['effective_from']}");
            }
        }
    }
}
