{{-- The newsletter as an interruption, after `preferences.newsletter.popup-delay`
     seconds of reading.

     A card anchored to the corner rather than a modal over a backdrop. A modal has
     to trap focus and hand it back to stay usable from a keyboard, and one that
     appears on a timer takes focus away from somebody mid-sentence to do it. A
     corner card asks the same question and costs the reader nothing if they ignore
     it.

     Dismissal is per-browser and lives in localStorage: it is a courtesy to the
     reader, not something the site needs to know, and there is no account to hang
     it on for a visitor who has not signed in. --}}
@php($newsletter = app(App\Services\NewsletterService::class))

@if ($newsletter->showsPopup())
    <div
        x-cloak
        x-data="{
            open: false,

            init() {
                // A browser with storage blocked or cleared reports nothing rather
                // than throwing the page away, so a private window still works — it
                // just does not remember the dismissal.
                try {
                    if (localStorage.getItem('newsletter-popup')) return
                } catch (e) {}

                setTimeout(() => { this.open = true }, {{ $newsletter->popupDelay() * 1000 }})
            },

            dismiss() {
                this.open = false

                try {
                    localStorage.setItem('newsletter-popup', '1')
                } catch (e) {}
            },
        }"
        x-on:newsletter-subscribed.window="dismiss()"
        x-on:keydown.escape.window="dismiss()"
        x-show="open"
        x-transition.duration.200ms
        class="fixed inset-x-4 bottom-4 z-40 sm:inset-x-auto sm:right-6 sm:bottom-6 sm:w-96"
        role="complementary"
        aria-label="Newsletter sign-up"
    >
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xl dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-start justify-between gap-3">
                <flux:heading size="lg">Before you go</flux:heading>

                <button
                    type="button"
                    class="press -mr-1 -mt-1 rounded-md p-1 text-slate-400 transition-colors duration-150 ease-out hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 dark:hover:text-slate-300"
                    aria-label="Dismiss the newsletter sign-up"
                    x-on:click="dismiss()"
                >
                    <flux:icon name="x-mark" class="size-4" />
                </button>
            </div>

            <flux:text class="mt-1 text-sm">
                Occasional updates from {{ $_configs['name'] }} — no more than one email a month.
            </flux:text>

            <div class="mt-4">
                {{-- The second copy of the footer's form, so it needs its own key. --}}
                <livewire:livewire.newsletter-form key="newsletter-popup" />
            </div>
        </div>
    </div>
@endif
