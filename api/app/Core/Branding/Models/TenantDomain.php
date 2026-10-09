<?php

namespace App\Core\Branding\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * BR-05: a custom domain of the tenant (`erp.company.co.ke`). It is
 * verified by a DNS TXT record holding its token (TenantDomains), checked
 * by `domains:verify`. Removing a domain archives it (TEN-06); a host
 * belongs to one tenant at a time among domains not archived.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $host
 * @property string $status
 * @property string $verification_token
 */
class TenantDomain extends Model
{
    use BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const VERIFIED = 'verified';

    public const FAILED = 'failed';

    protected $fillable = ['host', 'status', 'verification_token', 'pending_since', 'checked_at', 'verified_at', 'failure', 'created_by', 'archived_at'];

    protected function casts(): array
    {
        return [
            'pending_since' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED && $this->archived_at === null;
    }
}
