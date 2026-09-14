{{-- The cookie notice, behind `preferences.accept-cookies`.

     A notice rather than a consent gate, and deliberately so: this kit sets the
     session and CSRF cookies and nothing else, and those are the cookies a notice
     covers rather than the ones that need permission first. An install that adds
     analytics needs more than this — a real choice, and nothing loading until the
     visitor has made it — and the switch is here so that install can turn this one
     off and put that in its place.

     Acknowledgement is per-browser in localStorage. There is no account to hang it
     on for a visitor who has not signed in, and the site does not need to know. --}}
@if (kSiteFlag('preferences', 'accept-cookies', true))
    <div
        x-cloak
        x-data="{
            open: false,

            init() {
                // Storage can be blocked or cleared; a browser that says nothing
                // gets the notice, which is the right way round to fail.
                try {
                    this.open = ! localStorage.getItem('cookie-notice')
                } catch (e) {
                    this.open = true
                }
            },

            accept() {
                this.open = false

                try {
                    localStorage.setItem('cookie-notice', '1')
                } catch (e) {}
            },
        }"
        x-show="open"
        x-transition.duration.200ms
        class="fixed inset-x-0 bottom-0 z-50 border-t border-slate-200 bg-white/95 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95"
        role="region"
        aria-label="Cookie notice"
    >
        <div class="mx-auto flex w-full max-w-6xl flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
            <flux:text class="text-sm">
                This site uses cookies to keep you signed in and to remember what you were doing.
                <a
                    href="{{ App\Enums\PolicyTypeEnum::COOKIES->url() }}"
                    class="font-medium text-slate-900 underline underline-offset-2 transition-colors hover:text-lime-600 dark:text-white dark:hover:text-lime-400"
                >
                    {{ App\Enums\PolicyTypeEnum::COOKIES->defaultTitle() }}
                </a>
            </flux:text>

            <flux:button size="sm" variant="primary" class="shrink-0" x-on:click="accept()">
                Got it
            </flux:button>
        </div>
    </div>
@endif
