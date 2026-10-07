<?php

namespace App\Core\Tenancy\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A POS device paired to a location (TEN-05). Retired by status, not archived. */
class Device extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'location_id', 'name', 'status'];

    protected $attributes = ['status' => 'pending'];

    protected $hidden = ['pairing_code_hash'];

    protected function casts(): array
    {
        return [
            'pairing_code_expires_at' => 'datetime',
            'paired_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** RBAC-04: permission checks on a device apply at its location. */
    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }
}
