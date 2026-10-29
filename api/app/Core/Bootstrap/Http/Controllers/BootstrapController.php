<?php

namespace App\Core\Bootstrap\Http\Controllers;

use App\Core\Bootstrap\Http\Requests\BootstrapRequest;
use App\Core\Bootstrap\ShellBootstrap;
use Illuminate\Http\JsonResponse;

/** NFR-02: GET bootstrap, the app shell's payloads in one response. */
class BootstrapController
{
    public function __invoke(BootstrapRequest $request, ShellBootstrap $shell): JsonResponse
    {
        return new JsonResponse(['data' => $shell->parts($request, $request->place())]);
    }
}
