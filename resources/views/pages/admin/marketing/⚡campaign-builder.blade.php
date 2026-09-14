<?php

use App\Enums\EmailSectionTypeEnum;
use App\Enums\GateAccessEnum;
use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
use App\Models\NotificationType;
use App\Models\User;
use App\Rules\EmailRule;
use App\Services\ActivityLogService;
use App\Services\EmailCampaignService;
use App\Services\EmailSectionService;
use App\Services\EmailTemplateService;
use App\Traits\WithBlockEditor;
use App\Traits\WithGateProps;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    use WithBlockEditor, WithGateProps;

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

    public ?string $reply_to = null;

    public string $send_option = 'now';

    public ?string $scheduled_date = null;

    public ?string $scheduled_time = null;

    public string $timezone;

    public array $test_emails = [];

    public string $test_email_input = '';

    public ?int $preview_as_user_id = null;

    public bool $confirm_large_send = false;

    public const LARGE_AUDIENCE = 1000;

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
        $this->from_email = $campaign->from_email;
        $this->reply_to = $campaign->reply_to;

        if ($campaign->scheduled_at) {
            $this->send_option = 'schedule';
            $this->scheduled_date = $campaign->scheduled_at->format('Y-m-d');
            $this->scheduled_time = $campaign->scheduled_at->format('H:i');
            $this->timezone = $campaign->timezone ?? $this->timezone;
        }
    }

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
        $activity->logActivity(\App\Enums\ActivityActionEnum::EMAIL_CAMPAIGN_UPDATE, " campaign: {$this->campaign->name}", $affected, model: $this->campaign);

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

        $section = \App\Models\EmailSection::create([
            'name' => 'New section from '.$this->name,
            'email_section_type' => EmailSectionTypeEnum::CUSTOM->value,
            'content' => ['blocks' => [$this->blocks[$index]]],
        ]);

        app(ActivityLogService::class)->logActivity(\App\Enums\ActivityActionEnum::EMAIL_SECTION_CREATE, " section: {$section->name}", model: $section);

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
        return app(\App\Services\EmailRenderService::class)->renderCampaign($this->campaign)['html'];
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
        $activity->logActivity(\App\Enums\ActivityActionEnum::EMAIL_CAMPAIGN_UPDATE, " campaign: {$this->campaign->name}", $affected, model: $this->campaign);

        $this->respondSuccess('Draft saved.', flash: false);

        if ($nextStep) {
            $this->step = $nextStep;
        }
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // STEP 3 — RECIPIENTS

    public function addRecipientEmail(): void
    {
        $email = trim($this->recipient_email_input);
        $this->recipient_email_input = '';

        $this->respondError('That does not look like a valid email address.', ! filter_var($email, FILTER_VALIDATE_EMAIL));

        if (! in_array($email, $this->recipient_emails, true)) {
            $this->recipient_emails[] = $email;
        }
    }

    public function removeRecipientEmail(string $email): void
    {
        $this->recipient_emails = array_values(array_diff($this->recipient_emails, [$email]));
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
        $activity->logActivity(\App\Enums\ActivityActionEnum::EMAIL_CAMPAIGN_UPDATE, " campaign: {$this->campaign->name}", $affected, model: $this->campaign);

        unset($this->estimatedRecipients);

        $this->step = 'review';
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // STEP 4 — REVIEW & SEND

    public function saveSenderDetails(): void
    {
        $this->validate([
            'from_name' => ['required', 'string', 'max:255'],
            'from_email' => [new EmailRule(true)],
            'reply_to' => [new EmailRule(false)],
        ]);

        $this->campaign->fill([
            'from_name' => $this->from_name,
            'from_email' => $this->from_email,
            'reply_to' => $this->reply_to,
        ])->save();
    }

    public function addTestEmail(): void
    {
        $email = trim($this->test_email_input);
        $this->test_email_input = '';

        $this->respondError('That does not look like a valid email address.', ! filter_var($email, FILTER_VALIDATE_EMAIL));

        if (! in_array($email, $this->test_emails, true)) {
            $this->test_emails[] = $email;
        }
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

        app(EmailCampaignService::class)->startSending($this->campaign);

        $this->respondSuccess('Sending started.', flash: true);
        $this->redirectRoute('admin.marketing.campaigns', navigate: true);
    }
};
?>

<div class="space-y-6">
    @include('pages.admin.marketing.partials._campaign-toolbar')

    @if ($step === 'details')
        @include('pages.admin.marketing.partials._campaign-details')
    @elseif ($step === 'builder')
        @include('pages.admin.marketing.partials._campaign-builder-step')
    @elseif ($step === 'recipients')
        @include('pages.admin.marketing.partials._campaign-recipients')
    @elseif ($step === 'review')
        @include('pages.admin.marketing.partials._campaign-review')
    @endif

    <livewire:livewire.library.image-picker />

    @if ($campaign)
        <flux:modal name="previewModal" class="modal-lg">
            <div class="space-y-4">
                <flux:heading size="lg">Preview</flux:heading>
                <div class="mx-auto max-h-[70vh] max-w-[700px] overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-700">
                    <iframe srcdoc="{{ $this->previewHtml }}" class="h-[70vh] w-full" title="Email preview"></iframe>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
