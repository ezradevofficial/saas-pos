<?php

namespace App\Core\Webhooks\Principals;

use App\Core\Identity\Models\User;
use App\Core\Rbac\FieldRules;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;

/** HOOK-01: a subscription that receives events as a user (its creator): their roles, scopes and field rules. */
final class UserPrincipal implements WebhookPrincipal
{
    public const TYPE = 'user';

    /** @var array<string, list<string>> */
    private array $hidden = [];

    public function __construct(public readonly User $user) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function id(): string
    {
        return $this->user->id;
    }

    public function name(): string
    {
        return (string) $this->user->name;
    }

    public function isActive(): bool
    {
        return $this->user->status === User::STATUS_ACTIVE;
    }

    public function can(string $permission, Scope $scope): bool
    {
        return $this->isActive() && app(ScopeResolver::class)->can($this->user, $permission, $scope);
    }

    public function hiddenFields(string $resource): array
    {
        return $this->hidden[$resource] ??= app(FieldRules::class)->for($this->user, $resource)['hidden'];
    }

    public function actingUser(): ?User
    {
        return $this->isActive() ? $this->user : null;
    }

    public function notifiableUserId(): ?string
    {
        return $this->isActive() ? $this->user->id : null;
    }

    public function envelope(): array
    {
        return [];
    }
}
