<?php

namespace App\Core\Configuration\Http\Controllers;

use App\Core\Configuration\ConfigConflict;
use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\ConfigKinds;
use App\Core\Configuration\ConfigPolicy;
use App\Core\Configuration\ConfigResolver;
use App\Core\Configuration\ConfigVersions;
use App\Core\Configuration\Http\Requests\CopyConfigRequest;
use App\Core\Configuration\Http\Requests\DiscardConfigDraftRequest;
use App\Core\Configuration\Http\Requests\ListConfigRequest;
use App\Core\Configuration\Http\Requests\PublishConfigRequest;
use App\Core\Configuration\Http\Requests\ResolveConfigRequest;
use App\Core\Configuration\Http\Requests\RollbackConfigRequest;
use App\Core\Configuration\Http\Requests\SaveConfigRequest;
use App\Core\Configuration\Http\Requests\ShowConfigRequest;
use App\Core\Configuration\Http\Requests\UpdateConfigDraftRequest;
use App\Core\Configuration\Http\Resources\ConfigDocumentResource;
use App\Core\Configuration\Models\ConfigDocument;
use App\Core\Configuration\Models\ConfigVersion;
use App\Core\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * LAY-06, LAY-07: versioned configuration under config/{kind}: the kind's
 * documents, one document with its versions, saving the draft, publishing,
 * rolling back, copying to another place, discarding the draft, and what
 * applies to the signed-in user (resolved). Every change is audited by
 * ConfigVersions (`core.config.*`, AUD-01). A 409 (config_changed,
 * config_draft_exists) carries the document as it is now, like a success.
 */
class ConfigController
{
    public function __construct(private readonly ConfigVersions $versions) {}

    public function index(ListConfigRequest $request, ConfigPolicy $policy): AnonymousResourceCollection
    {
        $kind = $request->kind();
        // Lists never read payloads (up to 256 KB each).
        $summary = fn ($q) => $q->select(ConfigVersion::SUMMARY);
        $query = ConfigDocument::query()->with(['published' => $summary, 'draft' => $summary])->where('kind', $kind->key);
        $policy->visible($query, $request->user(), $kind);

        if (($key = $request->validated('key')) !== null) {
            $query->where('key', $key);
        }

        if (($scopeType = $request->validated('scope_type')) !== null) {
            $query->where('scope_type', $scopeType)->where('scope_id', $request->validated('scope_id'));
        }

        $query->orderBy('key')->orderBy('scope_type')->orderBy('id');

        return ConfigDocumentResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    /** Creates the document for the key and scope when missing (201), else saves its draft (200). */
    public function store(SaveConfigRequest $request): JsonResponse
    {
        $request->authorizeTarget();

        return $this->answer($request, function () use ($request) {
            [$document, $created] = $this->versions->open(
                $request->kind(),
                $request->key(),
                $request->validated('scope_type'),
                $request->scopeId(),
                $request->validated('name'),
                $request->payload(),
                $request->user(),
                $request->revision(),
            );

            return $this->present($document, $request)->setStatusCode($created ? 201 : 200);
        });
    }

    public function show(ShowConfigRequest $request, string $kind, ConfigDocument $configDocument): JsonResponse
    {
        return $this->present($configDocument, $request);
    }

    public function updateDraft(UpdateConfigDraftRequest $request, string $kind, ConfigDocument $configDocument): JsonResponse
    {
        return $this->answer($request, function () use ($request, $configDocument) {
            $this->versions->saveDraft($configDocument, $request->payload(), $request->validated('name'), $request->user(), $request->revision());

            return $this->present($configDocument, $request);
        });
    }

    public function publish(PublishConfigRequest $request, string $kind, ConfigDocument $configDocument): JsonResponse
    {
        return $this->answer($request, function () use ($request, $configDocument) {
            $this->versions->publish($configDocument, $request->kind(), $request->user(), $request->revision());

            return $this->present($configDocument, $request);
        });
    }

    public function rollback(RollbackConfigRequest $request, string $kind, ConfigDocument $configDocument): JsonResponse
    {
        $this->versions->rollback($configDocument, $request->kind(), (int) $request->validated('version'), $request->user());

        return $this->present($configDocument, $request);
    }

    public function copy(CopyConfigRequest $request, string $kind, ConfigDocument $configDocument): JsonResponse
    {
        $request->authorizeTarget();

        return $this->answer($request, function () use ($request, $configDocument) {
            $target = $this->versions->copyTo(
                $configDocument,
                $request->kind(),
                $request->validated('scope_type'),
                $request->scopeId(),
                $request->validated('from', 'published'),
                $request->user(),
                $request->boolean('replace'),
            );

            return $this->present($target, $request)->setStatusCode(201);
        });
    }

    public function discardDraft(DiscardConfigDraftRequest $request, string $kind, ConfigDocument $configDocument): JsonResponse
    {
        return $this->answer($request, function () use ($request, $configDocument) {
            $this->versions->discardDraft($configDocument, $request->user(), $request->revision());

            return $this->present($configDocument, $request);
        });
    }

    /**
     * What applies to the signed-in user (LAY-06): the payload (merged
     * with the current catalogue, LAY-07) and where it came from, or the
     * kind's defaults with no source.
     */
    public function resolved(ResolveConfigRequest $request, ConfigResolver $resolver): JsonResponse
    {
        $kind = $request->kind();
        $result = $resolver->resolve($kind, $request->key(), $request->user(), $request->place());

        return new JsonResponse(['data' => [
            'kind' => $kind->key,
            'key' => $request->key(),
            ...$this->layer($kind, $request->key(), $request->user(), $result),
        ]]);
    }

    /**
     * LAY-04: every published layer that applies to the signed-in user,
     * most specific first (their own, their roles', the place's, the
     * tenant's), each as `resolved` presents it; empty when none is.
     */
    public function layers(ResolveConfigRequest $request, ConfigResolver $resolver): JsonResponse
    {
        $kind = $request->kind();
        $layers = $resolver->layers($kind, $request->key(), $request->user(), $request->place());

        return new JsonResponse([
            'data' => array_map(fn (array $layer) => $this->layer($kind, $request->key(), $request->user(), $layer), $layers),
            'meta' => [
                'kind' => $kind->key,
                'key' => $request->key(),
                // What applies under every layer: the kind's defaults, as this reader may see them.
                'defaults' => $this->layer($kind, $request->key(), $request->user(), ['payload' => $resolver->defaults($kind, $request->key()), 'version' => null, 'document' => null])['payload'],
            ],
        ]);
    }

    /**
     * A resolved payload as its reader may see it (RBAC-05, the kind's
     * presenter), with where it came from (null: the kind's defaults).
     *
     * @param  array{payload: ?array, version: mixed, document: ?ConfigDocument}  $result
     * @return array{payload: mixed, source: ?array<string, mixed>}
     */
    private function layer(ConfigKind $kind, string $key, User $user, array $result): array
    {
        $document = $result['document'];
        $payload = $result['payload'] === null ? null : $kind->present($result['payload'], $key, $user);

        return [
            'payload' => $payload === [] ? (object) [] : $payload,
            'source' => $document === null ? null : [
                'document_id' => $document->id,
                'name' => $document->name,
                'scope' => ['type' => $document->scope_type, 'id' => $document->scope_id],
                'version' => $result['version']->version,
                'published_at' => $result['version']->published_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Run a change; a ConfigConflict answers 409 with its code and the
     * document as it is now, so a designer can offer to reload it.
     *
     * @param  callable(): JsonResponse  $change
     */
    private function answer(Request $request, callable $change): JsonResponse
    {
        try {
            return $change();
        } catch (ConfigConflict $conflict) {
            return new JsonResponse(['message' => $conflict->getMessage(), 'code' => $conflict->errorCode, ...$this->body($conflict->document, $request)], 409);
        }
    }

    /**
     * The document with the draft and published payloads, its history
     * (without payloads) and the problems that block publishing the draft.
     */
    private function present(ConfigDocument $document, Request $request): JsonResponse
    {
        return new JsonResponse($this->body($document, $request));
    }

    /** @return array{data: array, meta: array} */
    private function body(ConfigDocument $document, Request $request): array
    {
        $document = $document->fresh(['published', 'draft', 'versions' => fn ($q) => $q->select(ConfigVersion::SUMMARY)]);
        $draft = $document->draft;
        $kind = app(ConfigKinds::class)->get($document->kind);

        return [
            'data' => ConfigDocumentResource::make($document)->withPayloads()->resolve($request),
            'meta' => ['problems' => $draft === null ? [] : $this->versions->problems($kind, $draft->payload, $document)],
        ];
    }
}
