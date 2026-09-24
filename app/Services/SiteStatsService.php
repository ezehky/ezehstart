<?php

namespace App\Services;

use App\Models\Country;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;

/**
 * The figures the public pages boast about.
 *
 * Counted from the tables rather than typed in, so they are true on any install
 * and move as the site grows — nothing here goes stale waiting for somebody to
 * edit it.
 */
#[Singleton]
class SiteStatsService
{
    /**
     * The front page's counter row, ready for <x-site.stats>: one entry per
     * counter, in the order they are shown.
     *
     * Empty until somebody has signed up, the same way the FAQ and latest-posts
     * sections stay silent. An untouched starter kit should not boast "0 members".
     *
     * @return list<array{to: int, label: string, plus: bool, icon: string}>
     */
    public function frontPage(): array
    {
        $members = User::query()->users()->count();

        if ($members === 0) {
            return [];
        }

        return [
            // A floor rather than an exact count — the "+" says so, and the number
            // does not look wrong the moment somebody else signs up.
            [
                'to' => $members,
                'label' => __('Members'),
                'plus' => true,
                'icon' => 'users',
            ],
            [
                'to' => app(BlogService::class)->publishedQuery()->count(),
                'label' => __('Articles published'),
                'plus' => false,
                'icon' => 'newspaper',
            ],
            [
                'to' => Country::query()->count(),
                'label' => __('Countries supported'),
                'plus' => false,
                'icon' => 'globe-europe-africa',
            ],
            [
                'to' => app(CurrencyService::class)->active()->count(),
                'label' => __('Currencies'),
                'plus' => false,
                'icon' => 'banknotes',
            ],
        ];
    }
}
