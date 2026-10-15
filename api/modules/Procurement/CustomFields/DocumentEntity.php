<?php

namespace Modules\Procurement\CustomFields;

use App\Core\CustomFields\Entities\CustomFieldEntity;
use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Support\Places;

/**
 * CF-01, PRC-20: a Procurement document that carries custom fields in its
 * `custom` column, seen where the reader holds its view permission
 * (RBAC-04). Shown by number (drafts by "Draft").
 */
abstract class DocumentEntity extends CustomFieldEntity
{
    public function module(): ?string
    {
        return 'procurement';
    }

    /** `procurement.requisition` or `procurement.po` */
    abstract protected function permission(): string;

    public function fieldRules(): ?string
    {
        return null;
    }

    /** Documents are cancelled, not archived: unique values across them are not offered. */
    public function allowsUnique(): bool
    {
        return false;
    }

    public function viewAny(User $actor): bool
    {
        return app(ScopeResolver::class)->can($actor, "{$this->permission()}.view");
    }

    public function view(User $actor, Model $record): bool
    {
        return app(Places::class)->sees($actor, "{$this->permission()}.view", $record->branch_id, $record->location_id ?? null);
    }

    public function writeAny(User $actor): bool
    {
        return app(ScopeResolver::class)->can($actor, "{$this->permission()}.create");
    }

    public function visible(Builder $query, User $actor): Builder
    {
        return app(Places::class)->filter($query, $actor, "{$this->permission()}.view", locationColumn: 'location_id');
    }

    public function display(Model $record): string
    {
        return (string) ($record->number ?? __('procurement.statuses.draft'));
    }

    protected function matching(Builder $query, string $search): void
    {
        if ($search !== '') {
            $query->where('number', 'ilike', '%'.addcslashes($search, '\\%_').'%');
        }

        $query->orderByDesc('created_at')->orderBy('id');
    }

    public function search(User $actor, string $search, int $limit): array
    {
        if (! $this->viewAny($actor)) {
            return [];
        }

        $query = $this->visible($this->newQuery(), $actor)->where('status', '<>', 'cancelled');
        $this->matching($query, trim($search));

        return $query->limit($limit)->get()
            ->map(fn (Model $record) => ['id' => (string) $record->getKey(), 'label' => $this->display($record)])->values()->all();
    }
}
