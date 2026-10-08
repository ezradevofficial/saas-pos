<?php

namespace App\Core\MasterData\Dimensions;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A company's dimension (MD-05) that any module can tag transactions with:
 * a department, cost centre or project. A tree within its company
 * (`parent_id`, no cycles), with a case-insensitive code unique among the
 * company's active rows and an owner (the department head or cost-centre
 * owner approvers resolve to, APR-02). Archived, never deleted (TEN-06);
 * audited as `core.{department|cost_centre|project}.*` (MD-07).
 */
abstract class Dimension extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    /** Any of these at, above or beneath the company lets a user read its dimensions. */
    public const PERMISSIONS = ['core.dimension.view', 'core.dimension.create', 'core.dimension.edit', 'core.dimension.archive'];

    protected $fillable = ['company_id', 'code', 'name', 'parent_id', 'owner_user_id'];

    /** The URL name of this kind of dimension, e.g. `cost-centres`. */
    abstract public static function path(): string;

    /** The ids of this row and every row beneath it (archived included). @return list<string> */
    public function withDescendants(): array
    {
        $table = $this->getTable();

        return array_map(fn (object $row) => $row->id, DB::connection($this->getConnectionName())->select(
            "with recursive tree(id) as (
                select id from {$table} where id = ?::uuid
                union
                select c.id from {$table} c join tree t on c.parent_id = t.id
            ) select id::text from tree",
            [$this->id],
        ));
    }

    /** True when $candidate is this row or beneath it (a parent there would make a cycle). */
    public function isAncestorOf(string $candidate): bool
    {
        return in_array($candidate, $this->withDescendants(), true);
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
