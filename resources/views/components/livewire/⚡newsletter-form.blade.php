<?php

use App\Rules\EmailRule;
use App\Services\NewsletterService;
use App\Traits\WithCaptcha;
use App\Traits\WithFormResponseMessage;
use Livewire\Component;

/**
 * The sign-up form itself, and nothing around it.
 *
 * Rendered twice on a public page — once in the footer, once inside the popup —
 * so it deliberately carries no heading, no card and no chrome. The two callers
 * own how it is framed; this owns what happens when somebody presses Subscribe.
 */
new class extends Component
{
    use WithCaptcha, WithFormResponseMessage;

    public string $email = '';

    /**
     * Set once the address is recorded, which swaps the form for the thank-you
     * rather than clearing it. A visitor who presses twice cannot send twice, and
     * the popup listens for the same moment to take itself off the screen.
     */
    public bool $subscribed = false;

    protected function rules(): array
    {
        return $this->captchaRules([
            'email' => new EmailRule,
        ]);
    }

    public function subscribe(): bool
    {
        $service = app(NewsletterService::class);

        // The master switch is the boundary, not the template that hid the form.
        // A page rendered while the newsletter was on still carries a working
        // form in somebody's open tab, and this is what stops it writing a row
        // after the feature was turned off underneath it.
        $this->respondError(
            'The newsletter is not accepting sign-ups at the moment.',
            ! $service->isEnabled(),
            field: 'email',
        );

        $this->validate();

        $service->subscribe($this->email);

        $this->subscribed = true;

        // The popup wrapping this copy closes on it, and remembers that it did.
        $this->dispatch('newsletter-subscribed');

        return $this->respondSuccess('You are on the list. Thank you!');
    }
};
?>

<div>
    @if ($subscribed)
        <div class="flex items-start gap-3 rounded-lg bg-lime-50 px-4 py-3 dark:bg-lime-500/10">
            <flux:icon name="check-circle" class="mt-0.5 size-5 shrink-0 text-lime-600 dark:text-lime-400" />
            <flux:text class="text-sm">
                You are on the list. Look out for the next one.
            </flux:text>
        </div>
    @else
        <form wire:submit="subscribe" class="space-y-3">
            <div class="flex flex-col gap-3 sm:flex-row">
                {{-- Labelled for a screen reader only: two copies of this form sit
                     on the same page, and a visible label on each reads as the site
                     asking the same question twice. --}}
                <flux:input
                    wire:model="email"
                    type="email"
                    placeholder="you@example.com"
                    autocomplete="email"
                    aria-label="Email address"
                    class="sm:flex-1"
                />

                <flux:button type="submit" variant="primary" icon="envelope">
                    Subscribe
                </flux:button>
            </div>

            @if ($this->captchaRequired())
                <x-form.captcha action="newsletter" />
            @endif
        </form>
    @endif
</div>
