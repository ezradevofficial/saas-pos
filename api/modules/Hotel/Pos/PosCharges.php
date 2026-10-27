<?php

namespace Modules\Hotel\Pos;

use App\Core\Audit\Auditor;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Hotel\Contracts\RoomCharges;
use Modules\Hotel\Models\ChargeException;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Support\Properties;

/**
 * HOT-22: what a POS sale means for a guest's room, from the snapshot POS
 * sends with its events (never from POS tables). Each `room_charge` payment
 * of a completed sale is posted to the room's folio through RoomCharges, once
 * per payment; a voided sale takes its charges back; a refunded payment is a
 * credit. A charge that cannot be posted (the guest left, the room is
 * unknown, the folio is settled) is kept as a charge exception for the front
 * desk, never lost and never refused (device wins, NFR-04).
 */
class PosCharges
{
    public const SALE = 'pos.sale';

    public const REFUND = 'pos.refund';

    public function __construct(
        private readonly RoomCharges $rooms,
        private readonly Properties $properties,
        private readonly Auditor $auditor,
    ) {}

    /** @param array<string, mixed> $sale SaleSnapshot::sale() */
    public function completed(array $sale): void
    {
        foreach ($this->payments($sale) as $payment) {
            $this->charge($sale, $payment, self::SALE, (int) $payment['amount_minor'], $sale['receipt_number'] ?? null);
        }
    }

    /** @param array<string, mixed> $sale SaleSnapshot::sale() */
    public function voided(array $sale): void
    {
        foreach ($this->payments($sale) as $payment) {
            $this->takeBack($sale, $payment, self::SALE, (int) $payment['amount_minor']);
        }
    }

    /** @param array<string, mixed> $refund SaleSnapshot::refund() */
    public function refunded(array $refund): void
    {
        foreach ($this->payments($refund) as $payment) {
            $this->charge($refund, $payment, self::REFUND, -(int) $payment['amount_minor'], $refund['receipt_number'] ?? null);
        }
    }

    /** @return list<array<string, mixed>> the room_charge payments of a snapshot */
    private function payments(array $snapshot): array
    {
        return array_values(array_filter((array) ($snapshot['payments'] ?? []), fn ($p) => ($p['method_type'] ?? null) === 'room_charge'));
    }

    private function charge(array $doc, array $payment, string $source, int $amount, ?string $reference): void
    {
        $room = trim((string) ($payment['tender']['room'] ?? ''));
        $result = $room === ''
            ? ['posted' => false, 'duplicate' => false, 'reason' => 'room_unknown']
            : $this->rooms->post([
                'branch_id' => $doc['branch_id'], 'room_number' => $room, 'amount_minor' => $amount, 'currency' => $payment['currency'],
                'source' => $source, 'source_id' => $payment['id'], 'reference' => $reference,
            ]);

        if ($result['posted'] || $result['duplicate']) {
            return;
        }

        $this->keep($doc, $payment, $source, $amount, $reference, (string) $result['reason']);
    }

    private function takeBack(array $doc, array $payment, string $source, int $amount): void
    {
        if ($this->rooms->reverse($source, $payment['id'])) {
            return;
        }

        // Nothing posted to take back is fine (it was kept as an exception, or never charged); a settled folio is the front desk's.
        $open = ChargeException::query()->where('source', $source)->where('source_id', $payment['id'])->first();

        if ($open !== null) {
            if ($open->status === ChargeException::OPEN) {
                $open->forceFill(['status' => ChargeException::RESOLVED, 'resolution' => 'written_off', 'resolved_at' => now(), 'note' => 'sale voided'])->save();
            }

            return;
        }

        if (FolioLine::query()->where('source', $source)->where('source_id', $payment['id'])->exists()) {
            $this->keep($doc, $payment, 'pos.void', -$amount, $doc['receipt_number'] ?? null, 'folio_settled');
        }
    }

    private function keep(array $doc, array $payment, string $source, int $amount, ?string $reference, string $reason): void
    {
        $inserted = DB::connection(TenantContext::CONNECTION)->table('hotel_charge_exceptions')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'tenant_id' => app(TenantContext::class)->require(), 'company_id' => $doc['company_id'], 'branch_id' => $doc['branch_id'],
            'source' => $source, 'source_id' => $payment['id'], 'reference' => $reference === null ? null : mb_substr($reference, 0, 60),
            'room_number' => ($payment['tender']['room'] ?? null) === null ? null : mb_substr((string) $payment['tender']['room'], 0, 20),
            'currency' => $payment['currency'], 'amount_minor' => $amount, 'reason' => mb_substr($reason, 0, 40), 'status' => ChargeException::OPEN,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($inserted > 0) {
            $this->auditor->record('hotel.charge_exception.create', null, null, ['source' => $source, 'source_id' => $payment['id'], 'reason' => $reason, 'amount_minor' => $amount]);
        }
    }
}
