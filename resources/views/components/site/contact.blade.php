{{-- The contact section of the front page: one card per location, each with its
     own map when one was given.

        <x-site.contact :locations="$this->contactLocations" />

     Presentation only: the locations come from SiteConfigurationService::
     contactLocations(), which has already re-checked every map link. Silent when
     handed nothing, and a location without a map renders no frame at all — so a
     page with no maps makes no request to a map provider. --}}
@props([
    // [{label, address, phones, emails, map}], in the order the admin saved them.
    'locations' => [],
])

@if (filled($locations))
    <section id="contact" class="mx-auto w-full max-w-6xl scroll-mt-8 border-t border-slate-200 px-6 py-16 dark:border-white/10">
        <h2 class="font-heading text-3xl font-bold tracking-tight text-slate-950 dark:text-white">
            {{ __('Get in touch') }}
        </h2>
        <flux:text class="mt-3">
            {{ count($locations) > 1 ? __('Find the office nearest to you.') : __('Here is where to find us.') }}
        </flux:text>

        <div @class(['mt-10 grid gap-6', 'lg:grid-cols-2' => count($locations) > 1])>
            @foreach ($locations as $location)
                <flux:card class="flex flex-col gap-5 overflow-hidden">
                    <flux:heading size="lg">{{ $location['label'] }}</flux:heading>

                    <dl class="space-y-3 text-sm">
                        @if ($location['address'])
                            <div class="flex gap-3">
                                <dt><flux:icon.map-pin class="size-5 text-lime-600 dark:text-lime-400" /><span class="sr-only">{{ __('Address') }}</span></dt>
                                <dd class="text-slate-700 dark:text-slate-300 whitespace-pre-line">{{ $location['address'] }}</dd>
                            </div>
                        @endif

                        @if ($location['phones'])
                            <div class="flex gap-3">
                                <dt><flux:icon.phone class="size-5 text-lime-600 dark:text-lime-400" /><span class="sr-only">{{ __('Phone') }}</span></dt>
                                <dd class="flex flex-wrap gap-x-4 gap-y-1">
                                    @foreach ($location['phones'] as $phone)
                                        <flux:link href="tel:{{ kPhoneInternational($phone) }}" variant="subtle">{{ $phone }}</flux:link>
                                    @endforeach
                                </dd>
                            </div>
                        @endif

                        @if ($location['emails'])
                            <div class="flex gap-3">
                                <dt><flux:icon.envelope class="size-5 text-lime-600 dark:text-lime-400" /><span class="sr-only">{{ __('Email') }}</span></dt>
                                <dd class="flex flex-wrap gap-x-4 gap-y-1">
                                    @foreach ($location['emails'] as $email)
                                        <flux:link href="mailto:{{ $email }}" variant="subtle">{{ $email }}</flux:link>
                                    @endforeach
                                </dd>
                            </div>
                        @endif
                    </dl>

                    @if ($location['map'])
                        <iframe
                            src="{{ $location['map'] }}"
                            title="{{ __('Map of :location', ['location' => $location['label']]) }}"
                            class="mt-auto aspect-video w-full rounded-lg border border-slate-200 dark:border-white/10"
                            loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade"
                            allowfullscreen
                        ></iframe>
                    @endif
                </flux:card>
            @endforeach
        </div>
    </section>
@endif
