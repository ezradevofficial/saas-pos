<?php

namespace Modules\Procurement\Tests;

use App\Core\DocumentTemplates\DefaultTemplates;
use App\Core\DocumentTemplates\TemplateRenderer;
use Modules\Procurement\Printing\ProcurementSource;
use Modules\Procurement\Tests\Concerns\BuildsProcurement;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Templates\TemplateText;
use Tests\TestCase;

// PRC-12, PRC-13, TPL-01 (stage 7B walkthrough): Procurement prints use
// their own fields: a letter names its tender ("Tender TND-…"), a PO its
// requisition and its expected delivery, never the POS refund's "Original
// receipt" nor a "Due date"; the till's bundled wording has them too.
class PrintLabelsTest extends TestCase
{
    use BuildsProcurement, RefreshTenantDatabase;

    private function rendered(string $type): string
    {
        $source = new ProcurementSource($type);
        $html = $this->inTenant(fn () => app(TemplateRenderer::class)->html($type, DefaultTemplates::for($type), $source->sample('KE'), false));

        return TemplateText::of($html);
    }

    public function test_letters_name_their_tender_and_a_po_its_requisition_and_expected_delivery(): void
    {
        $this->setUpProcurement();

        foreach (['procurement.award_letter', 'procurement.regret_letter'] as $type) {
            $text = $this->rendered($type);
            $this->assertStringContainsString('Tender', $text, $type);
            $this->assertStringContainsString('TND-BR1-2026-00001', $text, $type);
            $this->assertStringNotContainsString('Original receipt', $text, $type);
        }

        $po = $this->rendered('procurement.po');
        $this->assertStringContainsString('Expected delivery', $po);
        $this->assertStringContainsString('Requisition', $po);
        $this->assertStringContainsString('PR-BR1-2026-00003', $po);
        $this->assertStringNotContainsString('Due date', $po);
        $this->assertStringNotContainsString('Original receipt', $po);

        foreach (['en', 'fr'] as $locale) {
            $pos = json_decode((string) file_get_contents(base_path("../pos/src/locales/{$locale}.json")), true)['templates']['print']['fields']['document'];

            foreach (['tender_ref', 'requisition_ref', 'expected_on'] as $field) {
                $this->assertSame(__("templates.print.fields.document.{$field}", [], $locale), $pos[$field] ?? null, "{$locale} {$field}");
            }
        }
    }
}
