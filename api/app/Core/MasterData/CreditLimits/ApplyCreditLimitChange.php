<?php

namespace App\Core\MasterData\CreditLimits;

use App\Core\Approvals\Resolvers\ApproverDirectory;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Applies an approved credit limit change to its party (CreditLimitChanges::
 * apply), inside the request's tenant (TenantAware: row-level security).
 * Idempotent: a request already applied, or no longer approved, is left
 * alone. An error is retried (3 tries, backing off); a conflict (the
 * party's limit is now in another currency) is not. When it finally fails
 * the request stays approved, not applied, and the holders of
 * `core.credit_limit.set_directly` at its company are told
 * (`core.credit_limit_change.apply_failed`), who can apply it again.
 */
class ApplyCreditLimitChange implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const FAILED_EVENT = 'core.credit_limit_change.apply_failed';

    public int $tries = 3;

    /** @var list<int> seconds before the second and third tries */
    public array $backoff = [10, 60];

    public function __construct(
        public string $tenantId,
        public string $changeId,
    ) {
        $this->afterCommit();
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(CreditLimitChanges $changes): void
    {
        try {
            $changes->apply($this->changeId);
        } catch (CreditLimitConflict $conflict) {
            // Retrying does not help: tell the people who can sort it out.
            $this->fail($conflict);
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        app(TenantContext::class)->run($this->tenantId, fn () => self::notifyFailure($this->changeId, $exception));
    }

    /** Tell the holders of set_directly at the request's company (or tenant-wide) that it was not applied. */
    public static function notifyFailure(string $changeId, ?Throwable $exception): void
    {
        $change = CreditLimitChange::query()->with('party')->find($changeId);

        if ($change === null || $change->status !== CreditLimitChange::APPROVED) {
            return;
        }

        $directory = app(ApproverDirectory::class);
        $chain = app(ScopeResolver::class)->chainOf(Scope::company($change->company_id)) ?? [];
        $users = $directory->holdersAt($directory->rolesWith(CreditLimitChangeType::SET_DIRECTLY), $chain);

        if ($users === []) {
            return;
        }

        app(Notifier::class)->send(new NotificationEvent(self::FAILED_EVENT, $users, [
            'document_number' => $change->number,
            'party_name' => (string) $change->party?->name,
            'problem' => $exception instanceof CreditLimitConflict ? $exception->getMessage() : __('core.credit_limit_change.errors.apply_failed'),
        ], '/contacts/credit-limit-changes'));
    }
}
