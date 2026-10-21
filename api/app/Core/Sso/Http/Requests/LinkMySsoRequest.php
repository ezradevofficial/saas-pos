<?php

namespace App\Core\Sso\Http\Requests;

use App\Core\Sso\Models\SsoConnection;

/** AUTH-11, ADR 023: link one's own account to an enabled connection (POST me/sso/link). */
class LinkMySsoRequest extends MySsoRequest
{
    public function rules(): array
    {
        return [
            'connection_id' => ['required', 'uuid'],
            'origin' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** An enabled connection of the tenant (404 otherwise). */
    public function connection(): SsoConnection
    {
        $connection = SsoConnection::query()->whereKey($this->validated('connection_id'))->first();
        abort_if($connection === null || ! $connection->isLive(), 404);

        return $connection;
    }
}
