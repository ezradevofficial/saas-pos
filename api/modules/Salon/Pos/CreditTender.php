<?php

namespace Modules\Salon\Pos;

use App\Core\Payments\Tenders\TenderProvider;
use App\Core\Payments\Tenders\TenderUse;
use Illuminate\Support\Str;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Package;
use Modules\Salon\Packages\Packages;
use Modules\Salon\Payments\Deposits;
use Modules\Salon\SalonServiceProvider;

/**
 * SAL-17, SAL-18, MD-04: the `salon_credit` payment method type, provided through the core tender seam like Hotel's
 * `room_charge`. The till sends `tender: {source: deposit, appointment_id}` or `{source: package, package_id,
 * service_id}`; when a sale is uploaded the server asks here whether that credit is there. What it notices is flagged
 * (`tender_flagged`), never refused: the service was given and the money has changed hands (device wins, NFR-04);
 * Credits then uses up what it can and keeps the rest as an exception.
 */
final class CreditTender implements TenderProvider
{
    public function __construct(private readonly Deposits $deposits, private readonly Packages $packages) {}

    public function type(): string
    {
        return 'salon_credit';
    }

    public function module(): string
    {
        return SalonServiceProvider::MODULE;
    }

    public function check(TenderUse $use): array
    {
        // Credit coming back to a refunded payment needs nothing checked.
        if ($use->kind !== TenderUse::SALE) {
            return [];
        }

        return match ($use->tender['source'] ?? '') {
            'deposit' => $this->deposit($use),
            'package' => $this->package($use),
            default => ['salon_credit_unknown'],
        };
    }

    /** @return list<string> */
    private function deposit(TenderUse $use): array
    {
        $id = (string) ($use->tender['appointment_id'] ?? '');
        $appointment = Str::isUuid($id) ? Appointment::query()->find($id) : null;

        return match (true) {
            $appointment === null || $appointment->company_id !== $use->companyId => ['salon_deposit_unknown'],
            $appointment->currency !== $use->documentCurrency => ['salon_deposit_currency'],
            $this->deposits->balance($appointment) < (int) $use->amountInDocumentMinor => ['salon_deposit_insufficient'],
            default => [],
        };
    }

    /** @return list<string> */
    private function package(TenderUse $use): array
    {
        $id = (string) ($use->tender['package_id'] ?? '');
        $package = Str::isUuid($id) ? Package::query()->find($id) : null;

        return match (true) {
            $package === null || $package->company_id !== $use->companyId => ['salon_package_unknown'],
            $package->customer_id !== $use->customerId => ['salon_package_customer'],
            $package->status === Package::VOID || $package->status === Package::EXHAUSTED || $package->sessionsLeft() <= 0 => ['salon_package_exhausted'],
            $package->status === Package::EXPIRED || $this->packages->expired($package) => ['salon_package_expired'],
            $package->currency !== $use->documentCurrency => ['salon_package_currency'],
            (array) $package->service_ids !== [] && ! in_array((string) ($use->tender['service_id'] ?? ''), (array) $package->service_ids, true) => ['salon_package_service'],
            default => [],
        };
    }
}
