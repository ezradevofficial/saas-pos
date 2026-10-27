<?php

namespace Modules\FuelStation\Approvals;

use App\Core\Identity\Models\User;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Support\Str;
use Modules\FuelStation\Models\Settlement;
use Modules\FuelStation\Models\Shift;

/**
 * WF-01, FUEL-14: `fuel.shift_variance`, an attendant's variance on a shift
 * (collected against expected by the meters). The default flow is one
 * approval by a Station manager at the station (any one of them, APR-07:
 * never the requester), escalated to the Admins after two business days; a
 * tenant may copy and change it. Conditions may route on the size of the
 * variance, the litre difference and the station.
 *
 * Whatever the flow says, the attendant whose variance it is, and whoever
 * opened or closed the shift, can never approve it (mayAct): the till only
 * submits, and nothing a device claims about who did what makes one of them
 * an approver.
 */
class ShiftVarianceType extends DocumentType
{
    public const KEY = 'fuel.shift_variance';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'fuel.workflow.shift_variance.type';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::reference('location', 'fuel.workflow.fields.location', 'core.location'),
            FieldDefinition::reference('branch', 'fuel.workflow.fields.branch', 'core.branch'),
            FieldDefinition::reference('company', 'fuel.workflow.fields.company', 'core.company'),
            FieldDefinition::reference('attendant', 'fuel.workflow.fields.attendant', 'core.user'),
            FieldDefinition::money('variance', 'fuel.workflow.fields.variance'),
            FieldDefinition::number('litres_variance', 'fuel.workflow.fields.litres_variance'),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        $settlement = $this->find($documentId);

        if ($settlement === null) {
            return [];
        }

        return [
            'location' => $settlement->location_id,
            'branch' => $settlement->branch_id,
            'company' => $settlement->company_id,
            'attendant' => $settlement->attendant_id,
            'variance' => ['amount_minor' => (string) abs($settlement->variance_minor), 'currency' => $settlement->currency],
            'litres_variance' => (float) abs((float) $settlement->litres_variance),
        ];
    }

    public function scope(string $documentId): ?DocumentScope
    {
        $settlement = $this->find($documentId);

        return $settlement === null ? null : new DocumentScope($settlement->company_id, $settlement->branch_id, $settlement->location_id);
    }

    /** APR-07: whoever closed the shift submitted the variance. */
    public function requesterId(string $documentId): ?string
    {
        $settlement = $this->find($documentId);

        return $settlement === null ? null : (Shift::query()->whereKey($settlement->shift_id)->value('closed_by') ?? $settlement->attendant_id);
    }

    public function viewPermission(): string
    {
        return 'fuel.variance.view';
    }

    public function actPermission(): string
    {
        return 'fuel.variance.approve';
    }

    /** FUEL-14: never the attendant, the person who opened the shift or the one who closed it. */
    public function mayAct(User $user, string $documentId, string $action): bool
    {
        $settlement = $this->find($documentId);

        if ($settlement === null) {
            return false;
        }

        if ($action !== 'approve') {
            return true;
        }

        $shift = Shift::query()->whereKey($settlement->shift_id)->first(['opened_by', 'closed_by']);

        return ! in_array($user->id, array_filter([$settlement->attendant_id, $shift?->opened_by, $shift?->closed_by]), true);
    }

    public function documentLink(string $documentId): string
    {
        return '/fuel/settlements/'.rawurlencode($documentId);
    }

    public function actions(): array
    {
        return ['approve', 'reject', 'cancel'];
    }

    public function summary(string $documentId): array
    {
        $settlement = $this->find($documentId);

        return $settlement === null
            ? ['number' => null, 'title' => null, 'amount' => null]
            : [
                'number' => $settlement->number,
                'title' => User::query()->whereKey($settlement->attendant_id)->value('name'),
                'amount' => ['amount_minor' => (string) abs($settlement->variance_minor), 'currency' => $settlement->currency],
            ];
    }

    public function amountLabel(): ?string
    {
        return 'fuel.workflow.fields.variance';
    }

    public function defaultFlow(?string $country): ?array
    {
        $prefix = 'fuel.workflow.shift_variance';

        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => __("{$prefix}.start")],
                ['id' => 'approve', 'type' => 'approval', 'name' => __("{$prefix}.approve"),
                    'approval' => ['approver' => ['type' => 'role', 'role' => 'template:station_manager'], 'mode' => 'any'],
                    'escalation' => ['after' => ['amount' => 2, 'unit' => 'business_days'], 'to' => ['type' => 'role', 'role' => 'template:admin']]],
                ['id' => 'approved', 'type' => 'end', 'outcome' => 'approved', 'name' => __("{$prefix}.approved")],
                ['id' => 'rejected', 'type' => 'end', 'outcome' => 'rejected', 'name' => __("{$prefix}.rejected")],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'approve'],
                ['from' => 'approve', 'to' => 'approved', 'branch' => 'approved'],
                ['from' => 'approve', 'to' => 'rejected', 'branch' => 'rejected'],
            ],
        ];
    }

    private function find(string $documentId): ?Settlement
    {
        return Str::isUuid($documentId) ? Settlement::query()->find($documentId) : null;
    }
}
