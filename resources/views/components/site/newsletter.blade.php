{{-- The sign-up block in the public footer.

     One of the two places the newsletter is offered, and the quiet one: it sits
     where somebody who has finished reading will look for it, rather than arriving
     over what they were reading. Whether it appears is
     `preferences.newsletter.footer`, behind the master switch. --}}
@php($newsletter = app(App\Services\NewsletterService::class))

@if ($newsletter->showsInFooter())
    <div class="mb-8 grid gap-6 rounded-xl border border-slate-200 p-6 sm:grid-cols-[1.1fr_1fr] sm:items-center dark:border-slate-800">
        <div>
            <flux:heading size="lg">Get the newsletter</flux:heading>
            <flux:text class="mt-1 text-sm">
                Occasional updates from {{ $_configs['name'] }}. No more than one email a month, and
                every one of them has an unsubscribe link.
            </flux:text>
        </div>

        {{-- Keyed because the popup renders a second copy of the same component on
             the same page, and Livewire needs to tell the two apart. --}}
        <livewire:livewire.newsletter-form key="newsletter-footer" />
    </div>
@endif
