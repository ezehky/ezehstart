@php(kSetSiteTitle('Unsubscribed'))

{{-- Where the unsubscribe link in an email lands.

     A page rather than a redirect with a flash: the person arriving has just left
     a mailing list and wants to see that it worked, and a public page has nowhere
     reliable to show a flash message. --}}
<x-layouts.base class="min-h-screen">
    <div class="flex min-h-screen flex-col">
        <x-site.header />

        <main class="mx-auto flex w-full max-w-3xl flex-1 flex-col justify-center px-6 py-16">
            <x-dashboard.icon-box icon="check-circle" tone="emerald" />

            <h1 class="mt-5 font-heading text-3xl font-bold tracking-tight dark:text-white">
                You are unsubscribed
            </h1>

            <flux:text class="mt-3 text-lg dark:text-slate-400">
                We will not send any more newsletters to <strong>{{ $email }}</strong>. Account
                emails — sign-in codes, security alerts and anything about a change you made —
                are not part of this and still go out.
            </flux:text>

            <flux:text class="mt-6 text-sm">
                Changed your mind? Sign up again from the form at the bottom of any page.
            </flux:text>

            <div class="mt-8">
                <flux:button href="{{ route('home') }}" variant="primary" icon="arrow-left">
                    Back to the site
                </flux:button>
            </div>
        </main>

        <x-site.footer />
    </div>
</x-layouts.base>
