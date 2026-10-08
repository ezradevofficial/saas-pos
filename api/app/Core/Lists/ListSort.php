<?php

namespace App\Core\Lists;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * One sort key of a list. `fields` are the resource keys the order is
 * built from: a user whose field rules hide any of them can't sort by it,
 * since the order alone would reveal the hidden values' ranking (RBAC-05).
 */
final class ListSort
{
    /**
     * @param  list<string>  $fields
     * @param  Closure(Builder, string): void  $apply  orders the query ('asc' or 'desc')
     */
    private function __construct(
        public readonly array $fields,
        private readonly Closure $apply,
    ) {}

    /** Order by a column of the list's table; the field defaults to the column. */
    public static function column(string $column, ?array $fields = null): self
    {
        return new self($fields ?? [$column], fn (Builder $query, string $direction) => $query->orderBy($query->qualifyColumn($column), $direction));
    }

    /**
     * @param  list<string>  $fields
     * @param  Closure(Builder, string): void  $apply
     */
    public static function by(array $fields, Closure $apply): self
    {
        return new self($fields, $apply);
    }

    public function apply(Builder $query, string $direction): void
    {
        ($this->apply)($query, $direction);
    }
}
