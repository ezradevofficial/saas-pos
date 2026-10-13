<?php

namespace Modules\Accounting\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Accounting\Http\Requests\CancelJournalRequest;
use Modules\Accounting\Http\Requests\ListJournalsRequest;
use Modules\Accounting\Http\Requests\ListRecurringJournalsRequest;
use Modules\Accounting\Http\Requests\PostJournalRequest;
use Modules\Accounting\Http\Requests\RecurringJournalActionRequest;
use Modules\Accounting\Http\Requests\ReverseJournalRequest;
use Modules\Accounting\Http\Requests\ShowJournalRequest;
use Modules\Accounting\Http\Requests\StoreJournalRequest;
use Modules\Accounting\Http\Requests\StoreRecurringJournalRequest;
use Modules\Accounting\Http\Requests\SubmitJournalRequest;
use Modules\Accounting\Http\Requests\UpdateJournalRequest;
use Modules\Accounting\Http\Requests\UpdateRecurringJournalRequest;
use Modules\Accounting\Http\Resources\JournalResource;
use Modules\Accounting\Http\Resources\RecordResource;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Ledger\LineParser;
use Modules\Accounting\Ledger\ManualJournals;
use Modules\Accounting\Ledger\Reversals;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\RecurringJournal;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** ACC-03 to ACC-06: journals, manual journals through their flow, reversals and recurring journals. */
class JournalController
{
    public function __construct(private readonly ManualJournals $journals) {}

    public function index(ListJournalsRequest $request, Company $company, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->filter(Journal::query(), 'date');

        if ($request->status() !== 'all') {
            $query->where('status', $request->status());
        }

        if ($request->filled('kind')) {
            $query->where('kind', $request->validated('kind'));
        }

        $request->applySearch($query, ['number' => 'number', 'reference' => 'reference', 'memo' => 'memo']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return JournalResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreJournalRequest $request, Company $company): JsonResponse
    {
        $journal = $this->journals->create($company, $request->validated(), $request->user());

        return $this->detail($journal)->response()->setStatusCode(201);
    }

    public function show(ShowJournalRequest $request, Journal $accJournal): JournalResource
    {
        return $this->detail($accJournal);
    }

    public function update(UpdateJournalRequest $request, Journal $accJournal): JournalResource
    {
        $this->assertManual($accJournal);

        return $this->detail($this->journals->update($accJournal, $request->validated()));
    }

    public function submit(SubmitJournalRequest $request, Journal $accJournal): JournalResource
    {
        $this->assertManual($accJournal);

        return $this->detail($this->journals->submit($accJournal, $request->user()));
    }

    public function cancel(CancelJournalRequest $request, Journal $accJournal): JournalResource
    {
        $this->assertManual($accJournal);

        return $this->detail($this->journals->cancel($accJournal, $request->user(), $request->validated('reason')));
    }

    public function post(PostJournalRequest $request, Journal $accJournal): JournalResource
    {
        return $this->detail($this->journals->postApproved($accJournal, $request->user()));
    }

    public function reverse(ReverseJournalRequest $request, Journal $accJournal, Reversals $reversals): JsonResponse
    {
        $date = $request->validated('date');
        $reversal = $reversals->reverse($accJournal, $date === null ? null : CarbonImmutable::parse($date), $request->user(), $request->validated('memo'));

        return $this->detail($reversal)->response()->setStatusCode(201);
    }

    public function recurring(ListRecurringJournalsRequest $request, Company $company): AnonymousResourceCollection
    {
        $status = (string) $request->validated('status', 'all');
        $query = RecurringJournal::query()->where('company_id', $company->id)->whereNull('archived_at')->orderBy('next_run_on');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return RecordResource::collection($query->get());
    }

    public function storeRecurring(StoreRecurringJournalRequest $request, Company $company, LineParser $parser): JsonResponse
    {
        $data = $request->validated();
        $data['currency'] ??= $company->base_currency;
        // The lines are checked now as a journal would be (accounts, sides, dimensions, Pro for a foreign currency).
        $parser->parse($company, array_map(fn (array $line) => [...$line, 'currency' => $data['currency']], $data['lines']));

        $recurring = RecurringJournal::query()->create([
            ...array_intersect_key($data, array_flip(['name', 'frequency', 'next_run_on', 'ends_on', 'memo', 'currency'])),
            'lines' => array_values($data['lines']),
            'company_id' => $company->id,
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);

        return RecordResource::make($recurring)->response()->setStatusCode(201);
    }

    public function updateRecurring(UpdateRecurringJournalRequest $request, RecurringJournal $accRecurringJournal, LineParser $parser): RecordResource
    {
        $data = $request->validated();

        if (isset($data['lines'])) {
            $parser->parse($accRecurringJournal->company, array_map(fn (array $line) => [...$line, 'currency' => $accRecurringJournal->currency], $data['lines']));
            $data['lines'] = array_values($data['lines']);
        }

        $accRecurringJournal->fill($data)->save();

        return RecordResource::make($accRecurringJournal);
    }

    public function archiveRecurring(RecurringJournalActionRequest $request, RecurringJournal $accRecurringJournal): RecordResource
    {
        $accRecurringJournal->archive();

        return RecordResource::make($accRecurringJournal);
    }

    private function detail(Journal $journal): JournalResource
    {
        return JournalResource::make($journal->load(['lines.account', 'lines.party', 'lines.costCentre', 'lines.department', 'lines.project']))->detail();
    }

    private function assertManual(Journal $journal): void
    {
        if (! in_array($journal->kind, Journal::EDITABLE_KINDS, true)) {
            throw LedgerError::make('journal_not_editable');
        }
    }
}
