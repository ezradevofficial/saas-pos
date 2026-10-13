<?php

namespace Modules\Accounting\Http\Controllers;

use App\Core\Tenancy\Models\Company;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Accounting\Bank\Reconciler;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Http\Requests\AutoMatchRequest;
use Modules\Accounting\Http\Requests\BankAccountActionRequest;
use Modules\Accounting\Http\Requests\BankRuleActionRequest;
use Modules\Accounting\Http\Requests\ImportStatementRequest;
use Modules\Accounting\Http\Requests\ListBankAccountsRequest;
use Modules\Accounting\Http\Requests\ListBankRulesRequest;
use Modules\Accounting\Http\Requests\MatchLineRequest;
use Modules\Accounting\Http\Requests\PostLineRequest;
use Modules\Accounting\Http\Requests\ShowReconciliationRequest;
use Modules\Accounting\Http\Requests\StoreBankAccountRequest;
use Modules\Accounting\Http\Requests\StoreBankRuleRequest;
use Modules\Accounting\Http\Requests\UnmatchLineRequest;
use Modules\Accounting\Http\Requests\UpdateBankAccountRequest;
use Modules\Accounting\Http\Resources\RecordResource;
use Modules\Accounting\Http\Resources\Shapes;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Ledger\LineParser;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\BankAccount;
use Modules\Accounting\Models\BankRule;
use Modules\Accounting\Models\BankStatementLine;
use Modules\Accounting\Models\JournalLine;

/** ACC-12 to ACC-14: bank and mobile money accounts, statements and reconciliation. */
class BankController
{
    public function __construct(private readonly Reconciler $reconciler) {}

    public function index(ListBankAccountsRequest $request, Company $company): AnonymousResourceCollection
    {
        $status = (string) $request->validated('status', 'active');
        $query = BankAccount::query()->where('company_id', $company->id)->orderBy('name');

        if ($status !== 'all') {
            $status === 'archived' ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        }

        return RecordResource::collection($query->get());
    }

    /**
     * The GL account is the company's postable, non-control asset account,
     * in the bank's currency: a foreign-currency bank fixes its account's
     * currency (when it has no postings yet) and flags it for revaluation.
     */
    public function store(StoreBankAccountRequest $request, Company $company, Charts $charts, LineParser $parser): JsonResponse
    {
        $data = $request->validated();
        $account = Account::query()->where('company_id', $company->id)->findOrFail($data['account_id']);

        if ($account->type !== 'asset' || ! $account->postable || $account->is_control || $account->isArchived()) {
            throw LedgerError::make('bank_gl_account', field: 'account_id');
        }

        $foreign = $data['currency'] !== $company->base_currency;

        if ($foreign) {
            $parser->assertPro('currency');
        }

        // The GL account takes the bank's currency for good, so the ledger side of the
        // reconciliation is in one currency; its earlier postings must already be in it.
        if ($account->currency !== $data['currency']) {
            $others = JournalLine::query()->where('account_id', $account->id)->where('currency', '<>', $data['currency'])->exists();

            if ($account->currency !== null || $others) {
                throw LedgerError::make('account_currency', ['code' => $account->code, 'currency' => (string) ($account->currency ?? $company->base_currency)], 'account_id');
            }

            $account->fill(['currency' => $data['currency'], 'revalue' => $foreign])->save();
        }

        try {
            $bank = BankAccount::query()->create([...$data, 'company_id' => $company->id]);
        } catch (UniqueConstraintViolationException) {
            throw LedgerError::make('bank_gl_taken', field: 'account_id', status: 409);
        }

        return RecordResource::make($bank)->response()->setStatusCode(201);
    }

    public function update(UpdateBankAccountRequest $request, BankAccount $accBankAccount): RecordResource
    {
        $accBankAccount->fill($request->validated())->save();

        return RecordResource::make($accBankAccount);
    }

    public function archive(BankAccountActionRequest $request, BankAccount $accBankAccount): RecordResource
    {
        $accBankAccount->archive();

        return RecordResource::make($accBankAccount);
    }

    public function import(ImportStatementRequest $request, BankAccount $accBankAccount): JsonResponse
    {
        $statement = $this->reconciler->import($accBankAccount, $request->file('file'), $request->validated('format'), (array) $request->validated('mapping', []), $request->user());

        return response()->json(['data' => [
            'id' => $statement->id, 'format' => $statement->format, 'file_name' => $statement->file_name,
            'lines' => $statement->line_count, 'skipped' => $statement->skipped_count,
            'starts_on' => $statement->starts_on?->toDateString(), 'ends_on' => $statement->ends_on?->toDateString(),
        ]], 201);
    }

    /** ACC-14: the two panes (unmatched statement lines, unmatched ledger lines), suggestions and the summary. */
    public function reconciliation(ShowReconciliationRequest $request, BankAccount $accBankAccount): JsonResponse
    {
        $statement = BankStatementLine::query()->where('bank_account_id', $accBankAccount->id)->where('status', BankStatementLine::UNMATCHED)
            ->orderBy('date')->orderBy('created_at')->limit(500)->get();
        $ledger = $this->reconciler->unmatchedLedger($accBankAccount);

        return response()->json(['data' => [
            'bank_account' => RecordResource::make($accBankAccount),
            'summary' => array_map(fn (int $minor) => Shapes::money($minor, $accBankAccount->currency), $this->reconciler->summary($accBankAccount)),
            'statement_lines' => RecordResource::collection($statement),
            'ledger_lines' => $ledger->take(500)->map(fn (JournalLine $line) => [
                'id' => $line->id,
                'date' => $line->journal->date->toDateString(),
                'journal' => ['id' => $line->journal->id, 'number' => $line->journal->number, 'source_type' => $line->journal->source_type, 'source_id' => $line->journal->source_id],
                'reference' => $line->journal->reference,
                'description' => $line->description ?? $line->journal->memo,
                'amount' => Shapes::money($line->debit_minor - $line->credit_minor, $line->currency),
            ])->values(),
            'suggestions' => $this->reconciler->suggestions($accBankAccount, $statement, $ledger),
        ]]);
    }

    public function autoMatch(AutoMatchRequest $request, BankAccount $accBankAccount): JsonResponse
    {
        return response()->json(['data' => $this->reconciler->autoMatch($accBankAccount, $request->user())]);
    }

    public function match(MatchLineRequest $request, BankStatementLine $accBankStatementLine): RecordResource
    {
        return RecordResource::make($this->reconciler->match($accBankStatementLine, $request->validated('journal_line_ids'), $request->user()));
    }

    public function unmatch(UnmatchLineRequest $request, BankStatementLine $accBankStatementLine): RecordResource
    {
        return RecordResource::make($this->reconciler->unmatch($accBankStatementLine, $request->user()));
    }

    public function postLine(PostLineRequest $request, BankStatementLine $accBankStatementLine): RecordResource
    {
        $data = $request->validated();

        return RecordResource::make($this->reconciler->fromLine($accBankStatementLine, $data['account_id'], $data['cost_centre_id'] ?? null, $data['description'] ?? null, $request->user()));
    }

    public function rules(ListBankRulesRequest $request, Company $company): AnonymousResourceCollection
    {
        $status = (string) $request->validated('status', 'active');
        $query = BankRule::query()->where('company_id', $company->id)->orderBy('name');

        if ($status !== 'all') {
            $status === 'archived' ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        }

        return RecordResource::collection($query->get());
    }

    public function storeRule(StoreBankRuleRequest $request, Company $company): JsonResponse
    {
        $rule = BankRule::query()->create([...$request->validated(), 'company_id' => $company->id, 'created_by' => $request->user()->id]);

        return RecordResource::make($rule)->response()->setStatusCode(201);
    }

    public function archiveRule(BankRuleActionRequest $request, BankRule $accBankRule): RecordResource
    {
        $accBankRule->archive();

        return RecordResource::make($accBankRule);
    }
}
