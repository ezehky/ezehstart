# notifications.md

## Rule

Three distinct feedback channels. Pick by lifetime.

| Channel | Lifetime | Mechanism |
| --- | --- | --- |
| **Toast** | this interaction | `respondSuccess()` / `respondError()` / `respondPrimary()` → Flux toast |
| **Flash** | one redirect | `session()->flash('status'\|'error')`, rendered by `layouts::app` |
| **Database notification** | until read | `NotificationService` → `GeneralNotification` → the bell menu |
| **Pending action** | until *resolved* | `AdminActionService` — read live from the records, never stored |

---

## 1. Toasts

Never call `Flux::toast()` from a page. Go through `WithFormResponseMessage`:

```php
return $this->respondSuccess('The question has been saved.');
$this->respondError('That cohort is locked.', if: $locked);
$this->respondPrimary(if: $model->isClean());
```

One toast group exists, in `components/layouts/base.blade.php`, inside
`@persist('toast')` so it survives `wire:navigate`.

See [forms.md](forms.md).

## 2. Flash messages

Only when the action redirects. `layouts::app` renders two keys:

```blade
@session('status')
    <flux:callout color="lime" class="mt-6 text-sm">{!! session('status') !!}</flux:callout>
@endsession
@session('error')
    <flux:callout variant="danger" icon="x-circle" class="mt-6 text-sm">{!! session('error') !!}</flux:callout>
@endsession
```

Keys used across the app: `status`, `error`, `success`, `primary`, `message`,
`accessDenied`.

From a controller:

```php
return to_route('user.enroll', $cohort)->with('error', $this->error);
return to_route('user.enroll', $cohort)->with('status', 'Payment confirmed successfully.');
```

From a Livewire action that redirects:

```php
return $this->respondSuccess('Saved.', flash: true);
```

## 3. Database notifications

**One notification class for everything**: `GeneralNotification`.

```php
class GeneralNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $message,
        public ?array $data = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'data' => $this->data,
        ];
    }
}
```

`via()` is `['database']` only — email is a separate concern, sent as a Mailable.
See [mail.md](mail.md).

### Always go through `NotificationService`

```php
app(NotificationService::class)->notifyUser($user, NotificationTopicEnum::REFUND_APPROVED, 'Your refund has been approved.', ['url' => route('user.payments')]);

app(NotificationService::class)->notifyAdmins(NotificationTopicEnum::ADMIN_WITHDRAWAL_REQUEST, "{$user->name} requested a withdrawal.", ['url' => route('admin.transactions')]);
```

`notifyAdmins()` **excludes the acting admin by default** — they already know. Pass
`except:` to skip someone else instead.

Reading:

```php
$service = app(NotificationService::class);

$service->unreadCountFor($user);
$service->recentFor($user, limit: 10);
$service->markAsRead($user, $notificationId);
$service->markAllAsRead($user);
$service->clearFor($user);
```

The topic is always a `NotificationTopicEnum` case, and `data['url']` is what the bell
menu links to.

### The bell

`resources/views/components/lv/⚡notifications.blade.php`, embedded as
`<livewire:lv.notifications />` in `x-dashboard.top-navigation`. Its badge is
`unreadCount + pendingCount`.

## 4. Pending actions — the important distinction

```php
/**
 * The work waiting on an admin, read live from the records themselves.
 *
 * A database notification is an announcement: it is read once and gone, and it
 * says nothing about whether the thing it announced was ever dealt with. These
 * items are the opposite — they exist for exactly as long as the underlying
 * record is unresolved, so a withdrawal nobody approved keeps showing up.
 */
#[Singleton]
class AdminActionService
```

`pendingItems(int $limit = 6): Collection` returns, oldest first:

```php
[
    'topic' => NotificationTopicEnum::ADMIN_WITHDRAWAL_REQUEST,
    'message' => "{$transaction->user?->name} is waiting on a {$transaction->amountMoney()} withdrawal.",
    'url' => route('admin.transaction', $transaction->reference),
    'since' => $transaction->created_at,
]
```

Sources: queued withdrawals, refund requests, refunds awaiting settlement, accounts
with no role.

`pendingCount()` counts **without loading**, and the bell only runs the heavy listing
when the count is non-zero:

```php
// The counts are cheap and usually zero, so they decide whether the
// heavier listing queries are worth running at all.
return $this->pendingCount
    ? app(AdminActionService::class)->pendingItems($this->actionLimit)
    : collect();
```

**When you add a new kind of admin work queue, add it to `AdminActionService`** —
`withdrawalRequests()`, `refundRequests()`, `refundsAwaitingSettlement()`,
`accountsWithoutRole()` are the pattern. Each is a `private` method returning
`array<int, array<string, mixed>>` with a matching `*Query()` used by both the listing
and the count.

## Why

- Toast for what the user just did, flash for what survives a redirect, a database
  notification for what they should learn about later — mixing them makes feedback
  unpredictable.
- A single `GeneralNotification` avoids 30 near-identical classes; the topic enum
  carries the meaning and the payload carries the link.
- Pending actions are deliberately **not** notifications: a notification is read once
  and gone, which is exactly wrong for "somebody must approve this withdrawal". Reading
  them from the records means the queue is always accurate and self-clearing.
- Counting before listing keeps the top bar cheap on every page load for the common
  case where nothing is pending.

## Example

Notifying both sides of a refund:

```php
$service = app(NotificationService::class);

$service->notifyUser(
    $admission->user,
    NotificationTopicEnum::REFUND_APPROVED,
    "Your refund of {$transaction->amountMoney()} has been approved.",
    ['url' => route('user.payments')],
);

$service->notifyAdmins(
    NotificationTopicEnum::ADMIN_REFUND_SETTLED,
    "{$admission->user->name}'s refund was settled.",
    ['url' => route('admin.transaction', $transaction->reference)],
);
```

## Template

Adding a pending-work queue to `AdminActionService`:

```php
// add to pendingItems()
return collect([
    ...$this->withdrawalRequests(),
    ...$this->refundRequests(),
    ...$this->unpaidInvoices(),          // new
])
    ->sortBy('since')
    ->values()
    ->take($limit);

// add to pendingCount()
public function pendingCount(): int
{
    return $this->withdrawalQuery()->count()
        + $this->unpaidInvoiceQuery()->count();      // new
}

// Queues

/**
 * Invoices past their due date with nobody chasing them.
 *
 * @return array<int, array<string, mixed>>
 */
private function unpaidInvoices(): array
{
    return $this->unpaidInvoiceQuery()
        ->with('user:id,name')
        ->oldest()
        ->limit(10)
        ->get()
        ->map(fn (Invoice $invoice) => [
            'topic' => NotificationTopicEnum::ADMIN_INVOICE_OVERDUE,
            'message' => $this->plain("{$invoice->user?->name} has an overdue {$invoice->amountMoney()} invoice."),
            'url' => route('admin.invoice', $invoice->reference),
            'since' => $invoice->due_at,
        ])
        ->all();
}

private function unpaidInvoiceQuery(): Builder
{
    return Invoice::query()
        ->where('status', StatusInvoice::ISSUED)
        ->where('due_at', '<', now());
}
```

Plus a `NotificationTopicEnum::ADMIN_INVOICE_OVERDUE` case.

## Avoid

- `Flux::toast()` directly in a page.
- `session()->flash()` for feedback on an action that does not redirect.
- A second toast group, or one per page.
- A new Notification class — extend `NotificationTopicEnum` instead.
- Adding `'mail'` to `GeneralNotification::via()` — email is a Mailable.
- `Notification::send()` directly; go through `NotificationService`.
- Notifying the acting admin about their own action.
- Storing a notification to represent unresolved work — that is `AdminActionService`.
- Running `pendingItems()` without checking `pendingCount()` first.
- A notification with no `url` in its payload when there is somewhere to go.
