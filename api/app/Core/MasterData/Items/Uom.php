<?php

namespace App\Core\MasterData\Items;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * MD-02: a unit of measure of the tenant (every company uses the same
 * units). Codes are upper case and unique among active units (citext).
 * The defaults are seeded at sign-up (DefaultUoms). Archived, never deleted
 * (TEN-06); audited as `core.uom.*` (MD-07).
 */
class Uom extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const KINDS = ['count', 'weight', 'volume', 'length', 'time'];

    protected $fillable = ['code', 'name', 'kind'];
}
