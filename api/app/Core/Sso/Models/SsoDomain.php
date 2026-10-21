<?php

namespace App\Core\Sso\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * AUTH-11, ADR 023: an email domain of the tenant (`company.co.ke`), proven
 * by a DNS TXT record as custom domains are (BR-05). Only addresses of a
 * verified domain sign in with the tenant's connections. Removing a domain
 * archives it (TEN-06).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $domain
 * @property string $status
 * @property string $verification_token
 * @property int $missed_checks
 */
class SsoDomain extends Model
{
    use BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const VERIFIED = 'verified';

    public const FAILED = 'failed';

    protected $fillable = ['domain', 'status', 'verification_token', 'pending_since', 'checked_at', 'verified_at', 'failure', 'missed_checks', 'failed_at', 'created_by', 'archived_at'];

    protected function casts(): array
    {
        return [
            'pending_since' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED && $this->archived_at === null;
    }
}
