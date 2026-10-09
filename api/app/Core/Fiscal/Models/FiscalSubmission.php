<?php

namespace App\Core\Fiscal\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One document (a sale, a refund, a void) on its way to the tax authority
 * (concept note 7.2). `payload` is the document as its module described it
 * when it was queued (FiscalDocument); `authority` holds what the
 * authority returned once it accepted it (for eTIMS: receipt number,
 * internal data, receipt signature, control unit id, QR content).
 *
 * Statuses: queued, sending, accepted, rejected (refused by the authority
 * or by a local check: a person must act, then retry), retrying (a
 * failure the authority may recover from: tried again with backoff, never
 * given up), needs_attention (held: the platform must not guess how to
 * send it; a person decides and retries). Status changes are audited (AUD-01); the payload is not
 * copied into the audit log.
 */
class FiscalSubmission extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const STATUSES = ['queued', 'sending', 'accepted', 'rejected', 'retrying', 'needs_attention'];

    public const TYPES = ['sale', 'refund', 'void'];

    protected $fillable = [
        'company_id', 'country', 'driver', 'source', 'document_type', 'document_id', 'document_number', 'invoice_no',
        'original_submission_id', 'payload', 'status', 'attempts', 'next_attempt_at', 'last_attempt_at', 'accepted_at',
        'deadline_at', 'authority', 'error_code', 'last_error', 'alerted_at',
    ];

    protected array $auditHidden = ['payload', 'authority', 'attempts', 'next_attempt_at', 'last_attempt_at'];

    protected $attributes = ['authority' => '{}', 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'authority' => 'array',
            'invoice_no' => 'integer',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'accepted_at' => 'datetime',
            'deadline_at' => 'datetime',
            'alerted_at' => 'datetime',
        ];
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_submission_id');
    }

    public function isCreditNote(): bool
    {
        return $this->document_type !== 'sale';
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
