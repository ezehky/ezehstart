<?php

namespace App\Http\Controllers;

use App\Enums\PolicyTypeEnum;
use App\Enums\StatusPolicy;
use App\Models\Policy;
use App\Services\PolicyContentService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Renders whichever version of a policy is currently in force.
 *
 * A plain controller rather than a Livewire page on purpose: a legal page is
 * read-only, has to be crawlable, and is linked from emails and contracts. There
 * is nothing here for a component to react to.
 *
 * The copy itself lives in the database and is written under
 * Admin → Site configuration → Policies.
 */
class PolicyPageController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, PolicyTypeEnum $type): View
    {
        $policy = app(PolicyContentService::class)->getCurrent($type);

        // An archived version stays reachable by ?version=, so somebody can always
        // go back and read the exact text they accepted. A draft never is — it has
        // not been published and nobody has agreed to it.
        if ($version = $request->query('version')) {
            $policy = Policy::query()
                ->where('policy_type', $type)
                ->where('version', $version)
                ->whereNot('status', StatusPolicy::DRAFT)
                ->first() ?? $policy;
        }

        $title = $policy?->title ?: $type->defaultTitle();

        // format: false keeps the hand-written casing — the title formatter would
        // otherwise render this as "Terms Of Service".
        kSetSiteTitle($title, format: false);

        kSetMetaData(
            description: $policy?->intro ?: "The {$title} for {$this->siteName()}.",
            key: "{$type->value}, {$type->defaultTitle()}",
            url: $type->url(),
        );

        return view('site.legal', [
            'title' => $title,
            'intro' => $policy?->intro,
            'version' => $policy?->version,
            'updatedAt' => $policy?->updated_at?->format('F j, Y'),
            'sections' => $policy?->sections() ?? [],
            'related' => $this->relatedPolicies($type),
        ]);
    }

    /**
     * The other policies, for the cross-links at the foot of the page.
     *
     * @return array<int, array{label: string, href: string}>
     */
    protected function relatedPolicies(PolicyTypeEnum $current): array
    {
        return collect(PolicyTypeEnum::cases())
            ->reject(fn (PolicyTypeEnum $type) => $type === $current)
            ->map(fn (PolicyTypeEnum $type) => [
                'label' => $type->defaultTitle(),
                'href' => $type->url(),
            ])
            ->values()
            ->all();
    }

    /**
     * The configured site name, falling back to the application name.
     */
    protected function siteName(): string
    {
        $name = kSiteConfig('name');

        return \is_string($name) && $name !== '' ? $name : (string) config('app.name');
    }
}
