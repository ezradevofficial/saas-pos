<?php

namespace Modules\Pharmacy\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pharmacy\Http\Resources\WriteOffResource;

/** PHA-12: write-offs, newest first. */
class WriteOffList extends PharmacyList
{
    public const RELATIONS = ['company', 'branch', 'location', 'requester'];

    public function name(): string
    {
        return 'pharmacy-write-offs';
    }

    public function auditAction(): string
    {
        return 'pharmacy.write_off.export';
    }

    public function title(array $filters): string
    {
        return __('pharmacy.lists.write_offs');
    }

    public function resource(Model $model): JsonResource
    {
        return WriteOffResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'created_at' => ListSort::column('created_at'),
            'number' => ListSort::column('number'),
            'status' => ListSort::column('status'),
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('number', 'pharmacy.columns.number'),
            self::at('created_at'),
            self::col('status', ['status'], fn (array $row) => __('pharmacy.states.'.$row['status'])),
            self::col('reason', ['reason'], fn (array $row) => __('pharmacy.reasons.'.$row['reason'])),
            self::named('location', 'location'),
            self::named('requested_by', 'requested_by'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
