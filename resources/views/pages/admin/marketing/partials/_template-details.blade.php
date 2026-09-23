{{--
    Step 1 of the template builder — the same shape as _campaign-details.blade.php:
    the name and description a template needs before there is anything to build.
    Footer and Design stay on the builder step, next to the canvas, because both
    are about the letter itself rather than the template row.
--}}

<flux:card class="mx-auto max-w-2xl space-y-5">
    <div>
        <flux:heading level="2" size="lg">Template details</flux:heading>
        <flux:text class="mt-1">Everything here can change later — the canvas is next.</flux:text>
    </div>

    <flux:input wire:model="name" label="Template name" placeholder="e.g. Newsletter Template" />
    <flux:input wire:model="description" label="Description" description="Internal only — shown to admins picking a starting point for a campaign." />

    <flux:separator variant="subtle" />

    {{-- A template can stand in for one of the emails the app sends by itself.
         Unassigned, that email goes out from its built-in view as before. --}}
    <flux:select wire:model.live="system_email" label="Use as a system email" description="Replaces the built-in design of that email. Leave on None for an ordinary campaign template.">
        <flux:select.option value="">None</flux:select.option>
        @foreach (App\Enums\SystemEmailEnum::cases() as $case)
            <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
        @endforeach
    </flux:select>

    @if ($this->systemEmailCase)
        <flux:input
            wire:model="subject"
            label="Subject"
            :placeholder="$this->systemEmailCase->defaultSubject()"
            description="Tokens work here too. Blank uses the placeholder."
        />

        <flux:callout icon="variable" color="zinc" class="text-sm">
            <flux:callout.heading>{{ $this->systemEmailCase->description() }}</flux:callout.heading>
            <flux:callout.text>
                Tokens this email adds, on top of the site and recipient ones:
                <ul class="mt-2 space-y-1">
                    @foreach ($this->systemEmailCase->tokens() as $token => $tokenLabel)
                        <li><code class="text-xs">{{ $token }}</code> — {{ $tokenLabel }}</li>
                    @endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex justify-end gap-2">
        <flux:button href="{{ route('admin.marketing.templates') }}" wire:navigate variant="ghost">Cancel</flux:button>
        <x-dashboard.gate.button
            gate="marketing.templates"
            :level="$template ? $gateModify : $gateCreate"
            wire:click="saveDetails"
            icon:trailing="arrow-right"
        >
            Continue to Builder
        </x-dashboard.gate.button>
    </div>
</flux:card>
