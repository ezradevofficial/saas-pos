<?php

namespace App\Core\CustomFields\Entities;

use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Parties\PartyPolicy;
use App\Core\MasterData\SharedRecordPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** CF-01: parties (MD-01) carry custom fields; customers sync to the till as `customers`. */
class PartyEntity extends SharedRecordEntity
{
    public const KEY = 'party';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'core.custom_field.entities.party';
    }

    public function model(): string
    {
        return Party::class;
    }

    public function fieldRules(): string
    {
        return 'party';
    }

    protected function policy(): SharedRecordPolicy
    {
        return app(PartyPolicy::class);
    }

    protected function permission(): string
    {
        return 'core.party';
    }

    /** @param Party $record */
    public function display(Model $record): string
    {
        return $record->name;
    }

    public function syncedRecords(): Builder
    {
        return Party::query()->whereRaw("roles @> array['customer']::text[]");
    }
}
