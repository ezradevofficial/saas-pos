<?php

namespace Modules\Accounting\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Chart\ChartTemplates;
use Modules\Accounting\Http\Requests\AccountActionRequest;
use Modules\Accounting\Http\Requests\ChartTemplatesRequest;
use Modules\Accounting\Http\Requests\CreateChartRequest;
use Modules\Accounting\Http\Requests\ListAccountsRequest;
use Modules\Accounting\Http\Requests\ShowAccountRequest;
use Modules\Accounting\Http\Requests\ShowSettingsRequest;
use Modules\Accounting\Http\Requests\StoreAccountRequest;
use Modules\Accounting\Http\Requests\UpdateAccountRequest;
use Modules\Accounting\Http\Requests\UpdateSettingsRequest;
use Modules\Accounting\Http\Resources\AccountResource;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountingSettings;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\Payment;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** ACC-01, ACC-02: the chart of accounts and the accounting settings of a company. */
class AccountController
{
    public function __construct(private readonly Charts $charts) {}

    public function index(ListAccountsRequest $request, Company $company, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->filter(Account::query());
        $status = $request->status('active');

        if ($status !== 'all') {
            $status === 'archived' ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        }

        if ($request->filled('type')) {
            $query->where('type', $request->validated('type'));
        }

        $request->applySearch($query, ['code' => 'code', 'name' => 'name']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return AccountResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreAccountRequest $request, Company $company): JsonResponse
    {
        $account = new Account([...$request->validated(), 'company_id' => $company->id]);
        $this->charts->assertParent($account, $account->parent_id);

        try {
            $account->save();
        } catch (UniqueConstraintViolationException) {
            throw LedgerError::make('account_code_taken', ['code' => $account->code], 'code', 409);
        }

        return AccountResource::make($account->refresh())->response()->setStatusCode(201);
    }

    public function show(ShowAccountRequest $request, Account $accAccount): AccountResource
    {
        return AccountResource::make($accAccount);
    }

    public function update(UpdateAccountRequest $request, Account $accAccount): AccountResource
    {
        $data = $request->validated();
        $accAccount->fill($data);

        // ACC-01: an account with postings keeps what its postings were made under.
        if ($this->charts->hasPostings($accAccount) && $accAccount->isDirty(['type', 'currency', 'postable', 'is_control'])) {
            throw LedgerError::make('account_has_postings', ['code' => $accAccount->getOriginal('code')]);
        }

        if (array_key_exists('parent_id', $data)) {
            $this->charts->assertParent($accAccount, $data['parent_id']);
        }

        $accAccount->save();

        return AccountResource::make($accAccount->refresh());
    }

    public function archive(AccountActionRequest $request, Account $accAccount): AccountResource
    {
        $accAccount->archive();

        return AccountResource::make($accAccount);
    }

    public function restore(AccountActionRequest $request, Account $accAccount): AccountResource
    {
        $accAccount->restore();

        return AccountResource::make($accAccount);
    }

    /** ACC-01: the country templates (structure only), with their names in the request's language. */
    public function templates(ChartTemplatesRequest $request, ChartTemplates $templates): JsonResponse
    {
        return response()->json(['data' => array_map(fn (string $code) => [
            'code' => $code,
            'name' => __("accounting_charts.{$code}.name"),
            'accounts' => count($templates->get($code)['accounts']),
        ], $templates->available())]);
    }

    public function createChart(CreateChartRequest $request, Company $company): JsonResponse
    {
        $this->charts->fromTemplate($company, $request->validated('template'));

        return $this->settingsResponse($company, 201);
    }

    public function settings(ShowSettingsRequest $request, Company $company): JsonResponse
    {
        return $this->settingsResponse($company);
    }

    public function updateSettings(UpdateSettingsRequest $request, Company $company): JsonResponse
    {
        $this->charts->settings($company);

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($request, $company) {
            // One change at a time: the settings row is locked while the roles are checked.
            $settings = AccountingSettings::query()->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            $accounts = Account::query()->where('company_id', $company->id)->get()->keyBy('id');

            foreach ($request->validated('roles') as $index => $role) {
                $account = $role['account_id'] === null ? null : $accounts->get($role['account_id']);

                if ($account !== null && (! $account->postable || $account->isArchived())) {
                    throw LedgerError::make('account_not_postable', ['code' => $account->code], "roles.{$index}.account_id");
                }

                // ACC-02: the subledgers post to control accounts of their side, and their open items stay where they were booked.
                $side = ['receivable' => 'asset', 'payable' => 'liability'][$role['role']] ?? null;

                if ($side !== null && $account !== null && (! $account->is_control || $account->type !== $side)) {
                    throw LedgerError::make('role_needs_control', ['code' => $account->code], "roles.{$index}.account_id");
                }

                if ($side !== null && $settings->accountFor($role['role']) !== null && $settings->accountFor($role['role']) !== $account?->id && $this->hasOpenItems($company, $role['role'])) {
                    throw LedgerError::make('role_has_open_items', field: "roles.{$index}.account_id");
                }

                $settings->setAttribute("{$role['role']}_account_id", $account?->id);
            }

            $settings->save();
        });

        return $this->settingsResponse($company);
    }

    /** Open invoices, credit notes or unallocated receipts and payments of the role's side. */
    private function hasOpenItems(Company $company, string $role): bool
    {
        $customer = $role === 'receivable';

        return Document::query()->where('company_id', $company->id)->where('status', Document::POSTED)->where('open_minor', '>', 0)
            ->whereIn('kind', $customer ? ['customer_invoice', 'customer_credit'] : ['supplier_invoice', 'supplier_credit'])->exists()
            || Payment::query()->where('company_id', $company->id)->where('kind', $customer ? 'receipt' : 'payment')->where('unallocated_minor', '>', 0)->exists();
    }

    private function settingsResponse(Company $company, int $status = 200): JsonResponse
    {
        $settings = AccountingSettings::query()->where('company_id', $company->id)->first();
        $accounts = Account::query()->where('company_id', $company->id)->count();

        return response()->json(['data' => [
            'company_id' => $company->id,
            'base_currency' => $company->base_currency,
            'template' => $settings?->template,
            'has_chart' => $accounts > 0,
            'roles' => array_map(fn (string $role) => ['role' => $role, 'account_id' => $settings?->accountFor($role)], AccountingSettings::ROLES),
        ]], $status);
    }
}
