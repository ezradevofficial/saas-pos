<?php

namespace Tests\Unit\Core\Approvals;

use App\Core\Approvals\ApprovalModes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** APR-01: when any / all / majority steps are decided. */
class ApprovalModesTest extends TestCase
{
    /** @return array<string, array{string, int, int, int, ?string}> */
    public static function cases(): array
    {
        return [
            'any: undecided' => ['any', 0, 0, 3, null],
            'any: first approval' => ['any', 1, 0, 3, 'approved'],
            'any: first rejection' => ['any', 0, 1, 3, 'rejected'],
            'all: one of two' => ['all', 1, 0, 2, null],
            'all: every one' => ['all', 2, 0, 2, 'approved'],
            'all: one rejection' => ['all', 1, 1, 3, 'rejected'],
            'majority: 1 of 3' => ['majority', 1, 0, 3, null],
            'majority: 2 of 3' => ['majority', 2, 0, 3, 'approved'],
            'majority: 1 yes 1 no of 3' => ['majority', 1, 1, 3, null],
            'majority: 2 no of 3' => ['majority', 0, 2, 3, 'rejected'],
            'majority: 1 of 2' => ['majority', 1, 0, 2, null],
            'majority: 1 no of 2' => ['majority', 0, 1, 2, 'rejected'],
            'majority: 2 yes 2 no of 4' => ['majority', 2, 2, 4, 'rejected'],
            'majority: 3 of 4' => ['majority', 3, 0, 4, 'approved'],
            'single approver' => ['majority', 1, 0, 1, 'approved'],
        ];
    }

    #[DataProvider('cases')]
    public function test_outcome(string $mode, int $approved, int $rejected, int $total, ?string $expected): void
    {
        $this->assertSame($expected, ApprovalModes::outcome($mode, $approved, $rejected, $total));
    }

    public function test_an_escalated_decision_decides_alone(): void
    {
        $this->assertSame('approved', ApprovalModes::outcome('all', 0, 0, 3, 'approved'));
        $this->assertSame('rejected', ApprovalModes::outcome('majority', 2, 0, 3, 'rejected'));
    }
}
