<?php

namespace Modules\Payroll\Webhooks;

use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Catalogue\WebhookEventType;
use App\Core\Webhooks\Catalogue\WebhookOccurrence;
use Modules\Payroll\Events\PayrollApproved;
use Modules\Payroll\Models\Run;
use Modules\Payroll\PayrollServiceProvider;
use Modules\Payroll\Support\PayFields;

/**
 * HOOK-01: Payroll's public webhook events (docs/webhooks.md), queued with
 * the run's id and read in the fan-out job. Never any
 * employee or pay line: the run and its totals per pay currency (minor-unit
 * digit strings), and the totals only for a principal whose field rules do
 * not hide them (PAY-23, RBAC-05: resource `payroll`, field `totals`).
 */
final class PayrollWebhookEvents
{
    public static function register(WebhookCatalogue $catalogue): void
    {
        $catalogue->register(new WebhookEventType(
            key: 'payroll.approved.v1',
            module: PayrollServiceProvider::MODULE,
            event: PayrollApproved::class,
            permission: 'payroll.run.view',
            // Queued with the run's id only: the totals are read in the fan-out job, never queued.
            capture: fn (PayrollApproved $e) => new WebhookOccurrence(['payroll_run_id' => $e->runId], $e->companyId),
            resolve: function (WebhookOccurrence $occurrence): ?WebhookOccurrence {
                $run = Run::query()->find($occurrence->data['payroll_run_id'] ?? null);

                return $run === null ? null : $occurrence->with([
                    'payroll_run_id' => $run->id,
                    'number' => $run->number,
                    'company_id' => $run->company_id,
                    'kind' => $run->kind,
                    'period_start' => $run->period_start?->toDateString(),
                    'period_end' => $run->period_end?->toDateString(),
                    'pay_date' => $run->pay_date?->toDateString(),
                    'totals' => array_map(fn (array $totals) => array_map('strval', $totals), (array) ($run->totals['currencies'] ?? [])),
                ]);
            },
            fields: ['payroll_run_id', 'number', 'company_id', 'kind', 'period_start', 'period_end', 'pay_date', 'totals'],
            hiddenBy: [PayFields::RESOURCE => [PayFields::TOTALS => ['totals']]],
        ));
    }
}
