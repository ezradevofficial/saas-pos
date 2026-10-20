<?php

namespace App\Core\Webhooks\Principals;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;

/**
 * HOOK-01: who a webhook subscription receives events as. Deliveries carry
 * only what this principal may see: events it holds the permission for at
 * the event's scope (RBAC-04), without the fields its rules hide (RBAC-05).
 *
 * Core implements `user` (UserPrincipal). The public API registers `api_key`
 * (a service principal with a role assignment and scopes) through
 * WebhookPrincipals::register(), so webhooks never depend on it.
 */
interface WebhookPrincipal
{
    /** `user`, `api_key`, ... (webhook_subscriptions.principal_type) */
    public function type(): string;

    public function id(): string;

    /** A name for the settings page and notices. */
    public function name(): string;

    /** A deactivated user or revoked key receives nothing. */
    public function isActive(): bool;

    public function can(string $permission, Scope $scope): bool;

    /** @return list<string> the fields of $resource this principal's field rules hide */
    public function hiddenFields(string $resource): array;

    /** The user to check a document's own visibility for (DocumentType::sees), if any. */
    public function actingUser(): ?User;

    /** The user to tell when the subscription is switched off, if any. */
    public function notifiableUserId(): ?string;
}
