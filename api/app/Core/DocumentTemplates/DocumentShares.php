<?php

namespace App\Core\DocumentTemplates;

use App\Core\Audit\Auditor;
use App\Core\DocumentTemplates\Models\DocumentShare;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TPL-04: public links to one document (WhatsApp share).
 *
 * - The token is 48 random characters, stored only as its sha256; the
 *   URL is `/d/{token}`, with no ids in it.
 * - A link expires after DocumentShare::DAYS days and can be revoked.
 * - The public route finds the tenant of a token hash through the
 *   security-definer function `document_share_tenant` (ADR 002), enters
 *   that tenant, and reads the share under row-level security.
 * - Creating, opening and revoking are audited (`core.document.share_*`);
 *   opens are also counted on the share. The token never reaches the log.
 */
class DocumentShares
{
    public const LENGTH = 48;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly TenantContext $tenants,
    ) {}

    /** @return array{0: DocumentShare, 1: string} the share and its token (shown once) */
    public function create(DocumentData $document, ?string $locationId, User $by): array
    {
        $token = Str::random(self::LENGTH);

        $share = DocumentShare::create([
            'document_type' => $document->type,
            'record_id' => $document->recordId,
            'company_id' => $document->companyId,
            'branch_id' => $document->branchId,
            'location_id' => $locationId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(DocumentShare::DAYS),
            'created_by' => $by->id,
        ]);

        $this->auditor->record('core.document.share_create', $share, null, [
            'document_type' => $document->type, 'record_id' => $document->recordId, 'number' => $document->number(), 'expires_at' => $share->expires_at->toIso8601String(),
        ]);

        return [$share, $token];
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/d/'.$token;
    }

    /**
     * The share a public token names, with its tenant entered; null for an
     * unknown or malformed token. Expired and revoked shares are returned
     * (the caller refuses them).
     */
    public function resolve(string $token): ?DocumentShare
    {
        if (preg_match('/^[A-Za-z0-9]{'.self::LENGTH.'}$/', $token) !== 1) {
            return null;
        }

        $hash = hash('sha256', $token);
        $tenantId = DB::selectOne('select document_share_tenant(?) as tenant_id', [$hash])?->tenant_id;

        if ($tenantId === null) {
            return null;
        }

        $this->tenants->set($tenantId);

        return DocumentShare::query()->where('token_hash', $hash)->first();
    }

    /** Counts and audits an open of an active share (no user: the public opened it). */
    public function recordOpen(DocumentShare $share, ?string $ip, ?string $userAgent): void
    {
        DocumentShare::query()->whereKey($share->id)->update([
            'access_count' => DB::raw('access_count + 1'),
            'last_accessed_at' => now(),
        ]);

        $this->auditor->record('core.document.share_open', $share, null, [
            'document_type' => $share->document_type,
            'record_id' => $share->record_id,
            'ip' => $ip,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 200),
        ]);
    }

    public function revoke(DocumentShare $share, User $by): DocumentShare
    {
        if ($share->revoked_at === null) {
            $share->forceFill(['revoked_at' => now(), 'revoked_by' => $by->id])->save();
            $this->auditor->record('core.document.share_revoke', $share, ['revoked_at' => null], ['revoked_at' => $share->revoked_at->toIso8601String()]);
        }

        return $share;
    }
}
