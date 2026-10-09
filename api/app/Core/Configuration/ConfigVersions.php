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
 * Saving and publishing name the draft revision they edited or reviewed
 * (null: "I saw no draft"); when the draft has moved on since, they are
 * refused with ConfigConflict (409 config_changed). Every change is
 * audited as `core.config.*` (AUD-01); draft saves record the payload's
 * hash and size, publishing and roll back the full payloads.
 */
class ConfigVersions
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  ?int  $revision  the draft revision the caller edited (null: it saw no draft)
     * @return array{0: ConfigDocument, 1: bool} the document, and whether it was created
     */
    public function open(ConfigKind $kind, string $key, string $scopeType, ?string $scopeId, ?string $name, array $payload, ?User $by, ?int $revision = null): array
    {
        return $this->onceMore(fn () => $this->transaction(function () use ($kind, $key, $scopeType, $scopeId, $name, $payload, $by, $revision) {
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

            // A document just created has no draft to have moved on.
            $this->saveDraft($document, $payload, $created ? null : $name, $by, $created ? null : $revision);

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

    /**
     * @param  ?int  $revision  the draft revision the caller edited (null: it saw no draft)
     */
    public function saveDraft(ConfigDocument $document, array $payload, ?string $name, ?User $by, ?int $revision = null): ConfigVersion
    {
        return $this->transaction(function () use ($document, $payload, $name, $by, $revision) {
            $this->lock($document);
            $draft = $document->draft()->first();
            $this->assertRevision($document, $draft, $revision);

            if ($name !== null && $name !== $document->name) {
                $before = $document->name;
                $document->forceFill(['name' => $name])->save();
                $this->auditor->record('core.config.rename', $document, ['name' => $before], ['name' => $name]);
            }

            // AUD-01: autosaves are frequent and payloads large; the trail
            // keeps each draft state's hash and size (ADR 010).
            if ($draft === null) {
                $draft = $this->newVersion($document, $payload, ConfigVersion::DRAFT, 'draft', $by);
                $this->auditor->record('core.config.draft_create', $document, null, ['version' => $draft->version, 'revision' => $draft->revision, ...self::digest($payload)]);

                return $draft;
            }

            $before = ['version' => $draft->version, 'revision' => $draft->revision, ...self::digest($draft->payload)];
            $draft->forceFill(['payload' => $payload, 'revision' => $draft->revision + 1, 'updated_by' => $by?->id])->save();
            $this->auditor->record('core.config.draft_update', $document, $before, ['version' => $draft->version, 'revision' => $draft->revision, ...self::digest($payload)]);

            return $draft;
        });
    }

    /** @return list<array{path: string, code: string, message: string}> */
    public function problems(ConfigKind $kind, array $payload): array
    {
        return $kind->problems($payload);
    }

    /** @param int $revision the draft revision the caller reviewed */
    public function publish(ConfigDocument $document, ConfigKind $kind, ?User $by, int $revision): ConfigVersion
    {
        return $this->transaction(function () use ($document, $kind, $by, $revision) {
            $this->lock($document);
            $draft = $document->draft()->first();
            // No draft any more (published or discarded since) is a change too.
            $this->assertRevision($document, $draft, $revision);
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
                $previous === null ? null : ['published_version' => $previous->version, 'version_id' => $previous->id, 'payload' => $previous->payload],
                ['published_version' => $draft->version, 'version_id' => $draft->id, 'revision' => $draft->revision, 'payload' => $draft->payload],
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
                $previous === null ? null : ['published_version' => $previous->version, 'version_id' => $previous->id, 'payload' => $previous->payload],
                ['published_version' => $copy->version, 'copied_from_version' => $source->version, 'version_id' => $copy->id, 'payload' => $copy->payload],
            );

            return $copy;
        });
    }

    /**
     * Put this document's published payload (or its draft, $from = draft)
     * into the document of the same kind and key at $targetType/$targetId
     * as its draft, creating that document when missing. A draft already
     * there is someone's work: the copy is refused (409
     * config_draft_exists) unless $replace, which archives that draft as
     * discarded (its payload kept) before the copy becomes the new draft.
     */
    public function copyTo(ConfigDocument $source, ConfigKind $kind, string $targetType, string $targetId, string $from, ?User $by, bool $replace = false): ConfigDocument
    {
        $version = $from === 'draft' ? $source->draft()->first() : $source->published()->first();

        if ($version === null) {
            $code = $from === 'draft' ? 'no_draft' : 'nothing_published';

            throw new ApiException(422, $code, __('config.errors.'.$code));
        }

        if ($source->scope_type === $targetType && $source->scope_id === $targetId) {
            throw new ApiException(422, 'same_scope', __('config.errors.same_scope'));
        }

        return $this->onceMore(fn () => $this->transaction(function () use ($source, $kind, $targetType, $targetId, $version, $by, $replace) {
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
            $replaced = $document->draft()->first();

            if ($replaced !== null) {
                if (! $replace) {
                    throw new ConfigConflict($document, ConfigConflict::DRAFT_EXISTS);
                }

                $this->archiveDraft($replaced, $by);
            }

            $draft = $this->newVersion($document, $version->payload, ConfigVersion::DRAFT, 'copy', $by, $version);

            // The replaced draft's payload stays on its (archived) version row.
            $this->auditor->record('core.config.copy', $document,
                $replaced === null ? null : ['draft_version' => $replaced->version, 'version_id' => $replaced->id, 'revision' => $replaced->revision, ...self::digest($replaced->payload)],
                [
                    'created' => $created,
                    'from_document_id' => $source->id,
                    'from_version' => $version->version,
                    'draft_version' => $draft->version,
                    'version_id' => $draft->id,
                    ...self::digest($draft->payload),
                ],
            );

            return $document;
        }));
    }

    /** Archive the draft (kept for the record, never offered for roll back). */
    /** @param ?int $revision when given, the draft revision the caller means to discard */
    public function discardDraft(ConfigDocument $document, ?User $by, ?int $revision = null): ConfigVersion
    {
        return $this->transaction(function () use ($document, $by, $revision) {
            $this->lock($document);
            $draft = $document->draft()->first();

            if ($revision !== null) {
                $this->assertRevision($document, $draft, $revision);
            }

            $draft ?? throw new ApiException(422, 'no_draft', __('config.errors.no_draft'));
            $this->archiveDraft($draft, $by);

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
        return $document->versions()->orderByDesc('version')->get(ConfigVersion::SUMMARY);
    }

    /**
     * Refuse (409 config_changed) unless the draft is still the revision
     * the caller worked from; null means it saw no draft.
     */
    private function assertRevision(ConfigDocument $document, ?ConfigVersion $draft, ?int $revision): void
    {
        if ($draft?->revision !== $revision) {
            throw new ConfigConflict($document);
        }
    }

    /** The hash and size of a payload, for the audit of draft saves (AUD-01, ADR 010). */
    private static function digest(array $payload): array
    {
        $json = (string) json_encode($payload);

        return ['payload_sha256' => hash('sha256', $json), 'payload_bytes' => strlen($json)];
    }

    /** Archive a draft as discarded: kept for the record, never offered for roll back. */
    private function archiveDraft(ConfigVersion $draft, ?User $by): void
    {
        $now = CarbonImmutable::now();

        $draft->forceFill([
            'status' => ConfigVersion::ARCHIVED,
            'archived_at' => $now,
            'discarded_at' => $now,
            'discarded_by' => $by?->id,
        ])->save();
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
            // Above every revision the document has had, so a client's
            // revision of an earlier draft never matches this one.
            'revision' => (int) $document->versions()->max('revision') + 1,
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
