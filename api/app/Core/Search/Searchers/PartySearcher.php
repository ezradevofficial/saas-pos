<?php

namespace App\Core\Search\Searchers;

use App\Core\MasterData\Parties\Http\Resources\PartyResource;
use App\Core\MasterData\Parties\Party;
use App\Core\Search\RecordHit;
use App\Core\Search\RecordSearcher;
use App\Core\Search\SearchContext;
use App\Core\Search\SearchTerm;
use Illuminate\Database\Eloquent\Builder;

/**
 * LAY-01, MD-01: customers or suppliers by name, legal name, the last digits
 * of a phone number or an email (RBAC-04: shared parties anywhere, a
 * company's from its company or below; RBAC-05: a hidden field is neither
 * matched nor shown). Trigram indexes serve the name, legal name, phones and
 * emails.
 */
class PartySearcher implements RecordSearcher
{
    /** @param 'customer'|'supplier' $role */
    public function __construct(private readonly string $role) {}

    public function type(): string
    {
        return $this->role;
    }

    public function module(): string
    {
        return 'core';
    }

    public function permission(): string
    {
        return 'core.party.view';
    }

    public function search(SearchTerm $term, SearchContext $context): array
    {
        $hides = fn (string $field) => $context->hides(PartyResource::FIELD_RULES, $field);

        if ($hides('name') && $hides('legal_name')) {
            return [];
        }

        $query = Party::query()
            ->whereNull('archived_at')
            ->whereRaw('roles @> ?::text[]', ['{'.$this->role.'}']);

        $companies = $context->visible->companiesTouched();

        if ($companies !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('company_id')->orWhereIn('company_id', $companies));
        }

        $regex = $term->regex();
        $query->where(function (Builder $q) use ($term, $regex, $hides) {
            $q->whereRaw('false');

            if (! $hides('name')) {
                $q->orWhereRaw('name ~* ?', [$regex]);
            }

            if (! $hides('legal_name')) {
                $q->orWhereRaw('legal_name ~* ?', [$regex]);
            }

            if ($term->looksLikePhone() && ! $hides('phones')) {
                $q->orWhereRaw('phones::text like ?', ['%'.$term->digits().'%']);
            }

            if ($term->looksLikeEmail() && ! $hides('emails')) {
                $q->orWhereRaw('emails::text ilike ?', [$term->contains()]);
            }
        });

        return $query
            ->orderByRaw('(name ~* ?) desc, lower(name)', [$term->regex(anchored: true)])
            ->limit($term->limit)
            ->get(['id', 'name', 'legal_name', 'phones', 'emails'])
            ->map(fn (Party $party) => new RecordHit(
                $party->id,
                $hides('name') ? (string) $party->legal_name : $party->name,
                $this->subtitle($party, $hides),
                "/contacts/{$this->role}s/{$party->id}",
            ))
            ->all();
    }

    private function subtitle(Party $party, callable $hides): ?string
    {
        if (! $hides('phones') && ($party->phones[0]['number'] ?? '') !== '') {
            return (string) $party->phones[0]['number'];
        }

        if (! $hides('emails') && ($party->emails[0]['address'] ?? '') !== '') {
            return (string) $party->emails[0]['address'];
        }

        return null;
    }
}
