<?php

use App\Models\Announcement;
use App\Services\AnnouncementService;
use App\Services\NewsletterService;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The popup on the public pages, after `preferences.newsletter.popup-delay`
 * seconds of reading.
 *
 * The latest live announcement if there is one — a promotion, a notice, or the
 * newsletter with a picture or an icon — and the plain newsletter sign-up if there
 * is not. See AnnouncementService for which, and for when the form comes with it.
 *
 * Flux's modal in its bare variant: the card is drawn here so the picture can run
 * to the edges, while the dialog underneath still traps focus, answers Escape and
 * hands focus back.
 *
 * Closing it hides it for the rest of the visit (sessionStorage). Ticking "don't
 * show this again" hides it for good (localStorage). Both are keyed on the
 * announcement *and* its last edit, so a new or reworded announcement is shown
 * again to somebody who dismissed the last one.
 */
new class extends Component
{
    #[Computed]
    public function announcement(): ?Announcement
    {
        return app(AnnouncementService::class)->current();
    }

    #[Computed]
    public function visible(): bool
    {
        return app(AnnouncementService::class)->showsPopup($this->announcement);
    }

    #[Computed]
    public function withForm(): bool
    {
        return app(AnnouncementService::class)->showsNewsletter($this->announcement);
    }

    #[Computed]
    public function delay(): int
    {
        return app(NewsletterService::class)->popupDelay();
    }
};
?>

<div>
    @php
        $announcement = $this->announcement;
        $key = $announcement?->dismissKey() ?? 'newsletter-popup';
        $imageOnly = $announcement?->isImageOnly() ?? false;
        $hasMedia = $announcement?->image || $announcement?->showsIcon();
        $split = $announcement?->layout?->isSplit() && $hasMedia && ! $imageOnly;
    @endphp

    @if ($this->visible)
        <div
            x-data="{
                key: @js($key),
                forever: false,

                init() {
                    // Storage can be blocked or cleared; a browser that reports nothing
                    // gets the popup, which is the right way round to fail.
                    try {
                        if (localStorage.getItem(this.key) || sessionStorage.getItem(this.key)) return
                    } catch (e) {}

                    setTimeout(() => this.$flux.modal('site-announcement').show(), {{ $this->delay * 1000 }})
                },

                dismissed() {
                    try {
                        (this.forever ? localStorage : sessionStorage).setItem(this.key, '1')
                    } catch (e) {}
                },
            }"
            x-on:newsletter-subscribed.window="forever = true; $flux.modal('site-announcement').close()"
        >
            <flux:modal
                name="site-announcement"
                variant="bare"
                :closable="false"
                x-on:close="dismissed()"
                @class([
                    'w-full p-4',
                    // Darker and blurred, so the page behind reads as paused rather
                    // than as something to keep reading around the card.
                    'backdrop:bg-slate-950/70! backdrop:backdrop-blur-md! dark:backdrop:bg-black/80!',
                    'md:max-w-4xl' => $split,
                    'max-w-lg' => $imageOnly,
                    'max-w-md' => ! $split && ! $imageOnly,
                ])
            >
                <div class="relative max-h-[calc(100dvh-2rem)] overflow-y-auto rounded-2xl bg-white shadow-2xl ring-1 ring-black/5 dark:bg-slate-900 dark:ring-white/10">
                    {{-- Over the picture more often than not, so it carries its own
                         background rather than relying on what is beneath it. --}}
                    <flux:modal.close>
                        <button
                            type="button"
                            aria-label="{{ __('Close') }}"
                            class="absolute end-3 top-3 z-10 grid size-9 cursor-pointer place-items-center rounded-full bg-white/90 text-slate-700 shadow-md ring-1 ring-black/5 backdrop-blur transition hover:bg-white hover:text-slate-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-lime-500 dark:bg-slate-800/90 dark:text-slate-200 dark:ring-white/10 dark:hover:bg-slate-800 dark:hover:text-white"
                        >
                            <flux:icon name="x-mark" variant="micro" class="size-4" />
                        </button>
                    </flux:modal.close>

                    @if ($imageOnly)
                        {{-- The picture is the message. Linked when there is somewhere to go. --}}
                        <x-site.announcement-image :announcement="$announcement" class="block max-h-[75vh] w-full object-cover" />

                        <div class="border-t border-slate-100 px-5 py-3 dark:border-white/10">
                            <flux:checkbox x-model="forever" :label="__('Do not show this again')" />
                        </div>
                    @else
                        <div @class(['grid', 'md:grid-cols-2' => $split])>
                            @if ($hasMedia)
                                <div @class([
                                    'relative overflow-hidden',
                                    'aspect-video' => ! $split,
                                    'aspect-video md:aspect-auto md:min-h-112' => $split,
                                ])>
                                    @if ($announcement->image)
                                        <x-site.announcement-image :announcement="$announcement" class="absolute inset-0 size-full object-cover" />
                                    @else
                                        {{-- The icon in place of a picture: large, on the accent's
                                             lightest tint, with a soft glow behind it for depth. --}}
                                        <div class="absolute inset-0 grid place-items-center bg-lime-100 dark:bg-lime-400/10">
                                            <div class="absolute size-48 rounded-full bg-lime-300/50 blur-3xl dark:bg-lime-400/20"></div>
                                            <flux:icon :name="$announcement->icon" class="relative size-24 text-lime-700 dark:text-lime-300" />
                                        </div>
                                    @endif
                                </div>
                            @endif

                            <div @class(['flex flex-col justify-center p-6 sm:p-8', 'pt-12' => ! $hasMedia])>
                                <div class="space-y-5">
                                    <div>
                                        <flux:heading size="xl" class="font-heading text-2xl font-bold tracking-tight">
                                            {{ $announcement?->title ?: __('Get the newsletter') }}
                                        </flux:heading>

                                        @if (! $announcement || $announcement->body)
                                            <flux:text class="mt-2 text-base">
                                                {{ $announcement
                                                    ? $announcement->body
                                                    : __('Occasional updates from :name — no more than one email a month.', ['name' => $_configs['name']]) }}
                                            </flux:text>
                                        @endif
                                    </div>

                                    @if ($announcement?->link_url && $announcement?->link_label)
                                        <flux:button
                                            :href="$announcement->link_url"
                                            variant="primary"
                                            icon:trailing="arrow-right"
                                            class="w-full"
                                        >
                                            {{ $announcement->link_label }}
                                        </flux:button>
                                    @endif

                                    @if ($this->withForm)
                                        {{-- Its own key: the footer renders a second copy of the
                                             same component on the same page. --}}
                                        <livewire:livewire.newsletter-form key="newsletter-popup" />
                                    @endif
                                </div>

                                <div class="mt-6">
                                    <flux:checkbox x-model="forever" :label="__('Do not show this again')" />
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </flux:modal>
        </div>
    @endif
</div>
