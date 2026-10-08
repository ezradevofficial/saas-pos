<?php

namespace Tests\Unit\Core\Sync;

use App\Core\Http\ApiException;
use App\Core\Identity\Pin\PinRules;
use App\Core\Sync\SyncCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** AUTH-06 PIN shape and weak PINs; NFR-04 cursor encoding. */
class PinRulesAndCursorTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function weakPins(): array
    {
        return array_combine($pins = ['0000', '1111', '999999', '1234', '4321', '0123', '345678', '987654', '1212', '4545', '121212', '123123', '2580', '0852', '1122', '2000'], array_map(fn ($p) => [$p], $pins));
    }

    #[DataProvider('weakPins')]
    public function test_weak_pins_are_refused(string $pin): void
    {
        $this->assertTrue(PinRules::isWeak($pin));
    }

    public function test_ordinary_pins_pass_and_the_shape_is_checked(): void
    {
        foreach (['4826', '7391', '50817', '931604', '1357', '8024'] as $pin) {
            $this->assertFalse(PinRules::isWeak($pin), $pin);
            PinRules::assertPin($pin);
        }

        foreach (['123', '1234567', '12a4', ' 4826', '४८२६'] as $bad) {
            try {
                PinRules::assertPin($bad);
                $this->fail("{$bad} accepted");
            } catch (\Illuminate\Validation\ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cursors_round_trip_and_garbage_is_refused(): void
    {
        $cursor = SyncCursor::at(1, 9_000_000_123, 42);
        $back = SyncCursor::decode('items', $cursor->encode());
        $this->assertSame([1, 9_000_000_123, 42, 'i'], [$back->version, $back->xid, $back->seq, $back->kind]);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $cursor->encode());

        $snapshot = SyncCursor::decode('staff', SyncCursor::snapshot(2, str_repeat('ab', 32))->encode());
        $this->assertSame(['s', 2, str_repeat('ab', 32)], [$snapshot->kind, $snapshot->version, $snapshot->hash]);

        foreach (['', 'x', base64_encode('i1.1.1'), base64_encode('i1.1.99999999999999999999.1'), base64_encode('s1.1.nothex')] as $bad) {
            try {
                SyncCursor::decode('items', $bad);
                $this->fail("{$bad} accepted");
            } catch (ApiException $e) {
                $this->assertSame('invalid_cursor', $e->errorCode);
            }
        }
    }
}
