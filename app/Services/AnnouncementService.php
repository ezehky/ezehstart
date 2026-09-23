<?php

namespace App\Services;

use App\Models\Announcement;
use Illuminate\Container\Attributes\Singleton;

/**
 * The popup on the public pages.
 *
 * One announcement at a time, and always the latest one that is live. Older live
 * rows are not queued behind it — a visitor who has dismissed this week's promo
 * should not be handed last week's as a consolation.
 */
#[Singleton]
class AnnouncementService
{
    /**
     * What the popup shows, if anything.
     *
     * "Latest" is by when it started running, falling back to when it was
     * written: an announcement scheduled for tomorrow and written today should
     * take over tomorrow, not sit behind one written after it.
     *
     * Not cached. Every row has a window that opens and closes on the clock, and
     * a cache would need expiring on those moments as well as on every save —
     * against one indexed query that returns a single row.
     */
    public function current(): ?Announcement
    {
        return Announcement::query()
            ->live()
            ->with('image')
            ->orderByRaw('COALESCE(starts_at, created_at) DESC')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Whether the popup renders at all.
     *
     * A live announcement is enough on its own. Without one, the popup falls back
     * to what it always was — the newsletter sign-up — for as long as the
     * newsletter's own popup switch is on.
     */
    public function showsPopup(?Announcement $announcement): bool
    {
        if ($announcement) {
            return true;
        }

        return app(NewsletterService::class)->showsPopup();
    }

    /**
     * Whether this announcement carries the sign-up form. The row asks for it, and
     * the newsletter has to be taking sign-ups — a form that refuses every address
     * is worse than no form.
     */
    public function showsNewsletter(?Announcement $announcement): bool
    {
        $newsletter = app(NewsletterService::class);

        return $announcement
            ? $announcement->showsNewsletter() && $newsletter->isEnabled()
            : $newsletter->showsPopup();
    }
}
