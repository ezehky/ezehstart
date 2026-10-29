<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Services\BlogService;
use App\Services\SiteConfigurationService;
use App\Services\SiteStatsService;
use Illuminate\View\View;

/**
 * The front page.
 *
 * Read-only, so a controller rather than a Livewire page: there is nothing on it
 * for a component to react to, and a plain view is crawlable and costs no
 * snapshot. Every section reads its data here and is handed it as a prop, so the
 * components stay presentation only. Each one renders nothing when handed
 * nothing — an untouched starter kit shows no empty stats, posts or accordion.
 */
class HomeController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): View
    {
        kSetSiteTitle('home');

        return view('site.home', [
            // The same switch the sign-in screens read. The button goes with it,
            // so a turned-off feature does not leave a link to a route that aborts.
            'passwordlessEnabled' => (bool) kSiteFlag('security', 'passwordless-login', false),
            'stats' => app(SiteStatsService::class)->frontPage(),
            'latestPosts' => app(BlogService::class)->publishedQuery()->limit(3)->get(),
            'faqs' => Faq::query()->active()->inFlowOrder()->get(),
            'contactLocations' => app(SiteConfigurationService::class)->contactLocations(),
        ]);
    }
}
