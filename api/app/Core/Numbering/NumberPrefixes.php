<?php

namespace App\Core\Numbering;

use App\Core\Http\ApiException;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * NUM-01, M2: no two number sequences ever print the same number, even
 * after formats or codes change.
 *
 * - **History.** Every sequence records each concrete prefix it issues
 *   numbers under (number_prefixes): its pattern with the place codes
 *   filled in, and the year for a yearly format (`R-L01-{000001}`,
 *   `S-NBI-2026-{001}`). Numbering records it with each number or block.
 * - **Check.** A format save, or a branch, location or device code change,
 *   works out the prefixes the affected sequences would issue from now on
 *   (for every active place the format reaches) and refuses the change
 *   when one could print a number another sequence's history could have
 *   printed. A sequence's own history never blocks it: one counter never
 *   repeats a value, so a code changed back is fine.
 * - **Overlap** is decided on the prefixes' shapes: their non-digit
 *   characters must match one for one, and each run of digits between
 *   them must admit a common digit string (date tokens are any digits; a
 *   counter is any digits at least its width long). This never misses a
 *   collision; it may refuse a change whose counters would in fact never
 *   meet (it does not reason about counter values), which is the safe side.
 * - A yearly format's future years are checked with the year left open,
 *   against every other format's history.
 */
final class NumberPrefixes
{
    public function __construct(private readonly Numbering $numbering) {}

    /** The concrete prefix: the pattern with place codes and, for a yearly sequence, its year filled in. */
    public static function concrete(Pattern $pattern, array $placeValues, ?string $year): string
    {
        if ($year !== null) {
            $placeValues += ['YYYY' => $year, 'YY' => substr($year, 2)];
        }

        return $pattern->with($placeValues)->pattern;
    }

    /** Remember that $sequenceId issued numbers under $prefix (once). */
    public static function record(string $sequenceId, string $prefix): void
    {
        DB::connection(TenantContext::CONNECTION)->insert(
            'insert into number_prefixes (id, number_sequence_id, prefix) values (?, ?, ?) on conflict (number_sequence_id, prefix) do nothing',
            [(string) Str::uuid7(), $sequenceId, $prefix],
        );
    }

    /**
     * A format about to take $pattern and $reset: refused when one of its
     * sequences (its own, or the ones a new format copies from $inherited)
     * would print in another sequence's prefix space.
     */
    public function assertFormat(NumberFormat $format, string $pattern, string $reset, ?NumberFormat $inherited): void
    {
        $parsed = Pattern::parse($pattern);
        $formats = NumberFormat::query()->where('document_type', $format->document_type)->get();
        $tuples = $this->tuples($parsed, $this->branchesUsing($format, $formats));
        $sequences = $format->exists
            ? $format->sequences()->get(['id', 'period'])->map(fn (NumberSequence $s) => ['id' => (string) $s->id, 'period' => $s->period])->all()
            : ($inherited?->sequences()->pluck('period')->map(fn (string $period) => ['id' => null, 'period' => $period])->all() ?? []);

        if ($this->collides($format->document_type, $parsed, $reset, $tuples, $sequences)) {
            $message = __('core.numbering.errors.prefix_used');

            throw new ApiException(422, 'numbering_prefix_used', $message, errors: ['pattern' => [$message]]);
        }
    }

    /**
     * A branch, location or device whose code is about to change (the
     * model holds the new code): refused when a sequence that prints that
     * code would print in another sequence's prefix space.
     */
    public function assertPlaceCode(Branch|Location|Device $place): void
    {
        [$branch, $token] = match (true) {
            $place instanceof Branch => [$place, 'BRANCH'],
            $place instanceof Location => [$place->branch()->first(), 'LOCATION'],
            default => [$place->location()->first()?->branch()->first(), 'DEVICE'],
        };

        if ($branch === null) {
            return;
        }

        foreach (NumberFormat::query()->distinct()->pluck('document_type') as $type) {
            $format = $this->numbering->formatAt($type, $branch->company_id, $branch->exists ? $branch->id : null);
            $parsed = $format?->parsed();

            if ($format === null || ! $parsed->uses($token)) {
                continue;
            }

            $tuples = $this->tuples($parsed, collect([$branch]), $place instanceof Location ? $place : null, $place instanceof Device ? $place : null);
            $sequences = $format->sequences()->get(['id', 'period'])->map(fn (NumberSequence $s) => ['id' => (string) $s->id, 'period' => $s->period])->all();

            if ($this->collides($type, $parsed, $format->reset, $tuples, $sequences)) {
                $message = __('core.numbering.errors.code_prefix_used');

                throw new ApiException(422, 'numbering_prefix_used', $message, errors: ['code' => [$message]]);
            }
        }
    }

    /**
     * @param  list<array<string, string>>  $tuples  place values for each place the sequences serve
     * @param  list<array{id: ?string, period: string}>  $sequences
     */
    private function collides(string $type, Pattern $pattern, string $reset, array $tuples, array $sequences): bool
    {
        $yearly = $reset === NumberFormat::RESET_YEARLY;
        $own = array_values(array_filter(array_column($sequences, 'id')));
        // Each existing period with its own year; the years to come with the year left open.
        $periods = array_map(fn (array $s) => ['year' => $yearly && $s['period'] !== NumberSequence::ALL ? $s['period'] : null, 'except' => array_filter([$s['id']])], $sequences);

        if ($yearly || $sequences === []) {
            $periods[] = ['year' => null, 'except' => $own];
        }

        $history = $this->history($type);

        foreach ($tuples as $values) {
            foreach ($periods as $period) {
                $shape = self::shape(self::concrete($pattern, $values, $period['year']));

                foreach ($history[$shape['key']] ?? [] as $used) {
                    if (! in_array($used['sequence'], $period['except'], true) && self::runsOverlap($shape['runs'], $used['runs'])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @return array<string, list<array{sequence: string, runs: list<list<array{0: string, 1: string|int}>>}>> the type's history by shape key */
    private function history(string $type): array
    {
        $rows = DB::connection(TenantContext::CONNECTION)->select(
            'select p.number_sequence_id, p.prefix from number_prefixes p
             join number_sequences s on s.id = p.number_sequence_id
             join number_formats f on f.id = s.number_format_id
             where f.document_type = ?',
            [$type],
        );
        $history = [];

        foreach ($rows as $row) {
            $shape = self::shape($row->prefix);
            $history[$shape['key']][] = ['sequence' => (string) $row->number_sequence_id, 'runs' => $shape['runs']];
        }

        return $history;
    }

    /**
     * The active branches whose documents take $format (the most specific
     * format applies).
     *
     * @param  Collection<int, NumberFormat>  $formats  every format of the type
     * @return Collection<int, Branch>
     */
    private function branchesUsing(NumberFormat $format, Collection $formats): Collection
    {
        $withOwn = $formats->whereNotNull('branch_id')->pluck('branch_id')->all();
        $companiesWithOwn = $formats->whereNotNull('company_id')->whereNull('branch_id')->pluck('company_id')->all();

        return Branch::query()->whereNull('archived_at')
            ->when($format->branch_id !== null, fn ($q) => $q->whereKey($format->branch_id))
            ->when($format->branch_id === null, fn ($q) => $q->whereNotIn('id', $withOwn))
            ->when($format->branch_id === null && $format->company_id !== null, fn ($q) => $q->where('company_id', $format->company_id))
            ->when($format->company_id === null, fn ($q) => $q->whereNotIn('company_id', $companiesWithOwn))
            ->get();
    }

    /**
     * The place values the pattern prints, one set per place it can be
     * printed for: every active location (and device) of $branches that
     * the pattern names, or only $location or $device when given (with
     * their new codes).
     *
     * @param  Collection<int, Branch>  $branches
     * @return list<array<string, string>>
     */
    private function tuples(Pattern $pattern, Collection $branches, ?Location $location = null, ?Device $device = null): array
    {
        $usesLocation = $pattern->uses('LOCATION') || $pattern->uses('DEVICE');
        $tuples = [];

        if (array_intersect(Pattern::PLACE_TOKENS, $pattern->tokens) === []) {
            return [[]];
        }

        foreach ($branches as $branch) {
            if (! $usesLocation) {
                $tuples[] = $this->values($pattern, $branch, null, null);

                continue;
            }

            $locations = match (true) {
                $device !== null => $device->location()->get(),
                $location !== null => collect([$location]),
                $branch->exists => $branch->locations()->whereNull('archived_at')->get(),
                default => collect(),
            };

            foreach ($locations as $place) {
                if (! $pattern->uses('DEVICE')) {
                    $tuples[] = $this->values($pattern, $branch, $place, null);

                    continue;
                }

                $devices = $device !== null ? collect([$device]) : ($place->exists ? $place->devices()->get() : collect());

                foreach ($devices as $till) {
                    $tuples[] = $this->values($pattern, $branch, $place, $till);
                }
            }
        }

        return array_values(array_unique($tuples, SORT_REGULAR));
    }

    /** @return array<string, string> the codes the pattern prints for this place (NumberContext's rules) */
    private function values(Pattern $pattern, Branch $branch, ?Location $location, ?Device $device): array
    {
        $values = ['BRANCH' => (string) $branch->code];

        if ($location !== null) {
            $values['LOCATION'] = $location->code ?? NumberContext::fallback((string) $location->id);
        }

        if ($device !== null) {
            $values['DEVICE'] = $device->code ?? NumberContext::fallback((string) $device->id);
        }

        return array_intersect_key($values, array_flip($pattern->tokens));
    }

    /**
     * A concrete prefix's shape: its non-digit characters with a marker
     * where a run of digits stands (the key), and each run's parts: a
     * digit (`d`), any digits of a fixed length (`any`, date tokens), or
     * the counter (`ctr`, any digits at least its width long).
     *
     * @return array{key: string, runs: list<list<array{0: string, 1: string|int}>>}
     */
    private static function shape(string $prefix): array
    {
        $parts = preg_split('/(\{[A-Z]+\}|\{0{0,11}1\})/', $prefix, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $key = '';
        $runs = [];
        $run = [];

        $close = function () use (&$key, &$runs, &$run): void {
            if ($run !== []) {
                $key .= '#';
                $runs[] = $run;
                $run = [];
            }
        };

        foreach ($parts as $part) {
            if (preg_match('/^\{(0{0,11}1)\}$/', $part, $m) === 1) {
                $run[] = ['ctr', strlen($m[1])];
            } elseif (preg_match('/^\{([A-Z]+)\}$/', $part, $m) === 1) {
                $run[] = match ($m[1]) {
                    'YYYY' => ['any', 4],
                    'YY', 'MM' => ['any', 2],
                    default => throw new LogicException("A prefix keeps no place token ({$m[1]})."),
                };
            } else {
                foreach (str_split($part) as $char) {
                    if (ctype_digit($char)) {
                        $run[] = ['d', $char];
                    } else {
                        $close();
                        $key .= $char;
                    }
                }
            }
        }

        $close();

        return ['key' => $key, 'runs' => $runs];
    }

    /**
     * @param  list<list<array{0: string, 1: string|int}>>  $a
     * @param  list<list<array{0: string, 1: string|int}>>  $b
     */
    private static function runsOverlap(array $a, array $b): bool
    {
        foreach ($a as $index => $run) {
            if (! self::runOverlaps($run, $b[$index])) {
                return false;
            }
        }

        return true;
    }

    /** Whether some string of digits fits both runs. */
    private static function runOverlaps(array $a, array $b): bool
    {
        [$minA, $varA] = self::length($a);
        [$minB, $varB] = self::length($b);
        $from = max($minA, $minB);
        // Past this length only the counters grow: nothing new can match.
        $to = match (true) {
            ! $varA && ! $varB => $minA === $minB ? $minA : -1,
            ! $varA => $minA >= $minB ? $minA : -1,
            ! $varB => $minB >= $minA ? $minB : -1,
            default => $from + $minA + $minB + 1,
        };

        for ($length = $from; $length <= $to; $length++) {
            $x = self::layout($a, $length);
            $y = self::layout($b, $length);

            $fits = true;

            foreach ($x as $i => $char) {
                if ($char !== '?' && $y[$i] !== '?' && $char !== $y[$i]) {
                    $fits = false;

                    break;
                }
            }

            if ($fits) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: int, 1: bool} the shortest length, and whether it can grow (a counter) */
    private static function length(array $run): array
    {
        $min = 0;
        $variable = false;

        foreach ($run as [$kind, $value]) {
            $min += $kind === 'd' ? 1 : (int) $value;
            $variable = $variable || $kind === 'ctr';
        }

        return [$min, $variable];
    }

    /** @return list<string> the run laid out over $length digits: a digit, or `?` for any */
    private static function layout(array $run, int $length): array
    {
        $before = [];
        $after = [];
        $counter = false;

        foreach ($run as [$kind, $value]) {
            if ($kind === 'ctr') {
                $counter = true;

                continue;
            }

            $chars = $kind === 'd' ? [(string) $value] : array_fill(0, (int) $value, '?');
            $counter ? array_push($after, ...$chars) : array_push($before, ...$chars);
        }

        $middle = $counter ? array_fill(0, $length - count($before) - count($after), '?') : [];

        return [...$before, ...$middle, ...$after];
    }
}
