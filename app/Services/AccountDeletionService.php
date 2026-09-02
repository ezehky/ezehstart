<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;

#[Singleton]
class AccountDeletionService
{
    /**
     * Whether the user has history worth preserving — if so we anonymize instead of
     * hard-deleting, so records that point at them stay resolvable.
     *
     * The starter only knows about the audit trail. Add each relation that counts as
     * history the moment your project grows one: orders, enrolments, referrals.
     */
    public function hasSignificantActivity(User $user): bool
    {
        return $user->activityLogs()->exists();
    }

    /**
     * Strip personal information but keep the account row (soft-deleted) so
     * related history remains intact and resolvable.
     */
    public function anonymize(User $user): void
    {
        DB::transaction(function () use ($user) {
            kDeleteFile($user->avatar);

            $user->forceFill([
                'name' => 'Anonymous User',
                'email' => "deleted-user-{$user->id}@anonymized.local",
                'phone_number' => null,
                'avatar' => null,
                'password' => null,
            ])->save();

            $user->userProfile()->update([
                'bio' => null,
                'gender' => null,
                'city' => null,
                'postal_code' => null,
            ]);

            app(ActivityLogService::class)->logActivity(ActivityActionEnum::ACCOUNT_ANONYMIZE);

            // $user->delete();
        });
    }

    /**
     * Permanently remove the user and all directly related records. Only
     * safe to call when hasSignificantActivity() is false.
     */
    public function hardDelete(User $user): void
    {
        DB::transaction(function () use ($user) {
            kDeleteFile($user->avatar);

            app(ActivityLogService::class)->logActivity(
                ActivityActionEnum::ACCOUNT_DELETE,
                model: $user,
            );

            $user->forceDelete();
        });
    }
}
