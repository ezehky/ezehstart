@php($recurrence = \App\Enums\EmailRecurrenceEnum::from($email_recurrence))
@php($customSender = $this::CUSTOM_SENDER)

{{-- max-w-4xl --}}

<div class="mx-auto grid grid-cols-1 gap-6 lg:grid-cols-[1fr_320px]">
    <div class="space-y-6">
        <flux:card class="space-y-4">
            <flux:heading level="2" size="lg">Email settings</flux:heading>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:input wire:model="from_name" label="From name" />

                {{-- A select, not a box. An address the mail service has never been told
                    to send for is a campaign that lands in spam, so the only choices
                    are the ones Email Senders has actually been configured with. --}}
                <flux:select
                    wire:model.live="from_email"
                    label="From email"
                    description:trailing="Set these up once under Configuration → Email Senders."
                >
                    @foreach ($this->senderOptions as $address => $label)
                        <flux:select.option value="{{ $address }}">{{ $label }}</flux:select.option>
                    @endforeach

                    @if ($this->senderDomain)
                        <flux:select.option value="{{ $customSender }}">
                            An address at &#64;{{ $this->senderDomain }}
                        </flux:select.option>
                    @endif

                    @if ($from_email !== $customSender && $from_email !== '' && ! isset($this->senderOptions[$from_email]))
                        <flux:select.option value="{{ $from_email }}">{{ $from_email }} (not configured)</flux:select.option>
                    @endif
                </flux:select>
            </div>

            @if ($from_email === $customSender)
                <flux:input
                    wire:model="from_username"
                    label="Address"
                    placeholder="e.g. news"
                    :description="'Sends as whatever you type, at @'.$this->senderDomain.'.'"
                />
            @endif

            <flux:input wire:model="reply_to" label="Reply-to (optional)" placeholder="e.g example@mail.com" />

            <flux:button wire:click="saveSenderDetails" variant="ghost" size="sm" icon="check">Save sender details</flux:button>
        </flux:card>

        <flux:card class="space-y-3">
            <flux:heading level="3" size="md">Email preview</flux:heading>
            {{-- The iframe scrolls its own document. Wrapping it in a scrollable box
                 too puts a second bar right beside the first. --}}
            <div class="mx-auto w-full overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                <iframe srcdoc="{{ $this->previewHtml }}" class="h-[420px] w-full" title="Email preview"></iframe>
            </div>
        </flux:card>
    </div>

    <div class="space-y-4">
        <flux:card class="space-y-3 bg-slate-900 text-white dark:bg-slate-950">
            <flux:heading size="md" class="text-white!">Ready to send?</flux:heading>

            <dl class="space-y-2 text-xs">
                <div class="flex justify-between border-t border-white/10 pt-2 first:border-t-0 first:pt-0">
                    <dt class="text-slate-400">Subject</dt><dd class="text-end">{{ $subject }}</dd>
                </div>
                <div class="flex justify-between border-t border-white/10 pt-2">
                    <dt class="text-slate-400">Recipients</dt><dd>{{ number_format($this->estimatedRecipients) }} users</dd>
                </div>
                <div class="flex justify-between border-t border-white/10 pt-2">
                    <dt class="text-slate-400">Audience</dt>
                    <dd class="text-end">{{ \App\Enums\EmailRecipientTypeEnum::from($email_recipient_type)->label() }}</dd>
                </div>
                @if ($recurrence->isRepeating())
                    <div class="flex justify-between border-t border-white/10 pt-2">
                        <dt class="text-slate-400">Repeats</dt>
                        <dd class="text-end">{{ $recurrence->label() }}</dd>
                    </div>
                @endif
            </dl>

            <div class="space-y-2 border-t border-white/10 pt-3">
                <flux:button x-on:click="$flux.modal('sendTestModal').show()" variant="ghost" icon="paper-airplane" class="w-full justify-center text-white!">Send Test</flux:button>
            </div>
        </flux:card>

        <flux:card class="space-y-4">
            <flux:heading level="3" size="md">When</flux:heading>

            <div class="flex gap-2">
                <flux:button wire:click="$set('send_option', 'now')" variant="{{ $send_option === 'now' ? 'primary' : 'subtle' }}" size="sm" class="flex-1 justify-center">
                    Send immediately
                </flux:button>
                <flux:button wire:click="$set('send_option', 'schedule')" variant="{{ $send_option === 'schedule' ? 'primary' : 'subtle' }}" size="sm" class="flex-1 justify-center">
                    Schedule
                </flux:button>
            </div>

            @if ($send_option === 'schedule')
                <div class="grid grid-cols-2 gap-3">
                    <flux:input type="date" wire:model="scheduled_date" label="Date" />
                    <flux:input type="time" wire:model="scheduled_time" label="Time" />
                </div>

                <flux:select wire:model="timezone" label="Timezone">
                    @foreach ($timezones as $tz)
                        <flux:select.option value="{{ $tz }}">{{ $tz }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            {{-- A repeat does not re-send this campaign. Each run finishes as its own
                 record of what went out, and the next one is created from it — which
                 is why the setting sits with the date rather than with the content. --}}
            <flux:select wire:model.live="email_recurrence" label="Repeat">
                @foreach (\App\Enums\EmailRecurrenceEnum::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">
                        {{ $case->isNone() ? 'Does not repeat' : $case->label() }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            @if ($recurrence->isRepeating())
                <flux:input
                    type="date"
                    wire:model="recurrence_ends_at"
                    label="Repeat until (optional)"
                    :description="$recurrence->description().' Leave empty to keep going.'"
                />
            @endif

            @if ($send_option === 'schedule')
                <flux:button wire:click="schedule" variant="primary" icon="clock" class="w-full justify-center">Schedule</flux:button>
            @else
                @if ($this->estimatedRecipients >= $this->largeAudienceThreshold())
                    <label class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs dark:border-amber-800 dark:bg-amber-400/10">
                        <input type="checkbox" wire:model="confirm_large_send" class="mt-0.5">
                        Yes, send this campaign to {{ number_format($this->estimatedRecipients) }} recipients.
                    </label>
                @endif

                <flux:button wire:click="send" variant="primary" icon="paper-airplane" class="w-full justify-center">Send Now</flux:button>
            @endif
        </flux:card>
    </div>

    <div class="lg:col-span-2">
        <flux:button wire:click="$set('step', 'recipients')" variant="ghost" icon="arrow-left">Back</flux:button>
    </div>
</div>

<flux:modal name="sendTestModal" class="modal-sm">
    <div class="space-y-4">
        <flux:heading size="lg">Send a test email</flux:heading>
        <flux:text>Renders exactly as a recipient would receive it, including dynamic content and variables.</flux:text>

        <div>
            <flux:label>Send to</flux:label>
            <div class="mt-1">
                @include('pages.admin.marketing.partials._email-chips', [
                    'emails' => $test_emails,
                    'model' => 'test_email_input',
                    'add' => 'addTestEmail',
                    'remove' => 'removeTestEmail',
                ])
            </div>
        </div>

        <flux:select wire:model="preview_as_user_id" label="Preview as">
            <flux:select.option value="">Nobody in particular</flux:select.option>
            @foreach (\App\Models\User::query()->users()->limit(50)->get() as $user)
                <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="flex justify-end gap-2">
            <flux:button x-on:click="$flux.modal('sendTestModal').close()" variant="ghost">Cancel</flux:button>
            <flux:button wire:click="sendTest" variant="primary">Send test</flux:button>
        </div>
    </div>
</flux:modal>
