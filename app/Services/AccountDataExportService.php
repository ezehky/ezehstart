<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Models\Country;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything an account holder is entitled to a copy of, as one JSON document.
 *
 * The companion to AccountDeletionService: that one answers "delete what you hold about
 * me", this one answers "show me what you hold about me". A starter kit that ships the
 * first without the second is only half of the obligation.
 *
 * JSON rather than a spreadsheet because the answer is not a table. ExportService takes
 * one set of columns and the rows under it, and an account is a profile plus a ledger
 * plus a consent history plus two library indexes — several shapes at once, which is
 * exactly what a nested document is for and what a CSV cannot be.
 *
 * **What is deliberately left out** is as much the point as what goes in. The password
 * hash and its history, the two-factor secret and its recovery codes, and the admin gate
 * map are all *about* the account without belonging to the person: handing them over
 * widens the blast radius of one leaked export and gives the holder nothing they can use.
 */
#[Singleton]
class AccountDataExportService
{
    /**
     * How many audit entries the document carries. An old account can hold tens of
     * thousands, and the point of an export is a file somebody can actually open.
     */
    public const ACTIVITY_LIMIT = 500;

    /**
     * Whether the account holder may take a copy at all.
     *
     * Read through kSiteFlag() rather than kSiteConfig(): the helper's default fires on
     * any falsy value, so a switch deliberately turned off would read back as on.
     */
    public function isEnabled(): bool
    {
        return (bool) kSiteFlag('user', 'allow-data-download', true);
    }

    // Getters

    /**
     * The whole document, ready to be encoded.
     *
     * The library sections carry the *record* of what was uploaded, not the files —
     * the account holder already has those, and they do not belong in a JSON document.
     *
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $user->loadMissing(['userProfile', 'connectedAccounts', 'notificationPreferences.notificationType']);

        return [
            'export' => [
                'generated_at' => now()->toIso8601String(),
                'site' => kSiteConfig('name'),
                'notice' => 'Security material — your password, its history, your two-factor secret and your recovery codes — is deliberately not included.',
            ],
            'account' => $this->account($user),
            'profile' => $this->profile($user),
            'consents' => $this->consents($user),
            'connected_accounts' => $this->connectedAccounts($user),
            'notification_preferences' => $this->notificationPreferences($user),
            'transactions' => $this->transactions($user),
            'images' => $this->images($user),
            'videos' => $this->videos($user),
            'activity' => $this->activity($user),
        ];
    }

    /**
     * The name the file lands under — the site, the account, and the day it was taken.
     */
    public function filename(User $user): string
    {
        return kSlug(kSiteConfig('name').' '.$user->name.' data').'-'.now()->format('Y-m-d').'.json';
    }

    // Actions

    /**
     * The document as a file the browser saves.
     *
     * Pretty-printed, with slashes and unicode left alone, because the person opening it
     * is a person — an export nobody can read is a box ticked rather than a right met.
     */
    public function download(User $user): Response
    {
        $payload = json_encode(
            $this->build($user),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::ACCOUNT_DATA_EXPORT);

        return response()->streamDownload(
            fn () => print $payload,
            $this->filename($user),
            [
                'Content-Type' => 'application/json',
                // The file is personal data — no cache, no proxy copy.
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ],
        );
    }

    // Tools

    /**
     * @return array<string, mixed>
     */
    private function account(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'workspace' => $user->user_type->label(),
            'status' => $user->status->label(),
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'last_seen_at' => $user->last_seen_at?->toIso8601String(),
            'joined_at' => $user->created_at?->toIso8601String(),
            'deletion_requested_at' => $user->deletion_requested_at?->toIso8601String(),
            'deletion_scheduled_at' => $user->deletion_scheduled_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function profile(User $user): ?array
    {
        if (! $profile = $user->userProfile) {
            return null;
        }

        return [
            'bio' => $profile->bio,
            'gender' => $profile->gender?->label(),
            'city' => $profile->city,
            'postal_code' => $profile->postal_code,
            // Resolved to a name rather than handed over as the id it is stored as: a
            // foreign key is our bookkeeping, not an answer to "where do you live".
            'country' => $profile->country_id
                ? Country::query()->whereKey($profile->country_id)->value('name')
                : null,
            'social_handles' => $profile->socialsArray(),
            'settings' => $profile->settings,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function consents(User $user): array
    {
        return $user->consents()
            ->with('policy:id,policy_type,version,title')
            ->latest('accepted_at')
            ->get()
            ->map(fn ($consent) => [
                'policy' => $consent->policy?->title,
                'version' => $consent->policy?->version,
                'accepted_at' => $consent->accepted_at?->toIso8601String(),
                // Kept because it is the evidence the consent was given, and what was
                // recorded about somebody is part of what they are entitled to see.
                'user_agent' => $consent->user_agent,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function connectedAccounts(User $user): array
    {
        return $user->connectedAccounts
            ->map(fn ($account) => [
                'provider' => $account->provider,
                'connected_at' => $account->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function notificationPreferences(User $user): array
    {
        return $user->notificationPreferences
            ->map(fn ($preference) => [
                'topic' => $preference->notificationType?->title,
                'subscribed' => $preference->status?->isActive(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transactions(User $user): array
    {
        return $user->transactions()
            ->latest()
            ->get()
            ->map(fn ($transaction) => [
                'reference' => $transaction->reference,
                'type' => $transaction->transaction_type?->label(),
                'group' => $transaction->transaction_group?->label(),
                'wallet' => $transaction->transaction_wallet?->label(),
                'via' => $transaction->via?->label(),
                // The plain number, not kMoneyFormat(): that returns an HTML entity for
                // a Blade view, and a currency symbol in a data file is not a value.
                'amount' => $transaction->amount,
                'status' => $transaction->status?->label(),
                'description' => $transaction->description,
                'created_at' => $transaction->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function images(User $user): array
    {
        return $user->images()
            ->latest()
            ->get()
            ->map(fn ($image) => [
                'title' => $image->title,
                'url' => $image->url(),
                'alt_text' => $image->alt_text,
                'visibility' => $image->visibility?->label(),
                'size' => $image->readableSize(),
                'uploaded_at' => $image->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function videos(User $user): array
    {
        return $user->videos()
            ->latest()
            ->get()
            ->map(fn ($video) => [
                'title' => $video->title,
                'provider' => $video->provider?->label(),
                // The provider and its id together are the whole of a video row — the
                // player URL is rebuilt from the pair rather than stored.
                'video_id' => $video->video_id,
                'description' => $video->description,
                'visibility' => $video->visibility?->label(),
                'added_at' => $video->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The audit trail of what this account did, most recent first.
     *
     * Capped, and the cap is stated in the file, so nobody mistakes a truncated list
     * for the whole of it.
     *
     * @return array<string, mixed>
     */
    private function activity(User $user): array
    {
        $total = $user->activityLogs()->count();

        return [
            'total_entries' => $total,
            'included' => min($total, self::ACTIVITY_LIMIT),
            'note' => $total > self::ACTIVITY_LIMIT
                ? 'Showing the most recent '.self::ACTIVITY_LIMIT." of {$total} entries."
                : null,
            'entries' => $user->activityLogs()
                ->latest()
                ->limit(self::ACTIVITY_LIMIT)
                ->get()
                ->map(fn ($log) => [
                    'action' => $log->activity_log_action?->label(),
                    'description' => $log->description,
                    'ip_address' => $log->ip_address,
                    'at' => $log->created_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }
}
