<?php

use App\Enums\ActivityActionEnum;
use App\Enums\EmailRecurrenceEnum;
use App\Enums\EmailSectionTypeEnum;
use App\Enums\GateAccessEnum;
use App\Models\EmailCampaign;
use App\Models\EmailSection;
use App\Models\EmailTemplate;
use App\Models\NotificationType;
use App\Models\User;
use App\Rules\EmailRule;
use App\Services\ActivityLogService;
use App\Services\EmailCampaignService;
use App\Services\EmailRenderService;
use App\Services\EmailSectionService;
use App\Services\EmailTemplateService;
use App\Traits\WithBlockEditor;
use App\Traits\WithEmailResolver;
use App\Traits\WithGateProps;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    use WithBlockEditor, WithEmailResolver, WithGateProps;

    public ?EmailCampaign $campaign = null;

    #[Url]
    public string $step = '';

    // Details
    public ?int $template_id = null;

    public string $name = '';

    public string $subject = '';

    public ?string $preview_text = null;

    // Builder
    public ?int $footer_section_id = null;

    public array $design = [];

    // Recipients
    public string $email_recipient_type = 'all_users';

    public array $recipient_emails = [];

    public string $recipient_email_input = '';

    public array $notification_type_values = [];

    // Review / send
    public string $from_name = '';

    public string $from_email = '';

    /**
     * The part before the "@" when the chosen sender is the site's own domain. A
     * domain is not an address — WithEmailResolver::setEmailFrom() asks for exactly
     * the same thing before it will build one.
     */
    public string $from_username = '';

    public ?string $reply_to = null;

    public string $send_option = 'now';

    public ?string $scheduled_date = null;

    public ?string $scheduled_time = null;

    public string $timezone;

    public string $email_recurrence = 'none';

    public ?string $recurrence_ends_at = null;

    public array $test_emails = [];

    public string $test_email_input = '';

    public ?int $preview_as_user_id = null;

    public bool $confirm_large_send = false;

    public const LARGE_AUDIENCE = 1000;

    /**
     * The select value standing for "an address on our own domain". Not an address
     * itself — choosing it reveals the username box that completes one.
     */
    public const CUSTOM_SENDER = '__custom__';

    public function largeAudienceThreshold(): int
    {
        return self::LARGE_AUDIENCE;
    }

    public function mount(?EmailCampaign $campaign = null): void
    {
        $this->timezone = config('app.timezone');
        $this->campaign = $campaign?->exists ? $campaign : null;

        kSetSiteTitle('marketing', 'campaigns', $this->campaign ? 'Edit campaign' : 'New campaign');
        $this->setPageGate('marketing.campaigns');

        if ($this->campaign) {
            $this->loadFromCampaign();
            $this->step = $this->step ?: 'builder';
        } else {
            $this->step = 'details';
            $this->from_email = (string) array_key_first($this->senderOptions);
            $this->from_name = (string) (kSiteConfig('name') ?: config('app.name'));
        }
    }

    private function loadFromCampaign(): void
    {
        $campaign = $this->campaign;

        $this->name = $campaign->name;
        $this->subject = $campaign->subject;
        $this->preview_text = $campaign->preview_text;
        $this->footer_section_id = $campaign->footer_section_id;
        $this->design = $campaign->design ?? [];
        $this->blocks = $campaign->content['blocks'] ?? [];
        $this->email_recipient_type = $campaign->email_recipient_type->value;
        $this->recipient_emails = $campaign->recipient_config['emails'] ?? [];
        $this->notification_type_values = $campaign->recipient_config['notification_types'] ?? [];
        $this->from_name = $campaign->from_name;
        $this->reply_to = $campaign->reply_to;
        $this->email_recurrence = ($campaign->email_recurrence ?? EmailRecurrenceEnum::NONE)->value;
        $this->recurrence_ends_at = $campaign->recurrence_ends_at?->format('Y-m-d');

        // An address that is no longer one of the configured senders is left showing
        // as it was saved rather than silently swapped for something else — which
        // sender replaces it is the administrator's decision, not this screen's.
        $domain = $this->customSenderDomain();

        if (isset($this->senderOptions[$campaign->from_email])) {
            $this->from_email = $campaign->from_email;
        } elseif ($domain && str_ends_with((string) $campaign->from_email, '@'.$domain)) {
            $this->from_email = self::CUSTOM_SENDER;
            $this->from_username = strstr((string) $campaign->from_email, '@', true) ?: '';
        } else {
            // An empty column would leave the select on nothing at all, which reads
            // as a choice somebody made rather than one nobody has made yet.
            $this->from_email = (string) ($campaign->from_email ?: array_key_first($this->senderOptions));
        }

        if ($campaign->scheduled_at) {
            $this->send_option = 'schedule';
            $this->scheduled_date = $campaign->scheduled_at->format('Y-m-d');
            $this->scheduled_time = $campaign->scheduled_at->format('H:i');
            $this->timezone = $campaign->timezone ?? $this->timezone;
        }
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // LOOKUPS

    #[Computed]
    public function templates()
    {
        return EmailTemplate::query()->orderBy('name')->get();
    }

    #[Computed]
    public function footers()
    {
        return app(EmailSectionService::class)->libraryQuery(EmailSectionTypeEnum::FOOTER)->get();
    }

    #[Computed]
    public function notificationTypes()
    {
        return NotificationType::query()->active()->get();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function senderOptions(): array
    {
        return $this->senderAddressOptions();
    }

    #[Computed]
    public function senderDomain(): ?string
    {
        return $this->customSenderDomain();
    }

    /**
     * What actually goes in the "From" header — the selected address, or the one
     * built out of the username and the site's own domain.
     */
    private function resolvedFromEmail(): string
    {
        if ($this->from_email !== self::CUSTOM_SENDER) {
            return trim($this->from_email);
        }

        $domain = $this->senderDomain;
        $username = trim($this->from_username);

        return $domain && $username ? "{$username}@{$domain}" : '';
    }

    /**
     * Whether the screen holds anything the campaign row does not. The close button
     * asks this before it lets the page go — everything in a builder lives in
     * component state until a Save Draft, and a closed tab is not a save.
     */
    public function hasUnsavedChanges(): bool
    {
        if (! $this->campaign) {
            return $this->name !== '' || $this->subject !== '' || $this->blocks !== [];
        }

        return $this->name !== $this->campaign->name
            || $this->subject !== $this->campaign->subject
            || (string) $this->preview_text !== (string) $this->campaign->preview_text
            || $this->blocks !== ($this->campaign->content['blocks'] ?? [])
            || $this->design !== ($this->campaign->design ?? [])
            || $this->footer_section_id !== $this->campaign->footer_section_id;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // STEP 1 — DETAILS

    public function saveDetails(): void
    {
        $this->checkGate($this->campaign ? GateAccessEnum::MODIFY : GateAccessEnum::CREATE);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'preview_text' => ['nullable', 'string', 'max:500'],
        ]);

        if ($this->campaign === null) {
            $template = $this->template_id ? EmailTemplate::query()->find($this->template_id) : null;

            $this->campaign = app(EmailTemplateService::class)->createCampaignFromTemplate($template, [
                'name' => $this->name,
                'subject' => $this->subject,
                'preview_text' => $this->preview_text,
            ]);

            $this->loadFromCampaign();

            $this->redirectRoute('admin.marketing.campaigns.edit', [$this->campaign, 'step' => 'builder'], navigate: true);

            return;
        }

        $activity = app(ActivityLogService::class);

        $this->campaign->fill([
            'name' => $this->name,
            'subject' => $this->subject,
            'preview_text' => $this->preview_text,
        ]);

        $affected = $activity->affectedColumns($this->campaign);
        $this->campaign->save();
        $activity->logActivity(ActivityActionEnum::EMAIL_CAMPAIGN_UPDATE, " campaign: {$this->campaign->name}", $affected, model: $this->campaign);

        $this->dispatch('builder-saved');

        $this->step = 'builder';
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // STEP 2 — BUILDER

    public function saveAsSection(int $index): void
    {
        $this->checkGate(GateAccessEnum::CREATE);

        if (! isset($this->blocks[$index])) {
            return;
        }

        $section = EmailSection::create([
            'name' => 'New section from '.$this->name,
            'email_section_type' => EmailSectionTypeEnum::CUSTOM->value,
            'content' => ['blocks' => [$this->blocks[$index]]],
        ]);

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::EMAIL_SECTION_CREATE, " section: {$section->name}", model: $section);

        $this->respondSuccess('Saved as a reusable section. Rename it from Saved Sections.');
    }

    public function openPreview(): void
    {
        $this->saveBuilder();

        \Flux\Flux::modal('previewModal')->show();
    }

    #[Computed]
    public function previewHtml(): string
    {
        return app(EmailRenderService::class)->renderCampaign($this->campaign)['html'];
    }

    public function saveBuilder(?string $nextStep = null): void
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        $activity = app(ActivityLogService::class);

        $this->campaign->fill([
            'content' => $this->blockContent(),
            'design' => $this->design,
            'footer_section_id' => $this->footer_section_id,
        ]);

        $affected = $activity->affectedColumns($this->campaign);
        $this->campaign->save();
        $activity->logActivity(ActivityActionEnum::EMAIL_CAMPAIGN_UPDATE, " campaign: {$this->campaign->name}", $affected, model: $this->campaign);

        // The preview reads the saved row, so a stale render would show the admin
        // the draft they had before this save rather than the one they just made.
        unset($this->previewHtml);

        $this->dispatch('builder-saved');

        $this->respondSuccess('Draft saved.', flash: false);

        if ($nextStep) {
            $this->step = $nextStep;
        }
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // STEP 3 — RECIPIENTS

    /**
     * Pull every address out of one piece of typed or pasted text.
     *
     * A pasted list arrives separated however the place it was copied from
     * separated it — commas, semicolons, newlines, tabs, angle brackets, or plain
     * spaces — and a phone keyboard has no convenient Enter, so a space has to end
     * an address too. Both address fields on this screen come through here rather
     * than each parsing its own.
     *
     * @return array<int, string>
     */
    private function parseEmails(string $raw): array
    {
        return collect(preg_split('/[\s,;<>]+/', $raw) ?: [])
            ->map(fn (string $email) => trim($email, " \t\n\r\0\x0B\"'"))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $existing
     * @return array{0: array<int, string>, 1: int}
     */
    private function mergeEmails(array $existing, string $raw): array
    {
        $rejected = 0;

        foreach ($this->parseEmails($raw) as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $rejected++;

                continue;
            }

            if (! in_array($email, $existing, true)) {
                $existing[] = $email;
            }
        }

        return [$existing, $rejected];
    }

    public function addRecipientEmail(): void
    {
        $raw = $this->recipient_email_input;
        $this->recipient_email_input = '';

        [$this->recipient_emails, $rejected] = $this->mergeEmails($this->recipient_emails, $raw);

        unset($this->estimatedRecipients);

        $this->respondError("Skipped {$rejected} entry(s) that are not valid email addresses.", $rejected > 0);
    }

    public function removeRecipientEmail(string $email): void
    {
        $this->recipient_emails = array_values(array_diff($this->recipient_emails, [$email]));

        unset($this->estimatedRecipients);
    }

    private function syncRecipientFieldsToCampaign(): void
    {
        $this->campaign->email_recipient_type = $this->email_recipient_type;
        $this->campaign->recipient_config = match ($this->email_recipient_type) {
            'specific' => ['emails' => $this->recipient_emails],
            'preferences' => ['notification_types' => $this->notification_type_values],
            default => null,
        };
    }

    #[Computed]
    public function estimatedRecipients(): int
    {
        $this->syncRecipientFieldsToCampaign();

        return app(EmailCampaignService::class)->estimateRecipients($this->campaign);
    }

    public function saveRecipients(): void
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        $this->syncRecipientFieldsToCampaign();
        $this->campaign->estimated_recipients = $this->estimatedRecipients;

        $activity = app(ActivityLogService::class);
        $affected = $activity->affectedColumns($this->campaign);
        $this->campaign->save();
        $activity->logActivity(ActivityActionEnum::EMAIL_CAMPAIGN_UPDATE, " campaign: {$this->campaign->name}", $affected, model: $this->campaign);

        unset($this->estimatedRecipients);

        $this->dispatch('builder-saved');

        $this->step = 'review';
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // STEP 4 — REVIEW & SEND

    public function saveSenderDetails(): void
    {
        $resolved = $this->resolvedFromEmail();

        $this->respondError(
            'Name the part before the "@" for an address on your own domain.',
            $this->from_email === self::CUSTOM_SENDER && $resolved === '',
        );

        $this->validate([
            'from_name' => ['required', 'string', 'max:255'],
            'reply_to' => [new EmailRule(false)],
            'email_recurrence' => ['required', Rule::enum(EmailRecurrenceEnum::class)],
            'recurrence_ends_at' => ['nullable', 'date'],
        ]);

        $this->respondError('That does not look like a valid sending address.', ! filter_var($resolved, FILTER_VALIDATE_EMAIL));

        $this->campaign->fill([
            'from_name' => $this->from_name,
            'from_email' => $resolved,
            'reply_to' => $this->reply_to,
            'email_recurrence' => $this->email_recurrence,
            'recurrence_ends_at' => $this->recurrence_ends_at ?: null,
        ])->save();

        $this->dispatch('builder-saved');
    }

    public function addTestEmail(): void
    {
        $raw = $this->test_email_input;
        $this->test_email_input = '';

        [$this->test_emails, $rejected] = $this->mergeEmails($this->test_emails, $raw);

        $this->respondError("Skipped {$rejected} entry(s) that are not valid email addresses.", $rejected > 0);
    }

    public function removeTestEmail(string $email): void
    {
        $this->test_emails = array_values(array_diff($this->test_emails, [$email]));
    }

    public function sendTest(): void
    {
        $this->checkGate(GateAccessEnum::MODIFY);
        $this->saveSenderDetails();

        $this->respondError('Add at least one test address.', $this->test_emails === []);

        $previewAs = $this->preview_as_user_id ? User::query()->find($this->preview_as_user_id) : null;

        $sent = app(EmailCampaignService::class)->sendTest($this->campaign, $this->test_emails, $previewAs);

        $this->respondSuccess("Test sent to {$sent} address(es).");
    }

    public function schedule(): void
    {
        $this->checkGate(GateAccessEnum::MODIFY);
        $this->saveSenderDetails();

        $this->validate([
            'scheduled_date' => ['required', 'date'],
            'scheduled_time' => ['required'],
        ]);

        $at = Carbon::parse("{$this->scheduled_date} {$this->scheduled_time}", $this->timezone)->setTimezone(config('app.timezone'));

        $this->respondError('That time has already passed.', $at->isPast());

        app(EmailCampaignService::class)->schedule($this->campaign, $at, $this->timezone);

        $this->respondSuccess('Campaign scheduled.', flash: true);
        $this->redirectRoute('admin.marketing.campaigns', navigate: true);
    }

    public function send(): void
    {
        $this->checkGate(GateAccessEnum::FULL, 'You do not have send access to campaigns.');
        $this->saveSenderDetails();

        $estimate = app(EmailCampaignService::class)->estimateRecipients($this->campaign);

        $this->respondError(
            'Confirm you want to reach this many recipients first.',
            $estimate >= self::LARGE_AUDIENCE && ! $this->confirm_large_send,
        );

        $this->respondError('This campaign has no recipients yet.', $estimate === 0);

        // A repeating send needs a first moment to count the next one from, and
        // sending immediately is that moment. Worked out from scheduled_at rather
        // than from whenever the queue happened to drain, so the series keeps its
        // time of day.
        if (EmailRecurrenceEnum::from($this->email_recurrence)->isRepeating() && $this->campaign->scheduled_at === null) {
            $this->campaign->scheduled_at = now();
            $this->campaign->timezone = $this->timezone;
            $this->campaign->save();
        }

        app(EmailCampaignService::class)->startSending($this->campaign);

        $this->respondSuccess('Sending started.', flash: true);
        $this->redirectRoute('admin.marketing.campaigns', navigate: true);
    }

    #[Computed]
    public function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }
};
?>

{{--
    A builder fills the window. It is its own workspace rather than a page inside
    the dashboard chrome: a 640px canvas with a palette either side has nothing
    left over for a sidebar, and the step the admin is on is the only navigation
    that means anything while they are here. Closing is the way out, and it asks
    first — see hasUnsavedChanges().

    `dirty` starts from the server's answer and is set again by any typing, because
    between two Livewire round trips the browser is the only one that knows.
--}}
<div
    x-data="{ dirty: @js($this->hasUnsavedChanges()) }"
    x-on:input.capture="dirty = true"
    x-on:builder-saved.window="dirty = false"
    x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = '' }"
    class="fixed inset-0 z-50 flex flex-col overflow-hidden bg-slate-50 dark:bg-slate-950"
>
    @include('pages.admin.marketing.partials._campaign-toolbar', ['closeRoute' => route('admin.marketing.campaigns')])

    <div class="flex-1 overflow-y-auto px-4 py-6 sm:px-6">
        @if ($step === 'details')
            @include('pages.admin.marketing.partials._campaign-details')
        @elseif ($step === 'builder')
            @include('pages.admin.marketing.partials._campaign-builder-step')
        @elseif ($step === 'recipients')
            @include('pages.admin.marketing.partials._campaign-recipients')
        @elseif ($step === 'review')
            @include('pages.admin.marketing.partials._campaign-review', ['timezones' => $this->timezones])
        @endif
    </div>

    <livewire:livewire.library.image-picker />

    @if ($campaign)
        <flux:modal name="previewModal" class="modal-lg">
            <div class="space-y-4">
                <flux:heading size="lg">Preview</flux:heading>
                {{-- The iframe does its own scrolling. A scrollable wrapper around
                     it would put a second bar alongside the first. --}}
                <div class="mx-auto w-full max-w-[700px] overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                    <iframe srcdoc="{{ $this->previewHtml }}" class="h-[70vh] w-full" title="Email preview"></iframe>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
