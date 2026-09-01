# jobs.md

## Rule

**This project has no Job classes.** `app/Jobs/` does not exist, and nothing calls
`dispatch()`, `Bus::`, `Queue::push()`, or a batch.

Background work takes one of two forms:

| Need | Mechanism |
| --- | --- |
| Send an email off the request cycle | a **Mailable** with `ShouldQueue`, sent with `Mail::queue()` |
| Notify a user in-app | `GeneralNotification implements ShouldQueue` via `NotificationService` |
| Periodic sweeps, bulk sends, status syncs | a **scheduled Artisan command** |

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
        Log::error('Class reminder failed to queue', [
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

Is it none of these — genuinely a one-off unit of deferred work?
└── Ask before creating app/Jobs/. It has no precedent here.
```

Rules that carry over if a Job is ever genuinely warranted:

- Constructor property promotion for its inputs
- `$this->afterCommit()` if it reads a record written in a transaction
- Failure isolation and `Log::error()` with a context array
- Idempotency through a database constraint, not through queue configuration

## Avoid

- Creating `app/Jobs/`.
- `dispatch(new SomeJob(...))`, `SomeJob::dispatch(...)`, `Bus::batch(...)`,
  `Bus::chain(...)`.
- A Job that only wraps `Mail::queue()`.
- `Mail::send()` (synchronous) instead of `Mail::queue()`.
- A Mailable or Notification without `ShouldQueue`.
- Sending mail inside a `DB::transaction()` closure.
- Using a queued Job for periodic work that a scheduled command handles more
  transparently.
- Adding Horizon or a queue dashboard without approval.
- Suggesting a Jobs layer as an "improvement" while working on an unrelated task.
