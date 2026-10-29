<?php

namespace Modules\Salon\Search;

use App\Core\MasterData\Parties\Http\Resources\PartyResource;
use App\Core\MasterData\Parties\Party;
use App\Core\Search\RecordHit;
use App\Core\Search\RecordSearcher;
use App\Core\Search\SearchContext;
use App\Core\Search\SearchTerm;
use Illuminate\Database\Eloquent\Builder;
use Modules\Salon\SalonServiceProvider;

/**
 * LAY-01, SAL-02: salon clients, who are the company's customers (as the
 * salon's client list shows them), by name or the last digits of a phone, for
 * users who hold `salon.client.view` (RBAC-04: shared customers anywhere, a
 * company's from that company or below). A name or phone the user's field
 * rules hide on the party (RBAC-05) is neither matched nor shown. Never reads
 * the client profile (preferences, allergies, formulas, notes). Trigram
 * indexes on the party serve the name and phones.
 */
class ClientSearcher implements RecordSearcher
{
    public function type(): string
    {
        return 'salon_client';
    }

    public function module(): string
    {
        return SalonServiceProvider::MODULE;
    }

    public function permission(): string
    {
        return 'salon.client.view';
    }

    public function search(SearchTerm $term, SearchContext $context): array
    {
        $hides = fn (string $field) => $context->hides(PartyResource::FIELD_RULES, $field);

        if ($hides('name')) {
            return [];
        }

        $query = Party::query()->whereNull('archived_at')->whereRaw('roles @> ?::text[]', ['{customer}']);
        $companies = $context->visible->companiesTouched();

        if ($companies !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('company_id')->orWhereIn('company_id', $companies));
        }

        $query->where(function (Builder $q) use ($term, $hides) {
            $q->whereRaw('name ~* ?', [$term->regex()]);

            if ($term->looksLikePhone() && ! $hides('phones')) {
                $q->orWhereRaw('phones::text like ?', ['%'.$term->digits().'%']);
            }
        });

        return $query
            ->orderByRaw('(name ~* ?) desc, lower(name)', [$term->regex(anchored: true)])
            ->limit($term->limit)
            ->get(['id', 'name', 'phones'])
            ->map(fn (Party $party) => new RecordHit(
                $party->id,
                $party->name,
                $hides('phones') ? null : ($party->phones[0]['number'] ?? null),
                '/salon/clients/'.$party->id,
            ))
            ->all();
    }
}
