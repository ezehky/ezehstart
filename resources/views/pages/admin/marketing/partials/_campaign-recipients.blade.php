<div class="mx-auto max-w-2xl space-y-4">
    <flux:card class="space-y-5">
        <div>
            <flux:heading level="2" size="lg">Send to</flux:heading>
            <flux:text class="mt-1">Choose who receives this campaign.</flux:text>
        </div>

        <div class="space-y-3">
            <label class="flex cursor-pointer items-start gap-3 rounded-xl border-2 p-4 {{ $email_recipient_type === 'all_users' ? 'border-lime-500 bg-lime-50 dark:bg-lime-400/10' : 'border-slate-200 dark:border-slate-700' }}">
                <input type="radio" wire:model.live="email_recipient_type" value="all_users" class="mt-1">
                <span>
                    <span class="block font-medium">All users</span>
                    <span class="block text-xs text-slate-500 dark:text-slate-400">Every account in the member workspace.</span>
                </span>
            </label>

            <label class="flex cursor-pointer items-start gap-3 rounded-xl border-2 p-4 {{ $email_recipient_type === 'preferences' ? 'border-lime-500 bg-lime-50 dark:bg-lime-400/10' : 'border-slate-200 dark:border-slate-700' }}">
                <input type="radio" wire:model.live="email_recipient_type" value="preferences" class="mt-1">
                <span class="w-full">
                    <span class="block font-medium">Notification preferences</span>
                    <span class="block text-xs text-slate-500 dark:text-slate-400">Users opted in to one or more of these categories.</span>

                    @if ($email_recipient_type === 'preferences')
                        <span class="mt-3 block space-y-2">
                            @foreach ($this->notificationTypes as $type)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" wire:model.live="notification_type_values" value="{{ $type->notification_type }}">
                                    {{ kBreakText($type->notification_type) }}
                                </label>
                            @endforeach
                        </span>
                    @endif
                </span>
            </label>

            <label class="flex cursor-pointer items-start gap-3 rounded-xl border-2 p-4 {{ $email_recipient_type === 'specific' ? 'border-lime-500 bg-lime-50 dark:bg-lime-400/10' : 'border-slate-200 dark:border-slate-700' }}">
                <input type="radio" wire:model.live="email_recipient_type" value="specific" class="mt-1">
                <span class="w-full">
                    <span class="block font-medium">Specific email addresses</span>
                    <span class="block text-xs text-slate-500 dark:text-slate-400">Enter one or more recipients directly.</span>

                    @if ($email_recipient_type === 'specific')
                        <span class="mt-3 flex flex-wrap gap-1.5 rounded-lg border border-slate-200 p-2 dark:border-slate-700">
                            @foreach ($recipient_emails as $email)
                                <flux:badge size="sm">
                                    {{ $email }}
                                    <button type="button" wire:click="removeRecipientEmail('{{ $email }}')" class="ms-1">&times;</button>
                                </flux:badge>
                            @endforeach
                            <input
                                type="email"
                                wire:model="recipient_email_input"
                                wire:keydown.enter.prevent="addRecipientEmail"
                                placeholder="Add an email and press Enter"
                                class="min-w-40 flex-1 border-0 bg-transparent p-1 text-sm outline-none"
                            >
                        </span>
                    @endif
                </span>
            </label>
        </div>

        <div class="rounded-xl bg-slate-900 p-4 text-white dark:bg-slate-800">
            <p class="text-xs text-slate-400">Estimated recipients</p>
            <p class="font-heading text-2xl font-bold">{{ number_format($this->estimatedRecipients) }}</p>
        </div>

        <flux:callout icon="information-circle" color="zinc" class="text-xs">
            Individual email addresses aren't shown here — only aggregate counts.
        </flux:callout>
    </flux:card>

    <div class="flex justify-between">
        <flux:button wire:click="$set('step', 'builder')" variant="ghost" icon="arrow-left">Back</flux:button>
        <flux:button wire:click="saveRecipients" variant="primary" icon:trailing="arrow-right">Continue to Review</flux:button>
    </div>
</div>
