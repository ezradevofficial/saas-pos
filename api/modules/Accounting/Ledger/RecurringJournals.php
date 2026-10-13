<?php

namespace Modules\Accounting\Ledger;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\Models\RecurringJournal;

/**
 * ACC-06: recurring journals. On each due date the template's lines
 * become a journal (kind `recurring`, dated the due date) drafted by the
 * template's author and submitted to its flow, so it still passes
 * approval; the next date moves on by the frequency, and the template
 * ends after its end date. A template whose journal is refused (an
 * archived account) is paused, so it is not retried every day.
 */
class RecurringJournals
{
    public function __construct(private readonly ManualJournals $journals) {}

    /** Run every template of the current tenant due on or before $today; returns how many journals were made. */
    public function runDue(CarbonImmutable $today): int
    {
        $made = 0;

        foreach (RecurringJournal::query()->where('status', 'active')->whereNull('archived_at')->where('next_run_on', '<=', $today->toDateString())->orderBy('next_run_on')->get() as $template) {
            $made += $this->run($template, $today);
        }

        return $made;
    }

    private function run(RecurringJournal $template, CarbonImmutable $today): int
    {
        $made = 0;

        while ($template->status === 'active' && $template->next_run_on <= $today) {
            $due = $template->next_run_on;

            if ($template->ends_on !== null && $due > $template->ends_on) {
                $template->fill(['status' => 'ended'])->save();

                break;
            }

            try {
                DB::connection(TenantContext::CONNECTION)->transaction(function () use ($template, $due) {
                    $author = User::query()->findOrFail($template->created_by);
                    $company = Company::query()->findOrFail($template->company_id);
                    $journal = $this->journals->create($company, [
                        'date' => $due->toDateString(),
                        'memo' => $template->memo ?? $template->name,
                        'reference' => mb_substr($template->name, 0, 80),
                        'lines' => array_map(fn (array $line) => [...$line, 'currency' => $line['currency'] ?? $template->currency], $template->lines),
                    ], $author, 'recurring', $template->id);
                    $this->journals->submit($journal, $author);
                    $template->fill(['last_run_on' => $due->toDateString(), 'next_run_on' => self::next($due, $template->frequency)->toDateString()])->save();
                });
                $made++;
            } catch (ApiException $e) {
                Log::warning('accounting.recurring_refused', ['template' => $template->id, 'code' => $e->errorCode]);
                $template->fill(['status' => 'paused'])->save();
            }

            $template->refresh();
        }

        return $made;
    }

    public static function next(CarbonImmutable $from, string $frequency): CarbonImmutable
    {
        return match ($frequency) {
            'monthly' => $from->addMonthsNoOverflow(1),
            'quarterly' => $from->addMonthsNoOverflow(3),
            default => $from->addYearsNoOverflow(1),
        };
    }
}
