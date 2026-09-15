<flux:card class="mx-auto max-w-2xl space-y-5">
    <div>
        <flux:heading level="2" size="lg">Campaign details</flux:heading>
        <flux:text class="mt-1">
            Start from a template, or a blank canvas; everything here can change in the builder next.
        </flux:text>
    </div>

    @if (! $campaign)
        <div>
            <flux:label>Start from</flux:label>
            <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <button
                    type="button"
                    wire:click="$set('template_id', null)"
                    @class([
                        'rounded-lg border-2 p-3 text-center text-xs font-medium',
                        'border-lime-500 bg-lime-50 dark:bg-lime-400/10' => ! $template_id,
                        'border-slate-200 dark:border-slate-700' => (bool) $template_id
                    ])
                >
                    Blank
                </button>
                @foreach ($this->templates as $item)
                    <button
                        type="button"
                        wire:click="$set('template_id', {{ $item->id }})"
                        @class([
                            'rounded-lg border-2 p-3 text-center text-xs font-medium',
                            'border-lime-500 bg-lime-50 dark:bg-lime-400/10' => $template_id === $item->id,
                            'border-slate-200 dark:border-slate-700' => $template_id !== $item->id
                        ])
                    >
                        {{ $item->name }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    <flux:input
        wire:model="name"
        label="Campaign name"
        description="Internal only, never shown in the email."
        placeholder="e.g. September Product Update"
    />
    <flux:input
        wire:model="subject"
        label="Subject"
        description="Supports variables, e.g. @{{user.first_name}}."
        placeholder="e.g. New products you'll love"
    />
    <flux:input
        wire:model="preview_text"
        label="Preview text"
        description="Shown next to the subject line in most inboxes."
        placeholder="e.g. Take a look at what's new..."
    />

    <div class="flex justify-end gap-2">
        <flux:button href="{{ route('admin.marketing.campaigns') }}" wire:navigate variant="ghost">Cancel</flux:button>
        <x-dashboard.gate.button
            gate="marketing.campaigns"
            :level="$campaign ? $gateModify : $gateCreate"
            wire:click="saveDetails"
            icon:trailing="arrow-right"
        >
            Continue to Builder
        </x-dashboard.gate.button>
    </div>
</flux:card>
