<?php

namespace Tests\Feature\Core\Notifications;

use App\Core\Notifications\Channels;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\Templates\Template;
use App\Core\Notifications\Templates\TemplateRenderer;
use App\Core\Notifications\Templates\Templates;
use Tests\TestCase;

// NOT-03: {placeholder} syntax, unknown placeholders, escaping for HTML
// email, defaults in English and French with a short text for SMS.
class TemplateRenderingTest extends TestCase
{
    public function test_placeholders_are_found_and_unknown_ones_named(): void
    {
        $text = 'PO {document_number} for {amount} by {requester_name}, {amount} again; {Not_One} {not-one} {} {9x}';

        $this->assertSame(['document_number', 'amount', 'requester_name'], TemplateRenderer::placeholders($text));
        $this->assertSame(['requester_name'], TemplateRenderer::unknown($text, ['document_number', 'amount']));
        $this->assertSame([], TemplateRenderer::unknown('No placeholders', []));
    }

    public function test_rendering_fills_values_and_leaves_no_placeholder_behind(): void
    {
        $rendered = TemplateRenderer::render('{document_number} for {amount} ({missing}) {Not_One}', [
            'document_number' => 'PO-0042', 'amount' => 'KES 12,450.00', 'ignored' => 'x',
        ]);

        $this->assertSame('PO-0042 for KES 12,450.00 () {Not_One}', $rendered);
        // A value is never read as a placeholder itself.
        $this->assertSame('{amount} x', TemplateRenderer::render('{a} {b}', ['a' => '{amount}', 'b' => 'x']));
    }

    public function test_html_escapes_the_template_and_the_values(): void
    {
        $message = (new Template('Hi {name}', "<b>Note</b>\n{value}"))->render(['name' => 'A&B', 'value' => '<img src=x onerror=alert(1)>']);

        $this->assertSame('Hi A&B', $message->subject);
        $this->assertSame("<b>Note</b>\n<img src=x onerror=alert(1)>", $message->body);
        $this->assertSame("&lt;b&gt;Note&lt;/b&gt;<br>\n&lt;img src=x onerror=alert(1)&gt;", $message->html());
    }

    public function test_defaults_come_in_english_and_french_with_a_short_sms_text(): void
    {
        $type = app(EventTypes::class)->get('core.notification.test');
        $templates = app(Templates::class);

        $this->assertSame('Test message from {sender_name}', $templates->default($type, Channels::EMAIL, 'en')->subject);
        $this->assertSame('Message de test de {sender_name}', $templates->default($type, Channels::EMAIL, 'fr')->subject);
        $this->assertStringStartsWith('Bonjour {recipient_name}', $templates->default($type, Channels::IN_APP, 'fr')->body);
        $this->assertSame('{app_name}: test message from {sender_name}: {message}', $templates->default($type, Channels::SMS, 'en')->body);
        $this->assertSame('{app_name} : message de test de {sender_name} : {message}', $templates->default($type, Channels::WHATSAPP, 'fr')->body);
        $this->assertStringStartsWith('Hello', $templates->default($type, Channels::PUSH, 'en')->body, 'push uses the full text');

        // Every default uses only the event's placeholders.
        foreach (Channels::LOCALES as $locale) {
            foreach ($type->channels as $channel) {
                $default = $templates->default($type, $channel, $locale);
                $this->assertSame([], TemplateRenderer::unknown($default->subject.' '.$default->body, $type->placeholderNames()));
            }
        }
    }
}
