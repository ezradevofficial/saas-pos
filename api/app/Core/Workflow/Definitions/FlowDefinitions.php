<?php

namespace App\Core\Workflow\Definitions;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Models\WorkflowDefinition;
use App\Core\Workflow\Models\WorkflowVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Flow definitions and their versions (WF-02, WF-03, WF-05, WF-06, WF-08,
 * WF-09, APR-09, spec 6.4):
 *
 * - create: a flow for a type in a company (or every company), starting
 *   with a draft copied from the type's default for the company's country;
 * - saveDraft: edit the one draft (created when there is none);
 * - publish: validate the draft, make it the published version (numbered
 *   above every earlier version) and archive the one it replaces;
 *   documents in progress keep their version;
 * - rollback: publish a copy of an older version;
 * - copyTo: put this flow's graph into another company's flow as its draft;
 * - restoreDefault: the type's default as the draft again.
 *
 * Version changes lock the flow row, so two publishes never race. Every
 * change is audited as `core.workflow.*` (AUD-01).
 */
class FlowDefinitions
{
    public function __construct(
        private readonly DocumentTypeRegistry $types,
        private readonly GraphValidator $validator,
        private readonly Auditor $auditor,
    ) {}

    /** The graph a type ships for $country (else for any country), or start → end. */
    public function defaultGraph(DocumentType $type, ?string $country): array
    {
        return $type->defaultFlow($country) ?? $type->defaultFlow(null) ?? self::blankGraph();
    }

    /** @return array{nodes: list<array<string, mixed>>, edges: list<array<string, string>>} */
    public static function blankGraph(): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'end', 'type' => 'end', 'outcome' => 'completed'],
            ],
            'edges' => [['from' => 'start', 'to' => 'end']],
        ];
    }

    public function create(DocumentType $type, ?Company $company, ?User $by): WorkflowDefinition
    {
        try {
            return $this->transaction(function () use ($type, $company, $by) {
                $definition = WorkflowDefinition::create([
                    'document_type' => $type->key(),
                    'company_id' => $company?->id,
                    'created_by' => $by?->id,
                ]);

                $draft = $this->newVersion($definition, $this->defaultGraph($type, $company?->country), WorkflowVersion::DRAFT, 'default', $by);

                $this->auditor->record('core.workflow.create', $definition, null, [
                    'document_type' => $type->key(), 'company_id' => $company?->id, 'draft_version' => $draft->version,
                ]);

                return $definition;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException(422, 'workflow_exists', __('workflow.errors.workflow_exists'));
        }
    }

    public function saveDraft(WorkflowDefinition $definition, array $graph, ?User $by): WorkflowVersion
    {
        $problems = $this->validator->structural($graph);

        if ($problems !== []) {
            throw new InvalidGraph($problems);
        }

        return $this->transaction(function () use ($definition, $graph, $by) {
            $this->lock($definition);
            $draft = $definition->draft()->first();

            if ($draft === null) {
                $draft = $this->newVersion($definition, $graph, WorkflowVersion::DRAFT, 'draft', $by);
                $this->auditor->record('core.workflow.draft_create', $definition, null, ['version' => $draft->version, 'graph' => $graph]);

                return $draft;
            }

            $before = $draft->graph;
            $draft->forceFill(['graph' => $graph, 'updated_by' => $by?->id])->save();
            $this->auditor->record('core.workflow.draft_update', $definition, ['version' => $draft->version, 'graph' => $before], ['version' => $draft->version, 'graph' => $graph]);

            return $draft;
        });
    }

    /** Problems that keep the draft (or $graph) from being published. */
    public function problems(WorkflowDefinition $definition, ?array $graph = null): array
    {
        $graph ??= $definition->draft()->first()?->graph;

        if ($graph === null) {
            return [['code' => 'no_draft', 'message' => __('workflow.errors.no_draft'), 'node' => null]];
        }

        return $this->validator->validate($graph, $this->types->get($definition->document_type));
    }

    public function publish(WorkflowDefinition $definition, ?User $by): WorkflowVersion
    {
        return $this->transaction(function () use ($definition, $by) {
            $this->lock($definition);
            $draft = $definition->draft()->first() ?? throw new ApiException(422, 'no_draft', __('workflow.errors.no_draft'));
            $this->assertValid($definition, $draft->graph);

            $previous = $this->archivePublished($definition);
            $number = $this->nextNumber($definition, $draft->version);

            $draft->forceFill([
                'version' => $number,
                'status' => WorkflowVersion::PUBLISHED,
                'published_at' => CarbonImmutable::now(),
                'published_by' => $by?->id,
            ])->save();

            $this->auditor->record('core.workflow.publish', $definition,
                $previous === null ? null : ['published_version' => $previous->version],
                ['published_version' => $draft->version, 'version_id' => $draft->id],
            );

            return $draft;
        });
    }

    public function rollback(WorkflowDefinition $definition, int $version, ?User $by): WorkflowVersion
    {
        return $this->transaction(function () use ($definition, $version, $by) {
            $this->lock($definition);
            $source = $definition->versions()->where('version', $version)->where('status', '<>', WorkflowVersion::DRAFT)->first()
                ?? throw new ApiException(422, 'version_not_found', __('workflow.errors.version_not_found'));

            if ($source->status === WorkflowVersion::PUBLISHED) {
                throw new ApiException(422, 'version_is_live', __('workflow.errors.version_is_live'));
            }

            // Roles may have been archived since: the copy must still be valid.
            $this->assertValid($definition, $source->graph);
            $previous = $this->archivePublished($definition);

            $copy = $this->newVersion($definition, $source->graph, WorkflowVersion::PUBLISHED, 'rollback', $by, $source);

            $this->auditor->record('core.workflow.rollback', $definition,
                $previous === null ? null : ['published_version' => $previous->version],
                ['published_version' => $copy->version, 'copied_from_version' => $source->version, 'version_id' => $copy->id],
            );

            return $copy;
        });
    }

    /**
     * Put this flow's published graph (or its draft) into $target's flow
     * for the same type as that flow's draft, creating the flow when the
     * company has none; an existing draft there is replaced.
     */
    public function copyTo(WorkflowDefinition $source, ?Company $target, string $from, ?User $by): WorkflowDefinition
    {
        $version = $from === 'draft' ? $source->draft()->first() : $source->published()->first();

        if ($version === null) {
            throw new ApiException(422, $from === 'draft' ? 'no_draft' : 'nothing_published', __('workflow.errors.'.($from === 'draft' ? 'no_draft' : 'nothing_published')));
        }

        if ($source->company_id === $target?->id) {
            throw new ApiException(422, 'same_company', __('workflow.errors.same_company'));
        }

        return $this->onceMore(fn () => $this->transaction(function () use ($source, $target, $version, $by) {
            $definition = WorkflowDefinition::query()
                ->where('document_type', $source->document_type)
                ->where('company_id', $target?->id)
                ->first();

            $created = $definition === null;
            $definition ??= WorkflowDefinition::create([
                'document_type' => $source->document_type,
                'company_id' => $target?->id,
                'created_by' => $by?->id,
            ]);

            $this->lock($definition);
            $draft = $definition->draft()->first();

            if ($draft === null) {
                $draft = $this->newVersion($definition, $version->graph, WorkflowVersion::DRAFT, 'copy', $by, $version);
            } else {
                $draft->forceFill(['graph' => $version->graph, 'source' => 'copy', 'source_version_id' => $version->id, 'updated_by' => $by?->id])->save();
            }

            $this->auditor->record('core.workflow.copy', $definition, null, [
                'created' => $created,
                'from_workflow_id' => $source->id,
                'from_version' => $version->version,
                'draft_version' => $draft->version,
            ]);

            return $definition;
        }));
    }

    /** WF-02: the type's default for the company's country becomes the draft again. */
    public function restoreDefault(WorkflowDefinition $definition, ?User $by): WorkflowVersion
    {
        $type = $this->types->get($definition->document_type);
        $graph = $this->defaultGraph($type, $definition->company?->country);

        return $this->transaction(function () use ($definition, $graph, $by) {
            $this->lock($definition);
            $draft = $definition->draft()->first();

            if ($draft === null) {
                $draft = $this->newVersion($definition, $graph, WorkflowVersion::DRAFT, 'default', $by);
            } else {
                $draft->forceFill(['graph' => $graph, 'source' => 'default', 'source_version_id' => null, 'updated_by' => $by?->id])->save();
            }

            $this->auditor->record('core.workflow.restore_default', $definition, null, ['draft_version' => $draft->version]);

            return $draft;
        });
    }

    /**
     * The published version a document of $type in $companyId runs on: the
     * company's own flow, else the flow for every company.
     */
    public function publishedFor(string $type, ?string $companyId): ?WorkflowVersion
    {
        foreach (array_unique([$companyId, null]) as $company) {
            $version = WorkflowVersion::query()
                ->where('status', WorkflowVersion::PUBLISHED)
                ->whereHas('definition', fn ($q) => $q->where('document_type', $type)->where('company_id', $company))
                ->first();

            if ($version !== null) {
                return $version;
            }
        }

        return null;
    }

    /**
     * WF-02: a tenant that never configured a type's flow runs the type's
     * default. It is published once, as version 1 of the flow for every
     * company (or the document's company when it has a country-specific
     * default), so documents pin it like any version (APR-09). Null when the
     * type ships no default.
     */
    public function adoptDefault(DocumentType $type, ?Company $company): ?WorkflowVersion
    {
        $country = $company?->country;
        $specific = $country !== null && $type->defaultFlow($country) !== null;
        $graph = $type->defaultFlow($country) ?? $type->defaultFlow(null);

        if ($graph === null) {
            return null;
        }

        $companyId = $specific ? $company->id : null;

        return $this->onceMore(fn () => $this->transaction(function () use ($type, $companyId, $graph) {
            $definition = WorkflowDefinition::query()->where('document_type', $type->key())->where('company_id', $companyId)->first()
                ?? WorkflowDefinition::create(['document_type' => $type->key(), 'company_id' => $companyId]);
            $this->lock($definition);

            if (($published = $definition->published()->first()) !== null) {
                return $published;
            }

            $this->assertValid($definition, $graph);
            $version = $this->newVersion($definition, $graph, WorkflowVersion::PUBLISHED, 'default', null);
            $this->auditor->record('core.workflow.publish', $definition, null, [
                'published_version' => $version->version, 'version_id' => $version->id, 'source' => 'default',
            ]);

            return $version;
        }));
    }

    /**
     * Run $fn again when a concurrent request created the same flow first
     * (a unique index refused ours; its transaction rolled back to its
     * savepoint): the second run finds that flow. A second clash is 422.
     */
    private function onceMore(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (UniqueConstraintViolationException) {
            try {
                return $fn();
            } catch (UniqueConstraintViolationException) {
                throw new ApiException(422, 'workflow_busy', __('workflow.errors.workflow_busy'));
            }
        }
    }

    private function assertValid(WorkflowDefinition $definition, array $graph): void
    {
        $problems = $this->problems($definition, $graph);

        if ($problems !== []) {
            throw new InvalidGraph($problems);
        }
    }

    private function archivePublished(WorkflowDefinition $definition): ?WorkflowVersion
    {
        $published = $definition->published()->first();
        $published?->forceFill(['status' => WorkflowVersion::ARCHIVED, 'archived_at' => CarbonImmutable::now()])->save();

        return $published;
    }

    /** $current when it is above every other version, else the next number. */
    private function nextNumber(WorkflowDefinition $definition, ?int $current = null): int
    {
        $max = (int) $definition->versions()->when($current !== null, fn ($q) => $q->where('version', '<>', $current))->max('version');

        return $current !== null && $current > $max ? $current : $max + 1;
    }

    private function newVersion(
        WorkflowDefinition $definition,
        array $graph,
        string $status,
        string $source,
        ?User $by,
        ?WorkflowVersion $from = null,
    ): WorkflowVersion {
        $published = $status === WorkflowVersion::PUBLISHED;

        return WorkflowVersion::create([
            'definition_id' => $definition->id,
            'version' => $this->nextNumber($definition),
            'status' => $status,
            'graph' => $graph,
            'source' => $source,
            'source_version_id' => $from?->id,
            'created_by' => $by?->id,
            'updated_by' => $by?->id,
            'published_by' => $published ? $by?->id : null,
            'published_at' => $published ? CarbonImmutable::now() : null,
        ]);
    }

    private function lock(WorkflowDefinition $definition): void
    {
        WorkflowDefinition::query()->whereKey($definition->id)->lockForUpdate()->firstOrFail();
    }

    private function transaction(callable $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }
}
