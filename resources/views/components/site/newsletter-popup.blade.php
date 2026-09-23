{{-- The popup on the public pages, after `preferences.newsletter.popup-delay`
     seconds of reading.

     The latest live announcement if there is one — a promotion, a notice, or the
     newsletter with a picture — and the plain newsletter sign-up if there is not.
     See AnnouncementService for which, and for when the form comes with it.

     A Flux modal rather than a hand-built overlay, because a card that appears on a
     timer over the page has to trap focus, answer Escape and hand focus back, and
     the modal already does all three.

     Closing it hides it for the rest of the visit (sessionStorage). Ticking "don't
     show this again" hides it for good (localStorage). Both are keyed on the
     announcement *and* its last edit, so a new or reworded announcement is shown
     again to somebody who dismissed the last one. --}}
@php
    $announcements = app(App\Services\AnnouncementService::class);
    $announcement = $announcements->current();
    $withForm = $announcements->showsNewsletter($announcement);
    $newsletter = app(App\Services\NewsletterService::class);

    $key = $announcement?->dismissKey() ?? 'newsletter-popup';
    $imageOnly = $announcement?->isImageOnly() ?? false;
    $split = $announcement?->layout?->isSplit() && $announcement?->image && ! $imageOnly;
    $image = $announcement?->image;
@endphp

@if ($announcements->showsPopup($announcement))
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

                setTimeout(() => this.$flux.modal('site-announcement').show(), {{ $newsletter->popupDelay() * 1000 }})
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
            x-on:close="dismissed()"
            @class([
                'overflow-hidden',
                'p-0!' => $imageOnly,
                'md:max-w-3xl' => $split,
                'max-w-md' => ! $split,
            ])
        >
            @if ($imageOnly)
                {{-- The picture is the message. Linked when there is somewhere to go. --}}
                <x-site.announcement-image :announcement="$announcement" class="max-h-[80vh] w-full object-cover" />
            @else
                <div @class(['grid gap-6', 'md:grid-cols-2 md:items-center' => $split])>
                    @if ($image && ! $split)
                        <x-site.announcement-image :announcement="$announcement" class="aspect-video w-full rounded-xl object-cover" />
                    @endif

                    <div class="space-y-4">
                        <div>
                            <flux:heading size="xl" class="font-heading font-bold">
                                {{ $announcement?->title ?: __('Get the newsletter') }}
                            </flux:heading>

                            @if (! $announcement || $announcement->body)
                                <flux:text class="mt-2">
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

                        @if ($withForm)
                            {{-- Its own key: the footer renders a second copy of the
                                 same component on the same page. --}}
                            <livewire:livewire.newsletter-form key="newsletter-popup" />
                        @endif
                    </div>

                    @if ($split)
                        <x-site.announcement-image :announcement="$announcement" class="h-full max-h-96 w-full rounded-xl object-cover md:max-h-none" />
                    @endif
                </div>
            @endif

            <div @class(['mt-5', 'px-4 pb-4' => $imageOnly])>
                <flux:checkbox x-model="forever" :label="__('Do not show this again')" />
            </div>
        </flux:modal>
    </div>
@endif
