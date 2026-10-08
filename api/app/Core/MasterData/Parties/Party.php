<?php

namespace App\Core\MasterData\Parties;

use App\Core\Audit\Audited;
use App\Core\Currency\Money;
use App\Core\MasterData\Support\TextArray;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MD-01: a person or organisation with roles (customer, supplier,
 * contact, employee link). Shared across the group when company_id is
 * null, else one company's (TEN-08, PartyRoles). Phones are stored as
 * [{number (E.164), label}], emails as [{address (lower case), label}].
 * Archived, never deleted (TEN-06). Audited as `core.party.*` (MD-07).
 *
 * Not HasScope: who reaches a party is decided by PartyPolicy (a shared
 * party is reached from any scope, a company's from its company or below).
 */
#[UsePolicy(PartyPolicy::class)]
class Party extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const KINDS = ['person', 'organisation'];

    protected $fillable = [
        'company_id', 'kind', 'name', 'legal_name', 'tax_id', 'phones', 'emails', 'addresses', 'currency',
        'payment_terms_days', 'credit_limit_minor', 'credit_limit_currency', 'price_list_id', 'tags', 'roles',
    ];

    protected $attributes = [
        'phones' => '[]',
        'emails' => '[]',
        'addresses' => '[]',
        'tags' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'phones' => 'array',
            'emails' => 'array',
            'addresses' => 'array',
            'tags' => TextArray::class,
            'roles' => TextArray::class,
            'payment_terms_days' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isShared(): bool
    {
        return $this->company_id === null;
    }

    public function creditLimit(): ?Money
    {
        return $this->credit_limit_minor === null ? null : Money::ofMinor((string) $this->credit_limit_minor, $this->credit_limit_currency);
    }

    /** @return list<string> */
    public function phoneNumbers(): array
    {
        return array_values(array_map(fn (array $phone) => $phone['number'], $this->phones ?? []));
    }
}
