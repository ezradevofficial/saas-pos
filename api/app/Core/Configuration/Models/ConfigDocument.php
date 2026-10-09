<?php

namespace App\Core\Configuration\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One piece of versioned configuration (LAY-06): a kind (theme, dashboard,
 * navigation, ...), a key within the kind (a list key, a form key, a
 * template name) and the scope it applies to (tenant, company, branch,
 * location, role or user; scope_id is null for the tenant). Its versions
 * hold the JSON payloads. Changes are audited by ConfigVersions as
 * `core.config.*` (AUD-01).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kind
 * @property string $key
 * @property string $scope_type
 * @property ?string $scope_id
 * @property string $name
 */
class ConfigDocument extends Model
{
    use BelongsToTenant, HasUuids;

    public const TENANT = 'tenant';

    public const COMPANY = 'company';

    public const BRANCH = 'branch';

    public const LOCATION = 'location';

    public const ROLE = 'role';

    public const USER = 'user';

    /** Scope types, most specific first (the resolution order, docs/adr/010). */
    public const SCOPES = [self::USER, self::ROLE, self::LOCATION, self::BRANCH, self::COMPANY, self::TENANT];

    /** Places in the organisation a document can be copied between. */
    public const PLACES = [self::COMPANY, self::BRANCH, self::LOCATION];

    protected $fillable = ['kind', 'key', 'scope_type', 'scope_id', 'name', 'created_by'];

    public function versions(): HasMany
    {
        return $this->hasMany(ConfigVersion::class, 'document_id');
    }

    public function published(): HasOne
    {
        return $this->hasOne(ConfigVersion::class, 'document_id')->where('status', ConfigVersion::PUBLISHED);
    }

    public function draft(): HasOne
    {
        return $this->hasOne(ConfigVersion::class, 'document_id')->where('status', ConfigVersion::DRAFT);
    }
}
