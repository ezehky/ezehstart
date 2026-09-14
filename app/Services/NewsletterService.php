<?php

namespace App\Services;

use App\Enums\NotificationTypeEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusUser;
use App\Models\NotificationType;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * The newsletter sign-up: whether it is being offered, where it appears, and what
 * happens to an address somebody hands over.
 *
 * There is no subscribers table. An address is a `users` row — a registered
 * account if one exists for it, and a StatusUser::NEWSLETTER_SUBSCRIBER row if
 * not — and the subscription itself is the ANNOUNCEMENTS switch in
 * notification_preferences that every account already carries. That is what makes
 * one address one row: somebody who signs up for the newsletter and registers a
 * month later claims the row they already have instead of colliding with it, and
 * a send reads one list rather than joining two that have to be kept in step.
 *
 * The four switches live under `preferences.newsletter` in the site configuration.
 * Every one of them is read through here rather than in a view: they are nested a
 * level deeper than kSiteFlag() reaches, and reading a nested switch with
 * kSiteConfig() would hand back the default the moment somebody turned that switch
 * off — which for a popup means "off" reads as "on".
 */
#[Singleton]
class NewsletterService
{
    /**
     * The longest a popup may be held back. A delay typed into the JSON editor
     * rather than the admin screen is not bounded by a max on a number field, and
     * a popup nobody ever sees is a feature that looks broken.
     */
    public const MAX_POPUP_DELAY = 120;

    /**
     * Which notification type the newsletter is.
     *
     * Deliberately an existing type rather than a new one: "product news and
     * occasional announcements" is what a newsletter is, and a second type beside
     * it would give one person two switches for the same mail.
     */
    public const TYPE = NotificationTypeEnum::ANNOUNCEMENTS;

    /**
     * Is the newsletter offered at all?
     *
     * The master switch. With it off neither placement renders and subscribe() is
     * refused, so a page cached while the newsletter was on still cannot write a
     * row after somebody turned the feature off.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->flag('status', true);
    }

    /**
     * The block in the public footer, which every public shell renders.
     */
    public function showsInFooter(): bool
    {
        return $this->isEnabled() && (bool) $this->flag('footer', true);
    }

    /**
     * The card that interrupts a visitor after popupDelay() seconds.
     */
    public function showsPopup(): bool
    {
        return $this->isEnabled() && (bool) $this->flag('popup', true);
    }

    /**
     * How long a visitor gets to read the page before the popup asks for anything.
     *
     * Clamped rather than trusted: zero is a page that opens with a card already
     * over it, which is the behaviour the delay exists to avoid.
     */
    public function popupDelay(): int
    {
        return max(1, min((int) $this->flag('popup-delay', 5), self::MAX_POPUP_DELAY));
    }

    /**
     * Put an address on the list.
     *
     * Three cases, one outcome. An address with an account switches that account's
     * ANNOUNCEMENTS preference on and is otherwise left alone — signing up for the
     * newsletter must not touch somebody's status, name or anything else about
     * their account. An address already on the list is switched back on, which is
     * what re-subscribing means. An address that is neither gets a row of its own.
     *
     * Idempotent on purpose: somebody who has forgotten they already signed up
     * types the same address again, and being told "you are already on the list"
     * is a worse outcome for both sides than simply being on it.
     */
    public function subscribe(string $email): User
    {
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($email) {
            $user = User::query()->where('email', $email)->first()
                ?? $this->createSubscriberRow($email);

            $this->setSubscription($user, subscribed: true);

            return $user;
        });
    }

    /**
     * Take an address off the list without touching anything else about it.
     *
     * The preference goes off rather than the row going away: a registered account
     * is not deleted because somebody stopped wanting the newsletter, and a row
     * that is only ever on the list is kept so a later sign-up is a deliberate act
     * by the address holder rather than a silent resurrection.
     */
    public function unsubscribe(string $email): bool
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        if (! $user) {
            return false;
        }

        $this->setSubscription($user, subscribed: false);

        return true;
    }

    /**
     * Is this address currently receiving the newsletter?
     */
    public function isSubscribed(string $email): bool
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        return $user !== null && $this->preferenceFor($user)?->status->isActive() === true;
    }

    /**
     * How many addresses a send would reach.
     *
     * Answered by NotificationSubscriberService, not here, because "everybody
     * subscribed to this type" is the same question for a newsletter as for any
     * other announcement — the whole point of not having a second table.
     */
    public function subscriberCount(): int
    {
        return app(NotificationSubscriberService::class)->subscriberCount(self::TYPE);
    }

    /**
     * A one-click unsubscribe link for this recipient.
     *
     * Signed rather than guessable, and deliberately without an expiry: the link
     * lives in an email somebody may open a year later, and an unsubscribe that has
     * timed out is an unsubscribe that does not work.
     */
    public function unsubscribeUrl(User $user): string
    {
        return URL::signedRoute('newsletter.unsubscribe', $user);
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PRIVATE

    /**
     * An address with no account behind it yet.
     *
     * No password and no verified address, so nothing here can be signed in to —
     * the sign-in flows read StatusUser::isRegistered() and refuse. The name is
     * derived from the address because the column is not nullable and a newsletter
     * greeting has to say something; registering later replaces it with the real
     * one while keeping the row and its subscription.
     */
    private function createSubscriberRow(string $email): User
    {
        $user = User::query()->create([
            'name' => $this->nameFromEmail($email),
            'email' => $email,
            'status' => StatusUser::NEWSLETTER_SUBSCRIBER,
            'ip_address' => request()->ip(),
        ]);

        // The same backfill every account gets on sign-in. A subscriber never signs
        // in, so this is the only place it happens for them — without it there is no
        // preference row to switch on, and nothing to switch off to unsubscribe.
        app(UserService::class, ['user' => $user])->runNotificationPreferencesUpdate();

        return $user;
    }

    /**
     * "jane.doe@example.com" becomes "Jane Doe".
     *
     * A guess, and clearly one, but a better greeting than the raw address and
     * better than a blank. Bounded because the column is not.
     */
    private function nameFromEmail(string $email): string
    {
        $name = Str::of($email)
            ->before('@')
            ->replace(['.', '_', '-', '+'], ' ')
            ->squish()
            ->title()
            ->limit(60, '')
            ->trim();

        return $name->isEmpty() ? 'Subscriber' : (string) $name;
    }

    /**
     * Turn this account's newsletter switch on or off.
     *
     * firstOrCreate rather than update: a type added after the account was created
     * has no preference row yet, and the unique index on the pair is what makes
     * two overlapping sign-ups safe.
     */
    private function setSubscription(User $user, bool $subscribed): void
    {
        $type = $this->typeRow();

        if (! $type) {
            return;
        }

        $user->notificationPreferences()->updateOrCreate(
            ['notification_type_id' => $type->id],
            ['status' => $subscribed ? StatusDefault::ACTIVE : StatusDefault::INACTIVE],
        );
    }

    private function preferenceFor(User $user): ?object
    {
        $type = $this->typeRow();

        return $type
            ? $user->notificationPreferences()->where('notification_type_id', $type->id)->first()
            : null;
    }

    /**
     * The announcements type row, or null where an administrator has retired it.
     * A retired type is one nobody is subscribed to, and the sign-up says so by
     * recording the address without a switch to turn on.
     */
    private function typeRow(): ?NotificationType
    {
        return NotificationType::query()
            ->where('notification_type', self::TYPE->value)
            ->first();
    }

    /**
     * One switch out of the `preferences.newsletter` group.
     *
     * Presence rather than truthiness decides whether the default applies, for the
     * reason given on kSiteFlag(): an install that has never saved a configuration
     * falls back, and a switch saved as false is honoured as false.
     */
    private function flag(string $key, mixed $default): mixed
    {
        $group = kSiteFlag('preferences', 'newsletter', []);

        return \is_array($group) && \array_key_exists($key, $group)
            ? $group[$key]
            : $default;
    }
}
