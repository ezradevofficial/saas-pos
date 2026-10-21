<?php

namespace Tests\Feature\Core\Api;

use App\Core\Api\Models\ApiKey;
use App\Core\Approvals\ApprovalConfig;
use App\Core\Identity\Models\User;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\BuildsApiKeys;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// ADR 020 review: an API key's principal (status `service`) is never taken
// where a person is meant: approvers, owners, assignees, custodians,
// riders, PIN holders.
class PrincipalsAreNotPeopleTest extends TestCase
{
    use BuildsApiKeys, RefreshTenantDatabase;

    public function test_every_users_existence_rule_names_the_people_it_accepts(): void
    {
        $unguarded = [];
        $files = Finder::create()->files()->name('*.php')->in([app_path(), base_path('modules')])->exclude('tests');

        foreach ($files as $file) {
            foreach (preg_split('/\R/', $file->getContents()) as $number => $line) {
                $existsUsers = str_contains($line, "Rule::exists('users'") || str_contains($line, 'exists:users');

                if ($existsUsers && ! preg_match("/->where(Not)?\\('status'/", $line)) {
                    $unguarded[] = $file->getRelativePathname().':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $unguarded, 'Rule::exists(users) without a status condition (active people, or not a service principal)');
    }

    public function test_a_principal_is_not_a_known_approver_nor_a_person(): void
    {
        $this->setUpOrganisation();
        $key = $this->apiKey();

        $this->inTenant(function () use ($key) {
            $principal = User::findOrFail(ApiKey::findOrFail($key['id'])->principal_id);

            $this->assertFalse($principal->isPerson());
            $this->assertTrue($this->owner->isPerson());
            $this->assertFalse(ApprovalConfig::knownUser($principal->id));
            $this->assertTrue(ApprovalConfig::knownUser($this->owner->id));
        });
    }
}
