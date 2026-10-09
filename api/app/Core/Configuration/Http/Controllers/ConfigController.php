<?php

namespace App\Core\Configuration\Http\Controllers;

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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * LAY-06, LAY-07: versioned configuration under config/{kind}: the kind's
 * documents, one document with its versions, saving the draft, publishing,
 * rolling back, copying to another place, discarding the draft, and what
 * applies to the signed-in user (resolved). Every change is audited by
 * ConfigVersions (`core.config.*`, AUD-01).
 */
class ConfigController
{
    public function __construct(private readonly ConfigVersions $versions) {}

    public function index(ListConfigRequest $request, ConfigPolicy $policy): AnonymousResourceCollection
    {
        $kind = $request->kind();
        $query = ConfigDocument::query()->with(['published', 'draft'])->where('kind', $kind->key);
        $policy->visible($query, $request->user(), $kind);

        if (($key = $request->validated('key')) !== null) {
            $query->where('key', $key);
        }

        $query->orderBy('key')->orderBy('scope_type')->orderBy('id');

        return ConfigDocumentResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    /** Creates the document for the key and scope when missing (201), else saves its draft (200). */
    public function store(SaveConfigRequest $request): JsonResponse
    {
        $request->authorizeTarget();
        [$document, $created] = $this->versions->open(
            $request->kind(),
            $request->key(),
            $request->validated('scope_type'),
            $request->scopeId(),
            $request->validated('name'),
            $request->payload(),
            $request->user(),
        );

        return $this->present($document, $request)->setStatusCode($created ? 201 : 200);
    }

    public function show(ShowConfigRequest $request, string $kind, ConfigDocument $configDocument): JsonResponse
    {
        return $this->present($configDocument, $request);
    }

    public function updateDraft(UpdateConfigDraftRequest $request, string $kind, ConfigDocument $configDocument): JsonResponse
    {
        $this->versions->saveDraft($configDocument, $request->payload(), $request->validated('name'), $request->user());

        return $this->present($configDocument, $request);
    }

    public function publish(PublishConfigRequest $request, string $kind, ConfigDocument $configDocument): JsonResponse
    {
        $this->versions->publish($configDocument, $request->kind(), $request->user());

        return $this->present($configDocument, $request);
    }

    public function rollback(RollbackConfigRequest $request, string $kind, ConfigDocument $configDocument): JsonResponse
    {
        $this->versions->rollback($configDocument, $request->kind(), (int) $request->validated('version'), $request->user());

        return $this->present($configDocument, $request);
    }

    public function copy(CopyConfigRequest $request, string $kind, ConfigDocument $configDocument): JsonResponse
    {
        $request->authorizeTarget();
        $target = $this->versions->copyTo(
            $configDocument,
            $request->kind(),
            $request->validated('scope_type'),
            $request->scopeId(),
            $request->validated('from', 'published'),
            $request->user(),
        );

        return $this->present($target, $request)->setStatusCode(201);
    }

    public function discardDraft(DiscardConfigDraftRequest $request, string $kind, ConfigDocument $configDocument): JsonResponse
    {
        $this->versions->discardDraft($configDocument, $request->user());

        return $this->present($configDocument, $request);
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
        $document = $result['document'];

        return new JsonResponse(['data' => [
            'kind' => $kind->key,
            'key' => $request->key(),
            'payload' => $result['payload'] === [] ? (object) [] : $result['payload'],
            'source' => $document === null ? null : [
                'document_id' => $document->id,
                'scope' => ['type' => $document->scope_type, 'id' => $document->scope_id],
                'version' => $result['version']->version,
                'published_at' => $result['version']->published_at?->toIso8601String(),
            ],
        ]]);
    }

    /** The document with both payloads, its history and the problems that block publishing the draft. */
    private function present(ConfigDocument $document, Request $request): JsonResponse
    {
        $document = $document->fresh(['published', 'draft', 'versions']);
        $draft = $document->draft;
        $kind = app(ConfigKinds::class)->get($document->kind);

        return new JsonResponse([
            'data' => ConfigDocumentResource::make($document)->withPayloads()->resolve($request),
            'meta' => ['problems' => $draft === null ? [] : $this->versions->problems($kind, $draft->payload)],
        ]);
    }
}
