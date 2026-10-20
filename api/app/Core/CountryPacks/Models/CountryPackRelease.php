<?php

namespace App\Core\CountryPacks\Models;

use App\Core\Platform\PlatformDatabase;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ADM-04 (ADR 022): one release of a country pack document (tax, payroll,
 * hr, holidays, fixed_assets, accounts), versioned per country and kind.
 * Loaded, approved by another staff member, published by a third role;
 * content never changes (trigger). Global reference data: written on the
 * platform connection, read by the runtime role (PackDocuments).
 *
 * @property string $id
 * @property string $country
 * @property string $kind
 * @property int $version
 * @property string $status
 * @property array<string, mixed> $content
 * @property string $content_hash
 * @property Carbon $effective_from
 * @property string|null $notes
 * @property array{errors: list<string>, warnings: list<string>} $validation
 * @property array<string, mixed> $diff
 * @property string $loaded_by
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $published_by
 * @property Carbon|null $published_at
 * @property string|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_note
 * @property Carbon|null $applied_at
 */
class CountryPackRelease extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const PUBLISHED = 'published';

    public const REJECTED = 'rejected';

    protected $connection = PlatformDatabase::CONNECTION;

    protected $table = 'country_pack_releases';

    protected $fillable = ['country', 'kind', 'version', 'status', 'content', 'content_hash', 'effective_from', 'notes', 'validation', 'diff', 'loaded_by',
        'approved_by', 'approved_at', 'published_by', 'published_at', 'rejected_by', 'rejected_at', 'rejection_note', 'applied_at'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'content' => 'array',
            'validation' => 'array',
            'diff' => 'array',
            'effective_from' => 'date',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'rejected_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function hasErrors(): bool
    {
        return ($this->validation['errors'] ?? []) !== [];
    }
}
