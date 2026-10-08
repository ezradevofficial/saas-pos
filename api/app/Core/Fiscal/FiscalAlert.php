<?php

namespace App\Core\Fiscal;

use App\Core\Fiscal\Models\FiscalSubmission;
use App\Core\Identity\Models\User;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;

/**
 * Tells the company's fiscal administrators (active users holding
 * `core.fiscal.edit` at the company) that a document was refused
 * (`core.fiscal.rejected`, at once) or is still not accepted after the
 * country's alert delay (`core.fiscal.delayed`, once per submission).
 */
class FiscalAlert
{
    public const REJECTED = 'core.fiscal.rejected';

    public const DELAYED = 'core.fiscal.delayed';

    public function __construct(private readonly Notifier $notifier) {}

    public function rejected(FiscalSubmission $submission): void
    {
        $this->send(self::REJECTED, $submission, ['error' => (string) $submission->last_error]);
    }

    public function delayed(FiscalSubmission $submission, int $hours): void
    {
        $this->send(self::DELAYED, $submission, ['hours' => (string) $hours, 'error' => (string) $submission->last_error]);
    }

    /** @return list<string> */
    public function administrators(string $companyId): array
    {
        $candidates = User::query()->where('status', User::STATUS_ACTIVE)
            ->whereIn('id', RoleAssignment::query()->select('user_id'))
            ->orderBy('id')
            ->get();

        return $candidates->filter(fn (User $user) => $user->can('core.fiscal.edit', Scope::company($companyId)))->modelKeys();
    }

    private function send(string $event, FiscalSubmission $submission, array $data): void
    {
        $recipients = $this->administrators($submission->company_id);

        if ($recipients === []) {
            return;
        }

        $this->notifier->send(new NotificationEvent($event, $recipients, [
            ...$data,
            'document_number' => (string) ($submission->document_number ?? $submission->invoice_no),
            'document_type' => __('fiscal.document_types.'.$submission->document_type),
            'company_name' => (string) Company::query()->whereKey($submission->company_id)->value('name'),
        ], '/fiscal/submissions/'.$submission->id));
    }
}
