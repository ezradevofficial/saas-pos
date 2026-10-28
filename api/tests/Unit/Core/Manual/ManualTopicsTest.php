<?php

namespace Tests\Unit\Core\Manual;

use App\Core\Manual\Topic;
use App\Core\Manual\TopicLibrary;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Rbac\RoleTemplates;
use Tests\TestCase;

// The user manual's topics (docs/manual): written once in English and French,
// naming real permissions and real role templates.
class ManualTopicsTest extends TestCase
{
    private TopicLibrary $library;

    protected function setUp(): void
    {
        parent::setUp();

        $this->library = app(TopicLibrary::class);
    }

    public function test_the_manual_has_english_and_french_topics(): void
    {
        $this->assertSame(['en', 'fr'], $this->library->locales());
        $this->assertGreaterThan(30, $this->library->all('en')->count());
    }

    public function test_every_topic_exists_in_both_locales_with_the_same_id_and_front_matter(): void
    {
        $english = $this->byPath('en');
        $french = $this->byPath('fr');

        $this->assertSame(array_keys($english), array_keys($french), 'en and fr must hold the same topic files.');

        foreach ($english as $path => $topic) {
            $other = $french[$path];

            $this->assertSame($topic->id, $other->id, "{$path}: id differs between en and fr.");
            $this->assertSame($topic->module, $other->module, "{$path}: module differs.");
            $this->assertSame($topic->permissions, $other->permissions, "{$path}: permissions differ.");
            $this->assertSame($topic->roles, $other->roles, "{$path}: roles differ.");
            $this->assertSame($topic->screens, $other->screens, "{$path}: screens differ.");
            $this->assertSame($topic->order, $other->order, "{$path}: order differs.");
            $this->assertNotSame($topic->title, $other->title, "{$path}: the French title is the English one.");
        }
    }

    public function test_topic_ids_are_unique_in_each_locale(): void
    {
        foreach (['en', 'fr'] as $locale) {
            $ids = $this->library->all($locale)->pluck('id');

            $this->assertSame($ids->unique()->count(), $ids->count(), "Duplicate topic id in {$locale}.");
        }
    }

    public function test_every_listed_permission_exists_in_the_catalogue(): void
    {
        $registry = app(PermissionRegistry::class);

        foreach ($this->library->all('en') as $topic) {
            foreach ($topic->permissions as $permission) {
                $this->assertTrue($registry->has($permission), "{$topic->id} lists [{$permission}], which is not in the permission catalogue.");
            }
        }
    }

    public function test_every_role_key_is_a_system_role_template(): void
    {
        $templates = array_column(app(RoleTemplates::class)->all(), 'key');

        foreach ($this->library->all('en') as $topic) {
            $this->assertNotEmpty($topic->roles, "{$topic->id} names no role.");

            foreach ($topic->roles as $role) {
                $this->assertContains($role, $templates, "{$topic->id} names [{$role}], which is not a role template.");
            }
        }
    }

    public function test_topics_use_only_the_app_name_placeholder_and_characters_the_pdf_font_can_print(): void
    {
        foreach (['en', 'fr'] as $locale) {
            foreach ($this->library->all($locale) as $topic) {
                $text = $topic->title."\n".$topic->body;

                $this->assertStringNotContainsString('{{ app_name', $text, "{$topic->id}: write {{app_name}} without spaces.");
                $this->assertSame(0, preg_match('/\{\{(?!app_name\}\})/', $text), "{$topic->id} ({$locale}): only the {{app_name}} placeholder is known.");
                $this->assertSame($text, mb_convert_encoding(mb_convert_encoding($text, 'Windows-1252', 'UTF-8'), 'UTF-8', 'Windows-1252'), "{$topic->id} ({$locale}) has a character outside Windows-1252, which the PDF font cannot print.");
            }
        }
    }

    public function test_the_two_guides_this_pass_delivers_are_complete(): void
    {
        $cashier = $this->library->forRole('en', 'cashier')->pluck('id')->all();

        foreach (['pos-sign-in', 'pos-open-shift', 'pos-close-shift', 'pos-sell', 'pos-take-cash-payment', 'pos-take-mpesa-payment', 'pos-give-discount', 'pos-receipt', 'pos-refund-sale', 'pos-void-sale', 'pos-manager-approval', 'pos-work-offline', 'pos-sync-status'] as $id) {
            $this->assertContains($id, $cashier, "The cashier guide lacks {$id}.");
        }

        foreach (['owner', 'admin'] as $role) {
            $guide = $this->library->forRole('en', $role)->pluck('id')->all();

            foreach (['organisation-add-company', 'organisation-add-branch-and-location', 'rbac-invite-user', 'rbac-give-role', 'core-set-brand-theme', 'security-set-up-two-factor', 'security-manage-sessions', 'billing-pay-invoice'] as $id) {
                $this->assertContains($id, $guide, "The {$role} guide lacks {$id}.");
            }
        }

        $owner = $this->library->forRole('en', 'owner')->pluck('id')->all();

        foreach (['onboarding-sign-up', 'onboarding-choose-modules', 'billing-change-modules'] as $id) {
            $this->assertContains($id, $owner, "The owner guide lacks {$id}.");
        }
    }

    /** @return array<string, Topic> */
    private function byPath(string $locale): array
    {
        $topics = [];

        foreach ($this->library->all($locale) as $topic) {
            $topics[$this->library->relativePath($topic, $locale)] = $topic;
        }

        ksort($topics);

        return $topics;
    }
}
