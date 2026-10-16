<?php

namespace Modules\CRM\Quotes;

use App\Core\Currency\Money;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Str;
use Modules\CRM\Models\Quote;
use Modules\CRM\Support\CrmAccess;

/**
 * WF-01, WF-02, CRM-21: `crm.quote`, a quote approved through its flow
 * (`crm.quote.approve`, WF-08). Conditions may route on the base total
 * (frozen at submission, CUR-04), the total in the quote's currency, the
 * largest line discount, `needs_approval` (base total above the
 * company's threshold, CRM-01), the customer, the company and the branch.
 *
 * The default flow approves at once unless `needs_approval` (the base
 * total above the threshold, or a line discount above the saver's
 * RBAC-06 limit, owner decision A, `approval_reason`); then one
 * Branch Manager at the branch approves (any one of them), escalated to
 * the Admins after two business days. The creator never approves their
 * own quote (APR-07). Who sees a quote's flow follows CRM-08.
 */
class QuoteType extends DocumentType
{
    public const KEY = 'crm.quote';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'crm.workflow.quote.type';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::money('base_total', 'crm.workflow.fields.base_total'),
            FieldDefinition::money('total', 'crm.workflow.fields.total'),
            FieldDefinition::number('max_discount_percent', 'crm.workflow.fields.max_discount_percent'),
            FieldDefinition::boolean('needs_approval', 'crm.workflow.fields.needs_approval'),
            FieldDefinition::boolean('discount_over_limit', 'crm.workflow.fields.discount_over_limit'),
            FieldDefinition::enum('approval_reason', 'crm.workflow.fields.approval_reason', ['amount_above_threshold', 'discount_above_limit']),
            FieldDefinition::reference('customer', 'crm.workflow.fields.customer', 'core.party'),
            FieldDefinition::reference('company', 'crm.workflow.fields.company', 'core.company'),
            FieldDefinition::reference('branch', 'crm.workflow.fields.branch', 'core.branch'),
            FieldDefinition::string('currency', 'crm.workflow.fields.currency'),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        $quote = $this->find($documentId);

        if ($quote === null) {
            return [];
        }

        return [
            'base_total' => $quote->base_total_minor === null || $quote->base_currency === null ? null : Money::ofMinor((string) $quote->base_total_minor, $quote->base_currency),
            'total' => Money::ofMinor((string) $quote->total_minor, $quote->currency),
            'max_discount_percent' => (string) $quote->max_discount_percent,
            'needs_approval' => (bool) $quote->needs_approval,
            'discount_over_limit' => (bool) $quote->discount_over_limit,
            'approval_reason' => $quote->approval_reason,
            'customer' => $quote->party_id,
            'company' => $quote->company_id,
            'branch' => $quote->branch_id,
            'currency' => $quote->currency,
        ];
    }

    public function scope(string $documentId): ?DocumentScope
    {
        $quote = $this->find($documentId);

        return $quote === null ? null : new DocumentScope($quote->company_id, $quote->branch_id);
    }

    public function viewPermission(): string
    {
        return 'crm.quote.view';
    }

    public function actPermission(): string
    {
        return 'crm.quote.approve';
    }

    public function requesterId(string $documentId): ?string
    {
        return $this->find($documentId)?->requested_by;
    }

    public function documentLink(string $documentId): string
    {
        return '/crm/quotes/'.rawurlencode($documentId);
    }

    public function actions(): array
    {
        return ['submit', 'approve', 'reject', 'cancel'];
    }

    public function amountLabel(): ?string
    {
        return 'crm.workflow.fields.total';
    }

    public function summary(string $documentId): array
    {
        $quote = $this->find($documentId);

        if ($quote === null) {
            return ['number' => null, 'title' => null, 'amount' => null];
        }

        return [
            'number' => $quote->number,
            'title' => Party::query()->whereKey($quote->party_id)->value('name'),
            'amount' => Money::ofMinor((string) $quote->total_minor, $quote->currency)->jsonSerialize(),
        ];
    }

    /** CRM-08: an approver sees the quote where they hold the branch scope (the default approvers do). */
    public function sees(User $user, string $documentId): bool
    {
        $quote = $this->find($documentId);

        return $quote !== null && app(CrmAccess::class)->sees($user, $quote, 'quote');
    }

    public function visibleDocumentIds(User $user): ?Builder
    {
        return app(CrmAccess::class)->visible(Quote::query(), $user, 'quote')->select('crm_quotes.id')->toBase();
    }

    public function defaultFlow(?string $country): ?array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => __('crm.workflow.quote.start')],
                ['id' => 'check', 'type' => 'condition', 'name' => __('crm.workflow.quote.check'),
                    'condition' => ['field' => 'needs_approval', 'op' => 'eq', 'value' => true]],
                ['id' => 'approve', 'type' => 'approval', 'name' => __('crm.workflow.quote.approve'),
                    'approval' => ['approver' => ['type' => 'role', 'role' => 'template:branch_manager'], 'mode' => 'any'],
                    'escalation' => ['after' => ['amount' => 2, 'unit' => 'business_days'], 'to' => ['type' => 'role', 'role' => 'template:admin']]],
                ['id' => 'approved', 'type' => 'end', 'outcome' => 'approved', 'name' => __('crm.workflow.quote.approved')],
                ['id' => 'rejected', 'type' => 'end', 'outcome' => 'rejected', 'name' => __('crm.workflow.quote.rejected')],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'check'],
                ['from' => 'check', 'to' => 'approve', 'branch' => 'yes'],
                ['from' => 'check', 'to' => 'approved', 'branch' => 'no'],
                ['from' => 'approve', 'to' => 'approved', 'branch' => 'approved'],
                ['from' => 'approve', 'to' => 'rejected', 'branch' => 'rejected'],
            ],
        ];
    }

    private function find(string $documentId): ?Quote
    {
        return Str::isUuid($documentId) ? Quote::query()->find($documentId) : null;
    }
}
