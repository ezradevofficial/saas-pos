<?php

namespace Modules\Accounting\Http\Controllers;

use App\Core\DocumentTemplates\DocumentOutput;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Accounting\Documents\DocumentPrints;
use Modules\Accounting\Http\Requests\ConsolidationRequest;
use Modules\Accounting\Http\Requests\ReportRequest;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Ledger\Periods;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Reports\Consolidation;
use Modules\Accounting\Reports\LedgerReports;
use Modules\Accounting\Reports\Statements;

/**
 * ACC-11, ACC-17, ACC-18: financial statements, the general ledger,
 * ageing and party statements of a company, and the group's
 * consolidation. Dates default to the current fiscal year to date in the
 * company's time zone.
 */
class ReportController
{
    public function __construct(
        private readonly Statements $statements,
        private readonly LedgerReports $reports,
        private readonly Periods $periods,
    ) {}

    public function trialBalance(ReportRequest $request, Company $company): JsonResponse
    {
        [$from, $to] = $this->range($request, $company);

        return response()->json(['data' => $this->statements->trialBalance($company, $from, $to, $this->dimensions($request))]);
    }

    public function profitAndLoss(ReportRequest $request, Company $company): JsonResponse
    {
        [$from, $to] = $this->range($request, $company);

        return response()->json(['data' => $this->statements->profitAndLoss($company, $from, $to, $this->dimensions($request))]);
    }

    public function balanceSheet(ReportRequest $request, Company $company): JsonResponse
    {
        [, $to] = $this->range($request, $company);

        return response()->json(['data' => $this->statements->balanceSheet($company, $to, $this->dimensions($request))]);
    }

    public function cashFlow(ReportRequest $request, Company $company): JsonResponse
    {
        [$from, $to] = $this->range($request, $company);

        return response()->json(['data' => $this->statements->cashFlow($company, $from, $to)]);
    }

    /** GL detail of one account (`?account=`); without one, the accounts with movements in the range to pick from. */
    public function generalLedger(ReportRequest $request, Company $company): JsonResponse
    {
        [$from, $to] = $this->range($request, $company);
        $accountId = $request->validated('account');

        if ($accountId === null) {
            return response()->json(['data' => ['from' => $from, 'to' => $to, 'account' => null, 'lines' => [], 'accounts' => $this->statements->trialBalance($company, $from, $to)['rows']]]);
        }

        $account = Account::query()->where('company_id', $company->id)->findOrFail($accountId);

        return response()->json(['data' => $this->reports->generalLedger($company, $account, $from, $to, $this->dimensions($request))]);
    }

    public function ageing(ReportRequest $request, Company $company): JsonResponse
    {
        [, $to] = $this->range($request, $company);

        return response()->json(['data' => $this->reports->ageing($company, (string) $request->validated('kind', 'customer'), $to, $request->validated('party'))]);
    }

    /** ACC-11: a party's statement (`?party=`, `?kind=`, `?currency=`), as data or `?format=pdf`. */
    public function partyStatement(ReportRequest $request, Company $company, DocumentPrints $prints, DocumentOutput $output): JsonResponse|Response
    {
        [$from, $to] = $this->range($request, $company);
        $partyId = $request->validated('party');

        if ($partyId === null) {
            return response()->json(['data' => ['from' => $from, 'to' => $to, 'party' => null, 'entries' => [], 'opening' => 0, 'closing' => 0]]);
        }

        $party = Party::query()->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))->find($partyId)
            ?? throw LedgerError::make('party_unknown', field: 'party');
        $currency = (string) $request->validated('currency', $party->currency ?: $company->base_currency);
        $statement = $this->reports->partyStatement($company, $party->id, (string) $request->validated('kind', 'customer'), $currency, $from, $to);

        if ($request->validated('format') === 'pdf') {
            $document = $prints->statement($company, $party, $statement);

            return response($output->pdf($document), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="statement.pdf"']);
        }

        return response()->json(['data' => [...$statement, 'party' => ['id' => $party->id, 'name' => $party->name]]]);
    }

    public function consolidation(ConsolidationRequest $request, Consolidation $consolidation): JsonResponse
    {
        $companies = $request->companies();

        if ($companies->isEmpty()) {
            abort(403);
        }

        $first = $companies->first();
        [$from, $to] = $this->rangeOf($request->validated('from'), $request->validated('to'), $first);

        return response()->json(['data' => $consolidation->report($companies, (string) $request->validated('currency', $first->base_currency), $from, $to)]);
    }

    /** @return array{0: string, 1: string} */
    private function range(ReportRequest $request, Company $company): array
    {
        return $this->rangeOf($request->validated('from'), $request->validated('to'), $company);
    }

    /** @return array{0: string, 1: string} the range asked, else the current fiscal year to date */
    private function rangeOf(?string $from, ?string $to, Company $company): array
    {
        $today = CarbonImmutable::now($company->timezone ?: 'UTC')->startOfDay();
        $to ??= $today->toDateString();
        $from ??= $this->periods->yearStart($company, $this->periods->fiscalYearOf($company, CarbonImmutable::parse($to)))->toDateString();

        if ($from > $to) {
            throw LedgerError::make('range_invalid', field: 'from');
        }

        return [$from, $to];
    }

    /** @return array{cost_centre_id: ?string} */
    private function dimensions(ReportRequest $request): array
    {
        return ['cost_centre_id' => $request->validated('cost_centre')];
    }
}
