<?php

namespace App\Core\Approvals\Resolvers;

use App\Core\Approvals\ApprovalConfig;
use App\Core\Identity\Models\User;
use App\Core\Workflow\DocumentTypes\DocumentType;

/** APR-02 `user` (`user_id`): that user of the tenant, while active. */
class UserResolver implements ApproverResolver
{
    public function key(): string
    {
        return 'user';
    }

    public function label(): string
    {
        return 'approvals.approver_types.user';
    }

    public function params(): array
    {
        return [['name' => 'user_id', 'type' => 'user', 'required' => true]];
    }

    public function validate(array $approver, DocumentType $type): array
    {
        return ApprovalConfig::knownUser($approver['user_id'] ?? null) ? [] : [__('approvals.validation.user')];
    }

    public function resolve(array $approver, ApprovalSubject $subject): array
    {
        return ApprovalConfig::knownUser($approver['user_id'] ?? null) ? [(string) $approver['user_id']] : [];
    }

    public function describe(array $approver): string
    {
        $name = ApprovalConfig::knownUser($approver['user_id'] ?? null) ? User::query()->whereKey($approver['user_id'])->value('name') : null;

        return $name === null ? __('approvals.approver_types.user') : (string) $name;
    }
}
