<?php

namespace App\Core\Search\Http\Controllers;

use App\Core\Search\Http\Requests\SearchRequest;
use App\Core\Search\RecordHit;
use App\Core\Search\RecordSearch;
use Illuminate\Http\JsonResponse;

/** LAY-01: `GET search?q=&limit=`: the user's own records, grouped by type. */
class SearchController
{
    public function __construct(private readonly RecordSearch $search) {}

    public function index(SearchRequest $request): JsonResponse
    {
        $found = $this->search->search($request->user(), $request->term());

        return new JsonResponse([
            'data' => array_map(fn (array $group) => [
                'type' => $group['type'],
                'results' => array_map(fn (RecordHit $hit) => [
                    'type' => $group['type'],
                    'id' => $hit->id,
                    'title' => $hit->title,
                    'subtitle' => $hit->subtitle,
                    'path' => $hit->path,
                ], $group['results']),
            ], $found['groups']),
            'meta' => ['partial' => $found['partial']],
        ]);
    }
}
