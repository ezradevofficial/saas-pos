<?php

namespace Modules\HR\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\HR\Http\Requests\AppraisalCycleRequest;
use Modules\HR\Http\Requests\AppraisalRequest;
use Modules\HR\Http\Requests\ChecklistItemRequest;
use Modules\HR\Http\Requests\ChecklistTemplateRequest;
use Modules\HR\Http\Requests\DisciplinaryRequest;
use Modules\HR\Http\Requests\ListAppraisalCyclesRequest;
use Modules\HR\Http\Requests\ListAppraisalsRequest;
use Modules\HR\Http\Requests\ListChecklistsRequest;
use Modules\HR\Http\Requests\ListChecklistTemplatesRequest;
use Modules\HR\Http\Requests\ListDisciplinaryRequest;
use Modules\HR\Http\Requests\StartChecklistRequest;
use Modules\HR\Http\Requests\StoreAppraisalCycleRequest;
use Modules\HR\Http\Requests\StoreChecklistTemplateRequest;
use Modules\HR\Http\Requests\StoreDisciplinaryRequest;
use Modules\HR\Http\Resources\RecordResource;
use Modules\HR\Http\Resources\SetupResource;
use Modules\HR\Models\Appraisal;
use Modules\HR\Models\AppraisalCycle;
use Modules\HR\Models\Checklist;
use Modules\HR\Models\ChecklistItem;
use Modules\HR\Models\ChecklistTemplate;
use Modules\HR\Models\DisciplinaryCase;
use Modules\HR\Models\Employee;
use Modules\HR\Records\Appraisals;
use Modules\HR\Records\Checklists;
use Modules\HR\Records\DisciplinaryCases;
use Modules\HR\Support\Sensitive;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** HR-28 to HR-30: checklists and their templates, disciplinary cases, appraisal cycles and appraisals. */
class RecordsController
{
    public function __construct(
        private readonly Checklists $checklists,
        private readonly DisciplinaryCases $cases,
        private readonly Appraisals $appraisals,
        private readonly Sensitive $sensitive,
    ) {}

    public function templates(ListChecklistTemplatesRequest $request): AnonymousResourceCollection
    {
        return SetupResource::collection($request->applyFilters(ChecklistTemplate::query(), ['name'])->orderBy('kind')->orderBy('name')->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    public function storeTemplate(StoreChecklistTemplateRequest $request): JsonResponse
    {
        return SetupResource::make($this->checklists->createTemplate($request->validated())->refresh())->response()->setStatusCode(201);
    }

    public function updateTemplate(ChecklistTemplateRequest $request, ChecklistTemplate $hrChecklistTemplate): SetupResource
    {
        return SetupResource::make($this->checklists->updateTemplate($hrChecklistTemplate, $request->validated()));
    }

    public function archiveTemplate(ChecklistTemplateRequest $request, ChecklistTemplate $hrChecklistTemplate): SetupResource
    {
        if (! $hrChecklistTemplate->isArchived()) {
            $hrChecklistTemplate->fill(['is_default' => false])->archive();
        }

        return SetupResource::make($hrChecklistTemplate->refresh());
    }

    public function checklists(ListChecklistsRequest $request): AnonymousResourceCollection
    {
        $query = Checklist::query()->with(['employee', 'items']);
        $request->applyFilters($query, viaEmployee: true);

        if ($request->filled('kind')) {
            $query->where('kind', $request->validated('kind'));
        }

        return RecordResource::collection($query->orderByDesc('created_at')->orderByDesc('id')->paginate($request->perPage())->withQueryString());
    }

    public function startChecklist(StartChecklistRequest $request, Employee $hrEmployee): JsonResponse
    {
        $checklist = $this->checklists->start($hrEmployee, ChecklistTemplate::query()->findOrFail($request->validated('template_id')), $request->user());

        return RecordResource::make($checklist->load(['employee', 'items']))->response()->setStatusCode(201);
    }

    public function completeItem(ChecklistItemRequest $request, ChecklistItem $hrChecklistItem): RecordResource
    {
        $item = $this->checklists->complete($hrChecklistItem, $request->user(), $request->validated('note'));

        return RecordResource::make(Checklist::query()->with(['employee', 'items'])->findOrFail($item->checklist_id));
    }

    public function reopenItem(ChecklistItemRequest $request, ChecklistItem $hrChecklistItem): RecordResource
    {
        $item = $this->checklists->reopen($hrChecklistItem);

        return RecordResource::make(Checklist::query()->with(['employee', 'items'])->findOrFail($item->checklist_id));
    }

    public function cases(ListDisciplinaryRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = DisciplinaryCase::query()->with('employee');
        $request->applyFilters($query, dateColumn: 'incident_date', viaEmployee: true);
        $request->applySearch($query, ['number' => 'number']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return RecordResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function storeCase(StoreDisciplinaryRequest $request): JsonResponse
    {
        $case = $this->cases->open($request->bodyEmployee(), $request->validated(), $request->user());

        return RecordResource::make($case->load('employee'))->response()->setStatusCode(201);
    }

    public function showCase(DisciplinaryRequest $request, DisciplinaryCase $hrDisciplinaryCase): RecordResource
    {
        $this->sensitive->recordView($request->employee(), [Sensitive::DISCIPLINARY], 'disciplinary_case');

        return RecordResource::make($hrDisciplinaryCase->load('employee'));
    }

    public function updateCase(DisciplinaryRequest $request, DisciplinaryCase $hrDisciplinaryCase): RecordResource
    {
        return RecordResource::make($this->cases->update($hrDisciplinaryCase, $request->validated())->load('employee'));
    }

    public function closeCase(DisciplinaryRequest $request, DisciplinaryCase $hrDisciplinaryCase): RecordResource
    {
        return RecordResource::make($this->cases->close($hrDisciplinaryCase, (string) $request->validated('outcome'), $request->user())->load('employee'));
    }

    public function cycles(ListAppraisalCyclesRequest $request): AnonymousResourceCollection
    {
        return SetupResource::collection($request->applyFilters(AppraisalCycle::query(), ['name'])->orderByDesc('period_start')->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    public function storeCycle(StoreAppraisalCycleRequest $request): JsonResponse
    {
        $cycle = AppraisalCycle::create([...$request->safe()->only(['company_id', 'name', 'period_start', 'period_end']), 'status' => 'draft']);

        return SetupResource::make($cycle->refresh())->response()->setStatusCode(201);
    }

    public function updateCycle(AppraisalCycleRequest $request, AppraisalCycle $hrAppraisalCycle): SetupResource
    {
        $hrAppraisalCycle->fill($request->validated())->save();

        return SetupResource::make($hrAppraisalCycle->refresh());
    }

    public function openCycle(AppraisalCycleRequest $request, AppraisalCycle $hrAppraisalCycle): SetupResource
    {
        return SetupResource::make($this->appraisals->open($hrAppraisalCycle));
    }

    public function closeCycle(AppraisalCycleRequest $request, AppraisalCycle $hrAppraisalCycle): SetupResource
    {
        return SetupResource::make($this->appraisals->close($hrAppraisalCycle));
    }

    public function appraisals(ListAppraisalsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Appraisal::query()->with(['employee', 'cycle', 'reviewer']);
        $request->applyFilters($query, viaEmployee: true);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return RecordResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function showAppraisal(AppraisalRequest $request, Appraisal $hrAppraisal): RecordResource
    {
        return RecordResource::make($hrAppraisal->load(['employee', 'cycle', 'reviewer']));
    }

    public function updateAppraisal(AppraisalRequest $request, Appraisal $hrAppraisal): RecordResource
    {
        return RecordResource::make($this->appraisals->review($hrAppraisal, $request->validated())->load(['employee', 'cycle', 'reviewer']));
    }

    public function completeAppraisal(AppraisalRequest $request, Appraisal $hrAppraisal): RecordResource
    {
        return RecordResource::make($this->appraisals->complete($hrAppraisal)->load(['employee', 'cycle', 'reviewer']));
    }
}
