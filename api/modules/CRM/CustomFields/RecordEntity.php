<?php

namespace Modules\CRM\CustomFields;

use App\Core\CustomFields\Entities\CustomFieldEntity;
use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\CRM\Models\ScopedRecord;
use Modules\CRM\Support\CrmAccess;

/**
 * CF-01, CRM-33: a CRM record that carries custom fields in its `custom`
 * column, offered only while CRM is active (RBAC-08), seen as CRM-08 says;
 * field rules on the same resource name as the entity (RBAC-05).
 */
abstract class RecordEntity extends CustomFieldEntity
{
    /** `lead` or `opportunity` */
    abstract protected function resource(): string;

    public function module(): ?string
    {
        return 'crm';
    }

    public function fieldRules(): ?string
    {
        return $this->key();
    }

    public function viewAny(User $actor): bool
    {
        return app(CrmAccess::class)->anywhere($actor, "crm.{$this->resource()}.view");
    }

    public function view(User $actor, Model $record): bool
    {
        return $record instanceof ScopedRecord && app(CrmAccess::class)->sees($actor, $record, $this->resource());
    }

    public function writeAny(User $actor): bool
    {
        return app(CrmAccess::class)->anywhere($actor, "crm.{$this->resource()}.create", "crm.{$this->resource()}.edit");
    }

    public function visible(Builder $query, User $actor): Builder
    {
        return app(CrmAccess::class)->visible($query, $actor, $this->resource());
    }

    public function display(Model $record): string
    {
        return (string) $record->getAttribute('name');
    }
}
