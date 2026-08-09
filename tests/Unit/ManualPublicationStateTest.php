<?php

namespace Tests\Unit;

use App\Models\ManualPublication;
use PHPUnit\Framework\TestCase;

class ManualPublicationStateTest extends TestCase
{
    public function test_status_machine_exposes_only_supported_next_states(): void
    {
        $this->assertSame(
            [ManualPublication::STATUS_READY, ManualPublication::STATUS_CANCELLED],
            ManualPublication::allowedNextStatuses(ManualPublication::STATUS_DRAFT),
        );
        $this->assertSame(
            [ManualPublication::STATUS_IN_PROGRESS, ManualPublication::STATUS_CANCELLED],
            ManualPublication::allowedNextStatuses(ManualPublication::STATUS_READY),
        );
        $this->assertContains(
            ManualPublication::STATUS_COMPLETED,
            ManualPublication::allowedNextStatuses(ManualPublication::STATUS_IN_PROGRESS),
        );
        $this->assertSame([], ManualPublication::allowedNextStatuses(ManualPublication::STATUS_COMPLETED));
    }

    public function test_only_failed_skipped_and_cancelled_states_can_reopen_to_ready(): void
    {
        foreach (ManualPublication::REOPENABLE_STATUSES as $status) {
            $publication = new ManualPublication(['status' => $status]);
            $this->assertTrue($publication->isReopenTransition(ManualPublication::STATUS_READY));
        }

        $completed = new ManualPublication(['status' => ManualPublication::STATUS_COMPLETED]);
        $this->assertFalse($completed->isReopenTransition(ManualPublication::STATUS_READY));
    }
}
