<?php

namespace Modules\Loyalty\Credit;

use App\Core\Audit\Auditor;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\Money;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\LimitRules;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Ledgers\Ledgers;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\CreditEntry;
use Modules\Loyalty\Notifications\LoyaltyNotices;
use Modules\Loyalty\Programmes;
use Modules\Loyalty\Support\LoyaltyError;

/**
 * LOY-14..LOY-16: customer credit accounts. The limit is the core party's
 * credit limit (MD-01; changed through the core credit-limit flow, WF-01),
 * read here and never stored. A party without a limit has no bound (core
 * rule); only customers with an open account buy on credit.
 */
class CreditAccounts
{
    public function __construct(
        private readonly Ledgers $ledgers,
        private readonly Auditor $auditor,
        private readonly LimitRules $limits,
        private readonly ScopeResolver $resolver,
        private readonly CurrencyDecimals $decimals,
        private readonly LoyaltyNotices $notices,
        private readonly Programmes $programmes,
    ) {}

    /** LOY-14: open (or reopen) the customer's account at the company, in the limit's currency else the base. */
    public function open(Party $party, Company $company, User $by): Account
    {
        return $this->transaction(function () use ($party, $company, $by) {
            $existing = $this->ledgers->lock($company->id, $party->id, Account::CREDIT);

            if ($existing?->isOpen()) {
                throw LoyaltyError::make('credit_account_exists', 'party_id');
            }

            if ($existing !== null) {
                $existing->forceFill(['status' => Account::OPEN, 'closed_at' => null, 'closed_by' => null])->save();
                $this->auditor->record('loyalty.credit_account.reopen', $existing, ['status' => Account::CLOSED], ['status' => Account::OPEN], ['user_id' => $by->id]);

                return $existing->refresh();
            }

            $account = Account::create([
                'company_id' => $company->id,
                'party_id' => $party->id,
                'kind' => Account::CREDIT,
                'currency' => $party->creditLimit()?->currency() ?? $company->base_currency,
                'balance' => 0,
                'status' => Account::OPEN,
                'opened_at' => CarbonImmutable::now(),
                'opened_by' => $by->id,
            ]);
            $this->auditor->record('loyalty.credit_account.open', $account, null, [
                'party_id' => $party->id, 'company_id' => $company->id, 'currency' => $account->currency,
            ], ['user_id' => $by->id]);

            return $account->refresh();
        });
    }

    /** Close an account whose balance is zero. */
    public function close(Account $account, User $by): Account
    {
        return $this->transaction(function () use ($account, $by) {
            $account = $this->ledgers->lockAccount($account->id);

            if (! $account->isOpen()) {
                throw LoyaltyError::make('credit_account_closed');
            }

            if ((string) $account->balance !== '0') {
                throw LoyaltyError::make('credit_balance_not_zero');
            }

            $account->forceFill(['status' => Account::CLOSED, 'closed_at' => CarbonImmutable::now(), 'closed_by' => $by->id])->save();
            $this->auditor->record('loyalty.credit_account.close', $account, ['status' => Account::OPEN], ['status' => Account::CLOSED], ['user_id' => $by->id]);

            return $account;
        });
    }

    /**
     * LOY-15: a charge from the back office. Refused above the limit
     * (`credit_limit_exceeded`) unless the user's RBAC-06
     * `credit_limit_override` (major units of the account's currency)
     * covers the excess; an Owner has no limit.
     */
    public function charge(Account $account, string $amountMinor, ?string $reference, ?string $note, User $by, ?string $key = null): CreditEntry
    {
        return $this->transaction(function () use ($account, $amountMinor, $reference, $note, $by, $key) {
            $account = $this->openLocked($account);

            // An idempotency key sent again answers the charge it made, unchanged.
            $existing = $key === null ? null : CreditEntry::query()->where('account_id', $account->id)->where('kind', 'charge')->where('source_type', 'manual')->where('source_id', $key)->first();

            if ($existing !== null) {
                return $existing;
            }

            $after = BigInteger::of((string) $account->balance)->plus($amountMinor);
            $excess = $this->excess($account, $after);

            if ($excess !== null && $excess->isPositive() && ! $this->mayExceed($by, $account, $excess)) {
                throw LoyaltyError::make('credit_limit_exceeded', 'amount_minor', ['limit' => $this->limitText($account)]);
            }

            $entry = $this->ledgers->credit($account, 'charge', $amountMinor, [
                'source_type' => 'manual', 'source_id' => $key, 'reference' => $reference, 'note' => $note, 'created_by' => $by->id,
                'flags' => $excess !== null && $excess->isPositive() ? [['code' => 'credit_limit_overridden', 'detail' => ['excess_minor' => (string) $excess]]] : [],
            ]);
            $this->audit('loyalty.credit.charge', $account, $entry, $by);
            $this->notices->creditLevel($account, $this->programmes->for($account->company_id));

            return $entry;
        });
    }

    /** LOY-16: money received from the customer (reduces what they owe). */
    public function payment(Account $account, string $amountMinor, ?string $reference, ?string $note, ?CarbonImmutable $at, User $by): CreditEntry
    {
        return $this->transaction(function () use ($account, $amountMinor, $reference, $note, $at, $by) {
            $account = $this->openLocked($account);
            $entry = $this->ledgers->credit($account, 'payment', (string) BigInteger::of($amountMinor)->negated(), [
                'source_type' => 'manual', 'reference' => $reference, 'note' => $note, 'created_by' => $by->id, 'occurred_at' => $at,
            ]);
            $this->audit('loyalty.credit.payment', $account, $entry, $by);

            return $entry;
        });
    }

    /** LOY-16: an adjustment with a reason (signed: positive adds to what is owed). */
    public function adjust(Account $account, string $amountMinor, string $reason, User $by): CreditEntry
    {
        return $this->transaction(function () use ($account, $amountMinor, $reason, $by) {
            $account = $this->ledgers->lockAccount($account->id);
            $entry = $this->ledgers->credit($account, 'adjust', $amountMinor, ['source_type' => 'manual', 'note' => $reason, 'created_by' => $by->id]);
            $this->audit('loyalty.credit.adjust', $account, $entry, $by);

            return $entry;
        });
    }

    /** What a balance would exceed the limit by (null: no limit in the account's currency). */
    public function excess(Account $account, BigInteger $balance): ?BigInteger
    {
        $limit = Party::query()->whereKey($account->party_id)->first()?->creditLimit();

        if ($limit === null || $limit->currency() !== $account->currency) {
            return null;
        }

        return $balance->minus($limit->minor());
    }

    private function mayExceed(User $user, Account $account, BigInteger $excess): bool
    {
        // M7: RBAC-06 limits resolve at the account's company (a role there or above it).
        $scope = Scope::company($account->company_id);
        $roles = $this->resolver->roleIds($user, $scope);

        if ($roles !== [] && Role::query()->whereKey($roles)->where('is_owner', true)->exists()) {
            return true;
        }

        $max = $this->limits->max($user, 'credit_limit_override', $scope);

        return $max !== null && BigDecimal::ofUnscaledValue((string) $excess, $this->decimals->for($account->currency))->isLessThanOrEqualTo($max);
    }

    private function limitText(Account $account): string
    {
        $limit = Party::query()->whereKey($account->party_id)->first()?->creditLimit();

        return $limit === null ? '' : $limit->currency().' '.Money::ofMinor($limit->minor(), $limit->currency())->toDecimalString();
    }

    private function openLocked(Account $account): Account
    {
        $account = $this->ledgers->lockAccount($account->id);

        if (! $account->isOpen()) {
            throw LoyaltyError::make('credit_account_closed');
        }

        return $account;
    }

    private function audit(string $action, Account $account, ?CreditEntry $entry, User $by): void
    {
        $this->auditor->record($action, $account, null, [
            'entry_id' => $entry?->id,
            'amount' => $entry === null ? null : ['amount_minor' => (string) $entry->amount_minor, 'currency' => $entry->currency],
            'reference' => $entry?->reference,
            'note' => $entry?->note,
            'balance' => ['amount_minor' => (string) $account->balance, 'currency' => $account->currency],
        ], ['user_id' => $by->id]);
    }

    private function transaction(\Closure $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }
}
