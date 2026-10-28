<?php

namespace App\Core\Manual\Http\Controllers;

use App\Core\Http\ApiException;
use App\Core\Manual\Http\Requests\ManualRequest;
use App\Core\Manual\ManualBuilder;
use App\Core\Manual\Topic;
use App\Core\Manual\TopicLibrary;
use App\Core\Manual\VisibleTopics;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The manual inside the app, filtered to what the user can do (RBAC-08, L10N-01). */
class ManualController
{
    public const PDF_TTL_SECONDS = 300;

    public function __construct(
        private readonly VisibleTopics $visible,
        private readonly ManualBuilder $builder,
        private readonly TopicLibrary $library,
    ) {}

    public function index(ManualRequest $request): JsonResponse
    {
        $locale = $request->locale();

        return new JsonResponse(['data' => $this->visible->for($request->user(), $locale)->map(fn (Topic $topic) => [
            'id' => $topic->id,
            'title' => $topic->title,
            'module' => $topic->module,
            'module_label' => $this->builder->moduleLabel($topic->module, $locale),
            'screens' => $topic->screens,
            'excerpt' => $this->excerpt($topic),
        ])->values()]);
    }

    public function show(ManualRequest $request, string $topic): JsonResponse
    {
        $locale = $request->locale();
        $found = $this->visible->find($request->user(), $locale, $topic);

        // A hidden topic looks the same as one that does not exist.
        if ($found === null) {
            throw new ApiException(404, 'not_found', Lang::get('manual.errors.topic_not_found', [], $locale));
        }

        return new JsonResponse(['data' => [
            'id' => $found->id,
            'title' => $found->title,
            'module' => $found->module,
            'module_label' => $this->builder->moduleLabel($found->module, $locale),
            'screens' => $found->screens,
            'html' => $this->builder->markdown($found, $this->appName(), $locale),
            'image_url' => $this->library->imagePath($found, $locale) === null ? null : url('/api/v1/manual/topics/'.$found->id.'/image').'?locale='.$locale,
            'image_alt' => $this->library->imagePath($found, $locale) === null ? null : ($found->imageAlt ?? Lang::get('manual.screenshot_alt', ['title' => $found->title], $locale)),
        ]]);
    }

    /** The topic's screenshot, only for a user who can see the topic (RBAC-08); anything else looks like a missing image. */
    public function image(ManualRequest $request, string $topic): BinaryFileResponse
    {
        $locale = $request->locale();
        $found = $this->visible->find($request->user(), $locale, $topic);
        $path = $found === null ? null : $this->library->imagePath($found, $locale);

        if ($path === null) {
            throw new ApiException(404, 'not_found', Lang::get('manual.errors.topic_not_found', [], $locale));
        }

        return new BinaryFileResponse($path, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function pdf(ManualRequest $request, TenantContext $tenants): Response
    {
        $locale = $request->locale();
        $topics = $this->visible->for($request->user(), $locale);

        if ($topics->isEmpty()) {
            throw new ApiException(404, 'not_found', Lang::get('manual.errors.topic_not_found', [], $locale));
        }

        // The visible topic set stands for the user's permission set and the tenant's modules.
        $key = 'manual-pdf:'.$tenants->id().':'.$locale.':'.sha1($topics->pluck('id')->implode('|').'|'.$this->appName());

        $pdf = base64_decode((string) Cache::remember($key, self::PDF_TTL_SECONDS, fn () => base64_encode(
            $this->builder->pdf($locale, null, $topics, Lang::get('manual.guide_mine', [], $locale)),
        )));

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="guide-'.$locale.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function appName(): string
    {
        return (string) config('app.name');
    }

    /** The first paragraph as plain text, shortened. */
    private function excerpt(Topic $topic): string
    {
        $first = trim(explode("\n\n", str_replace('{{app_name}}', $this->appName(), $topic->body))[0]);
        $plain = trim((string) preg_replace('/\s+/', ' ', preg_replace('/[#*_`>]|^\s*(\d+\.|-)\s+/m', '', $first)));

        return Str::limit($plain, 140);
    }
}
