<?php

namespace Modules\Loyalty\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Loyalty\Credit\Ageing;
use Modules\Loyalty\Credit\CreditAccounts;
use Modules\Loyalty\Http\Lists\AccountList;
use Modules\Loyalty\Http\Requests\AccountRequest;
use Modules\Loyalty\Http\Requests\ListLoyaltyRequest;
use Modules\Loyalty\Http\Requests\RecordRequest;
use Modules\Loyalty\Http\Resources\AccountResource;
use Modules\Loyalty\Http\Resources\EntryResource;
use Modules\Loyalty\Http\Resources\StatementResource;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\CreditEntry;
use Modules\Loyalty\Models\Statement;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** LOY-14..LOY-18: customer credit accounts, their ledger, payments, charges, adjustments and the ageing report. */
class AccountController
{
    public function __construct(private readonly CreditAccounts $accounts) {}

    /** GET loyalty/accounts and GET loyalty/ageing (with `?as_of=`). */
    public function index(ListLoyaltyRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->applyFilters(Account::query()->where('kind', Account::CREDIT)->with(AccountList::RELATIONS));
        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $query->whereIn('party_id', Party::query()->where('name', 'ilike', '%'.addcslashes($search, '\\%_').'%')->select('id'));
        }

        if ($request->kind() === 'ageing') {
            // LOY-18: accounts with something open (or owed to the customer) at the date.
            AccountResource::$ageingAsOf = $request->asOf();
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('loyalty_credit_entries')->whereColumn('loyalty_credit_entries.account_id', 'loyalty_accounts.id'));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return AccountResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(AccountRequest $request): JsonResponse
    {
        $account = $this->accounts->open(
            Party::query()->findOrFail($request->validated('party_id')),
            Company::query()->findOrFail($request->validated('company_id')),
            $request->user(),
        );

        return AccountResource::make($account->load(['company', 'party']))->response()->setStatusCode(201);
    }

    /** The account with its ledger (running balance), its ageing today and its statements. */
    public function show(RecordRequest $request, Account $loyaltyAccount): JsonResponse
    {
        abort_unless($loyaltyAccount->kind === Account::CREDIT, 404);
        $loyaltyAccount->load(['company', 'party']);
        $entries = CreditEntry::query()->where('account_id', $loyaltyAccount->id)->with('creator')->orderBy('occurred_at')->orderBy('id')->get();
        $balance = BigInteger::zero();

        foreach ($entries as $entry) {
            $balance = $balance->plus((string) $entry->amount_minor);
            $entry->setAttribute('running_balance', ['amount_minor' => (string) $balance, 'currency' => $entry->currency]);
        }

        return response()->json(['data' => [
            ...AccountResource::make($loyaltyAccount)->resolve($request),
            'entries' => EntryResource::collection($entries->reverse()->values())->resolve($request),
            'ageing' => Ageing::of($entries, CarbonImmutable::now(), $loyaltyAccount->company?->timezone ?: 'UTC'),
            'statements' => StatementResource::collection(Statement::query()->where('account_id', $loyaltyAccount->id)->orderByDesc('created_at')->limit(50)->get())->resolve($request),
        ]]);
    }

    public function payment(AccountRequest $request, Account $loyaltyAccount): JsonResponse
    {
        $at = $request->filled('occurred_at') ? CarbonImmutable::parse($request->validated('occurred_at')) : null;
        $entry = $this->accounts->payment($loyaltyAccount, $request->validated('amount_minor'), $request->validated('reference'), $request->validated('note'), $at, $request->user());

        return EntryResource::make($entry)->response()->setStatusCode(201);
    }

    public function charge(AccountRequest $request, Account $loyaltyAccount): JsonResponse
    {
        $entry = $this->accounts->charge($loyaltyAccount, $request->validated('amount_minor'), $request->validated('reference'), $request->validated('note'), $request->user());

        return EntryResource::make($entry)->response()->setStatusCode(201);
    }

    public function adjust(AccountRequest $request, Account $loyaltyAccount): JsonResponse
    {
        $entry = $this->accounts->adjust($loyaltyAccount, $request->validated('amount_minor'), $request->validated('reason'), $request->user());

        return EntryResource::make($entry)->response()->setStatusCode(201);
    }

    public function close(RecordRequest $request, Account $loyaltyAccount): AccountResource
    {
        abort_unless($loyaltyAccount->kind === Account::CREDIT, 404);

        return AccountResource::make($this->accounts->close($loyaltyAccount, $request->user())->load(['company', 'party']));
    }
}
