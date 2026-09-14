<?php

use App\Enums\EmailSectionTypeEnum;
use App\Enums\GateAccessEnum;
use App\Enums\NotificationTypeEnum;
use App\Models\EmailSection;
use App\Services\EmailSectionService;
use App\Traits\WithFormResponseMessage;
use App\Traits\WithGateProps;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage, WithGateProps;

    public ?int $default_footer_id = null;

    public function mount(): void
    {
        kSetSiteTitle('marketing', 'settings');
        $this->setPageGate('marketing.settings');

        $this->default_footer_id = app(EmailSectionService::class)->defaultFor(EmailSectionTypeEnum::FOOTER)?->id;
    }

    #[Computed]
    public function footers()
    {
        return app(EmailSectionService::class)->libraryQuery(EmailSectionTypeEnum::FOOTER)->get();
    }

    public function saveDefaultFooter(): bool
    {
        $this->checkGate();

        $section = EmailSection::query()->ofType(EmailSectionTypeEnum::FOOTER)->whereKey($this->default_footer_id)->first();

        $this->respondError('Choose a footer first.', ! $section);

        app(EmailSectionService::class)->setDefault($section);

        unset($this->footers);

        return $this->respondSuccess('Default footer updated.');
    }
};
?>

<div class="space-y-6">
    <x-dashboard.page-header icon="megaphone" subtitle="Design templates, build campaigns, and send to your audience.">
        <x-slot:actions>
            <x-dashboard.gate.button gate="marketing.campaigns" level="create" href="{{ route('admin.marketing.campaigns.create') }}" wire:navigate variant="primary" icon="plus">
                Create Email
            </x-dashboard.gate.button>
        </x-slot:actions>
    </x-dashboard.page-header>

    <x-marketing.tabs active="settings" />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <flux:card class="space-y-4">
            <div>
                <flux:heading level="3" size="md">Default footer</flux:heading>
                <flux:text class="mt-1">Applied to a new campaign unless the builder picks another.</flux:text>
            </div>

            @if ($this->footers->isEmpty())
                <flux:text>No saved footers yet. <flux:link href="{{ route('admin.marketing.sections.create') }}" wire:navigate>Create one</flux:link>.</flux:text>
            @else
                <form wire:submit="saveDefaultFooter" class="flex items-end gap-3">
                    <flux:select wire:model="default_footer_id" label="Footer" class="flex-1">
                        @foreach ($this->footers as $footer)
                            <flux:select.option value="{{ $footer->id }}">{{ $footer->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <x-dashboard.gate.button :gate="$pageGate" :level="$gateModify" type="submit">Save</x-dashboard.gate.button>
                </form>
            @endif
        </flux:card>

        <flux:card class="space-y-4">
            <div>
                <flux:heading level="3" size="md">Notification preferences</flux:heading>
                <flux:text class="mt-1">Categories members can opt into — what the Recipients step targets.</flux:text>
            </div>

            <div class="space-y-2">
                @foreach (NotificationTypeEnum::cases() as $type)
                    <div class="flex items-center justify-between border-t border-slate-100 py-2 first:border-t-0 dark:border-white/10">
                        <span class="text-sm">{{ $type->label() }}</span>
                        <span class="text-xs text-slate-400">{{ $type->description() }}</span>
                    </div>
                @endforeach
            </div>

            <flux:button href="{{ route('admin.config.notification-types') }}" wire:navigate variant="ghost" size="sm" icon="cog-6-tooth">
                Manage notification types
            </flux:button>
        </flux:card>

        <flux:card class="space-y-4 lg:col-span-2">
            <div>
                <flux:heading level="3" size="md">Sender identities &amp; domains</flux:heading>
                <flux:text class="mt-1">A campaign's "From" and "Reply-to" default here — set once for the whole site.</flux:text>
            </div>

            <flux:button href="{{ route('admin.config.email-senders') }}" wire:navigate icon="at-symbol">
                Open Email Senders
            </flux:button>
        </flux:card>
    </div>
</div>
