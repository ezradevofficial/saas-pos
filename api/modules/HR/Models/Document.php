<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** HR-27: a file on an employee; its category decides who sees it. Archived, never deleted. */
class Document extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const CATEGORIES = ['contract', 'identity', 'certificate', 'medical', 'disciplinary', 'other'];

    protected $table = 'hr_documents';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'document';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_on' => 'immutable_date', 'size' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
