<?php

namespace App\Core\CustomForms\Http\Controllers;

use App\Core\CustomFields\CustomFieldLists;
use App\Core\CustomForms\CustomFormAccess;
use App\Core\CustomForms\CustomFormRecord;
use App\Core\CustomForms\CustomForms;
use App\Core\CustomForms\CustomFormType;
use App\Core\CustomForms\Http\Requests\ArchiveCustomFormRecordRequest;
use App\Core\CustomForms\Http\Requests\CancelCustomFormRecordRequest;
use App\Core\CustomForms\Http\Requests\CustomFormRecordRequest;
use App\Core\CustomForms\Http\Requests\ListCustomFormRecordsRequest;
use App\Core\CustomForms\Http\Requests\StoreCustomFormRecordRequest;
use App\Core\CustomForms\Http\Requests\SubmitCustomFormRecordRequest;
use App\Core\CustomForms\Http\Requests\UpdateCustomFormRecordRequest;
use App\Core\CustomForms\Http\Resources\CustomFormRecordResource;
use App\Core\Exports\ListExport;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CF-04, CF-05: custom form records: the list (search, filters, sort,
 * export), create, change a draft, submit (to its flow), cancel, archive
 * and restore. The detail carries the record's lines, attachments, its
 * flow (WF-10) and, while an approval waits, the approval's id (APR-04).
 */
class CustomFormRecordController
{
    private const RELATIONS = ['type', 'company', 'branch', 'location', 'creator'];

    public function __construct(
        private readonly CustomForms $forms,
        private readonly WorkflowEngine $engine,
    ) {}

    public function index(ListCustomFormRecordsRequest $request, CustomFormType $customFormType, CustomFormAccess $access, CustomFieldLists $lists, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $access->visible(CustomFormRecord::query()->with(self::RELATIONS), $request->user(), $customFormType);

        match ($request->validated('status')) {
            null => $query->whereNull('archived_at'),
            'all' => null,
            'archived' => $query->whereNotNull('archived_at'),
            default => $query->whereNull('archived_at')->where('status', $request->validated('status')),
        };

        foreach (['company' => 'company_id', 'branch' => 'branch_id', 'location' => 'location_id'] as $filter => $column) {
            if ($request->filled($filter)) {
                $query->where($column, $request->validated($filter));
            }
        }

        if (($search = trim((string) $request->validated('search', ''))) !== '') {
            $query->where('number', 'ilike', '%'.addcslashes($search, '\\%_').'%');
        }

        $lists->apply($query, $customFormType->entity(), $request->validated('custom'));
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return CustomFormRecordResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreCustomFormRecordRequest $request, CustomFormType $customFormType): JsonResponse
    {
        return $this->respond($request, $this->forms->create($customFormType, $request->recordData(), $request->user()), 201);
    }

    public function show(CustomFormRecordRequest $request): JsonResponse
    {
        return $this->respond($request, $request->record());
    }

    public function update(UpdateCustomFormRecordRequest $request): JsonResponse
    {
        return $this->respond($request, $this->forms->update($request->record(), $request->recordData(), $request->user()));
    }

    public function submit(SubmitCustomFormRecordRequest $request): JsonResponse
    {
        return $this->respond($request, $this->forms->submit($request->record(), $request->user()));
    }

    public function cancel(CancelCustomFormRecordRequest $request): JsonResponse
    {
        return $this->respond($request, $this->forms->cancel($request->record(), $request->user(), (string) $request->validated('reason')));
    }

    public function archive(ArchiveCustomFormRecordRequest $request): JsonResponse
    {
        $record = $request->record();

        if (! $record->isArchived()) {
            $record->archive();
        }

        return $this->respond($request, $record);
    }

    public function restore(ArchiveCustomFormRecordRequest $request): JsonResponse
    {
        $record = $request->record();

        if ($record->isArchived()) {
            $record->restore();
        }

        return $this->respond($request, $record);
    }

    /** The record with its lines, attachments and flow (WF-10). */
    private function respond(Request $request, CustomFormRecord $record, int $status = 200): JsonResponse
    {
        $record = $record->fresh([...self::RELATIONS, 'lines', 'attachmentFiles']);
        $workflow = $record->type->workflow ? $this->engine->current($record->type->documentType(), $record->id) : null;

        return CustomFormRecordResource::make($record)->withDetail()->additional(['meta' => [
            'workflow' => $workflow === null ? null : $this->engine->status($workflow, $request->user()),
        ]])->response()->setStatusCode($status);
    }
}
