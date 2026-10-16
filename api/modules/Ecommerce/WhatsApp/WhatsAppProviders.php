<?php

namespace Modules\Ecommerce\WhatsApp;

use Illuminate\Contracts\Container\Container;

/**
 * ECOM-24: the configured WhatsApp driver (`ecommerce.whatsapp.driver`).
 * A real provider registers its class here; `fake` is refused outside
 * local work and tests (NFR-06), and no driver (or a refused one) means
 * WhatsApp is off: the webhook answers 401.
 */
final class WhatsAppProviders
{
    /** @var array<string, class-string<WhatsAppProvider>> */
    private array $drivers = ['fake' => FakeWhatsAppProvider::class];

    public function __construct(private readonly Container $container) {}

    /** @param class-string<WhatsAppProvider> $class */
    public function register(string $name, string $class): void
    {
        $this->drivers[$name] = $class;
    }

    public function current(): ?WhatsAppProvider
    {
        $name = (string) config('ecommerce.whatsapp.driver', '');

        if ($name === '' || ! isset($this->drivers[$name])) {
            return null;
        }

        // NFR-06: the fake driver accepts messages nobody sent: only in local and testing (a hard
        // check of the environment, whatever the switch says), and refused like any unverified call.
        if ($name === 'fake' && (! app()->environment(['local', 'testing']) || ! config('ecommerce.whatsapp.allow_fake'))) {
            return null;
        }

        return $this->container->make($this->drivers[$name]);
    }
}
