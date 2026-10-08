<?php

namespace App\Core\Identity\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Identity\Http\Resources\SessionResource;
use App\Core\Identity\Models\PersonalAccessToken;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user's own sessions (AUTH-10): sort keys and exportable
 * columns (EXP-01). No field rules apply.
 */
class SessionList extends ListDefinition
{
    public function name(): string
    {
        return 'sessions';
    }

    public function auditAction(): string
    {
        return 'core.session.export';
    }

    public function title(array $filters): string
    {
        return __('core.session.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return SessionResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'device' => ListSort::column('name', ['name']),
            'ip' => ListSort::column('ip'),
            // Last active: last used, else signed in (never used since).
            'last_active' => ListSort::by(['last_used_at', 'created_at'], fn (Builder $query, string $direction) => $query->orderByRaw(
                "coalesce({$query->qualifyColumn('last_used_at')}, {$query->qualifyColumn('created_at')}) {$direction}",
            )),
            'created_at' => ListSort::column('created_at'),
        ];
    }

    public function defaultSort(): string
    {
        // Most recently active first, as before sorting existed.
        return '-last_active';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('device', 'core.session.columns.device', 'name'),
            ListColumn::text('user_agent', 'core.session.columns.user_agent'),
            ListColumn::text('ip', 'core.session.columns.ip'),
            ListColumn::make('last_active', 'core.session.columns.last_active', ['last_used_at', 'created_at'],
                fn (array $row, PersonalAccessToken $token, ExportValues $values) => $values->dateTime($row['last_used_at'] ?? $row['created_at'])),
            ListColumn::make('current', 'core.session.columns.current', ['current'],
                fn (array $row) => __('core.list.'.($row['current'] ? 'yes' : 'no'))),
            ListColumn::make('created_at', 'core.session.columns.created_at', ['created_at'],
                fn (array $row, PersonalAccessToken $token, ExportValues $values) => $values->dateTime($row['created_at'])),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return ($filters['search'] ?? '') === '' ? [] : [__('core.list.search') => $filters['search']];
    }
}
