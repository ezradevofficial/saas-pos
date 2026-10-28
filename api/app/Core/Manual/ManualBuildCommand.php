<?php

namespace App\Core\Manual;

use App\Core\Rbac\RoleTemplates;
use Illuminate\Console\Command;

/**
 * Build the user manual as PDFs: one guide per role template and locale
 * (`--role=cashier`), or one with every topic (`--all`). Output goes to
 * storage/app/manual/{locale}/{role}.pdf (`all.pdf` for --all), which is
 * not committed.
 */
class ManualBuildCommand extends Command
{
    protected $signature = 'manual:build {--role= : A system role template key, such as cashier} {--locale= : en or fr; both when left out} {--all : Every topic in one guide, written to all.pdf}';

    protected $description = 'Build the user manual as a PDF per role template and locale';

    public function handle(TopicLibrary $library, ManualBuilder $builder, RoleTemplates $templates): int
    {
        $role = $this->option('role') ?: null;
        $all = (bool) $this->option('all');

        if ($all === ($role !== null)) {
            $this->error('Choose one of --role=<template key> or --all.');

            return self::FAILURE;
        }

        if ($role !== null && ! in_array($role, array_column($templates->all(), 'key'), true)) {
            $this->error("[{$role}] is not a system role template key.");

            return self::FAILURE;
        }

        $locales = $this->option('locale') ? [(string) $this->option('locale')] : $library->locales();

        foreach ($locales as $locale) {
            if (! in_array($locale, $library->locales(), true)) {
                $this->error("There are no manual topics for the locale [{$locale}].");

                return self::FAILURE;
            }
        }

        foreach ($locales as $locale) {
            $count = $builder->topics($locale, $role)->count();

            if ($count === 0) {
                $this->error("No {$locale} topics are written for the role [{$role}].");

                return self::FAILURE;
            }

            $directory = storage_path('app/manual/'.$locale);

            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }

            $path = $directory.'/'.($all ? 'all' : $role).'.pdf';
            file_put_contents($path, $builder->pdf($locale, $role));

            $this->info("Built {$path} ({$count} topics).");
        }

        return self::SUCCESS;
    }
}
