<?php

namespace Modules\Hotel\Folios;

use App\Core\Audit\Auditor;
use App\Core\Fiscal\FiscalQueue;
use App\Core\Identity\Models\User;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Hotel\Events\FolioSettled;
use Modules\Hotel\HotelServiceProvider;
use Modules\Hotel\Integrations\HotelFiscalSource;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\Payment;
use Modules\Hotel\Support\HotelError;

/**
 * HOT-19, HOT-27: settling a folio. A folio settles when nothing is owed (or
 * a user allowed to leave a balance on account says so, flagged) and no
 * payment is still pending. The hotel's own charges become one invoice,
 * numbered per branch and queued on the core fiscal queue (source `hotel`,
 * KRA eTIMS or DRC DGI by company; nothing is queued while the company has
 * transmission off); sales charged to the room are not on it (already
 * receipted at the till). The ledger already has every charge, payment and
 * applied deposit (HotelPosting), so settling posts nothing more. A settled
 * folio takes no more lines.
 */
class Settlement
{
    public const NUMBER_TYPE = 'hotel.invoice';

    public const NUMBER = 'HI-{BRANCH}-{YYYY}-{00001}';

    public function __construct(
        private readonly Folios $folios,
        private readonly FolioPayments $payments,
        private readonly InvoiceLines $invoice,
        private readonly Numbering $numbering,
        private readonly FiscalQueue $fiscal,
        private readonly Auditor $auditor,
    ) {}

    public static function numberType(): DocumentNumberType
    {
        return new DocumentNumberType(self::NUMBER_TYPE, HotelServiceProvider::MODULE, self::NUMBER, NumberFormat::RESET_YEARLY, ['BRANCH'], langKey: 'hotel.numbering.invoice');
    }

    public function settle(Folio $folio, ?User $by, bool $allowBalance = false): Folio
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($folio, $by, $allowBalance) {
            $folio = Folio::query()->whereKey($folio->id)->lockForUpdate()->firstOrFail();

            if (! $folio->isOpen()) {
                return $folio;
            }

            if (Payment::query()->where('folio_id', $folio->id)->where('status', Payment::PENDING)->exists()) {
                throw HotelError::make(409, 'payment_pending');
            }

            // Deposits still held move to the guest ledger before the folio closes.
            $this->payments->applyDeposits($folio);
            $balance = $this->folios->balance($folio);

            if ($balance !== 0 && ! $allowBalance) {
                throw HotelError::make(409, 'folio_balance', ['balance' => $balance], 'folio_id');
            }

            $lines = $this->invoice->of($folio->id);
            $totals = $this->invoice->totals($lines);
            $number = null;

            if ($lines->isNotEmpty() && $totals['gross_minor'] > 0) {
                $number = $this->numbering->next(self::NUMBER_TYPE, new NumberContext(Company::query()->findOrFail($folio->company_id), Branch::query()->findOrFail($folio->branch_id)))->number;
            }

            $folio->forceFill([
                'status' => Folio::SETTLED, 'settled_at' => now(), 'settled_by' => $by?->id, 'invoice_number' => $number,
                'balance_left_minor' => $balance === 0 ? null : $balance,
            ])->save();

            // The invoice goes to the tax authority (once; nothing while the company does not transmit).
            if ($number !== null) {
                $this->fiscal->enqueue(HotelFiscalSource::KEY, 'sale', $folio->id, $folio->company_id);
            }

            $this->auditor->record('hotel.folio.settle', $folio, ['status' => Folio::OPEN], [
                'status' => Folio::SETTLED, 'invoice_number' => $number, 'invoice_minor' => $totals['gross_minor'], 'balance_left_minor' => $balance,
            ]);
            FolioSettled::dispatch($folio->tenant_id, $folio->company_id, $folio->id, $number);

            return $folio->refresh();
        });
    }
}
