<?php

namespace App\Core\Observability;

use JsonException;

/**
 * NFR-10: removes secrets and personal data from error reports and logs
 * before they leave the API. The PHP twin of `@app/scrub` (web and POS):
 * both read the same rules (packages/scrub/rules.json, copied to
 * config/scrub-rules.json because only api/ reaches the servers) and pass
 * the same fixtures (ScrubberTest).
 *
 * - A value under a sensitive key (password, token, pin, card, email,
 *   phone, ID number, address and so on) is replaced whole, unless it is
 *   null or a boolean.
 * - Every other string has secret- and PII-looking substrings replaced.
 * - Keys listed in `keep` (event ids, release, tenant_id, request_id) are
 *   left as they are.
 */
final class Scrubber
{
    private const MAX_DEPTH = 12;

    /** @var array{replacement: string, keys: list<list<string>>, values: list<array{name: string, pattern: string, flags: string, replace: string}>, keep: list<string>}|null */
    private static ?array $rules = null;

    /** @var list<array{0: string, 1: string}>|null compiled value patterns and replacements */
    private static ?array $patterns = null;

    /** @var array<string, bool> */
    private static array $sensitive = [];

    public static function rulesPath(): string
    {
        return config_path('scrub-rules.json');
    }

    /** @return array{replacement: string, keys: list<list<string>>, values: list<array{name: string, pattern: string, flags: string, replace: string}>, keep: list<string>} */
    public static function rules(): array
    {
        if (self::$rules === null) {
            try {
                self::$rules = json_decode((string) file_get_contents(self::rulesPath()), true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                // Fail closed: without rules, everything is redacted.
                self::$rules = ['replacement' => '[redacted]', 'keys' => [], 'values' => [['name' => 'all', 'pattern' => '^.*$', 'flags' => 's', 'replace' => '[redacted]']], 'keep' => []];
            }
        }

        return self::$rules;
    }

    /**
     * Words of a key: "apiKey", "API_KEY" and "x-api-key" all give [..., "api", "key"].
     *
     * @return list<string>
     */
    public static function keyWords(string $key): array
    {
        $key = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $key);
        $key = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $key);

        return preg_split('/[^a-z0-9]+/', strtolower($key), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public static function isSensitiveKey(string $key): bool
    {
        if (isset(self::$sensitive[$key])) {
            return self::$sensitive[$key];
        }

        $words = self::keyWords($key);
        $found = false;

        foreach (self::rules()['keys'] as $phrase) {
            $length = count($phrase);
            for ($i = 0; $i + $length <= count($words); $i++) {
                if (array_slice($words, $i, $length) === $phrase) {
                    $found = true;
                    break 2;
                }
            }
        }

        if (count(self::$sensitive) < 2000) {
            self::$sensitive[$key] = $found;
        }

        return $found;
    }

    public static function scrubString(string $value): string
    {
        foreach (self::patterns() as [$pattern, $replace]) {
            $value = (string) (preg_replace($pattern, $replace, $value) ?? '[redacted]');
        }

        return $value;
    }

    /** A scrubbed copy of any array, object or scalar (objects come back as arrays). */
    public static function scrub(mixed $value, int|string|null $key = null, int $depth = 0): mixed
    {
        if ($key !== null && in_array((string) $key, self::rules()['keep'], true)) {
            return $value;
        }

        if ($value === null || is_bool($value)) {
            return $value;
        }

        if (is_string($key) && self::isSensitiveKey($key)) {
            return self::rules()['replacement'];
        }

        if (is_string($value)) {
            return self::scrubString($value);
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if ($depth >= self::MAX_DEPTH) {
            return self::rules()['replacement'];
        }

        // An exception's message can carry SQL bindings or a provider's reply.
        if ($value instanceof \Throwable) {
            return [
                'class' => $value::class,
                'message' => self::scrubString($value->getMessage()),
                'code' => $value->getCode(),
                'file' => $value->getFile().':'.$value->getLine(),
                'trace' => self::scrubString($value->getTraceAsString()),
                'previous' => $value->getPrevious() === null ? null : self::scrub($value->getPrevious(), null, $depth + 1),
            ];
        }

        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        } elseif ($value instanceof \Stringable && ! is_iterable($value)) {
            return self::scrubString((string) $value);
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::scrub($v, $k, $depth + 1);
        }

        return $out;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function patterns(): array
    {
        if (self::$patterns === null) {
            self::$patterns = array_map(
                fn (array $rule) => ['#'.str_replace('#', '\#', $rule['pattern']).'#u'.$rule['flags'], $rule['replace']],
                self::rules()['values'],
            );
        }

        return self::$patterns;
    }

    /** Forget the loaded rules (tests that swap the rules file). */
    public static function flush(): void
    {
        self::$rules = null;
        self::$patterns = null;
        self::$sensitive = [];
    }
}
