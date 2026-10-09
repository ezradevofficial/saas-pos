<?php

namespace App\Core\CustomForms\Entities;

use App\Core\CustomForms\CustomFormAccess;
use App\Core\CustomForms\CustomFormLine;
use App\Core\CustomForms\CustomFormRecord;
use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * CF-05: the line fields of one custom form type, `custom_form_line:<key>`.
 * A line is seen with its record. Line fields can't be unique and lines
 * are no lookup target: they are parts of a record, not records.
 */
class CustomFormLineEntity extends CustomFormEntity
{
    public function key(): string
    {
        return $this->type->lineEntity();
    }

    public function model(): string
    {
        return CustomFormLine::class;
    }

    public function newQuery(): Builder
    {
        return CustomFormLine::query()->whereIn('record_id', CustomFormRecord::query()->where('type_id', $this->type->id)->select('id'));
    }

    public function view(User $actor, Model $record): bool
    {
        $parent = $record instanceof CustomFormLine ? $record->record : null;

        return $parent !== null && parent::view($actor, $parent);
    }

    public function visible(Builder $query, User $actor): Builder
    {
        return $query->whereIn('record_id', app(CustomFormAccess::class)->visible(CustomFormRecord::query(), $actor, $this->type)->select('id'));
    }

    public function display(Model $record): string
    {
        return (string) $record->getKey();
    }

    public function allowsUnique(): bool
    {
        return false;
    }

    public function isLookupTarget(): bool
    {
        return false;
    }
}
