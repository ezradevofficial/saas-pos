<?php

namespace Modules\Pharmacy\WriteOffs;

use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Support\Str;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\WriteOff;
use Modules\Pharmacy\Models\WriteOffLine;

/**
 * WF-01, PHA-12: `pharmacy.write_off`, a write-off of lot stock with a
 * reason, posted when its flow approves it. The default flow is one
 * approval by a Branch Manager where the stock is (any one of them,
 * APR-07: never the requester), escalated to the Admins after two business
 * days; a tenant may copy and change it. Conditions may route on the
 * reason, the location and the number of lines.
 */
class WriteOffType extends DocumentType
{
    public const KEY = 'pharmacy.write_off';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'pharmacy.workflow.write_off.type';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::reference('location', 'pharmacy.workflow.fields.location', 'core.location'),
            FieldDefinition::reference('branch', 'pharmacy.workflow.fields.branch', 'core.branch'),
            FieldDefinition::reference('company', 'pharmacy.workflow.fields.company', 'core.company'),
            FieldDefinition::enum('reason', 'pharmacy.workflow.fields.reason', WriteOff::REASONS),
            FieldDefinition::number('lines', 'pharmacy.workflow.fields.lines'),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        $writeOff = $this->find($documentId);

        if ($writeOff === null) {
            return [];
        }

        return [
            'location' => $writeOff->location_id,
            'branch' => $writeOff->branch_id,
            'company' => $writeOff->company_id,
            'reason' => $writeOff->reason,
            'lines' => WriteOffLine::query()->where('write_off_id', $writeOff->id)->count(),
        ];
    }

    public function scope(string $documentId): ?DocumentScope
    {
        $writeOff = $this->find($documentId);

        return $writeOff === null ? null : new DocumentScope($writeOff->company_id, $writeOff->branch_id, $writeOff->location_id);
    }

    public function requesterId(string $documentId): ?string
    {
        return $this->find($documentId)?->requested_by;
    }

    public function viewPermission(): string
    {
        return 'pharmacy.writeoff.view';
    }

    public function actPermission(): string
    {
        return 'pharmacy.writeoff.approve';
    }

    public function documentLink(string $documentId): string
    {
        return '/pharmacy/write-offs/'.rawurlencode($documentId);
    }

    public function actions(): array
    {
        return ['submit', 'approve', 'reject', 'cancel'];
    }

    public function summary(string $documentId): array
    {
        $writeOff = $this->find($documentId);

        return $writeOff === null
            ? ['number' => null, 'title' => null, 'amount' => null]
            : ['number' => $writeOff->number, 'title' => $writeOff->location()->value('name'), 'amount' => null];
    }

    public function defaultFlow(?string $country): ?array
    {
        $prefix = 'pharmacy.workflow.write_off';

        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => __("{$prefix}.start")],
                ['id' => 'approve', 'type' => 'approval', 'name' => __("{$prefix}.approve"),
                    'approval' => ['approver' => ['type' => 'role', 'role' => 'template:branch_manager'], 'mode' => 'any'],
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

    private function find(string $documentId): ?WriteOff
    {
        return Str::isUuid($documentId) ? WriteOff::query()->find($documentId) : null;
    }
}
