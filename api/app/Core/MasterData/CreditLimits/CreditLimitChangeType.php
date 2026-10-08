<?php

namespace App\Core\MasterData\CreditLimits;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\FieldRules;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Support\Str;

/**
 * WF-01: `core.credit_limit_change`, a request to change a party's credit
 * limit (CreditLimitChange). Its place is the request's company (branch and
 * location unknown); its requester is whoever asked (APR-07, never its
 * approver). The engine reads it only through this class (architecture
 * rule 1).
 *
 * Default flow (WF-02), the same for every country: one approval by the
 * company's Accountant (the role from the system template, held at the
 * company or tenant-wide), any one of them deciding; approved ends
 * `approved` (the limit is applied), rejected ends `rejected`. No
 * escalation is set: when no Accountant other than the requester is
 * eligible, approvals moves the request to the next level's managers
 * (Admin or Owner above the company, APR-07). No second approval above an
 * amount is shipped: a tenant adds one in the builder (a condition on
 * `increase`) with its own threshold.
 */
class CreditLimitChangeType extends DocumentType
{
    public const KEY = 'core.credit_limit_change';

    /** Who may submit a request, and act on its flow where a stage names no roles (WF-08). */
    public const REQUEST = 'core.credit_limit.request';

    /** Who may set a limit on the party directly, raises included (Owner, Admin). */
    public const SET_DIRECTLY = 'core.credit_limit.set_directly';

    public const VIEW = 'core.party.view';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'core.credit_limit_change.type';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::reference('party', 'core.credit_limit_change.fields.party', 'core.party'),
            FieldDefinition::money('current_limit', 'core.credit_limit_change.fields.current_limit'),
            FieldDefinition::money('requested_limit', 'core.credit_limit_change.fields.requested_limit'),
            FieldDefinition::money('increase', 'core.credit_limit_change.fields.increase'),
            FieldDefinition::string('reason', 'core.credit_limit_change.fields.reason'),
            FieldDefinition::reference('company', 'core.credit_limit_change.fields.company', 'core.company'),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        $change = $this->find($documentId);

        if ($change === null) {
            return [];
        }

        return [
            'party' => $change->party_id,
            'current_limit' => $change->currentLimit(),
            'requested_limit' => $change->requestedLimit(),
            'increase' => $change->increase(),
            'reason' => $change->reason,
            'company' => $change->company_id,
        ];
    }

    public function scope(string $documentId): ?DocumentScope
    {
        $change = $this->find($documentId);

        return $change === null ? null : new DocumentScope($change->company_id);
    }

    public function requesterId(string $documentId): ?string
    {
        return $this->find($documentId)?->requested_by;
    }

    public function viewPermission(): string
    {
        return self::VIEW;
    }

    public function actPermission(): string
    {
        return self::REQUEST;
    }

    public function actions(): array
    {
        return ['submit', 'approve', 'reject', 'cancel'];
    }

    /**
     * APR-04: "CLC-000123", the party's name as the title (no wording, so
     * nothing is frozen in a language; no amounts, which field rules may
     * hide) and the requested limit as the amount.
     */
    public function summary(string $documentId): array
    {
        $change = $this->find($documentId);

        if ($change === null) {
            return ['number' => null, 'title' => null, 'amount' => null];
        }

        return [
            'number' => $change->number,
            'title' => Party::query()->whereKey($change->party_id)->value('name'),
            'amount' => $change->requestedLimit()->jsonSerialize(),
        ];
    }

    /** RBAC-05: the amount when the party's credit limit is hidden, the title when its name is. */
    public function hiddenSummaryFields(User $viewer): array
    {
        $hidden = app(FieldRules::class)->for($viewer, CreditLimitChangeAccess::FIELD_RULES)['hidden'];

        return array_values(array_filter([
            array_intersect(CreditLimitChangeAccess::LIMIT_FIELDS, $hidden) !== [] ? 'amount' : null,
            in_array('name', $hidden, true) ? 'title' : null,
        ]));
    }

    public function defaultFlow(?string $country): ?array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => __('core.credit_limit_change.flow.start')],
                ['id' => 'approve', 'type' => 'approval', 'name' => __('core.credit_limit_change.flow.approve'),
                    'approval' => ['approver' => ['type' => 'role', 'role' => 'template:accountant'], 'mode' => 'any']],
                ['id' => 'approved', 'type' => 'end', 'outcome' => 'approved', 'name' => __('core.credit_limit_change.flow.approved')],
                ['id' => 'rejected', 'type' => 'end', 'outcome' => 'rejected', 'name' => __('core.credit_limit_change.flow.rejected')],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'approve'],
                ['from' => 'approve', 'to' => 'approved', 'branch' => 'approved'],
                ['from' => 'approve', 'to' => 'rejected', 'branch' => 'rejected'],
            ],
        ];
    }

    private function find(string $documentId): ?CreditLimitChange
    {
        return Str::isUuid($documentId) ? CreditLimitChange::query()->find($documentId) : null;
    }
}
