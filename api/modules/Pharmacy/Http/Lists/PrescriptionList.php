<?php

namespace Modules\Pharmacy\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pharmacy\Http\Resources\PrescriptionResource;

/** PHA-14, PHA-28: prescriptions (personal data), newest prescribed first. */
class PrescriptionList extends PharmacyList
{
    public const RELATIONS = ['company', 'branch', 'location', 'patient', 'creator'];

    public function name(): string
    {
        return 'pharmacy-prescriptions';
    }

    public function auditAction(): string
    {
        return 'pharmacy.prescription.export';
    }

    public function title(array $filters): string
    {
        return __('pharmacy.lists.prescriptions');
    }

    public function resource(Model $model): JsonResource
    {
        return PrescriptionResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'prescribed_on' => ListSort::column('prescribed_on'),
            'created_at' => ListSort::column('created_at'),
            'patient_name' => ListSort::column('patient_name'),
        ];
    }

    public function defaultSort(): string
    {
        return '-prescribed_on';
    }

    public function columns(): array
    {
        return [
            self::on('prescribed_on'),
            ListColumn::text('patient_name', 'pharmacy.columns.patient_name'),
            ListColumn::text('prescriber_name', 'pharmacy.columns.prescriber_name'),
            ListColumn::text('prescriber_licence', 'pharmacy.columns.prescriber_licence'),
            ListColumn::text('facility', 'pharmacy.columns.facility'),
            self::col('status', ['status'], fn (array $row) => __('pharmacy.states.'.$row['status'])),
            self::col('source', ['source'], fn (array $row) => __('pharmacy.sources.'.$row['source'])),
            self::named('location', 'location'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
