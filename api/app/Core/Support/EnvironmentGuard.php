<?php

namespace App\Core\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * Development drivers never run in a real environment: outside `local` and
 * `testing`, booting fails when mail goes to the log or an array, SMS goes
 * to the log, or the cache or queue is not Redis (README, pre-deploy
 * checklist).
 *
 * It applies once the environment is declared: config is cached, an `.env`
 * exists, or APP_ENV is set in the process. A fresh checkout without `.env`
 * (`composer install` in CI or `composer setup`) is not an environment yet.
 */
final class EnvironmentGuard
{
    public const DEVELOPMENT = ['local', 'testing'];

    public static function enforce(Application $app): void
    {
        if (! self::applies($app)) {
            return;
        }

        $problems = self::problems($app->make('config'));

        if ($problems !== []) {
            throw new RuntimeException(sprintf(
                'The %s environment uses development drivers: %s. See the pre-deploy checklist in README.md.',
                $app->environment(),
                implode('; ', $problems),
            ));
        }
    }

    public static function applies(Application $app): bool
    {
        if ($app->environment(self::DEVELOPMENT)) {
            return false;
        }

        return $app->configurationIsCached()
            || is_file($app->environmentFilePath())
            || getenv('APP_ENV') !== false;
    }

    /** @return list<string> what is wrong, empty when ready */
    public static function problems(Repository $config): array
    {
        $problems = [];

        $mailer = $config->get('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) {
            $problems[] = "mail mailer is {$mailer} (set MAIL_MAILER)";
        }

        if ($config->get('services.sms.driver') === 'log') {
            $problems[] = 'SMS driver is log (set SMS_DRIVER)';
        }

        if ($config->get('cache.default') !== 'redis') {
            $problems[] = 'cache store is '.var_export($config->get('cache.default'), true).', not redis (set CACHE_STORE=redis)';
        }

        if ($config->get('queue.default') !== 'redis') {
            $problems[] = 'queue connection is '.var_export($config->get('queue.default'), true).', not redis (set QUEUE_CONNECTION=redis)';
        }

        return $problems;
    }
}
