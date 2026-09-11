@props(['user'])

{{-- The byline card at the foot of a post.

     Rendered from the author's own profile rather than from anything stored on the
     post, so correcting a bio fixes every post at once instead of the most recent one.

     Silent when there is nothing to say: an author who has not written a bio or left a
     handle gets no card, because an empty box under every post is worse than none. The
     byline above the article already names them. --}}

@php($profile = $user?->userProfile)
@php($links = $profile?->socialLinks() ?? [])

@if ($user && ($profile?->bio || $links))
    <section class="mt-12 rounded-xl border border-slate-200 p-6 dark:border-slate-800">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
            <img
                src="{{ kSafeImage($user->avatar, 'user') }}"
                alt="{{ $user->name }}"
                class="size-14 shrink-0 rounded-full object-cover"
                loading="lazy"
            />

            <div class="min-w-0 space-y-2">
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-400">Written by</p>
                    <flux:heading level="2" size="lg">{{ $user->name }}</flux:heading>
                </div>

                @if ($profile?->bio)
                    <flux:text class="text-slate-600 dark:text-slate-300">{{ $profile->bio }}</flux:text>
                @endif

                @if ($links)
                    <div class="flex flex-wrap items-center gap-3 pt-1">
                        @foreach ($links as $link)
                            <a
                                href="{{ $link['url'] }}"
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                                class="text-slate-400 transition hover:text-slate-950 dark:hover:text-white"
                                aria-label="{{ $user->name }} on {{ $link['platform']->label() }}"
                            >
                                <flux:icon :name="$link['platform']->icon()" class="size-5" />
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </section>
@endif
