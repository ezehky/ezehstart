<?php

namespace App\Listeners;

use App\Enums\NotificationTopicEnum;
use App\Events\SubscriptionEnded;
use App\Events\SubscriptionPaymentFailed;
use App\Events\SubscriptionStarted;
use App\Services\NotificationService;

/**
 * The bell-menu entries a subscription raises. Renewals are not announced —
 * a notification every month that nothing changed is noise.
 */
class SendSubscriptionNotifications
{
    public function handleStarted(SubscriptionStarted $event): void
    {
        $subscription = $event->subscription;

        app(NotificationService::class)->notifyUser(
            $subscription->user,
            NotificationTopicEnum::SUBSCRIPTION_STARTED,
            "Your {$subscription->plan?->name} subscription is active.",
            ['url' => route('user.subscriptions')],
        );
    }

    public function handlePaymentFailed(SubscriptionPaymentFailed $event): void
    {
        $subscription = $event->subscription;

        app(NotificationService::class)->notifyUser(
            $subscription->user,
            NotificationTopicEnum::SUBSCRIPTION_PAYMENT_FAILED,
            "We could not take the payment for your {$subscription->plan?->name} subscription. {$subscription->vendor->label()} will try again — check your card is still valid.",
            ['url' => route('user.subscriptions')],
        );
    }

    public function handleEnded(SubscriptionEnded $event): void
    {
        $subscription = $event->subscription;

        app(NotificationService::class)->notifyUser(
            $subscription->user,
            NotificationTopicEnum::SUBSCRIPTION_ENDED,
            "Your {$subscription->plan?->name} subscription has ended.",
            ['url' => route('user.subscriptions')],
        );
    }
}
