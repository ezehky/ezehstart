{{-- The cookie banner, behind `preferences.accept-cookies`, from
     CookieConsentService::banner() (a view composer hands it over as $banner;
     null prints nothing).

     Two shapes, decided by whether there is anything optional to allow:

     - A notice, while the site sets only the session and CSRF cookies. Those are
       the cookies a notice covers rather than the ones that need permission
       first, so "Got it" is the only answer.
     - A choice, once a Google Analytics ID is set on Site info: strictly
       necessary only, or everything, with "Choose" opening a switch per
       category. Nothing optional loads until it is made. The footer's "Cookie
       settings" reopens it with the switches showing.

     Either way the answer is one plain cookie the server reads through
     CookieConsentService, so it applies on the next page without an account. A
     notice's "Got it" writes strictly-necessary only, which is all it covers. --}}
@php($categories = App\Enums\CookieCategoryEnum::cases())

@if ($banner)
    <div
        x-cloak
        x-data="{
            open: false,
            customise: false,
            chosen: { necessary: true, measurement: false },
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
                const value = encodeURIComponent(this.version + ':' + categories.join(','))
                const secure = location.protocol === 'https:' ? '; secure' : ''

                document.cookie = `${this.name}=${value}; path=/; max-age=${this.days * 86400}; samesite=lax${secure}`
                Object.keys(this.chosen).forEach((key) => this.chosen[key] = categories.includes(key))
                this.open = false
                this.customise = false
                window.dispatchEvent(new CustomEvent('cookie-consent-updated', { detail: categories }))
            },

            saveChosen() {
                this.save(Object.keys(this.chosen).filter((key) => this.chosen[key]))
            },
        }"
        x-on:cookie-settings.window="open = true; customise = true"
        x-on:keydown.escape.window="if (open && read()) open = false"
        x-show="open"
        x-transition.duration.200ms
        class="fixed inset-x-0 bottom-0 z-50 border-t border-slate-200 bg-white/95 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95"
        role="{{ $banner['consent'] ? 'dialog' : 'region' }}"
        aria-label="{{ $banner['consent'] ? __('Your cookie choices') : __('Cookie notice') }}"
    >
        <div class="mx-auto w-full max-w-6xl px-6 py-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <flux:text class="text-sm">
                    @if ($banner['consent'])
                        {{ __('We use cookies to keep you signed in and, if you allow it, to count visits with Google Analytics so we can improve the site.') }}
                    @else
                        {{ __('This site uses cookies to keep you signed in and to remember what you were doing.') }}
                    @endif
                    <a
                        href="{{ App\Enums\PolicyTypeEnum::COOKIES->url() }}"
                        class="font-medium text-slate-900 underline underline-offset-2 transition-colors hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                    >
                        {{ App\Enums\PolicyTypeEnum::COOKIES->defaultTitle() }}
                    </a>
                </flux:text>

                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    @if ($banner['consent'])
                        <flux:button size="sm" variant="ghost" x-on:click="customise = ! customise">
                            <span x-text="customise ? @js(__('Back')) : @js(__('Choose'))"></span>
                        </flux:button>
                        <template x-if="! customise">
                            <div class="flex gap-2">
                                <flux:button size="sm" x-on:click="save(['necessary'])">{{ __('Necessary only') }}</flux:button>
                                <flux:button size="sm" variant="primary" x-on:click="save(@js(collect($categories)->pluck('value')->all()))">{{ __('Accept all') }}</flux:button>
                            </div>
                        </template>
                        <template x-if="customise">
                            <flux:button size="sm" variant="primary" x-on:click="saveChosen()">{{ __('Save my choices') }}</flux:button>
                        </template>
                    @else
                        <flux:button size="sm" variant="primary" x-on:click="save(['necessary'])">
                            {{ __('Got it') }}
                        </flux:button>
                    @endif
                </div>
            </div>

            @if ($banner['consent'])
                <ul x-show="customise" x-collapse class="mt-4 divide-y divide-slate-200 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
                    @foreach ($categories as $category)
                        <li class="flex items-start justify-between gap-4 px-4 py-3">
                            <div>
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ __($category->label()) }}</p>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __($category->description()) }}</p>
                            </div>
                            @if ($category->isRequired())
                                <span class="shrink-0 pt-0.5 text-xs font-semibold text-slate-500 dark:text-slate-400">{{ __('Always on') }}</span>
                            @else
                                <flux:switch x-model="chosen.{{ $category->value }}" class="shrink-0" aria-label="{{ __($category->label()) }}" />
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endif
