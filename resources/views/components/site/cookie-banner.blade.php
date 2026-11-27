{{-- The cookie banner, behind `preferences.accept-cookies`, from
     CookieConsentService::banner() (a view composer hands it over as $banner;
     null prints nothing). Laid out the way hellomoint's is: a card in the corner
     with the choice on its face and the per-category switches folded under
     Manage preferences.

     Two shapes, decided by whether there is anything optional to allow:

     - A notice, while the site sets only the session and CSRF cookies. Those are
       the cookies a notice covers rather than the ones that need permission
       first, so "Got it" is the only answer.
     - A choice, once a Google Analytics ID is set on Site info: Accept all,
       Reject optional, or Manage preferences for a switch per category. Nothing
       optional loads until it is made. The footer's "Cookie settings" reopens it
       with the switches showing.

     Either way the answer is one plain cookie the server reads through
     CookieConsentService, so it applies on the next page without an account.
     Taking a category back reloads the page: a script that has already run cannot
     be unrun, and on the reload the analytics tag sees no consent, stays out and
     clears its cookies. --}}
@php($categories = App\Enums\CookieCategoryEnum::cases())

@if ($banner)
    <div
        x-cloak
        x-data="{
            open: false,
            managing: false,
            chosen: { necessary: true, measurement: false },
            all: @js(collect($categories)->pluck('value')->all()),
            name: @js($banner['cookie']),
            version: @js($banner['version']),
            days: @js($banner['days']),

            init() {
                const stored = this.read()

                if (stored) {
                    Object.keys(this.chosen).forEach((key) => this.chosen[key] = key === 'necessary' || stored.includes(key))
                } else {
                    this.open = true
                }
            },

            read() {
                const pair = document.cookie.split('; ').find((row) => row.startsWith(this.name + '='))
                const value = pair ? decodeURIComponent(pair.split('=')[1]) : ''

                return value.startsWith(this.version + ':') ? value.slice(this.version.length + 1).split(',') : null
            },

            save(categories) {
                const previous = this.read() || []
                const value = encodeURIComponent(this.version + ':' + categories.join(','))
                const secure = location.protocol === 'https:' ? '; secure' : ''

                document.cookie = `${this.name}=${value}; path=/; max-age=${this.days * 86400}; samesite=lax${secure}`
                Object.keys(this.chosen).forEach((key) => this.chosen[key] = categories.includes(key))
                this.open = false
                this.managing = false

                if (previous.some((key) => ! categories.includes(key))) {
                    window.location.reload()

                    return
                }

                window.dispatchEvent(new CustomEvent('cookie-consent-updated', { detail: categories }))
            },

            saveChosen() {
                this.save(Object.keys(this.chosen).filter((key) => this.chosen[key]))
            },
        }"
        x-on:cookie-settings.window="open = true; managing = true"
        x-on:keydown.escape.window="if (open && read()) open = false"
        x-show="open"
        x-transition.opacity.duration.200ms
        class="fixed inset-x-3 bottom-3 z-50 sm:inset-x-auto sm:right-5 sm:bottom-5 sm:w-104"
        role="{{ $banner['consent'] ? 'dialog' : 'region' }}"
        aria-label="{{ $banner['consent'] ? __('Your cookie choices') : __('Cookie notice') }}"
        aria-describedby="cookie-banner-text"
    >
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-2xl shadow-slate-900/15 dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/40">
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                {{ $banner['consent'] ? __('Choose your cookie preferences.') : __('We use cookies.') }}
            </h2>

            <p id="cookie-banner-text" class="mt-1.5 text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                @if ($banner['consent'])
                    {{ __('We use essential cookies to run the site, and count visits with Google Analytics only if you allow it.') }}
                @else
                    {{ __('This site only uses the essential cookies it needs to work, such as keeping you signed in.') }}
                @endif
                {{ __('See our') }}
                <a
                    href="{{ App\Enums\PolicyTypeEnum::COOKIES->url() }}"
                    class="font-medium text-brand-600 underline underline-offset-2 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300"
                >{{ App\Enums\PolicyTypeEnum::COOKIES->defaultTitle() }}</a>.
            </p>

            @if ($banner['consent'])
                <div x-show="managing" x-collapse class="mt-4 space-y-3 border-t border-slate-200 pt-4 dark:border-slate-800">
                    @foreach ($categories as $category)
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ __($category->label()) }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ __($category->description()) }}</p>
                            </div>
                            @if ($category->isRequired())
                                <span class="mt-0.5 shrink-0 rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700 dark:bg-brand-400/10 dark:text-brand-300">{{ __('Always on') }}</span>
                            @else
                                <flux:switch x-model="chosen.{{ $category->value }}" class="shrink-0" aria-label="{{ __($category->label()) }}" />
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 grid grid-cols-2 gap-2">
                    <flux:button variant="primary" x-on:click="save(all)">{{ __('Accept all') }}</flux:button>
                    <flux:button x-on:click="save(['necessary'])">{{ __('Reject optional') }}</flux:button>
                    <flux:button variant="ghost" class="col-span-2" x-show="! managing" x-on:click="managing = true">
                        {{ __('Manage preferences') }}
                    </flux:button>
                    <flux:button variant="filled" class="col-span-2" x-show="managing" x-cloak x-on:click="saveChosen()">
                        {{ __('Save preferences') }}
                    </flux:button>
                </div>
            @else
                <div class="mt-4">
                    <flux:button variant="primary" class="w-full" x-on:click="save(['necessary'])">{{ __('Got it') }}</flux:button>
                </div>
            @endif
        </div>
    </div>
@endif
