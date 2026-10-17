<?php

namespace App\Core\Privacy\Http\Controllers;

use App\Core\Privacy\Http\Requests\ApprovePrivacyRequest;
use App\Core\Privacy\Http\Requests\ManagePrivacyRequest;
use App\Core\Privacy\Http\Requests\PrivacyRequestAction;
use App\Core\Privacy\Http\Requests\RejectPrivacyRequest;
use App\Core\Privacy\Http\Requests\SearchSubjectsRequest;
use App\Core\Privacy\Http\Requests\StorePrivacyRequest;
use App\Core\Privacy\Models\PrivacyRequest;
use App\Core\Privacy\PrivacyRequests;
use App\Core\Privacy\SubjectData;
use Illuminate\Http\JsonResponse;

/**
 * NFR-08: Settings → Privacy, data subject requests (docs/privacy.md).
 * Requests are found under row-level security (another tenant's id is 404).
 */
class PrivacyRequestController
{
    public function __construct(private readonly PrivacyRequests $requests) {}

    public function index(PrivacyRequestAction $request): JsonResponse
    {
        $status = $request->query('status');
        $items = PrivacyRequest::query()->with(['requester', 'approver'])
            ->whereNull('archived_at')
            ->when(is_string($status) && in_array($status, ['open', PrivacyRequest::RECEIVED, PrivacyRequest::APPROVED, PrivacyRequest::COMPLETED, PrivacyRequest::REJECTED], true), function ($q) use ($status) {
                $status === 'open' ? $q->whereIn('status', [PrivacyRequest::RECEIVED, PrivacyRequest::APPROVED]) : $q->where('status', $status);
            })
            ->orderBy('due_at')->orderBy('id')->limit(200)->get();

        return response()->json([
            'data' => $items->map(fn (PrivacyRequest $r) => $this->requests->present($r, $request->user()))->values(),
            'meta' => ['subject_types' => $this->requests->subjects()->types(), 'correctable' => $this->correctable()],
        ]);
    }

    public function show(PrivacyRequestAction $request, PrivacyRequest $privacyRequest): JsonResponse
    {
        $privacyRequest->load(['requester', 'approver']);

        return response()->json(['data' => $this->requests->present($privacyRequest, $request->user())]);
    }

    public function store(StorePrivacyRequest $request): JsonResponse
    {
        $created = $this->requests->create(
            $request->user(),
            (string) $request->validated('subject_type'),
            (string) $request->validated('subject_id'),
            (string) $request->validated('kind'),
            $request->validated('note'),
            $request->validated('corrections'),
        );
        $created->load(['requester', 'approver']);

        return response()->json(['data' => $this->requests->present($created, $request->user())], 201);
    }

    public function approve(ApprovePrivacyRequest $request, PrivacyRequest $privacyRequest): JsonResponse
    {
        $this->requests->approve($privacyRequest, $request->user())->load(['requester', 'approver']);

        return response()->json(['data' => $this->requests->present($privacyRequest, $request->user())]);
    }

    public function reject(RejectPrivacyRequest $request, PrivacyRequest $privacyRequest): JsonResponse
    {
        $this->requests->reject($privacyRequest, $request->user(), (string) $request->validated('reason'))->load(['requester', 'approver']);

        return response()->json(['data' => $this->requests->present($privacyRequest, $request->user())]);
    }

    public function execute(ManagePrivacyRequest $request, PrivacyRequest $privacyRequest): JsonResponse
    {
        $this->requests->execute($privacyRequest, $request->user())->load(['requester', 'approver']);

        return response()->json(['data' => $this->requests->present($privacyRequest, $request->user())]);
    }

    /** The access report as a JSON download, built when asked (never stored), audited. */
    public function report(ManagePrivacyRequest $request, PrivacyRequest $privacyRequest): JsonResponse
    {
        $report = $this->requests->report($privacyRequest, $request->user());

        return response()->json($report, 200, [
            'Content-Disposition' => sprintf('attachment; filename="access-report-%s.json"', $privacyRequest->id),
            'Cache-Control' => 'private, no-store',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function subjects(SearchSubjectsRequest $request, SubjectData $data): JsonResponse
    {
        return response()->json(['data' => $data->search((string) $request->validated('type'), (string) ($request->validated('search') ?? ''))]);
    }

    /** @return array<string, list<string>> correctable fields per subject type */
    private function correctable(): array
    {
        $out = [];
        foreach ($this->requests->subjects()->types() as $type) {
            $out[$type] = $this->requests->subjects()->primary($type)->correctableFields();
        }

        return $out;
    }
}
