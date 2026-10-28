<?php

namespace Modules\FuelStation\Approvals;

use App\Core\Identity\Models\User;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Support\Str;
use Modules\FuelStation\Models\Dip;
use Modules\FuelStation\Models\Reconciliation;

/**
 * WF-01, FUEL-26: `fuel.tank_variance`, a tank's loss or gain beyond its
 * tolerance. The default flow is one approval by a Station manager at the
 * station (APR-07: never the requester), escalated to the Admins after two
 * business days. The person who took the dip can never approve it.
 */
class TankVarianceType extends DocumentType
{
    public const KEY = 'fuel.tank_variance';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'fuel.workflow.tank_variance.type';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::reference('location', 'fuel.workflow.fields.location', 'core.location'),
            FieldDefinition::reference('branch', 'fuel.workflow.fields.branch', 'core.branch'),
            FieldDefinition::reference('company', 'fuel.workflow.fields.company', 'core.company'),
            FieldDefinition::number('litres', 'fuel.workflow.fields.litres'),
            FieldDefinition::enum('direction', 'fuel.workflow.fields.direction', ['loss', 'gain']),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        $reconciliation = $this->find($documentId);

        if ($reconciliation === null) {
            return [];
        }

        return [
            'location' => $reconciliation->location_id,
            'branch' => $reconciliation->branch_id,
            'company' => $reconciliation->company_id,
            'litres' => (float) abs((float) $reconciliation->variance_litres),
            'direction' => (float) $reconciliation->variance_litres < 0 ? 'loss' : 'gain',
        ];
    }

    public function scope(string $documentId): ?DocumentScope
    {
        $reconciliation = $this->find($documentId);

        return $reconciliation === null ? null : new DocumentScope($reconciliation->company_id, $reconciliation->branch_id, $reconciliation->location_id);
    }

    public function requesterId(string $documentId): ?string
    {
        $reconciliation = $this->find($documentId);

        return $reconciliation === null ? null : Dip::query()->whereKey($reconciliation->dip_id)->value('taken_by');
    }

    public function viewPermission(): string
    {
        return 'fuel.variance.view';
    }

    public function actPermission(): string
    {
        return 'fuel.variance.approve';
    }

    /** FUEL-26: never the person who took the dip. */
    public function mayAct(User $user, string $documentId, string $action): bool
    {
        $reconciliation = $this->find($documentId);

        if ($reconciliation === null) {
            return false;
        }

        return $user->id !== Dip::query()->whereKey($reconciliation->dip_id)->value('taken_by');
    }

    public function documentLink(string $documentId): string
    {
        return '/fuel/reconciliations/'.rawurlencode($documentId);
    }

    public function actions(): array
    {
        return ['approve', 'reject', 'cancel'];
    }

    public function summary(string $documentId): array
    {
        $reconciliation = $this->find($documentId);

        return ['number' => $reconciliation?->number, 'title' => $reconciliation === null ? null : $reconciliation->variance_litres.' L', 'amount' => null];
    }

    public function defaultFlow(?string $country): ?array
    {
        $prefix = 'fuel.workflow.tank_variance';

        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => __("{$prefix}.start")],
                ['id' => 'approve', 'type' => 'approval', 'name' => __("{$prefix}.approve"),
                    'approval' => ['approver' => ['type' => 'role', 'role' => 'template:station_manager', 'also' => ['template:branch_manager']], 'mode' => 'any'],
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

    private function find(string $documentId): ?Reconciliation
    {
        return Str::isUuid($documentId) ? Reconciliation::query()->find($documentId) : null;
    }
}
