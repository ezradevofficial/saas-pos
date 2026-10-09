<?php

namespace App\Core\Configuration\Http\Requests;

use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\ConfigPolicy;
use Closure;

/**
 * LAY-06: POST config/{kind} {key?, scope_type, scope_id, name?, payload}:
 * save the draft of the document for that key and scope, creating the
 * document when it does not exist yet. `key` defaults to `default`.
 * Needs the kind's edit permission at the scope (ConfigPolicy).
 */
class SaveConfigRequest extends ConfigKindRequest
{
    use ValidatesConfigScope;

    public function rules(): array
    {
        $kind = $this->kind();

        return [
            'key' => ['sometimes', 'string', 'max:100', $this->keyRule($kind)],
            ...$this->scopeRules($kind, $kind->scopes),
            'name' => ['sometimes', 'nullable', 'string', 'max:150'],
            ...self::payloadRules($kind),
        ];
    }

    public function key(): string
    {
        return $this->validated('key', ConfigKind::DEFAULT_KEY);
    }

    /** The payload as sent (validated() keeps only the keys named in rules()). */
    public function payload(): array
    {
        return (array) $this->input('payload');
    }

    /** Called after validation: saving needs edit rights at the scope. */
    public function authorizeTarget(): void
    {
        abort_unless(app(ConfigPolicy::class)->allows($this->user(), $this->kind(), 'edit', $this->validated('scope_type'), $this->scopeId()), 403);
    }

    /** @return array<string, list<mixed>> */
    public static function payloadRules(ConfigKind $kind): array
    {
        return [
            'payload' => ['present', 'array', function (string $attribute, mixed $value, Closure $fail) use ($kind) {
                if (strlen((string) json_encode($value)) > $kind->maxBytes) {
                    $fail(__('config.errors.payload_too_large', ['kb' => intdiv($kind->maxBytes, 1024)]));
                }
            }],
        ];
    }

    private function keyRule(ConfigKind $kind): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($kind) {
            if (! is_string($value) || ! $kind->acceptsKey($value)) {
                $fail(__('config.errors.unknown_key'));
            }
        };
    }
}
