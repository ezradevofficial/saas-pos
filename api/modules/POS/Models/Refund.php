<?php

namespace Modules\POS\Models;

use App\Core\Currency\FxSnapshot;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** POS-05: lines or quantities of a sale given back, with the money returned. */
class Refund extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    protected $table = 'pos_refunds';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'receipt_seq' => 'integer',
            'override_verified' => 'boolean',
            'flags' => 'array',
            'decided_at' => 'immutable_datetime',
            'fx' => FxSnapshot::class.':fx',
            'refunded_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RefundLine::class)->orderBy('created_at')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(RefundPayment::class)->orderBy('created_at')->orderBy('id');
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }
}
