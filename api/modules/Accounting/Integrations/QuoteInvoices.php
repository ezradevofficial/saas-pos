<?php

namespace Modules\Accounting\Integrations;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\Money;
use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Accounting\Documents\Documents;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Models\PostingRule;
use Modules\CRM\Events\QuoteConverted;

/**
 * CRM-24 (docs/modules/crm.md decision 4): an accepted CRM quote converted
 * to `invoice` becomes a **draft** customer invoice (ACC-09), written in
 * the converting transaction (QuoteConverted is dispatched inside it), so
 * the quote and the invoice commit or roll back together. The accountant
 * reviews and posts it: numbering, the journal and the fiscal submission
 * (ACC-19) stay here.
 *
 * - The converting user must hold `accounting.invoice.create` at the
 *   company (403 otherwise).
 * - Each line's account is the `revenue` line of the company's posting rule
 *   `crm.quote_invoice` (ACC-07, seeded from the country chart); without
 *   it the conversion is refused (`quote_revenue_missing`).
 * - Lines map one to one. A discounted line, or one whose quantity has
 *   more than 4 decimals (Accounting keeps 4), is written as quantity 1 at
 *   its amount (Accounting's lines have no discount column),
 *   its quantity, price and discount in the description, so its amount and
 *   tax are the quote's. Tax is computed by Documents at the invoice's
 *   issue date (the conversion day); an invoice whose net or tax differs
 *   from the quote's is refused (`quote_invoice_mismatch`), never written.
 * - The invoice is dated the conversion day, in the quote's currency and
 *   tax treatment, at the quote's branch, for its customer.
 */
class QuoteInvoices
{
    public const EVENT_TYPE = 'crm.quote_invoice';

    public function __construct(
        private readonly Documents $documents,
        private readonly ModuleRegistry $modules,
        private readonly CurrencyDecimals $decimals,
    ) {}

    public function handle(QuoteConverted $event): void
    {
        if ($event->target !== 'invoice' || $event->result !== null || ! $this->modules->isActive(AccountingServiceProvider::MODULE)) {
            return;
        }

        $company = Company::query()->findOrFail($event->companyId);
        $user = User::query()->findOrFail($event->userId);

        if (! $user->can('accounting.invoice.create', Scope::company($company->id))) {
            throw new AuthorizationException(__('accounting.errors.quote_invoice_forbidden'));
        }

        $rule = PostingRule::query()->where('company_id', $company->id)->where('event_type', self::EVENT_TYPE)->whereNull('archived_at')->first();
        $account = collect($rule?->lines ?? [])->firstWhere('field', 'revenue')['account_id'] ?? null;

        if ($account === null) {
            throw LedgerError::make('quote_revenue_missing', field: 'target');
        }

        $major = fn (string $minor) => Money::ofMinor($minor, $event->currency)->toDecimalString($this->decimals);
        $lines = array_map(function (array $line) use ($account, $major) {
            // A discount, or a quantity Accounting's 4 decimals cannot hold: one unit at the line's amount.
            $discounted = $line['discount_minor'] !== '0' || self::finerThanFour($line['qty']);

            return [
                'description' => mb_substr($discounted
                    ? __('accounting.integrations.quote_line_discounted', [
                        'description' => $line['description'], 'qty' => self::decimal($line['qty']), 'price' => $major($line['unit_price_minor']), 'percent' => self::decimal($line['discount_percent']),
                    ])
                    : $line['description'], 0, 255),
                'item_id' => $line['item_id'],
                'account_id' => $account,
                'qty' => $discounted ? '1' : $line['qty'],
                'unit_price' => $major($discounted ? $line['amount_minor'] : $line['unit_price_minor']),
                'tax_code_id' => $line['tax_code_id'],
            ];
        }, $event->lines);

        $document = $this->documents->create($company, [
            'kind' => 'customer_invoice',
            'party_id' => $event->partyId,
            'branch_id' => $event->branchId,
            'currency' => $event->currency,
            'tax_inclusive' => $event->taxInclusive,
            'issue_date' => $event->date,
            'memo' => __('accounting.integrations.crm_quote', ['number' => $event->number]),
            'lines' => $lines,
        ], $user);

        // The invoice says what the quote said: its net and tax equal the quote's, or nothing is written
        // (a rate changed since the quote date: a new quote is made at today's rate).
        $quoteTax = array_sum(array_map(fn (array $line) => (int) $line['tax_minor'], $event->lines));
        $quoteNet = array_sum(array_map(fn (array $line) => (int) $line['total_minor'] - (int) $line['tax_minor'], $event->lines));

        if ($document->tax_minor !== $quoteTax || $document->subtotal_minor !== $quoteNet) {
            throw LedgerError::make('quote_invoice_mismatch', field: 'target');
        }

        $event->result = ['type' => 'accounting.invoice', 'id' => $document->id, 'label' => null];
    }

    /** Whether a decimal quantity has a non-zero digit past the 4th decimal ("1.23456"). */
    private static function finerThanFour(string $qty): bool
    {
        $fraction = str_contains($qty, '.') ? rtrim(substr($qty, strpos($qty, '.') + 1), '0') : '';

        return strlen($fraction) > 4;
    }

    /** A decimal string without trailing zeros ("2.500000" → "2.5"). */
    private static function decimal(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
