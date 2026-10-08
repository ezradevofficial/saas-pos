<?php

namespace App\Core\Currency\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One rate of a company's history (CUR-03): 1 base = mid quote, kind
 * `reference` (a feed) or `shop` (typed by a role holding
 * `core.exchange_rate.override`). Append-only: a new rate supersedes an
 * old one by its effective time. Audited as `core.exchange_rate.create`.
 */
class ExchangeRate extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    /** Microseconds kept, so two rates entered within a second do not collide. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['company_id', 'base', 'quote', 'kind', 'buy', 'sell', 'mid', 'effective_at', 'source', 'entered_by'];

    protected function casts(): array
    {
        return [
            'effective_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** Set by the history filter: `direct` when stored as the pair asked, `inverse` when stored the other way. */
    public ?string $direction = null;

    public function setDirection(string $direction): self
    {
        $this->direction = $direction;

        return $this;
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
