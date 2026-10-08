<?php

namespace Modules\POS\Http\Controllers\Device;

use Illuminate\Http\JsonResponse;
use Modules\POS\Http\Requests\Device\AllocateNumberRangesRequest;
use Modules\POS\Http\Requests\Device\UploadCashMovementsRequest;
use Modules\POS\Http\Requests\Device\UploadRefundsRequest;
use Modules\POS\Http\Requests\Device\UploadSalesRequest;
use Modules\POS\Http\Requests\Device\UploadShiftsRequest;
use Modules\POS\Http\Requests\Device\UploadVoidsRequest;
use Modules\POS\Models\NumberRange;
use Modules\POS\Sync\CashMovementUploads;
use Modules\POS\Sync\DevicePlace;
use Modules\POS\Sync\NumberRanges;
use Modules\POS\Sync\RefundUploads;
use Modules\POS\Sync\SaleUploads;
use Modules\POS\Sync\ShiftUploads;
use Modules\POS\Sync\UploadResults;
use Modules\POS\Sync\VoidUploads;

/**
 * POS-09, NUM-02: what a paired till sends (device token, ability
 * `device`, scoped to the device's location). Every upload is a batch of
 * records with device-made ids, answered with one result per record in
 * order: `stored` (also for a resend of a stored record, with the same
 * result) or `rejected` with an error. 200 when anything in the batch is
 * stored; 422 `upload_rejected` when nothing is. Upload order: shifts,
 * sales, cash movements, voids, refunds, then shifts again to close them.
 */
class DeviceUploadController
{
    public function shifts(UploadShiftsRequest $request, ShiftUploads $uploads): JsonResponse
    {
        return $this->respond($uploads->upload(DevicePlace::of($request->user()), $request->validated('shifts')));
    }

    public function sales(UploadSalesRequest $request, SaleUploads $uploads): JsonResponse
    {
        return $this->respond($uploads->upload(DevicePlace::of($request->user()), $request->validated('sales')));
    }

    public function cashMovements(UploadCashMovementsRequest $request, CashMovementUploads $uploads): JsonResponse
    {
        return $this->respond($uploads->upload(DevicePlace::of($request->user()), $request->validated('movements')));
    }

    public function voids(UploadVoidsRequest $request, VoidUploads $uploads): JsonResponse
    {
        return $this->respond($uploads->upload(DevicePlace::of($request->user()), $request->validated('voids')));
    }

    public function refunds(UploadRefundsRequest $request, RefundUploads $uploads): JsonResponse
    {
        return $this->respond($uploads->upload(DevicePlace::of($request->user()), $request->validated('refunds')));
    }

    /** NUM-02: the device's active ranges of a document type, topped up when running low. */
    public function numberRanges(AllocateNumberRangesRequest $request, NumberRanges $ranges): JsonResponse
    {
        $active = $ranges->topUp(DevicePlace::of($request->user()), $request->validated('document_type'), $request->validated('next'));

        return response()->json(['data' => $active->map(fn (NumberRange $range) => [
            'id' => $range->id,
            'document_type' => $range->document_type,
            'period' => $range->period,
            'pattern' => $range->pattern,
            'from' => $range->range_from,
            'to' => $range->range_to,
            'next' => $range->next_value,
            'status' => $range->status,
            'allocated_at' => $range->allocated_at->toIso8601String(),
        ])->values()]);
    }

    /** @param list<array<string, mixed>> $results */
    private function respond(array $results): JsonResponse
    {
        if (! collect($results)->contains('status', UploadResults::STORED)) {
            return response()->json([
                'message' => __('pos.errors.upload_rejected'),
                'code' => 'upload_rejected',
                'results' => $results,
            ], 422);
        }

        return response()->json(['results' => $results]);
    }
}
