<?php

namespace App\Core\Automation\Chain;

use Illuminate\Support\Str;

/**
 * AUTO-06: where a change came from. A person's change starts a new chain
 * (depth 0, no rules); while a rule's actions run, the changes they make
 * carry the rule's chain: the same id, its depth, and every rule in the
 * chain so far. A rule triggered by such a change runs one level deeper.
 */
final class Cause
{
    /** @param list<string> $rules ids of the rules in the chain, oldest first */
    public function __construct(
        public readonly string $chainId,
        public readonly int $depth,
        public readonly array $rules,
    ) {}

    public static function root(): self
    {
        return new self((string) Str::uuid7(), 0, []);
    }

    public static function fromArray(?array $data): ?self
    {
        if ($data === null || ! is_string($data['chain_id'] ?? null) || ! Str::isUuid($data['chain_id'])) {
            return null;
        }

        return new self(
            $data['chain_id'],
            max(0, (int) ($data['depth'] ?? 0)),
            array_values(array_filter((array) ($data['rules'] ?? []), 'is_string')),
        );
    }

    /** The cause a rule's actions carry: one level deeper, with the rule added. */
    public function through(string $ruleId, int $depth): self
    {
        return new self($this->chainId, $depth, [...$this->rules, $ruleId]);
    }

    public function contains(string $ruleId): bool
    {
        return in_array($ruleId, $this->rules, true);
    }

    /** @return array{chain_id: string, depth: int, rules: list<string>} */
    public function toArray(): array
    {
        return ['chain_id' => $this->chainId, 'depth' => $this->depth, 'rules' => $this->rules];
    }
}
