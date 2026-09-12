<?php

namespace App\Traits;

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusDefault;
use App\Services\ActivityLogService;
use Illuminate\Database\Eloquent\Model;

/**
 * The row switch behind <x-util.e-badge>: one click flips a record between
 * active and inactive without opening its edit form.
 *
 * A status is the one field on a listing that gets changed on its own — a tag is
 * retired, a category is taken off the site — and routing that through the edit modal
 * means opening a form to change nothing else. It is the same write either way: the
 * same gate, the same activity log, the same toast.
 *
 * The host page says which record a key belongs to. Everything else has a default it
 * can leave alone.
 *
 *     use WithPagination, WithStatusToggle;
 *
 *     protected function statusRecord(int|string $key): ?Model
 *     {
 *         return Tag::find($key);
 *     }
 *
 *     protected function afterStatusToggle(): void
 *     {
 *         unset($this->tags);
 *     }
 */
trait WithStatusToggle
{
    use WithGateProps;

    public function toggleStatus(int|string $key): bool
    {
        // The switch is hidden from an account that cannot use it, which is a
        // courtesy. This is the boundary.
        $this->checkGate(GateAccessEnum::MODIFY, 'You do not have access to change this status.');

        $record = $this->statusRecord($key);

        $this->respondError('That record is no longer available.', if: ! $record instanceof Model);

        $column = $this->statusColumn();
        $current = $record->{$column};

        // Cast or not: a model with a StatusDefault cast hands the enum back, and a
        // plain boolean column answers the same question in its own currency. A
        // multi-state status — a post's draft/published/archived — is not a switch
        // and does not belong here.
        $active = $current instanceof StatusDefault
            ? ! $current->isActive()
            : ! $current;

        $record->{$column} = $current instanceof StatusDefault
            ? StatusDefault::tryFrom((int) $active)
            : $active;

        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($record);

        $record->save();

        $serviceInstance->logActivity(
            $this->statusAction(),
            $this->statusDescription($record, $active),
            $affectedColumns,
            model: $record,
        );

        $this->afterStatusToggle();

        return $this->respondSuccess($this->statusMessage($record, $active));
    }

    /**
     * The record a switch's key belongs to. Pages implement this — it is the one
     * thing the trait cannot know, and taking a model name from the markup would put
     * the choice of table in the view.
     */
    abstract protected function statusRecord(int|string $key): ?Model;

    /**
     * The column the switch flips. Override where a model calls it something else.
     */
    protected function statusColumn(): string
    {
        return 'status';
    }

    /**
     * What the audit trail calls the change. A listing with an action case of its own
     * — TAG_UPDATE rather than UPDATE — overrides this.
     */
    protected function statusAction(): ActivityActionEnum
    {
        return ActivityActionEnum::UPDATE;
    }

    /**
     * How the log reads. The leading space is deliberate: logActivity() prefixes the
     * verb, so the description continues the sentence it started.
     *
     * The direction is spelled out here because it cannot be read anywhere else:
     * ActivityLogService excludes `status` from the before-and-after payload, so a
     * toggle that said only "Updated tag: winter" would leave no trace of which way
     * the switch went.
     */
    protected function statusDescription(Model $record, bool $active): string
    {
        $name = $record->name ?? $record->title ?? $record->getKey();

        // headline() rather than kBreakText(): the subject is a class name, and
        // ImageFolder has to come out as "image folder" rather than "imagefolder".
        $subject = str(class_basename($record))->headline()->lower();

        return " {$subject}: {$name} — ".($active ? 'active' : 'inactive');
    }

    /**
     * The toast. Says which way the switch went, because the row it came from may
     * already have left the page under a filter.
     */
    protected function statusMessage(Model $record, bool $active): string
    {
        return str(class_basename($record))->headline().' is now '.($active ? 'active' : 'inactive').'.';
    }

    /**
     * Refresh whatever the host page renders once a status changed. Pages override this.
     */
    protected function afterStatusToggle(): void {}
}
