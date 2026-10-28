<?php

namespace App\Core\Manual;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Reads the manual's topic files (docs/manual/{locale}/**.md).
 *
 * A topic starts with a small front matter block between two `---` lines:
 * `key: value` lines, where a list is written inline as `[a, b]`. No YAML
 * library is needed, so the format is deliberately narrow.
 */
class TopicLibrary
{
    /** Screenshots live in docs/manual/images/{locale}/{file}, beside the locale folders. */
    public const IMAGES = 'images';

    private const LISTS = ['permissions', 'roles', 'screens'];

    private const REQUIRED = ['id', 'title', 'module', 'permissions', 'roles', 'screens'];

    public function __construct(private readonly string $root) {}

    /** The locales that have a folder of topics. @return list<string> */
    public function locales(): array
    {
        $found = array_values(array_filter(
            array_map('basename', glob($this->root.'/*', GLOB_ONLYDIR) ?: []),
            fn (string $name) => $name !== self::IMAGES,
        ));
        sort($found);

        return $found;
    }

    /**
     * Every topic of $locale, ordered by module (core first), then `order`, then id.
     *
     * @return Collection<int, Topic>
     */
    public function all(string $locale): Collection
    {
        $directory = $this->root.'/'.$locale;
        $topics = new Collection;

        if (! is_dir($directory)) {
            return $topics;
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'md') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        foreach ($files as $path) {
            $topics->push($this->parse($path, $directory));
        }

        return $topics->sortBy([
            fn (Topic $a, Topic $b) => self::moduleRank($a->module) <=> self::moduleRank($b->module),
            fn (Topic $a, Topic $b) => $a->module <=> $b->module,
            fn (Topic $a, Topic $b) => $a->order <=> $b->order,
            fn (Topic $a, Topic $b) => $a->id <=> $b->id,
        ])->values();
    }

    /**
     * The topics written for a role template, or every topic when $role is null.
     *
     * @return Collection<int, Topic>
     */
    public function forRole(string $locale, ?string $role): Collection
    {
        return $this->all($locale)
            ->filter(fn (Topic $topic) => $role === null || in_array($role, $topic->roles, true))
            ->values();
    }

    /** Path of a topic file relative to its locale folder, e.g. `pos/sell.md`. */
    public function relativePath(Topic $topic, string $locale): string
    {
        return ltrim(substr($topic->path, strlen($this->root.'/'.$locale)), '/');
    }

    /**
     * The topic's screenshot on disk, or null: the `image:` file, or
     * `{id}.png` when it exists. Only plain `.png` names inside the
     * locale's image folder are served.
     */
    public function imagePath(Topic $topic, string $locale): ?string
    {
        if ($topic->image === null || ! preg_match('/\A[a-z0-9][a-z0-9_-]*\.png\z/', $topic->image) || ! preg_match('/\A[a-z]{2}\z/', $locale)) {
            return null;
        }

        $path = $this->root.'/'.self::IMAGES.'/'.$locale.'/'.$topic->image;

        return is_file($path) ? $path : null;
    }

    private static function moduleRank(string $module): int
    {
        return $module === 'core' ? 0 : 1;
    }

    private function parse(string $path, string $directory): Topic
    {
        $source = str_replace("\r\n", "\n", (string) file_get_contents($path));
        $name = ltrim(substr($path, strlen($directory)), '/');

        if (! preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $source, $parts)) {
            throw new InvalidArgumentException("Manual topic [{$name}] has no front matter between two --- lines.");
        }

        $meta = [];

        foreach (explode("\n", $parts[1]) as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (! preg_match('/\A([a-z_]+):\s*(.*)\z/', $line, $pair)) {
                throw new InvalidArgumentException("Manual topic [{$name}] has a front matter line that is not `key: value`: {$line}");
            }

            $meta[$pair[1]] = in_array($pair[1], self::LISTS, true) ? $this->list($pair[2], $name) : $this->scalar($pair[2]);
        }

        foreach (self::REQUIRED as $key) {
            if (! array_key_exists($key, $meta) || $meta[$key] === '') {
                throw new InvalidArgumentException("Manual topic [{$name}] needs a front matter key [{$key}].");
            }
        }

        $body = trim($parts[2]);

        if ($body === '') {
            throw new InvalidArgumentException("Manual topic [{$name}] has no body.");
        }

        $image = isset($meta['image']) && $meta['image'] !== '' ? $meta['image'] : $meta['id'].'.png';
        $locale = basename($directory);

        return new Topic(
            id: $meta['id'],
            title: $meta['title'],
            module: $meta['module'],
            permissions: $meta['permissions'],
            roles: $meta['roles'],
            screens: $meta['screens'],
            order: (int) ($meta['order'] ?? 1000),
            body: $body,
            path: $path,
            image: is_file($this->root.'/'.self::IMAGES.'/'.$locale.'/'.$image) ? $image : null,
            imageAlt: isset($meta['image_alt']) && $meta['image_alt'] !== '' ? $meta['image_alt'] : null,
        );
    }

    private function scalar(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /** @return list<string> */
    private function list(string $value, string $name): array
    {
        $value = trim($value);

        if (! str_starts_with($value, '[') || ! str_ends_with($value, ']')) {
            throw new InvalidArgumentException("Manual topic [{$name}] needs a list written as [a, b]: {$value}");
        }

        $inner = trim(substr($value, 1, -1));

        if ($inner === '') {
            return [];
        }

        return array_map(fn (string $item) => $this->scalar($item), explode(',', $inner));
    }
}
