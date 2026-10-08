<?php

namespace App\Core\MasterData\CreditLimits;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\FieldRules;
use App\Core\Workflow\Definitions\FlowGraph;
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
 * Default flow (WF-02), the same for every country: one approval by an
 * Accountant (the system template role) at the party's company or
 * tenant-wide (a shared party: tenant-wide only), any one of them
 * deciding; approved ends `approved` (the limit is applied), rejected ends
 * `rejected`. When no Accountant other than the requester is eligible, or
 * none decided within two business days, it goes to the Admins at the
 * same places. No second approval above an amount is shipped: a tenant
 * adds one in the builder (a condition on `increase`) with its own
 * threshold. A flow must pass an approval before an `approved` end.
 */
class CreditLimitChangeType extends DocumentType
{
    public const KEY = 'core.credit_limit_change';

    /** Who may submit a request. */
    public const REQUEST = 'core.credit_limit.request';

    /** Who acts on the flow (WF-08: stages naming no roles, cancel, return): Accountant, Admin, Owner. */
    public const APPROVE = 'core.credit_limit.approve';

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

    /**
     * L2: the party's own company; a shared party's request is decided at
     * the tenant (its limit applies in every company), whatever company the
     * requester named for it, so approvers cannot be steered by that choice.
     */
    public function scope(string $documentId): ?DocumentScope
    {
        $change = $this->find($documentId);

        if ($change === null) {
            return null;
        }

        return new DocumentScope(Party::query()->whereKey($change->party_id)->value('company_id'));
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
        return self::APPROVE;
    }

    /**
     * M4: an `approved` end must not be reachable from the start without
     * passing an approval node, or a flow could raise limits unapproved.
     */
    public function validateFlow(FlowGraph $flow): array
    {
        $start = $flow->start();

        if ($start === null) {
            return [];
        }

        $seen = [$start => true];
        $queue = [$start];
        $problems = [];

        while ($queue !== []) {
            $id = array_shift($queue);
            $node = $flow->node($id) ?? [];

            if (($node['type'] ?? null) === 'approval') {
                continue;
            }

            if (($node['type'] ?? null) === 'end' && ($node['outcome'] ?? null) === 'approved') {
                $problems[] = ['code' => 'approval_required', 'message' => __('core.credit_limit_change.validation.approval_required', ['node' => $flow->name($id)]), 'node' => $id];
            }

            foreach ($flow->outgoing($id) as $edge) {
                if (! isset($seen[$edge['to']])) {
                    $seen[$edge['to']] = true;
                    $queue[] = $edge['to'];
                }
            }
        }

        return $problems;
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
                    'approval' => ['approver' => ['type' => 'role', 'role' => 'template:accountant'], 'mode' => 'any'],
                    'escalation' => ['after' => ['amount' => 2, 'unit' => 'business_days'], 'to' => ['type' => 'role', 'role' => 'template:admin']]],
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
