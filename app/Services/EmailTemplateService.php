<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\EmailRecipientTypeEnum;
use App\Enums\StatusEmailCampaign;
use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reusable design structures. A campaign started from one is a one-time copy — see
 * createCampaignFromTemplate() — never a live link back to the template.
 */
#[Singleton]
class EmailTemplateService
{
    public function libraryQuery(?string $search = null): Builder
    {
        return EmailTemplate::query()
            ->when($search, fn (Builder $query) => $query->searchMacro('name', $search))
            ->orderByDesc('updated_at');
    }

    public function duplicate(EmailTemplate $template): EmailTemplate
    {
        $copy = EmailTemplate::create([
            'name' => "{$template->name} (Copy)",
            'description' => $template->description,
            'thumbnail_image_id' => $template->thumbnail_image_id,
            'footer_section_id' => $template->footer_section_id,
            'content' => $template->content,
            'design' => $template->design,
        ]);

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::EMAIL_TEMPLATE_CREATE,
            " template: {$copy->name} (duplicated from {$template->name})",
        );

        return $copy;
    }

    /**
     * A new draft campaign, its blocks and design deep-copied from the template so
     * editing the campaign afterward never reaches back into the template.
     *
     * @param  array{name: string, subject: string, preview_text?: ?string}  $details
     */
    public function createCampaignFromTemplate(?EmailTemplate $template, array $details): EmailCampaign
    {
        $campaign = EmailCampaign::create([
            'user_id' => auth()->id(),
            'email_template_id' => $template?->id,
            'footer_section_id' => $template?->footer_section_id,
            'name' => $details['name'],
            'subject' => $details['subject'],
            'preview_text' => $details['preview_text'] ?? null,
            'from_name' => (string) kSiteConfig('email-senders.default.from-name', default: kSiteConfig('name')),
            // A blank sender is a campaign that cannot go out, so an install that
            // has not filled in Email Senders yet falls back to the mailer's own
            // address — the same one WithEmailResolver::setEmailFrom() falls back to.
            'from_email' => (string) (kSiteConfig('email-senders.default.from') ?: config('mail.from.address')),
            'reply_to' => kSiteConfig('email-senders.default.reply-to') ?: null,
            'content' => $template?->content ?? ['blocks' => []],
            'design' => $template?->design ?? [],
            'email_recipient_type' => EmailRecipientTypeEnum::ALL_USERS,
            'status' => StatusEmailCampaign::DRAFT,
        ]);

        if ($template) {
            EmailTemplate::query()->whereKey($template->id)->increment('used_count');
        }

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::EMAIL_CAMPAIGN_CREATE,
            " campaign: {$campaign->name}",
            model: $campaign,
        );

        return $campaign;
    }

    public function delete(EmailTemplate $template): void
    {
        $activity = app(ActivityLogService::class);
        $description = " template: {$template->name}";

        // Campaigns already built from this template keep their own copy of
        // content/design — nulling this FK (see the migration's nullOnDelete) never
        // touches what they render.
        $template->delete();

        $activity->logActivity(ActivityActionEnum::EMAIL_TEMPLATE_DELETE, $description);
    }
}
