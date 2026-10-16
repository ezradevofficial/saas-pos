<?php

namespace Modules\Ecommerce\WhatsApp;

use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * ECOM-24: the configured WhatsApp driver (`ecommerce.whatsapp.driver`).
 * A real provider registers its class here; `fake` is refused outside
 * local work and tests (NFR-06), and no driver means WhatsApp is off.
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

        if ($name === 'fake' && ! config('ecommerce.whatsapp.allow_fake')) {
            throw new RuntimeException('The fake WhatsApp driver is only available in local and testing.');
        }

        return $this->container->make($this->drivers[$name]);
    }
}
