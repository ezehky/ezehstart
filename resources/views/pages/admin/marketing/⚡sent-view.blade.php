<?php

use App\Enums\GateAccessEnum;
use App\Models\EmailCampaign;
use App\Services\EmailTemplateService;
use App\Traits\WithGateProps;
use Livewire\Component;

new class extends Component
{
    use WithGateProps;

    public EmailCampaign $campaign;

    public function mount(EmailCampaign $campaign): void
    {
        abort_unless($campaign->status->isSent() || $campaign->status->isFailed(), 404);

        $this->campaign = $campaign;

        kSetSiteTitle('marketing', $campaign->name, format: false);
        $this->setPageGate('marketing.sent');
    }

    public function duplicate(): void
    {
        $this->checkGate(GateAccessEnum::CREATE);

        $copy = app(EmailTemplateService::class)->createCampaignFromTemplate($this->campaign->emailTemplate, [
            'name' => "{$this->campaign->name} (Copy)",
            'subject' => $this->campaign->subject,
            'preview_text' => $this->campaign->preview_text,
        ]);

        $copy->fill([
            'content' => $this->campaign->content,
            'design' => $this->campaign->design,
            'footer_section_id' => $this->campaign->footer_section_id,
        ])->save();

        $this->redirectRoute('admin.marketing.campaigns.edit', $copy, navigate: true);
    }
};
?>

<div class="space-y-6">
    <x-dashboard.page-header
        :title="$campaign->name"
        :subtitle="'&quot;'.$campaign->subject.'&quot; &middot; sent '.$campaign->sent_at?->format('M j, Y g:i A').' by '.($campaign->user?->name ?? 'an admin')"
        :back="['route' => route('admin.marketing.sent')]"
    >
        <x-slot:actions>
            <x-dashboard.gate.button gate="marketing.campaigns" level="create" wire:click="duplicate" icon="document-duplicate">
                Duplicate Campaign
            </x-dashboard.gate.button>
            <x-util.e-badge :enum="$campaign->status" />
        </x-slot:actions>
    </x-dashboard.page-header>

    @php($counts = $campaign->deliveryCounts())
    @php($recipients = $campaign->recipients()->count())
    @php($delivered = $counts['SENT'] + $counts['QUEUED'])

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <flux:card><flux:text size="sm">Recipients</flux:text><p class="mt-1 font-heading text-xl font-bold">{{ number_format($recipients) }}</p></flux:card>
        <flux:card><flux:text size="sm">Delivered</flux:text><p class="mt-1 font-heading text-xl font-bold text-emerald-600 dark:text-emerald-400">{{ number_format($delivered) }}</p></flux:card>
        <flux:card><flux:text size="sm">Bounced</flux:text><p class="mt-1 font-heading text-xl font-bold text-amber-600 dark:text-amber-400">{{ number_format($counts['BOUNCED']) }}</p></flux:card>
        <flux:card><flux:text size="sm">Failed</flux:text><p class="mt-1 font-heading text-xl font-bold text-rose-600 dark:text-rose-400">{{ number_format($counts['FAILED']) }}</p></flux:card>
        <flux:card><flux:text size="sm">Pending</flux:text><p class="mt-1 font-heading text-xl font-bold">{{ number_format($counts['PENDING']) }}</p></flux:card>
    </div>

    <flux:callout icon="information-circle" color="zinc" class="text-sm">
        Open, click, and unsubscribe-from-email tracking are not part of this pass — delivery status is the
        source of truth here. See the project notes for that follow-up.
    </flux:callout>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <flux:card class="space-y-4">
            <flux:heading level="3" size="md">Details</flux:heading>
            <dl class="divide-y divide-slate-100 text-sm dark:divide-white/10">
                <div class="flex justify-between py-2"><dt class="text-slate-500">Template</dt><dd>{{ $campaign->emailTemplate?->name ?? 'Blank' }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-slate-500">Subject</dt><dd>{{ $campaign->subject }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-slate-500">From</dt><dd>{{ $campaign->from_name }} &lt;{{ $campaign->from_email }}&gt;</dd></div>
                <div class="flex justify-between py-2"><dt class="text-slate-500">Reply-to</dt><dd>{{ $campaign->reply_to ?? '—' }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-slate-500">Audience</dt><dd>{{ $campaign->email_recipient_type->label() }}</dd></div>
            </dl>
        </flux:card>

        <flux:card class="space-y-4">
            <flux:heading level="3" size="md">Email preview</flux:heading>
            <div class="max-h-[500px] overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-700">
                <iframe
                    srcdoc="{{ app(\App\Services\EmailRenderService::class)->renderCampaign($campaign)['html'] }}"
                    class="h-[500px] w-full"
                    title="Email preview"
                ></iframe>
            </div>
        </flux:card>
    </div>
</div>
