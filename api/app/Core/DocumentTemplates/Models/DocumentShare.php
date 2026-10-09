<?php

namespace App\Core\DocumentTemplates\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * TPL-04: one document shared through a public link `/d/{token}`. The
 * token is never stored, only its sha256. Revoked or expired links stop
 * working; rows are kept (TEN-06). Audited by DocumentShares (AUD-01).
 */
class DocumentShare extends Model
{
    use BelongsToTenant, HasUuids;

    public const DAYS = 7;

    protected $fillable = [
        'document_type', 'record_id', 'company_id', 'branch_id', 'location_id', 'token_hash', 'expires_at', 'created_by',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_accessed_at' => 'datetime',
            'access_count' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }
}
