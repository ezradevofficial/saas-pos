<?php

namespace App\Core\Privacy\Http\Controllers;

use App\Core\Privacy\Consents;
use App\Core\Privacy\Http\Requests\ConsentRequest;
use App\Core\Privacy\Http\Requests\StoreConsentRequest;
use Illuminate\Http\JsonResponse;

/** NFR-08: Settings → Privacy, a customer's marketing consent: current state and history. */
class ConsentController
{
    public function __construct(private readonly Consents $consents) {}

    public function index(ConsentRequest $request): JsonResponse
    {
        if (! $request->validated('subject_id')) {
            return response()->json(['data' => ['current' => [], 'history' => []]]);
        }

        return response()->json(['data' => $this->consents->forSubject((string) $request->validated('subject_type'), (string) $request->validated('subject_id'))]);
    }

    public function store(StoreConsentRequest $request): JsonResponse
    {
        $record = $this->consents->record(
            $request->user(),
            (string) $request->validated('subject_type'),
            (string) $request->validated('subject_id'),
            (string) $request->validated('purpose'),
            (string) $request->validated('channel'),
            (bool) $request->validated('granted'),
            'back_office',
            $request->validated('evidence'),
            $request->validated('wording_version'),
        );
        $record->load('recorder');

        return response()->json(['data' => $this->consents->present($record)], 201);
    }
}
