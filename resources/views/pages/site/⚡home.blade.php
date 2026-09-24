<?php

use App\Models\Faq;
use App\Services\BlogService;
use App\Services\SiteStatsService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The front page.
 *
 * Every section below reads its data here and is handed it as a prop, so the
 * components stay presentation only. Each one renders nothing when handed
 * nothing — an untouched starter kit shows no empty stats, posts or accordion.
 */
new #[Layout('layouts::site')] class extends Component
{
    public function mount(): void
    {
        kSetSiteTitle('home');
    }

    /**
     * The same switch the sign-in screens read. The button goes with it, so a
     * turned-off feature does not leave a link to a route that aborts.
     */
    #[Computed]
    public function passwordlessEnabled(): bool
    {
        return (bool) kSiteFlag('security', 'passwordless-login', false);
    }

    /**
     * @return list<array{to: int, label: string, plus: bool, icon: string}>
     */
    #[Computed]
    public function stats(): array
    {
        return app(SiteStatsService::class)->frontPage();
    }

    #[Computed]
    public function latestPosts(): Collection
    {
        return app(BlogService::class)->publishedQuery()->limit(3)->get();
    }

    #[Computed]
    public function faqs(): Collection
    {
        return Faq::query()->active()->inFlowOrder()->get();
    }
};
?>

<div>
    <section class="mx-auto flex w-full max-w-6xl flex-col justify-center px-6 py-16">
        <div class="max-w-2xl">
            <flux:text color="lime" class="font-semibold">{{ __('Starter kit') }}</flux:text>
            <h1 class="mt-3 font-heading text-4xl font-bold tracking-tight sm:text-5xl dark:text-white">
                {{ $_configs['name'] }}
            </h1>
            <flux:text class="mt-5 text-lg dark:text-slate-400">
                {{ __('Authentication, roles, two workspaces and account management — already wired, already tested. Replace this page and start building the part that is yours.') }}
            </flux:text>

            <div class="mt-8 flex flex-wrap gap-3">
                @guest
                    <flux:button :href="route('register')" variant="primary" icon="arrow-right" wire:navigate>
                        {{ __('Create an account') }}
                    </flux:button>
                    <x-auth.passwordless :enabled="$this->passwordlessEnabled" />
                @endguest
            </div>
        </div>

        <div class="mt-16 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['icon' => 'lock-closed', 'title' => 'Auth, four ways', 'body' => 'Password, passwordless codes, email verification and reset.'],
                ['icon' => 'users', 'title' => 'Roles and workspaces', 'body' => 'Admin and member, each behind its own middleware and route file.'],
                ['icon' => 'clock', 'title' => 'Audit trail', 'body' => 'Every admin write records what changed, and what it changed from.'],
                ['icon' => 'cog-6-tooth', 'title' => 'Site configuration', 'body' => 'Editable settings with sensible defaults, no deploy required.'],
            ] as $feature)
                <flux:card class="space-y-3">
                    <x-dashboard.icon-box size="sm" :icon="$feature['icon']" tone="emerald" />
                    <flux:heading size="lg">{{ __($feature['title']) }}</flux:heading>
                    <flux:text class="text-sm">{{ __($feature['body']) }}</flux:text>
                </flux:card>
            @endforeach
        </div>
    </section>

    <x-site.stats :stats="$this->stats" />

    <x-site.latest-posts :posts="$this->latestPosts" />

    <x-site.faq :faqs="$this->faqs" />
</div>
