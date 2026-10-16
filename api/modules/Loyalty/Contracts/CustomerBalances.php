<?php

namespace Modules\Loyalty\Contracts;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\ModuleRegistry;
use Modules\Loyalty\LoyaltyServiceProvider;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Support\LoyaltyAccess;

/**
 * Read contract (docs/modules/crm.md, CRM-25): a customer's points and
 * credit balances for another module's view (the CRM customer 360), so it
 * never reads `loyalty_*` tables itself. Answers only while Loyalty is
 * active, with the accounts of the companies where the reader holds
 * `loyalty.points.view` or `loyalty.credit.view` (LoyaltyAccess, RBAC-04);
 * null when they hold neither anywhere.
 */
final class CustomerBalances
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly LoyaltyAccess $access,
    ) {}

    /**
     * @return array{points: list<array{company: array{id: string, name: string}, balance: string, status: string}>, credit: list<array{company: array{id: string, name: string}, currency: string, balance_minor: string, limit_minor: ?string, status: string}>}|null
     */
    public function forCustomer(User $reader, string $partyId): ?array
    {
        if (! $this->modules->isActive(LoyaltyServiceProvider::MODULE) || ! $this->access->anywhere($reader, 'loyalty.points.view', 'loyalty.credit.view')) {
            return null;
        }

        $limit = Party::query()->find($partyId)?->creditLimit();
        $accounts = Account::query()->where('party_id', $partyId)->with('company')->orderBy('created_at')->get()
            ->filter(fn (Account $account) => $this->access->allows($reader, $account->kind === Account::POINTS ? 'loyalty.points.view' : 'loyalty.credit.view', $account->company_id));
        $company = fn (Account $account) => ['id' => $account->company_id, 'name' => (string) $account->company?->name];

        return [
            'points' => $accounts->where('kind', Account::POINTS)->map(fn (Account $account) => [
                'company' => $company($account), 'balance' => (string) $account->balance, 'status' => $account->status,
            ])->values()->all(),
            'credit' => $accounts->where('kind', Account::CREDIT)->map(fn (Account $account) => [
                'company' => $company($account), 'currency' => (string) $account->currency, 'balance_minor' => (string) $account->balance,
                'limit_minor' => $limit !== null && $limit->currency() === $account->currency ? (string) $limit->minor() : null, 'status' => $account->status,
            ])->values()->all(),
        ];
    }
}
