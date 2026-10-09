<?php

namespace App\Core\Configuration;

use App\Core\Audit\Auditor;
use App\Core\Configuration\Models\ConfigDocument;
use App\Core\Configuration\Models\ConfigVersion;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Configuration documents and their versions (LAY-06), the generic form
 * of the workflow versioning (FlowDefinitions):
 *
 * - open: the document for a kind, key and scope (created when missing),
 *   with $payload saved as its draft;
 * - saveDraft: edit the one draft (created when there is none); drafts
 *   may have problems, publishing may not;
 * - problems: what keeps a payload from being published (the kind's
 *   validator);
 * - publish: the draft becomes the published version (numbered above
 *   every earlier one) and the one it replaces is archived;
 * - rollback: publish a copy of any earlier published version;
 * - copyTo: put this document's published payload (or draft) into the
 *   same kind and key at another company, branch or location of the
 *   tenant, as that document's draft;
 * - discardDraft: archive the draft (versions are never deleted);
 * - history: every version, newest first.
 *
 * Version changes lock the document row, so two publishes never race.
 * Every change is audited as `core.config.*` (AUD-01).
 */
class ConfigVersions
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @return array{0: ConfigDocument, 1: bool} the document, and whether it was created
     */
    public function open(ConfigKind $kind, string $key, string $scopeType, ?string $scopeId, ?string $name, array $payload, ?User $by): array
    {
        return $this->onceMore(fn () => $this->transaction(function () use ($kind, $key, $scopeType, $scopeId, $name, $payload, $by) {
            $document = $this->find($kind, $key, $scopeType, $scopeId);
            $created = $document === null;

            if ($created) {
                $document = ConfigDocument::create([
                    'kind' => $kind->key,
                    'key' => $key,
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                    'name' => $name ?? $key,
                    'created_by' => $by?->id,
                ]);

                $this->auditor->record('core.config.create', $document, null, [
                    'kind' => $kind->key, 'key' => $key, 'scope_type' => $scopeType, 'scope_id' => $scopeId, 'name' => $document->name,
                ]);
            }

            $this->saveDraft($document, $payload, $created ? null : $name, $by);

            return [$document, $created];
        }));
    }

    public function find(ConfigKind $kind, string $key, string $scopeType, ?string $scopeId): ?ConfigDocument
    {
        return ConfigDocument::query()
            ->where('kind', $kind->key)
            ->where('key', $key)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->first();
    }

    public function saveDraft(ConfigDocument $document, array $payload, ?string $name, ?User $by): ConfigVersion
    {
        return $this->transaction(function () use ($document, $payload, $name, $by) {
            $this->lock($document);

            if ($name !== null && $name !== $document->name) {
                $before = $document->name;
                $document->forceFill(['name' => $name])->save();
                $this->auditor->record('core.config.rename', $document, ['name' => $before], ['name' => $name]);
            }

            $draft = $document->draft()->first();

            if ($draft === null) {
                $draft = $this->newVersion($document, $payload, ConfigVersion::DRAFT, 'draft', $by);
                $this->auditor->record('core.config.draft_create', $document, null, ['version' => $draft->version, 'payload' => $payload]);

                return $draft;
            }

            $before = $draft->payload;
            $draft->forceFill(['payload' => $payload, 'updated_by' => $by?->id])->save();
            $this->auditor->record('core.config.draft_update', $document, ['version' => $draft->version, 'payload' => $before], ['version' => $draft->version, 'payload' => $payload]);

            return $draft;
        });
    }

    /** @return list<array{path: string, code: string, message: string}> */
    public function problems(ConfigKind $kind, array $payload): array
    {
        return $kind->problems($payload);
    }

    public function publish(ConfigDocument $document, ConfigKind $kind, ?User $by): ConfigVersion
    {
        return $this->transaction(function () use ($document, $kind, $by) {
            $this->lock($document);
            $draft = $document->draft()->first() ?? throw new ApiException(422, 'no_draft', __('config.errors.no_draft'));
            $this->assertValid($kind, $draft->payload);

            $previous = $this->archivePublished($document);
            $number = $this->nextNumber($document, $draft->version);

            $draft->forceFill([
                'version' => $number,
                'status' => ConfigVersion::PUBLISHED,
                'published_at' => CarbonImmutable::now(),
                'published_by' => $by?->id,
            ])->save();

            $this->auditor->record('core.config.publish', $document,
                $previous === null ? null : ['published_version' => $previous->version],
                ['published_version' => $draft->version, 'version_id' => $draft->id],
            );

            return $draft;
        });
    }

    /** Publish a copy of an earlier published version $version. */
    public function rollback(ConfigDocument $document, ConfigKind $kind, int $version, ?User $by): ConfigVersion
    {
        return $this->transaction(function () use ($document, $kind, $version, $by) {
            $this->lock($document);
            // A discarded draft was never live: it is not a version to roll back to.
            $source = $document->versions()->where('version', $version)->where('status', '<>', ConfigVersion::DRAFT)->whereNotNull('published_at')->first()
                ?? throw new ApiException(422, 'version_not_found', __('config.errors.version_not_found'));

            if ($source->status === ConfigVersion::PUBLISHED) {
                throw new ApiException(422, 'version_is_live', __('config.errors.version_is_live'));
            }

            // The kind's rules may have tightened since: the copy must still be valid.
            $this->assertValid($kind, $source->payload);
            $previous = $this->archivePublished($document);
            $copy = $this->newVersion($document, $source->payload, ConfigVersion::PUBLISHED, 'rollback', $by, $source);

            $this->auditor->record('core.config.rollback', $document,
                $previous === null ? null : ['published_version' => $previous->version],
                ['published_version' => $copy->version, 'copied_from_version' => $source->version, 'version_id' => $copy->id],
            );

            return $copy;
        });
    }

    /**
     * Put this document's published payload (or its draft, $from = draft)
     * into the document of the same kind and key at $targetType/$targetId
     * as its draft, creating that document when missing; an existing
     * draft there is replaced.
     */
    public function copyTo(ConfigDocument $source, ConfigKind $kind, string $targetType, string $targetId, string $from, ?User $by): ConfigDocument
    {
        $version = $from === 'draft' ? $source->draft()->first() : $source->published()->first();

        if ($version === null) {
            $code = $from === 'draft' ? 'no_draft' : 'nothing_published';

            throw new ApiException(422, $code, __('config.errors.'.$code));
        }

        if ($source->scope_type === $targetType && $source->scope_id === $targetId) {
            throw new ApiException(422, 'same_scope', __('config.errors.same_scope'));
        }

        return $this->onceMore(fn () => $this->transaction(function () use ($source, $kind, $targetType, $targetId, $version, $by) {
            $document = $this->find($kind, $source->key, $targetType, $targetId);
            $created = $document === null;
            $document ??= ConfigDocument::create([
                'kind' => $kind->key,
                'key' => $source->key,
                'scope_type' => $targetType,
                'scope_id' => $targetId,
                'name' => $source->name,
                'created_by' => $by?->id,
            ]);

            $this->lock($document);
            $draft = $document->draft()->first();

            if ($draft === null) {
                $draft = $this->newVersion($document, $version->payload, ConfigVersion::DRAFT, 'copy', $by, $version);
            } else {
                $draft->forceFill(['payload' => $version->payload, 'source' => 'copy', 'source_version_id' => $version->id, 'updated_by' => $by?->id])->save();
            }

            $this->auditor->record('core.config.copy', $document, null, [
                'created' => $created,
                'from_document_id' => $source->id,
                'from_version' => $version->version,
                'draft_version' => $draft->version,
            ]);

            return $document;
        }));
    }

    /** Archive the draft (kept for the record, never offered for roll back). */
    public function discardDraft(ConfigDocument $document, ?User $by): ConfigVersion
    {
        return $this->transaction(function () use ($document, $by) {
            $this->lock($document);
            $draft = $document->draft()->first() ?? throw new ApiException(422, 'no_draft', __('config.errors.no_draft'));
            $now = CarbonImmutable::now();

            $draft->forceFill([
                'status' => ConfigVersion::ARCHIVED,
                'archived_at' => $now,
                'discarded_at' => $now,
                'discarded_by' => $by?->id,
            ])->save();

            $this->auditor->record('core.config.draft_discard', $document,
                ['draft_version' => $draft->version, 'payload' => $draft->payload],
                ['published_version' => $document->published()->value('version')],
            );

            return $draft;
        });
    }

    /** @return Collection<int, ConfigVersion> newest first */
    public function history(ConfigDocument $document): Collection
    {
        return $document->versions()->orderByDesc('version')->get();
    }

    private function assertValid(ConfigKind $kind, array $payload): void
    {
        $problems = $this->problems($kind, $payload);

        if ($problems !== []) {
            throw new InvalidPayload($problems);
        }
    }

    private function archivePublished(ConfigDocument $document): ?ConfigVersion
    {
        $published = $document->published()->first();
        $published?->forceFill(['status' => ConfigVersion::ARCHIVED, 'archived_at' => CarbonImmutable::now()])->save();

        return $published;
    }

    /** $current when it is above every other version, else the next number. */
    private function nextNumber(ConfigDocument $document, ?int $current = null): int
    {
        $max = (int) $document->versions()->when($current !== null, fn ($q) => $q->where('version', '<>', $current))->max('version');

        return $current !== null && $current > $max ? $current : $max + 1;
    }

    private function newVersion(ConfigDocument $document, array $payload, string $status, string $source, ?User $by, ?ConfigVersion $from = null): ConfigVersion
    {
        $published = $status === ConfigVersion::PUBLISHED;

        return ConfigVersion::create([
            'document_id' => $document->id,
            'version' => $this->nextNumber($document),
            'status' => $status,
            'payload' => $payload,
            'source' => $source,
            'source_version_id' => $from?->id,
            'created_by' => $by?->id,
            'updated_by' => $by?->id,
            'published_by' => $published ? $by?->id : null,
            'published_at' => $published ? CarbonImmutable::now() : null,
        ]);
    }

    /**
     * Run $fn again when a concurrent request created the same document
     * first (the unique index refused ours and its savepoint rolled back):
     * the second run finds that document. A second clash is 422.
     */
    private function onceMore(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (UniqueConstraintViolationException) {
            try {
                return $fn();
            } catch (UniqueConstraintViolationException) {
                throw new ApiException(422, 'config_busy', __('config.errors.config_busy'));
            }
        }
    }

    private function lock(ConfigDocument $document): void
    {
        ConfigDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
    }

    private function transaction(callable $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }
}
