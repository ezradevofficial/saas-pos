<?php

namespace Modules\Payroll\Webhooks;

use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Catalogue\WebhookEventType;
use App\Core\Webhooks\Catalogue\WebhookOccurrence;
use Modules\Payroll\Events\PayrollApproved;
use Modules\Payroll\PayrollServiceProvider;
use Modules\Payroll\Support\PayFields;

/**
 * HOOK-01: Payroll's public webhook events (docs/webhooks.md). Never any
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
            capture: fn (PayrollApproved $e) => new WebhookOccurrence([
                'payroll_run_id' => $e->runId,
                'number' => $e->number,
                'company_id' => $e->companyId,
                'kind' => $e->kind,
                'period_start' => $e->periodStart,
                'period_end' => $e->periodEnd,
                'pay_date' => $e->payDate,
                'totals' => array_map(fn (array $totals) => array_map('strval', $totals), $e->totals),
            ], $e->companyId),
            fields: ['payroll_run_id', 'number', 'company_id', 'kind', 'period_start', 'period_end', 'pay_date', 'totals'],
            hiddenBy: [PayFields::RESOURCE => [PayFields::TOTALS => ['totals']]],
        ));
    }
}
