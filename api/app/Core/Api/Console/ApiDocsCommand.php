<?php

namespace App\Core\Api\Console;

use App\Core\Api\Docs\OpenApiDocument;
use Illuminate\Console\Command;

/**
 * API-04 (ADR 020): the public API's OpenAPI document.
 *
 * `--write` regenerates the committed baseline (`resources/openapi/v1.json`).
 * `--check` (CI) fails when:
 * - a public route has no English or French summary in `api_reference.php`,
 *   or Scramble could not document it;
 * - two public operations share an id;
 * - an operation or a parameter of the baseline is gone without a
 *   deprecation entry (`api.deprecations`);
 * - the baseline is not the generated document (run `--write`).
 */
class ApiDocsCommand extends Command
{
    protected $signature = 'api:docs {--write : Write the generated document to the baseline} {--check : Fail on undocumented routes, removals without a deprecation and a stale baseline}';

    protected $description = 'Generate or check the public API\'s OpenAPI document (API-04).';

    public function handle(OpenApiDocument $document): int
    {
        $spec = $document->generate();
        $json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $path = base_path(OpenApiDocument::BASELINE);

        if ($this->option('write')) {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            file_put_contents($path, $json);
            $this->info('Wrote '.OpenApiDocument::BASELINE.' ('.count($document->operations()).' operations).');

            if (! $this->option('check')) {
                return self::SUCCESS;
            }
        }

        $problems = [
            ...$this->undocumented($document, $spec),
            ...$this->removals($spec, is_file($path) ? json_decode((string) file_get_contents($path), true) : []),
        ];

        if (is_file($path) && file_get_contents($path) !== $json) {
            $problems[] = OpenApiDocument::BASELINE.' is not the generated document: run `php artisan api:docs --write` and commit it.';
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        if ($problems !== []) {
            return self::FAILURE;
        }

        $this->info('The public API document is complete and current ('.count($document->operations()).' operations).');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    private function undocumented(OpenApiDocument $document, array $spec): array
    {
        $problems = [];
        $ids = [];
        $documented = [];

        foreach ($spec['paths'] ?? [] as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                $documented[OpenApiDocument::template(strtoupper($method).' '.$path)] = true;
            }
        }

        foreach ($document->operations() as $key => $operation) {
            if (! isset($documented[OpenApiDocument::template($key)])) {
                $problems[] = "{$key}: public route missing from the generated document.";
            }

            foreach (['en', 'fr'] as $locale) {
                if (! trans()->hasForLocale(OpenApiDocument::key($operation['id'], 'summary'), $locale)) {
                    $problems[] = "{$key}: no {$locale} summary (".OpenApiDocument::key($operation['id'], 'summary').').';
                }
            }

            if (isset($ids[$operation['id']])) {
                $problems[] = "{$key}: operation id {$operation['id']} is also {$ids[$operation['id']]}.";
            }

            $ids[$operation['id']] = $key;
        }

        return $problems;
    }

    /**
     * Operations and parameters of the baseline that the generated document
     * no longer has, unless the operation is listed in `api.deprecations`.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $baseline
     * @return list<string>
     */
    private function removals(array $spec, array $baseline): array
    {
        $deprecated = [];
        $problems = [];

        foreach (array_keys(config('api.deprecations', [])) as $entry) {
            $deprecated[OpenApiDocument::template((string) $entry)] = true;
        }

        foreach ($baseline['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $key = strtoupper($method).' '.ltrim($path, '/');

                if (isset($deprecated[OpenApiDocument::template($key)])) {
                    continue;
                }

                $current = $spec['paths'][$path][$method] ?? null;

                if ($current === null) {
                    $problems[] = "{$key}: removed without a deprecation entry (config/api.php deprecations).";

                    continue;
                }

                $names = fn (array $op) => array_map(fn (array $p) => ($p['in'] ?? '').':'.($p['name'] ?? ''), array_filter($op['parameters'] ?? [], 'is_array'));

                foreach (array_diff($names($operation), $names($current)) as $parameter) {
                    $problems[] = "{$key}: parameter {$parameter} removed without a deprecation entry.";
                }
            }
        }

        return $problems;
    }
}
