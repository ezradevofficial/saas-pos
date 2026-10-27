<?php

namespace Modules\Salon\Models;

use App\Core\Audit\Audited;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SAL-25: the salon's profile of a customer party. Allergies and formulas are encrypted at rest and never audited in
 * clear (NFR-08); registered with the privacy module and hidden by field rules (`salon_client`).
 *
 * @property string $company_id
 * @property string $party_id
 * @property ?string $preferred_staff_id
 * @property ?string $preferences
 * @property ?string $allergies
 * @property ?string $formulas
 * @property ?string $notes
 */
class Client extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'salon_clients';

    protected string $auditModule = 'salon';

    protected string $auditResource = 'client';

    protected array $auditHidden = ['preferences', 'allergies', 'formulas', 'notes'];

    protected $guarded = [];

    protected $hidden = ['allergies', 'formulas'];

    protected function casts(): array
    {
        return ['allergies' => 'encrypted', 'formulas' => 'encrypted'];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
