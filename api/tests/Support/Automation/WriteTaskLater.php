<?php

namespace Tests\Support\Automation;

use App\Core\Automation\Chain\CarriesAutomationCause;
use App\Core\Automation\Chain\RestoresAutomationCause;
use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** A module's queued job that changes a task later (AUTO-06: carries the chain it was created in). */
class WriteTaskLater implements ShouldQueue
{
    use CarriesAutomationCause, Dispatchable, Queueable;

    public function __construct(
        public string $tenantId,
        public string $taskId,
        public array $values,
    ) {
        $this->captureAutomationCause();
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware, new RestoresAutomationCause];
    }

    public function handle(DocumentTypeRegistry $types): void
    {
        $types->get(TestTaskType::KEY)->write($this->taskId, $this->values);
    }
}
