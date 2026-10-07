<?php

namespace Tests\Support;

/**
 * One tenant built by TwoTenants: its ids by type, the bearer tokens of its
 * Owner, branch manager and paired device, and its users' contacts.
 */
final class TenantFixture
{
    /**
     * @param  array<string, string>  $ids  company, branch, location, device, user, manager, role, invitation, assignment, session, ...
     * @param  array{owner: string, manager: string, device: string}  $tokens
     * @param  list<string>  $contacts  emails and phone numbers of the tenant's users and invitees
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly array $ids,
        public readonly array $tokens,
        public readonly array $contacts,
    ) {}

    public function id(string $type): string
    {
        return $this->ids[$type] ?? throw new \InvalidArgumentException("No [{$type}] id in the fixture.");
    }

    /** @return array<string, string> */
    public function bearer(string $who = 'owner'): array
    {
        return ['Authorization' => 'Bearer '.$this->tokens[$who], 'Accept' => 'application/json'];
    }
}
