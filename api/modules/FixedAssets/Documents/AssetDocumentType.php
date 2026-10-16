<?php

namespace Modules\FixedAssets\Documents;

use App\Core\Automation\Capabilities\LinksDocuments;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\FixedAssets\Models\Asset;

/**
 * WF-01, FA-14, FA-17: what transfers and disposals share as workflow
 * documents. Their scope is the asset's place when requested (company,
 * branch, location); the requester never approves (APR-07). The default
 * flow (WF-02) is one approval by a Branch Manager at that branch,
 * escalated to the Admins after two business days. Conditions may route on
 * the asset's category, branch, location and company. The engine reads
 * them only through these classes (architecture rule 1).
 */
abstract class AssetDocumentType extends DocumentType implements LinksDocuments
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** `transfer` or `disposal` */
    abstract protected function kind(): string;

    public function label(): string
    {
        return "fixed_assets.workflow.{$this->kind()}.type";
    }

    public function fields(): array
    {
        return [
            FieldDefinition::reference('category', 'fixed_assets.workflow.fields.category', 'fixed_assets.category'),
            FieldDefinition::reference('location', 'fixed_assets.workflow.fields.location', 'core.location'),
            FieldDefinition::reference('branch', 'fixed_assets.workflow.fields.branch', 'core.branch'),
            FieldDefinition::reference('company', 'fixed_assets.workflow.fields.company', 'core.company'),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        $document = $this->find($documentId);
        $asset = $document === null ? null : Asset::query()->find($document->asset_id);

        if ($asset === null) {
            return [];
        }

        return [
            'category' => $asset->category_id,
            'location' => $this->locationOf($document, $asset),
            'branch' => $this->branchOf($document, $asset),
            'company' => $asset->company_id,
        ];
    }

    public function scope(string $documentId): ?DocumentScope
    {
        $document = $this->find($documentId);
        $asset = $document === null ? null : Asset::query()->find($document->asset_id);

        return $asset === null ? null : new DocumentScope($asset->company_id, $this->branchOf($document, $asset), $this->locationOf($document, $asset));
    }

    public function viewPermission(): string
    {
        return 'fixed_assets.asset.view';
    }

    public function actPermission(): string
    {
        return "fixed_assets.{$this->kind()}.approve";
    }

    public function requesterId(string $documentId): ?string
    {
        return $this->find($documentId)?->requested_by;
    }

    public function actions(): array
    {
        return ['submit', 'approve', 'reject', 'cancel'];
    }

    public function summary(string $documentId): array
    {
        $document = $this->find($documentId);

        if ($document === null) {
            return ['number' => null, 'title' => null, 'amount' => null];
        }

        $asset = Asset::query()->find($document->asset_id);

        return ['number' => $document->number, 'title' => $asset === null ? null : trim($asset->number.' '.$asset->name), 'amount' => $this->amount($document)];
    }

    public function amountLabel(): ?string
    {
        return null;
    }

    public function defaultFlow(?string $country): ?array
    {
        $prefix = "fixed_assets.workflow.{$this->kind()}";

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

    /** @return array{amount_minor: string, currency: string}|null */
    protected function amount(Model $document): ?array
    {
        return null;
    }

    protected function locationOf(Model $document, Asset $asset): string
    {
        return $asset->location_id;
    }

    protected function branchOf(Model $document, Asset $asset): string
    {
        return $asset->branch_id;
    }

    protected function find(string $documentId): ?Model
    {
        $model = $this->model();

        return Str::isUuid($documentId) ? $model::query()->find($documentId) : null;
    }
}
