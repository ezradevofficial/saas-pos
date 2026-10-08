<?php

namespace App\Core\Identity\Pin\Http\Requests;

use App\Core\Identity\Pin\PinRules;
use Closure;
use Illuminate\Validation\ValidationException;

/** AUTH-06: validation of a new PIN and staff card code (PinRules). */
trait ValidatesNewPin
{
    /** @return array<string, list<mixed>> */
    protected function newPinRules(): array
    {
        return [
            'pin' => ['required', 'string', 'max:6', $this->check(fn (string $value) => PinRules::assertPin($value))],
            // A code sets the card, null clears it, absent keeps it.
            'card' => ['sometimes', 'nullable', 'string', 'max:64', $this->check(fn (string $value) => PinRules::assertCard($value))],
        ];
    }

    /** The card as Pins::set takes it: a code, '' to clear, null to keep. */
    public function cardInput(): ?string
    {
        if (! $this->has('card')) {
            return null;
        }

        return $this->validated('card') ?? '';
    }

    private function check(callable $assert): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($assert) {
            if (! is_string($value)) {
                return;
            }

            try {
                $assert($value);
            } catch (ValidationException $e) {
                $fail(collect($e->errors())->flatten()->first());
            }
        };
    }
}
