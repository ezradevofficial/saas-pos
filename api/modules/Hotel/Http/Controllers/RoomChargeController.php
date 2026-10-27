<?php

namespace Modules\Hotel\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Exports\ListExport;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Modules\Hotel\Contracts\RoomCharges;
use Modules\Hotel\Folios\Folios;
use Modules\Hotel\Http\Requests\DeviceRoomLookupRequest;
use Modules\Hotel\Http\Requests\ListChargeExceptionsRequest;
use Modules\Hotel\Http\Requests\ResolveChargeExceptionRequest;
use Modules\Hotel\Http\Requests\RoomChargeLookupRequest;
use Modules\Hotel\Http\Resources\ChargeExceptionResource;
use Modules\Hotel\Models\ChargeException;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Support\Access;
use Modules\Hotel\Support\HotelError;
use Modules\Hotel\Support\Text;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** HOT-22, HOT-23, HOT-35: finding the in-house guest of a room, from the back office and from a paired till, and the charges that could not be posted. */
class RoomChargeController
{
    use RespondsWithLists;

    public function __construct(private readonly Access $access, private readonly RoomCharges $rooms, private readonly Folios $folios, private readonly Auditor $auditor) {}

    public function lookup(RoomChargeLookupRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->rooms->lookup(
            $request->validated('branch_id'), $request->validated('room'), $request->validated('currency'), isset($request->validated()['amount_minor']) ? (int) $request->validated('amount_minor') : null,
        )]);
    }

    /** A paired till asks (online) about a room of its own property: only what the cashier needs to confirm the guest. */
    public function deviceLookup(DeviceRoomLookupRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $branchId = (string) DB::connection(TenantContext::CONNECTION)->table('locations')->where('id', $device->location_id)->value('branch_id');
        $found = $this->rooms->lookup($branchId, $request->validated('room'), $request->validated('currency'), isset($request->validated()['amount_minor']) ? (int) $request->validated('amount_minor') : null);

        return response()->json(['data' => [
            'allowed' => $found['allowed'], 'reason' => $found['reason'], 'room_number' => $found['room_number'], 'guest_name' => $found['guest_name'], 'folio_id' => $found['folio_id'], 'currency' => $found['currency'],
        ]]);
    }

    public function exceptions(ListChargeExceptionsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $this->access->scopeBranches(ChargeException::query(), $request->user(), 'hotel.folio.view');

        if ($request->filled('branch')) {
            $query->where('branch_id', $request->validated('branch'));
        }

        $status = $request->validated('status', 'open');
        $status === 'all' ? null : $query->where('status', $status);

        return $this->listed($request, $query, ChargeExceptionResource::class, $export);
    }

    /** HOT-22: move the charge to a folio, or close it with a note (billed another way, written off); audited. */
    public function resolve(ResolveChargeExceptionRequest $request, ChargeException $hotelException): ChargeExceptionResource
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($request, $hotelException) {
            $exception = ChargeException::query()->whereKey($hotelException->id)->lockForUpdate()->firstOrFail();

            if ($exception->status !== ChargeException::OPEN) {
                throw HotelError::make(409, 'exception_resolved');
            }

            $folioId = null;

            if ($request->validated('resolution') === 'moved') {
                // A charge that was posted (a retry after the exception, a sale synced late) cannot be moved on top of itself.
                if (FolioLine::query()->where('source', $exception->source)->where('source_id', $exception->source_id)->exists()) {
                    throw HotelError::make(409, 'exception_already_posted');
                }

                $folio = Folio::query()->findOrFail($request->validated('folio_id'));

                if ($folio->company_id !== $exception->company_id || $folio->currency !== $exception->currency || ! $folio->isOpen()) {
                    throw HotelError::make(422, 'transfer_mismatch', field: 'folio_id');
                }

                $this->folios->post($folio, [
                    'kind' => FolioLine::POS_CHARGE, 'description' => Text::line($exception->amount_minor > 0 ? 'hotel.lines.room_charge' : 'hotel.lines.room_charge_credit', ['reference' => (string) $exception->reference]),
                    'amount_minor' => $exception->amount_minor, 'net_minor' => $exception->amount_minor, 'source' => $exception->source, 'source_id' => $exception->source_id,
                    'reference' => $exception->reference, 'created_by' => $request->user()->id,
                ]);
                $folioId = $folio->id;
            }

            $exception->forceFill([
                'status' => ChargeException::RESOLVED, 'resolution' => $request->validated('resolution'), 'resolved_folio_id' => $folioId, 'note' => $request->validated('note'),
                'resolved_by' => $request->user()->id, 'resolved_at' => now(),
            ])->save();
            $this->auditor->record('hotel.charge_exception.resolve', $exception, ['status' => ChargeException::OPEN], [
                'status' => ChargeException::RESOLVED, 'resolution' => $exception->resolution, 'folio_id' => $folioId, 'amount_minor' => $exception->amount_minor,
            ]);

            return ChargeExceptionResource::make($exception->refresh());
        });
    }
}
