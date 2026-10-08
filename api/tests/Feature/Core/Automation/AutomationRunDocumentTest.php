<?php

namespace Tests\Feature\Core\Automation;

use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsAutomation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Automation\TestTaskType;
use Tests\TestCase;

/**
 * AUTO-05: the run log names a run's document by its number, else its
 * title (the type's summary, APR-04), never only an id; a title hidden
 * from the reader by field rules (RBAC-05) is left out; the link is the
 * type's page (LinksDocuments) for a reader who may see the document.
 */
class AutomationRunDocumentTest extends TestCase
{
    use BuildsAutomation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpAutomation();
    }

    public function test_runs_show_the_documents_number_or_title_and_link(): void
    {
        $this->saveRule(['type' => 'record_created'], [$this->notifyOwner()]);
        $numbered = $this->createTask(['number' => 'TSK-0007']);
        $untitled = $this->createTask(['title' => 'Count chairs']);

        $rows = collect($this->getJson('/api/v1/automation-runs', $this->headersFor())->assertOk()->json('data'))->keyBy('document_id');

        $this->assertSame(['id' => $numbered, 'number' => 'TSK-0007', 'title' => 'Count stock', 'link' => '/tasks/'.$numbered], $rows[$numbered]['document']);
        $this->assertSame(['id' => $untitled, 'number' => null, 'title' => 'Count chairs', 'link' => '/tasks/'.$untitled], $rows[$untitled]['document']);

        $run = $rows[$numbered]['id'];
        $this->getJson("/api/v1/automation-runs/{$run}", $this->headersFor())->assertOk()->assertJsonPath('data.document.number', 'TSK-0007');
    }

    public function test_a_title_hidden_from_the_reader_is_left_out(): void
    {
        $clerk = $this->inTenant(function () {
            $user = $this->colleague($this->owner, ['name' => 'Clerk']);
            $role = $this->role('Automation reader', ['core.automation.view', 'core.party.view']);
            FieldRule::create(['role_id' => $role->id, 'resource' => TestTaskType::KEY, 'field' => 'title', 'mode' => FieldRule::HIDDEN]);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });
        $this->saveRule(['type' => 'record_created'], [$this->notifyOwner()]);
        $id = $this->createTask();

        $this->getJson('/api/v1/automation-runs', $this->headersFor($clerk))->assertOk()
            ->assertJsonPath('data.0.document', ['id' => $id, 'number' => null, 'title' => null, 'link' => '/tasks/'.$id]);
        $this->getJson('/api/v1/automation-runs', $this->headersFor())->assertOk()
            ->assertJsonPath('data.0.document.title', 'Count stock');
    }
}
