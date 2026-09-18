<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\EmailSectionTypeEnum;
use App\Enums\StatusYes;
use App\Models\EmailSection;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reusable content blocks — Saved Header, Saved Footer, Saved CTA, Saved Promotional
 * Section — designed once and dropped into any template or campaign.
 */
#[Singleton]
class EmailSectionService
{
    public function libraryQuery(?EmailSectionTypeEnum $type = null, ?string $search = null): Builder
    {
        return EmailSection::query()
            ->when($type, fn (Builder $query) => $query->ofType($type))
            ->when($search, fn (Builder $query) => $query->searchMacro('name', $search))
            ->orderByDesc('is_default')
            ->orderBy('name');
    }

    /**
     * The one default section of a type — what a new campaign's footer picker
     * pre-selects.
     */
    public function defaultFor(EmailSectionTypeEnum $type): ?EmailSection
    {
        return EmailSection::query()->ofType($type)->defaults()->first();
    }

    /**
     * Make one section the default of its type, unsetting whichever one held that
     * spot before — a type only ever has one.
     */
    public function setDefault(EmailSection $section): void
    {
        EmailSection::query()
            ->ofType($section->email_section_type)
            ->where('id', '!=', $section->id)
            ->update(['is_default' => StatusYes::NO]);

        $section->is_default = StatusYes::YES;
        $section->save();
    }

    /**
     * Why this section cannot be deleted, or null when it can.
     */
    public function deleteBlockedReason(EmailSection $section): ?string
    {
        if (! $section->isAttached()) {
            return null;
        }

        return "This section is used by {$section->templates()->count()} template(s) and {$section->campaigns()->count()} campaign(s). Point them at another section first.";
    }

    public function delete(EmailSection $section): ?string
    {
        if ($reason = $this->deleteBlockedReason($section)) {
            return $reason;
        }

        $activity = app(ActivityLogService::class);
        $description = " section: {$section->name}";

        $section->delete();

        $activity->logActivity(ActivityActionEnum::EMAIL_SECTION_DELETE, $description);

        return null;
    }
}
