<?php

namespace App\Core\MasterData\History\Http;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Support\TextArray;
use App\Core\Rbac\FieldRules;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * MD-07: a record's change history, newest first, read from the audit log
 * (AUD-01) under RLS by the record's type and id. Each entry has the
 * action, the actor's name (null for the system), the values before and
 * after, and when it happened. Security events (`auth.*`: sign-ins, 2FA,
 * password resets) stay in the audit log, behind `core.audit.view`.
 * Fields hidden from the user by field rules (RBAC-05) are removed from
 * before and after, and entries that only changed them are left out.
 */
class HistoryController
{
    public function __invoke(HistoryRequest $request, FieldRules $fieldRules): AnonymousResourceCollection
    {
        $record = $request->record();
        $resource = $request->fieldRulesResource();
        $hidden = $resource === null ? [] : $fieldRules->for($request->user(), $resource)['hidden'];

        $query = AuditEntry::query()
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where('audit_logs.auditable_type', $record->getMorphClass())
            ->where('audit_logs.auditable_id', (string) $record->getKey())
            ->where('audit_logs.module', '!=', 'auth')
            ->orderByDesc('audit_logs.seq')
            ->select(['audit_logs.id', 'audit_logs.action', 'audit_logs.user_id', 'audit_logs.before', 'audit_logs.after', 'audit_logs.occurred_at', 'users.name as actor_name']);

        // RBAC-05: an entry that only touched hidden fields is left out (in
        // SQL, so pages stay full); entries that never had values stay.
        if ($hidden !== []) {
            $keys = TextArray::format($hidden);
            $query->whereRaw(
                "((coalesce(audit_logs.before, '{}'::jsonb) - ?::text[]) <> '{}'::jsonb
                or (coalesce(audit_logs.after, '{}'::jsonb) - ?::text[]) <> '{}'::jsonb
                or (coalesce(audit_logs.before, '{}'::jsonb) = '{}'::jsonb and coalesce(audit_logs.after, '{}'::jsonb) = '{}'::jsonb))",
                [$keys, $keys],
            );
        }

        $page = $query->paginate($request->perPage())->withQueryString();
        $page->getCollection()->each(fn (AuditEntry $entry) => $entry->hiddenFields = $hidden);

        return HistoryEntryResource::collection($page);
    }
}
