<?php

namespace App\Core\MasterData\Duplicates;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Parties\PartyPolicy;
use Illuminate\Database\Eloquent\Builder;

/**
 * MD-06: likely duplicates of a party, as a warning that never blocks.
 * A candidate is an active party the user can view (PartyPolicy) sharing a
 * normalised phone number or the tax ID, or with a name at least 0.6
 * similar (pg_trgm `similarity()`). Strongest reason first (tax_id, phone,
 * name), then the most similar name; at most five.
 */
class DuplicateFinder
{
    public const LIMIT = 5;

    public const NAME_SIMILARITY = 0.6;

    private const REASONS = ['tax_id', 'phone', 'name'];

    public function __construct(private readonly PartyPolicy $policy) {}

    /** @return list<array{id: string, name: string, reason: string}> */
    public function forParty(Party $party, User $user): array
    {
        if (! $this->policy->viewAny($user)) {
            return [];
        }

        $phones = $party->phoneNumbers();
        $taxId = $party->tax_id;
        $bindings = [];
        $cases = [];

        if ($taxId !== null) {
            $cases[] = 'when tax_id = ? then 0';
            $bindings[] = $taxId;
        }

        if ($phones !== []) {
            $cases[] = 'when '.$this->phoneMatch($phones, $bindings).' then 1';
        }

        $cases[] = 'when similarity(name, ?) >= '.self::NAME_SIMILARITY.' then 2';
        $bindings[] = $party->name;

        $query = Party::query()
            ->select(['id', 'name'])
            ->selectRaw('case '.implode(' ', $cases).' end as reason_rank', $bindings)
            ->selectRaw('similarity(name, ?) as name_similarity', [$party->name])
            ->whereNull('archived_at')
            ->whereKeyNot($party->id)
            ->where(function (Builder $q) use ($taxId, $phones, $party) {
                if ($taxId !== null) {
                    $q->orWhere('tax_id', $taxId);
                }

                if ($phones !== []) {
                    $phoneBindings = [];
                    $q->orWhereRaw($this->phoneMatch($phones, $phoneBindings), $phoneBindings);
                }

                // `%` narrows with the trigram index (threshold 0.3); the
                // similarity floor then applies.
                $q->orWhere(fn (Builder $n) => $n->whereRaw('name % ?', [$party->name])
                    ->whereRaw('similarity(name, ?) >= '.self::NAME_SIMILARITY, [$party->name]));
            });

        $companies = $this->policy->listableCompanies($user);

        if ($companies !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('company_id')->orWhereIn('company_id', $companies));
        }

        return $query->orderBy('reason_rank')->orderByDesc('name_similarity')->orderBy('name')->orderBy('id')
            ->limit(self::LIMIT)->toBase()->get()
            ->map(fn (object $row) => ['id' => $row->id, 'name' => $row->name, 'reason' => self::REASONS[(int) $row->reason_rank]])
            ->all();
    }

    /**
     * `phones @> '[{"number": ...}]'` for each number, ORed (GIN jsonb_path_ops).
     *
     * @param  list<string>  $phones
     */
    private function phoneMatch(array $phones, array &$bindings): string
    {
        $parts = [];

        foreach ($phones as $number) {
            $parts[] = 'phones @> ?::jsonb';
            $bindings[] = json_encode([['number' => $number]]);
        }

        return '('.implode(' or ', $parts).')';
    }
}
