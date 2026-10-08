<?php

namespace App\Core\MasterData\History\Http;

use App\Core\Audit\AuditEntry;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * MD-07: a record's change history, newest first, read from the audit log
 * (AUD-01) under RLS by the record's type and id. Each entry has the
 * action, the actor's name (null for the system), the values before and
 * after, and when it happened. Security events (`auth.*`: sign-ins, 2FA,
 * password resets) stay in the audit log, behind `core.audit.view`.
 */
class HistoryController
{
    public function __invoke(HistoryRequest $request): AnonymousResourceCollection
    {
        $record = $request->record();

        return HistoryEntryResource::collection(AuditEntry::query()
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where('audit_logs.auditable_type', $record->getMorphClass())
            ->where('audit_logs.auditable_id', (string) $record->getKey())
            ->where('audit_logs.module', '!=', 'auth')
            ->orderByDesc('audit_logs.seq')
            ->select(['audit_logs.id', 'audit_logs.action', 'audit_logs.user_id', 'audit_logs.before', 'audit_logs.after', 'audit_logs.occurred_at', 'users.name as actor_name'])
            ->paginate($request->perPage())
            ->withQueryString());
    }
}
