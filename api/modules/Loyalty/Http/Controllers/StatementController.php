<?php

namespace Modules\Loyalty\Http\Controllers;

use App\Core\DocumentTemplates\DocumentOutput;
use App\Core\Tenancy\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Loyalty\Credit\Statements;
use Modules\Loyalty\Documents\StatementSource;
use Modules\Loyalty\Http\Requests\AccountRequest;
use Modules\Loyalty\Http\Requests\RecordRequest;
use Modules\Loyalty\Http\Resources\StatementResource;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\Statement;

/** LOY-17, TPL-04: statements of a credit account, stored, shown and printed as a PDF (`party.statement`). */
class StatementController
{
    public function __construct(private readonly Statements $statements) {}

    public function store(AccountRequest $request, Account $loyaltyAccount): JsonResponse
    {
        $statement = $this->statements->create($loyaltyAccount, $request->validated('from'), $request->validated('to'), $request->user());

        return $this->present($statement)->response()->setStatusCode(201);
    }

    public function show(RecordRequest $request, Statement $loyaltyStatement): StatementResource
    {
        return $this->present($loyaltyStatement);
    }

    public function pdf(RecordRequest $request, Statement $loyaltyStatement, DocumentOutput $output): Response
    {
        $document = (new StatementSource)->load($loyaltyStatement->id) ?? abort(404);

        return new Response($output->pdf($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document->fileName().'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** The statement with its lines (the ledger rows of the period, with the running balance). */
    private function present(Statement $statement): StatementResource
    {
        $statement->load('party');
        $view = Statements::view($statement->account, Company::query()->findOrFail($statement->company_id), $statement->period_from->format('Y-m-d'), $statement->period_to->format('Y-m-d'));
        $money = fn (?string $minor) => $minor === null ? null : ['amount_minor' => $minor, 'currency' => $statement->currency];
        $statement->setAttribute('lines', array_map(fn (array $line) => [
            'id' => $line['entry']->id,
            'kind' => $line['entry']->kind,
            'occurred_at' => $line['entry']->occurred_at->toIso8601String(),
            'reference' => $line['entry']->reference,
            'note' => $line['entry']->note,
            'debit' => $money($line['debit']),
            'credit' => $money($line['credit']),
            'balance' => $money($line['balance']),
        ], $view['lines']));

        return StatementResource::make($statement);
    }
}
