# jobs.md

## Rule

Background work takes one of four forms:

| Need | Mechanism |
| --- | --- |
| Send an email off the request cycle | a **Mailable** with `ShouldQueue`, sent with `Mail::queue()` |
| Notify a user in-app | `GeneralNotification implements ShouldQueue` via `NotificationService` |
| Periodic sweeps, bulk sends, status syncs | a **scheduled Artisan command** |
| One unit of deferred work that must retry on its own — an inbound webhook | a **Job** in `app/Jobs/` |

`app/Jobs/` was sanctioned on 2026-10-10 for the payments module.
`ProcessPaymentWebhook` is the model: the controller stores the webhook row and
answers in milliseconds, and the Job does the slow part with retries and backoff.
Reach for a Job only when the first three rows do not fit — most deferred work here is
still "send something" or "sweep something".

The queue infrastructure is present and used — `jobs`, `job_batches`, and `failed_jobs`
tables exist, and `composer dev` runs `php artisan queue:listen --tries=1` — it is
simply driven by queued mail and notifications rather than hand-written Jobs.

## Why

- Every asynchronous need in this domain is "send something" or "sweep something on a
  schedule". Queued Mailables cover the first; scheduled commands cover the second.
- A Job class for `SendWelcomeEmailJob` would be a wrapper around
  `Mail::queue(new WelcomeEmail(...))` with no added behaviour and one more file to
  keep in sync with the Mailable's constructor.
- Scheduled commands are inspectable (`--dry-run`, `--force`, `--session=`) and
  re-runnable by hand, which a dispatched Job is not.
- Idempotency for the sweeps is handled by a unique index + `insertOrIgnore`, which
  survives overlapping runs more reliably than queue-level deduplication.

## Example

**Queued email instead of a Job:**

```php
// app/Mail/WelcomeEmail.php
class WelcomeEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithEmailResolver;

    public function __construct(
        public User $user,
        public ?string $otp = null,
        public int $expiresInMinutes = 15,
    ) {
        $this->afterCommit();
    }
    …
}
```

```php
// app/Traits/WithAuthWorker.php — after the transaction commits
$user = DB::transaction(function () use (…) { … });

// Send welcome email after the transaction is committed
app(EmailVerificationOtpService::class)->sendWelcomeEmail($user, $sendOtp);
```

```php
Mail::to($user->email)->queue(new LoginEmail($user, request()->ip()));
```

**Queued notification instead of a Job:**

```php
class GeneralNotification extends Notification implements ShouldQueue
{
    use Queueable;
    …
}

app(NotificationService::class)->notifyAdmins($topic, $message, ['url' => $url]);
```

**Scheduled command instead of a recurring Job:**

```php
// routes/console.php
Schedule::command('training:sync-cohort-status')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('training:send-class-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

```php
// app/Console/Commands/SendClassReminderCommand.php — bulk send with isolation
foreach ($recipients as $row) {
    try {
        Mail::to($row['user']->email)->queue(new ClassReminderEmail(…));

        $queued++;
    } catch (\Throwable $exception) {
        // One bad address must not stop the rest of the cohort.
        Log::channel('ezeh')->error('Class reminder failed to queue', [
            'class_session_id' => $session->id,
            'user_id' => $row['user']->id,
            'message' => $exception->getMessage(),
        ]);
    }
}
```

## Template

```
Work to do off the request cycle?

Is it an email?
└── yes → a Mailable with ShouldQueue + afterCommit(), sent with Mail::queue()
          from a Service or a Command  (see mail.md)

Is it an in-app notification?
└── yes → NotificationService::notifyUser() / notifyAdmins()  (see notifications.md)

Is it periodic, or a bulk sweep over records?
└── yes → an Artisan command with --dry-run, scheduled in routes/console.php,
          idempotent via a unique index + insertOrIgnore  (see commands.md)

Is it none of these — one unit of work that must retry by itself on failure?
└── a Job in app/Jobs/, modelled on ProcessPaymentWebhook
```

Job conventions:

- Named for what it does, verb first, no suffix: `ProcessPaymentWebhook`.
- `use Queueable;` and constructor property promotion for its inputs — a model, never
  an array of its attributes.
- Connection and queue read from config in the constructor; `$this->afterCommit()` if
  it reads a record written in a transaction.
- `tries()` and `backoff()` as methods; `ShouldBeUnique` with a `uniqueId()` when a
  manual retry could otherwise run it twice.
- `handle()` is one call into a service. A `failed()` that records the failure on the
  row, so it can be retried by hand.
- Idempotency through a database constraint, not through queue configuration — the
  webhook's `unique(vendor, event_key)` is what stops a duplicate, not the queue.

## Avoid

- A Job for something a queued Mailable, a queued notification or a scheduled command
  already covers.
- `dispatch(new SomeJob(...))` — use `SomeJob::dispatch(...)`. `Bus::batch(...)` and
  `Bus::chain(...)` still have no precedent; ask first.
- A Job that only wraps `Mail::queue()`.
- `Mail::send()` (synchronous) instead of `Mail::queue()`.
- A Mailable or Notification without `ShouldQueue`.
- Sending mail inside a `DB::transaction()` closure.
- Using a queued Job for periodic work that a scheduled command handles more
  transparently.
- Adding Horizon or a queue dashboard without approval.
- Suggesting a Jobs layer as an "improvement" while working on an unrelated task.
