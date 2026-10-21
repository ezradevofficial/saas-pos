<?php

namespace Modules\Payroll\Statutory;

use App\Core\Audit\Auditor;
use App\Core\CountryPacks\ResearchStatus;
use Carbon\CarbonImmutable;
use Modules\Payroll\Models\Settings;
use Modules\Payroll\Support\Errors;

/**
 * CP-01: a company's "not applicable" marks on pack components it does
 * not owe (only components with `may_not_apply`, e.g. CD INPP). A mark is
 * made against the component's period in force (`period_from`); it holds
 * while that period stays unusable. Once the period becomes usable
 * (confirmed or researched), or leaves the pack, the mark is void: it is
 * removed and audited (`payroll.statutory.not_applicable_voided`), so runs
 * block again until someone with `payroll.statutory.exempt` marks the
 * component again.
 */
class NotApplicableMarks
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * Void the company's stale marks (audited) and return the marks that
     * hold, by component code.
     *
     * @return array<string, array{reason: string, marked_by: ?string, marked_at: string, period_from: ?string}>
     */
    public function refresh(string $companyId, array $pack): array
    {
        $marks = (array) (Settings::query()->where('company_id', $companyId)->first()?->not_applicable ?? []);

        if ($marks === [] || array_filter(array_keys($marks), fn (string $code) => self::voidReason($pack, $code, $marks[$code]) !== null) === []) {
            return $marks;
        }

        return Errors::transaction(function () use ($companyId, $pack) {
            $settings = Settings::query()->where('company_id', $companyId)->lockForUpdate()->first();
            $marks = (array) ($settings?->not_applicable ?? []);
            $kept = $marks;

            foreach ($marks as $code => $mark) {
                $reason = self::voidReason($pack, $code, $mark);

                if ($reason === null) {
                    continue;
                }

                unset($kept[$code]);
                $this->auditor->record('payroll.statutory.not_applicable_voided', $settings, ['component' => $code, 'not_applicable' => $mark], ['component' => $code, 'reason' => $reason], ['user_id' => null]);
            }

            if ($settings !== null && $kept !== $marks) {
                $settings->not_applicable = $kept === [] ? null : $kept;
                $settings->save();
            }

            return $kept;
        });
    }

    /**
     * The marks that apply to a run or screen on $on: those whose period is
     * the one in force then (a mark made against a later period does not
     * reach an earlier pay date).
     *
     * @param  array<string, array<string, mixed>>  $marks  from refresh()
     * @return array<string, array<string, mixed>>
     */
    public static function inEffect(array $pack, array $marks, CarbonImmutable $on): array
    {
        $inForce = collect(PayrollPack::inForce($pack, $on))->keyBy('code');

        return array_filter($marks, function (array $mark, string $code) use ($inForce) {
            $component = $inForce[$code] ?? null;

            return $component !== null && $component['may_not_apply'] && $component['values'] !== null
                && ($component['values']['effective_from'] ?? null) === ($mark['period_from'] ?? null)
                && ! ResearchStatus::usable($component['values']);
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** Why a mark no longer holds (`period_confirmed`, `period_removed`, `not_allowed`), or null. */
    private static function voidReason(array $pack, string $code, array $mark): ?string
    {
        $component = collect($pack['components'] ?? [])->firstWhere('code', $code);

        if ($component === null || ! ($component['may_not_apply'] ?? false)) {
            return 'not_allowed';
        }

        $period = collect($component['periods'] ?? [])->firstWhere('effective_from', $mark['period_from'] ?? null);

        if ($period === null) {
            return 'period_removed';
        }

        return ResearchStatus::usable($period) ? 'period_confirmed' : null;
    }
}
