<?php

namespace Modules\Pharmacy\Http\Lists;

use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pharmacy\Http\Resources\RegisterEntryResource;

/** PHA-18, PHA-19, PHA-24: the controlled-drug register, in entry order (oldest first). */
class RegisterList extends PharmacyList
{
    public const RELATIONS = ['item', 'location', 'pharmacist', 'recorder'];

    public function name(): string
    {
        return 'pharmacy-register';
    }

    public function auditAction(): string
    {
        return 'pharmacy.register.export';
    }

    public function title(array $filters): string
    {
        return __('pharmacy.lists.register');
    }

    public function resource(Model $model): JsonResource
    {
        return RegisterEntryResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'entry_no' => ListSort::column('entry_no'),
            'occurred_at' => ListSort::column('occurred_at'),
        ];
    }

    public function defaultSort(): string
    {
        return 'entry_no';
    }

    public function columns(): array
    {
        return [
            self::col('entry_no', ['entry_no'], fn (array $row) => (string) $row['entry_no']),
            self::at('occurred_at'),
            self::col('kind', ['kind'], fn (array $row) => __('pharmacy.kinds.'.$row['kind'])),
            self::item(),
            self::col('batch_number', ['batch_number'], fn (array $row) => $row['batch_number']),
            self::qty('qty', 'qty'),
            self::qty('balance', 'balance'),
            self::col('patient_name', ['patient_name'], fn (array $row) => $row['patient_name']),
            self::col('prescriber', ['prescriber_name'], fn (array $row) => $row['prescriber_name'] === null ? null : trim($row['prescriber_name'].' '.($row['prescriber_licence'] ?? ''))),
            self::named('pharmacist', 'pharmacist'),
            self::named('location', 'location'),
            self::col('reason', ['reason'], fn (array $row) => $row['reason']),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
