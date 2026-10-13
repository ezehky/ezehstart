<?php

namespace App\Listeners;

use App\Enums\NotificationTopicEnum;
use App\Events\PaymentFailed;
use App\Events\PaymentNeedsReview;
use App\Events\PaymentSucceeded;
use App\Events\RefundProcessed;
use App\Services\NotificationService;

/**
 * The bell-menu entries a gateway payment raises.
 *
 * The kit's own reaction to a payment — settling the ledger — is a direct call
 * inside PaymentService, because it has to happen, in order, inside the lock.
 * Telling people about it is not like that, which is what makes it a listener:
 * a project can add its own beside this one, or remove this one, without
 * touching the payment flow. Discovered automatically from app/Listeners.
 */
class SendPaymentNotifications
{
    public function handleSucceeded(PaymentSucceeded $event): void
    {
        $transaction = $event->transaction;

        app(NotificationService::class)->notifyUser(
            $transaction->user,
            NotificationTopicEnum::PAYMENT_CONFIRMED,
            'Your payment of '.kMoneyFormat($transaction->amount, decodeHtml: true)." ({$transaction->reference}) ".$transaction->status->endMessage(),
            ['url' => route('user.transactions')],
        );
    }

    public function handleFailed(PaymentFailed $event): void
    {
        $transaction = $event->transaction;

        app(NotificationService::class)->notifyUser(
            $transaction->user,
            NotificationTopicEnum::PAYMENT_FAILED,
            "Your payment {$transaction->reference} ".$transaction->status->endMessage(),
            ['url' => route('user.transactions')],
        );
    }

    public function handleRefunded(RefundProcessed $event): void
    {
        $refund = $event->refund;

        app(NotificationService::class)->notifyUser(
            $refund->user,
            NotificationTopicEnum::PAYMENT_REFUNDED,
            kMoneyFormat($refund->amount, decodeHtml: true)." has been sent back for payment {$refund->transactionable?->reference}.",
            ['url' => route('user.transactions')],
        );
    }

    public function handleNeedsReview(PaymentNeedsReview $event): void
    {
        app(NotificationService::class)->notifyAdmins(
            NotificationTopicEnum::ADMIN_PAYMENT_REVIEW,
            "{$event->transaction->reference}: {$event->reason}",
            ['url' => route('admin.transactions')],
        );
    }
}
