<?php

namespace Modules\FuelStation\Vouchers;

use App\Core\Payments\Tenders\TenderProvider;
use App\Core\Payments\Tenders\TenderUse;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Modules\FuelStation\FuelStationServiceProvider;
use Modules\FuelStation\Models\Voucher;

/**
 * FUEL-21, LOY-27: the `fuel_voucher` payment method type (MD-04), provided
 * through the core tender seam like Loyalty's `credit` and Hotel's
 * `room_charge`. The till sends `tender: {code}` (the voucher code the
 * attendant typed or scanned); when a sale or refund is uploaded the server
 * checks the voucher: unknown, void, expired, in another currency or
 * overdrawn. What it notices is flagged (`tender_flagged`), never refused:
 * the fuel has been dispensed (device wins, NFR-04). The value is taken off
 * the balance when the sale is completed (Vouchers::redeem).
 */
final class VoucherTender implements TenderProvider
{
    public function __construct(private readonly Vouchers $vouchers) {}

    public function type(): string
    {
        return 'fuel_voucher';
    }

    public function module(): string
    {
        return FuelStationServiceProvider::MODULE;
    }

    public function check(TenderUse $use): array
    {
        // A refund hands value back to the voucher's sale: only the code must be one of ours.
        $code = trim((string) ($use->tender['code'] ?? ''));

        if ($code === '') {
            return ['fuel_voucher_no_code'];
        }

        $voucher = $this->vouchers->find($use->companyId, $code);

        if ($voucher === null) {
            return ['fuel_voucher_unknown'];
        }

        if ($use->kind === TenderUse::REFUND) {
            return [];
        }

        $flags = [];

        if ($voucher->status === Voucher::VOID) {
            $flags[] = 'fuel_voucher_void';
        }

        if ($voucher->expires_on !== null && $voucher->expires_on->lessThan(CarbonImmutable::now(Company::query()->whereKey($use->companyId)->value('timezone') ?: 'UTC')->startOfDay())) {
            $flags[] = 'fuel_voucher_expired';
        }

        if ($voucher->currency !== $use->documentCurrency) {
            $flags[] = 'fuel_voucher_currency';
        }

        if ((int) $use->amountInDocumentMinor > (int) $voucher->balance_minor) {
            $flags[] = 'fuel_voucher_overdrawn';
        }

        return $flags;
    }
}
