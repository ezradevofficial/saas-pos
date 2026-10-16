<?php

namespace Tests\Feature\Core\Imports;

use App\Core\MasterData\RecordUsage;
use Tests\TestCase;

/**
 * IMP-02, L10N-02: every reason a module registers with RecordUsage (why
 * an import cannot be undone) is shown to the user, so it has a
 * translation in English and French.
 */
class UsageReasonsTest extends TestCase
{
    public function test_every_registered_usage_reason_is_translated_in_both_languages(): void
    {
        $reasons = app(RecordUsage::class)->reasons();

        $this->assertContains('loyalty_account', $reasons);
        $this->assertContains('accounting_payment', $reasons);
        // Stage 7D closing review item 8: CRM's leads and activities, Fixed assets' disposals, work orders and postings.
        foreach (['crm_lead', 'crm_activity', 'fixed_assets_disposal', 'fixed_assets_work_order', 'fixed_assets_posting'] as $reason) {
            $this->assertContains($reason, $reasons);
        }

        foreach ($reasons as $reason) {
            foreach (['en', 'fr'] as $locale) {
                $this->assertTrue(trans()->hasForLocale("imports.usage.{$reason}", $locale), "imports.usage.{$reason} in {$locale}");
            }
        }
    }
}
