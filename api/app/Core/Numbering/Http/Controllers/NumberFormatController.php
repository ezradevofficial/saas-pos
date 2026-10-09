<?php

namespace App\Core\Numbering\Http\Controllers;

use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Numbering\Http\Requests\ListNumberFormatsRequest;
use App\Core\Numbering\Http\Requests\SaveNumberFormatRequest;
use App\Core\Numbering\Http\Resources\NumberFormatResource;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\NumberFormats;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * NUM-01: numbered document types of the tenant's active modules, each
 * with its default and the formats set for the tenant, its companies and
 * branches (those the user reaches with `core.numbering.view`), and
 * setting one.
 */
class NumberFormatController
{
    public function __construct(
        private readonly DocumentNumberTypes $types,
        private readonly NumberFormats $formats,
        private readonly ScopeResolver $resolver,
    ) {}

    public function index(ListNumberFormatsRequest $request): JsonResponse
    {
        $visible = $this->resolver->visibleIds($request->user(), 'core.numbering.view');
        $types = $this->types->active();

        $formats = NumberFormat::query()
            ->whereIn('document_type', array_map(fn (DocumentNumberType $type) => $type->key, $types))
            ->when(! $visible->all, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereNull('company_id')
                ->orWhere(fn (Builder $c) => $c->whereNull('branch_id')->whereIn('company_id', $visible->companiesTouched() ?? []))
                ->orWhereIn('branch_id', $visible->branchIds)))
            ->orderBy('document_type')->orderByRaw('company_id nulls first')->orderByRaw('branch_id nulls first')->orderBy('id')
            ->get()
            ->groupBy('document_type');

        return response()->json(['data' => array_map(fn (DocumentNumberType $type) => [
            'document_type' => $type->key,
            'name' => $type->name(),
            'place_tokens' => $type->placeTokens,
            'ranged' => $type->ranged,
            'default' => ['pattern' => $type->defaultPattern, 'reset' => $type->defaultReset],
            'formats' => NumberFormatResource::collection($formats->get($type->key, collect()))->resolve($request),
        ], $types)]);
    }

    /** PUT sets the format at its scope, created or replaced: 200 either way. */
    public function save(SaveNumberFormatRequest $request): JsonResponse
    {
        $request->authorizedScope();

        return NumberFormatResource::make($this->formats->save($request->numberFormat()))->response()->setStatusCode(200);
    }
}
