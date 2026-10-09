<?php

namespace App\Core\DocumentTemplates;

use App\Core\Configuration\Models\ConfigDocument;
use App\Core\CountryPacks\PackFile;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use InvalidArgumentException;

/**
 * TPL-03, CP-01: whether a document must carry the tax authority's block
 * (KRA eTIMS in Kenya, DGI in the DR Congo). The rule is country pack
 * data (`documents.fiscal` in `country-packs/{CODE}/pack.json`), never a
 * constant in code.
 *
 * A template document's country is its company's (a branch's company for
 * a branch). A tenant-wide template is used by every company, so it needs
 * the block when any company of the tenant does, or when the tenant has
 * no company yet (both supported countries require it).
 */
class FiscalRules
{
    /** @var array<string, array{authority: string, required_on: list<string>, qr: bool}|null> */
    private array $packs = [];

    /** @return array{authority: string, required_on: list<string>, qr: bool}|null */
    public function forCountry(?string $country): ?array
    {
        if ($country === null || preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return null;
        }

        if (! array_key_exists($country, $this->packs)) {
            try {
                $this->packs[$country] = PackFile::read(PackFile::path($country))->fiscalDocuments();
            } catch (InvalidArgumentException) {
                $this->packs[$country] = null;
            }
        }

        return $this->packs[$country];
    }

    /** The authority whose block $type needs in $country, or null. */
    public function authorityFor(string $type, ?string $country): ?string
    {
        $rules = $this->forCountry($country);

        return $rules !== null && in_array($type, $rules['required_on'], true) ? $rules['authority'] : null;
    }

    public function requiredFor(string $type, ?string $country): bool
    {
        return $this->authorityFor($type, $country) !== null;
    }

    /** The countries a template document at this scope prints for (null: every country the tenant has). */
    public function countriesOf(?ConfigDocument $document): array
    {
        $companyId = match ($document?->scope_type) {
            ConfigDocument::COMPANY => $document->scope_id,
            ConfigDocument::BRANCH => Branch::query()->whereKey($document->scope_id)->value('company_id'),
            default => null,
        };

        if ($companyId !== null) {
            return array_values(array_filter([Company::query()->whereKey($companyId)->value('country')]));
        }

        $countries = Company::query()->distinct()->pluck('country')->filter()->values()->all();

        return $countries === [] ? ['KE', 'CD'] : $countries;
    }

    /** Whether a template for $type at the document's scope must keep the fiscal block. */
    public function requiredAt(string $type, ?ConfigDocument $document): bool
    {
        foreach ($this->countriesOf($document) as $country) {
            if ($this->requiredFor($type, $country)) {
                return true;
            }
        }

        return false;
    }
}
