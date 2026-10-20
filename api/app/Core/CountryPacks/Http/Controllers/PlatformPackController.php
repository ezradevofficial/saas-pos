<?php

namespace App\Core\CountryPacks\Http\Controllers;

use App\Core\CountryPacks\Http\Requests\DecidePackReleaseRequest;
use App\Core\CountryPacks\Http\Requests\LoadPackReleaseRequest;
use App\Core\CountryPacks\Http\Requests\PackReleaseRequest;
use App\Core\CountryPacks\Http\Requests\SupersedePackReleaseRequest;
use App\Core\CountryPacks\Models\CountryPackRelease;
use App\Core\CountryPacks\PackDocuments;
use App\Core\CountryPacks\PackReleases;
use App\Core\Platform\Models\PlatformUser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * ADM-04: country pack management in the console: releases, the pack in
 * force, load (validated and diffed), approve, reject and publish.
 */
class PlatformPackController
{
    public function __construct(
        private readonly PackReleases $releases,
        private readonly PackDocuments $documents,
    ) {}

    public function index(PackReleaseRequest $request): JsonResponse
    {
        $rows = CountryPackRelease::query()
            ->when($request->validated('country'), fn ($q, $country) => $q->where('country', $country))
            ->when($request->validated('kind'), fn ($q, $kind) => $q->where('kind', $kind))
            ->orderByDesc('created_at')->limit(200)->get();
        $names = $this->names($rows->all());

        return response()->json(['data' => $rows->map(fn (CountryPackRelease $r) => self::present($r, $names, withContent: false))->all()]);
    }

    /** What each country and kind reads now: a release (and its version) or the shipped file. */
    public function current(PackReleaseRequest $request): JsonResponse
    {
        $rows = [];

        foreach (['KE', 'CD'] as $country) {
            foreach (array_keys(PackDocuments::KINDS) as $kind) {
                $current = $this->documents->current($country, $kind);
                $rows[] = ['country' => $country, 'kind' => $kind, ...array_diff_key($current, ['content' => true])];
            }
        }

        return response()->json(['data' => $rows]);
    }

    public function show(PackReleaseRequest $request, string $release): JsonResponse
    {
        $row = CountryPackRelease::query()->findOrFail($release);

        return response()->json(['data' => self::present($row, $this->names([$row]), withContent: true)]);
    }

    public function store(LoadPackReleaseRequest $request): JsonResponse
    {
        $release = $this->releases->load(
            (string) $request->validated('country'),
            (string) $request->validated('kind'),
            (array) $request->validated('content'),
            CarbonImmutable::parse((string) $request->validated('effective_from'), 'UTC'),
            $request->validated('notes'),
            $request->staff(),
        );

        return response()->json(['data' => self::present($release, $this->names([$release]), withContent: false)], 201);
    }

    /** Review L7: a draft that puts back what was in force before $release (approve and publish as usual). */
    public function supersede(SupersedePackReleaseRequest $request, string $release): JsonResponse
    {
        $row = $this->releases->supersede(CountryPackRelease::query()->findOrFail($release), $request->staff());

        return response()->json(['data' => self::present($row, $this->names([$row]), withContent: false)], 201);
    }

    public function approve(DecidePackReleaseRequest $request, string $release): JsonResponse
    {
        $row = $this->releases->approve(CountryPackRelease::query()->findOrFail($release), $request->staff());

        return response()->json(['data' => self::present($row, $this->names([$row]), withContent: false)]);
    }

    public function reject(DecidePackReleaseRequest $request, string $release): JsonResponse
    {
        $row = $this->releases->reject(CountryPackRelease::query()->findOrFail($release), $request->staff(), $request->validated('note'));

        return response()->json(['data' => self::present($row, $this->names([$row]), withContent: false)]);
    }

    public function publish(DecidePackReleaseRequest $request, string $release): JsonResponse
    {
        $row = $this->releases->publish(CountryPackRelease::query()->findOrFail($release), $request->staff());

        return response()->json(['data' => self::present($row, $this->names([$row]), withContent: false)]);
    }

    /**
     * @param  list<CountryPackRelease>  $rows
     * @return array<string, string>
     */
    private function names(array $rows): array
    {
        $ids = collect($rows)->flatMap(fn (CountryPackRelease $r) => [$r->loaded_by, $r->approved_by, $r->published_by, $r->rejected_by])->filter()->unique()->values()->all();

        return $ids === [] ? [] : PlatformUser::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    public static function present(CountryPackRelease $r, array $names, bool $withContent): array
    {
        $person = fn (?string $id, $at = null) => $id === null ? null : ['id' => $id, 'name' => $names[$id] ?? null, 'at' => $at?->toIso8601String()];

        return [
            'id' => $r->id,
            'country' => $r->country,
            'kind' => $r->kind,
            'version' => $r->version,
            'status' => $r->status,
            'effective_from' => $r->effective_from->toDateString(),
            'notes' => $r->notes,
            'validation' => $r->validation,
            'diff' => $r->diff,
            'content_hash' => $r->content_hash,
            'loaded_by' => $person($r->loaded_by, $r->created_at),
            'approved_by' => $person($r->approved_by, $r->approved_at),
            'published_by' => $person($r->published_by, $r->published_at),
            'rejected_by' => $person($r->rejected_by, $r->rejected_at),
            'rejection_note' => $r->rejection_note,
            'applied_at' => $r->applied_at?->toIso8601String(),
            ...($withContent ? ['content' => $r->content] : []),
        ];
    }
}
