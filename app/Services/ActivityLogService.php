<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\ActivityPlatformEnum;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Model;
use Jenssegers\Agent\Facades\Agent;

#[Singleton]
class ActivityLogService
{
    // Tools
    protected function modifyDirtyColumns(array $data, array $exceptions = []): array
    {
        $exceptions = [
            'id',
            'password',
            'kSlug',
            'title_metaphone',
            'updated_at',
            'created_at',
            'slug',
            'status',
            ...$exceptions,
        ];

        return collect($data)
            ->mapWithKeys(fn ($value, $index) => [
                $index => \is_string($value) ? str()->limit($value, 1500) : $value,
            ])
            ->filter(fn ($value, $index) => ! \in_array($index, $exceptions))
            ->toArray();
    }

    public function affectedColumns(Model $model, array $exceptions = [], array $include = []): ?array
    {
        // Check if model is dirty and has original values
        if ($model->isDirty() && $model->getOriginal()) {
            $data = [];

            // GET DIRTY KEYS
            $dirtyKeys = [...array_keys($model->getDirty()), ...$include];

            // Original: Extract only affected columns
            $original = array_intersect_key($model->getOriginal(), array_flip($dirtyKeys));
            $data['original'] = $this->modifyDirtyColumns($original, $exceptions);

            // Changes: Extract only affected columns
            $changes = $model->getDirty();
            $data['changes'] = $this->modifyDirtyColumns($changes, $exceptions);

            // RETURN
            return $data;
        }

        return null;
    }

    // Getters

    /**
     * How many days of audit trail to keep, 0 when it is kept forever.
     *
     * Clamped rather than trusted: the value comes from a JSON file an administrator
     * edits, and a stray 1 would quietly shred the trail on the next nightly run.
     */
    public function retentionDays(): int
    {
        $days = (int) kSiteFlag('security', 'activity-log-retention-days', 0);

        return $days <= 0 ? 0 : max(30, min($days, 3650));
    }

    public function getActivityLogsForUser(User $user, ?array $columns = null, int $limit = 6)
    {
        $columns ??= [
            'id',
            'user_id',
            'activity_log_action',
            'description',
            'created_at',
        ];

        return ActivityLog::query()
            ->with('user:id,name,avatar')
            ->select($columns)
            ->where('user_id', $user->id)
            ->latest()
            ->limit($limit)
            ->get();
    }

    // Actions

    public function logActivity(
        ActivityActionEnum $action,
        ?string $description = null,
        ?array $affectedColumns = null,
        ?Model $model = null,
        ActivityPlatformEnum $platform = ActivityPlatformEnum::WEB,
        ?string $device = null,
        ?string $browser = null,
        ?string $os = null,
        ?string $userAgent = null,
        bool $prefixDescription = true
    ) {
        if (! auth()->check()) {
            return; // No authenticated user, no log
        }

        $data = [];

        // Get device
        if (! $device) {
            $device = Agent::device();
        }

        // Get browser
        if (! $browser) {
            $browser = Agent::browser();
        }

        // Get OS
        if (! $os) {
            $os = Agent::platform();
        }

        // Get user agent. The column is 500 chars, and a spoofed header can be far
        // longer, so it is trimmed rather than allowed to fail the insert.
        if (! $userAgent) {
            $userAgent = str()->limit((string) request()->userAgent(), 495);
        }

        // Original
        if ($affectedColumns) {
            $data = [...$affectedColumns, ...$data];
        }

        // CHECK MODEL
        if ($model) {
            // LOGGABLE
            $data['loggable_id'] = $model->id;
            $data['loggable_type'] = \get_class($model);
        }

        // Resolve description
        if (! $description) {
            $description = $action->defaultDescription();
        } elseif ($prefixDescription) {
            $description = $action->startDescription().$description;
        }

        if (! $description) {
            return; // No description, no log
        }
        // |||||||||||||||||||||||||||||||||||

        // LOG
        ActivityLog::query()->create([
            'user_id' => auth()->id(),
            'platform' => $platform,
            'device_type' => $device,
            'browser' => $browser,
            'os' => $os,
            'user_agent' => $userAgent,
            'ip_address' => request()->ip(),
            'activity_log_action' => $action,
            'description' => $description,
            ...$data,
        ]);
    }

    /**
     * Delete audit entries older than the retention window and report how many went.
     *
     * Bounded on both sides rather than "everything before the cutoff": a table that
     * has never been pruned can hold years, and one unbounded delete on it is a lock
     * held long enough to take the site down. Each pass takes a chunk and the caller
     * keeps asking until a pass comes back short.
     */
    public function prune(?int $days = null, int $chunk = 1000): int
    {
        $days ??= $this->retentionDays();

        // Zero is "keep forever", and it is the default. Pruning an audit trail is a
        // decision somebody has to make on purpose.
        if ($days <= 0) {
            return 0;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $passed = ActivityLog::query()
                ->where('created_at', '<', $cutoff)
                ->limit($chunk)
                ->delete();

            $deleted += $passed;
        } while ($passed >= $chunk);

        return $deleted;
    }
}
