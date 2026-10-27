<?php

namespace Modules\Pharmacy\Http\Lists;

use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pharmacy\Http\Resources\DispenseResource;

/** PHA-24, PHA-26: the prescription log (dispensing against prescriptions) and the flagged dispenses to review, newest first. */
class DispenseList extends PharmacyList
{
    public const RELATIONS = ['item', 'location', 'customer', 'prescription', 'pharmacist', 'cashier'];

    public function name(): string
    {
        return 'pharmacy-dispenses';
    }

    public function auditAction(): string
    {
        return 'pharmacy.dispense.export';
    }

    public function title(array $filters): string
    {
        return __('pharmacy.lists.dispenses');
    }

    public function resource(Model $model): JsonResource
    {
        return DispenseResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'dispensed_at' => ListSort::column('dispensed_at'),
            'receipt_number' => ListSort::column('receipt_number'),
        ];
    }

    public function defaultSort(): string
    {
        return '-dispensed_at';
    }

    public function columns(): array
    {
        return [
            self::at('dispensed_at'),
            self::col('receipt_number', ['receipt_number'], fn (array $row) => $row['receipt_number']),
            self::item(),
            self::col('class', ['class'], fn (array $row) => __('pharmacy.classes.'.$row['class'])),
            self::qty('qty', 'qty'),
            self::col('patient_name', ['prescription'], fn (array $row) => $row['prescription']['patient_name'] ?? null),
            self::col('prescriber', ['prescription'], fn (array $row) => isset($row['prescription']['prescriber_name']) ? trim($row['prescription']['prescriber_name'].' '.$row['prescription']['prescriber_licence']) : null),
            self::named('pharmacist', 'pharmacist'),
            self::col('approval_mode', ['approval_mode'], fn (array $row) => __('pharmacy.approvals.'.$row['approval_mode'])),
            self::col('flags', ['flags'], fn (array $row) => implode(', ', array_map(fn (string $flag) => __('pharmacy.flags.'.$flag), $row['flags']))),
            self::named('location', 'location'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
