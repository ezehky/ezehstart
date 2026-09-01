# commands.md

## Rule

Console commands live in `app/Console/Commands/`, named `{Verb}{Subject}Command`, with
a **domain-prefixed signature** (`training:…`). They are registered by discovery and
scheduled in `routes/console.php`.

```php
class SendClassReminderCommand extends Command
{
    protected $signature = 'training:send-class-reminders
                            {--reminder=* : Only send these lead times (1-day, 5-hours, 1-hour)}
                            {--session=* : Send for these class session ids, ignoring the time window}
                            {--tolerance=15 : How many minutes wide the send window is}
                            {--force : Send again even if the reminder was already recorded}
                            {--dry-run : Report what would be sent without mailing or recording anything}';

    protected $description = 'Email enrolled students and assigned trainers 1 day, 5 hours and 1 hour before a class starts';

    public function handle(): int
    {
        …

        return self::SUCCESS;
    }
}
```

### The three existing commands

| Command | Signature | Purpose |
| --- | --- | --- |
| `SyncCohortStatusCommand` | `training:sync-cohort-status` | Move cohorts through their lifecycle by date |
| `SyncClassSessionStatusCommand` | `training:sync-class-status` | Same for class sessions |
| `SendClassReminderCommand` | `training:send-class-reminders` | 1-day / 5-hour / 1-hour class reminders |

### Conventions

- `handle(): int`, returning `self::SUCCESS` (or `self::FAILURE`).
- Options declared multi-line in `$signature`, each with a description after `:`.
- `--dry-run` and `--force` on anything that sends or mutates in bulk.
- Flags cached as typed properties in `handle()`:

```php
protected bool $dryRun = false;

protected bool $force = false;

public function handle(): int
{
    $this->dryRun = (bool) $this->option('dry-run');
    $this->force = (bool) $this->option('force');
    …
}
```

- Work split into small `protected` methods with docblocks and `Collection` return
  types: `reminders()`, `sessionsFor()`, `claim()`, `recipients()`, `deliver()`.
- Progress via `$this->line(sprintf(…))` with colour tags, summary via `$this->info()`:

```php
$this->line(sprintf(
    '  <fg=green>%s</> · %s · %s · %d recipient(s)',
    $reminder->label(),
    $session->cohort->displayName(),
    $session->starts_at->format('M d, Y g:i A'),
    $recipients->count(),
));

$this->info(sprintf(
    '%s %d reminder(s) covering %d email(s).',
    $this->dryRun ? 'Would send' : 'Sent',
    $sent,
    $mails,
));
```

### Idempotency — claim before acting

A scheduled command that sends must reserve its work through a **unique index**, not a
read-then-write:

```php
/**
 * Reserve this reminder before mailing. The unique index on
 * (class_session_id, reminder) means two overlapping runs cannot both win,
 * so nobody gets the same reminder twice.
 */
protected function claim(ClassSession $session, ClassReminderEnum $reminder): bool
{
    if ($this->dryRun) {
        return $this->force || ! $session->reminders()->where('reminder', $reminder)->exists();
    }

    if ($this->force) {
        ClassSessionReminder::updateOrCreate(
            ['class_session_id' => $session->id, 'reminder' => $reminder],
            ['sent_at' => now()],
        );

        return true;
    }

    return ClassSessionReminder::insertOrIgnore([
        'class_session_id' => $session->id,
        'reminder' => $reminder->value,
        'sent_at' => now(),
    ]) > 0;
}
```

### Bounded time windows

Never "everything older than X" — a closed window prevents an outage from replaying
stale work:

```php
/**
 * Classes due for this reminder: still scheduled, on a live cohort, and
 * starting inside the window that closes `tolerance` minutes after the
 * lead time is reached. The window keeps a long outage from blasting stale
 * reminders once the scheduler comes back up.
 */
protected function sessionsFor(ClassReminderEnum $reminder, Carbon $now, int $tolerance): Collection
{
    $windowEnd = $now->copy()->addMinutes($reminder->minutes());
    $windowStart = $windowEnd->copy()->subMinutes($tolerance);

    return ClassSession::query()
        ->with(['cohort.training'])
        ->where('status', StatusClassSession::SCHEDULED)
        ->whereHas('cohort', fn ($cohort) => $cohort->whereNotIn('status', [
            StatusCohort::CANCELLED,
            StatusCohort::PAUSED,
        ]))
        ->when(
            $sessions,
            fn ($query) => $query->whereIn('id', $sessions),
            fn ($query) => $query->whereBetween('starts_at', [$windowStart, $windowEnd]),
        )
        ->orderBy('starts_at')
        ->get();
}
```

### Failure isolation

One bad record must not abort a batch:

```php
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
```

### The schedule — `routes/console.php`

```php
/*
|--------------------------------------------------------------------------
| Training schedule
|--------------------------------------------------------------------------
|
| Statuses are refreshed before the reminders go out, so a reminder is never
| sent for a class the same tick would have completed or cancelled.
|
*/

Schedule::command('training:sync-cohort-status')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('training:sync-class-status')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('training:send-class-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

Rules:

- `->withoutOverlapping()` on **every** scheduled command.
- `->runInBackground()` on independent commands; **omit it** where ordering matters
  (the reminder command runs after the two syncs, in the same tick, deliberately).
- A block comment above the group explains the ordering.

Closure commands (`Artisan::command('inspire', …)`) exist only for the framework
default.

## Why

- A domain prefix (`training:`) groups the commands in `php artisan list` and leaves
  room for other domains later.
- `--dry-run` makes a bulk mailer safe to inspect in production before it sends.
- Claiming through `insertOrIgnore` on a unique index is the only reliable defence when
  two scheduler ticks overlap — a `where()->exists()` check has a race between the read
  and the insert.
- A closed time window means restoring a server after two days of downtime does not
  email everyone about classes that already happened.
- Ordering the reminders after the status syncs prevents mailing about a class the same
  tick is about to cancel.

## Example

`SendClassReminderCommand::handle()`:

```php
public function handle(): int
{
    $this->dryRun = (bool) $this->option('dry-run');
    $this->force = (bool) $this->option('force');

    $tolerance = max(1, (int) $this->option('tolerance'));
    $now = now();
    $sent = 0;
    $mails = 0;

    foreach ($this->reminders() as $reminder) {
        $this->sessionsFor($reminder, $now, $tolerance)->each(function (ClassSession $session) use ($reminder, &$sent, &$mails) {
            if (! $this->claim($session, $reminder)) {
                return;
            }

            $recipients = $this->recipients($session);
            $mails += $this->deliver($session, $reminder, $recipients);
            $sent++;

            $this->line(sprintf(…));
        });
    }

    $this->info(sprintf(…));

    return self::SUCCESS;
}
```

## Template

```php
<?php

namespace App\Console\Commands;

use App\Enums\StatusInvoice;
use App\Mail\InvoiceReminderEmail;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendInvoiceReminderCommand extends Command
{
    protected $signature = 'finance:send-invoice-reminders
                            {--invoice=* : Send for these invoice ids, ignoring the window}
                            {--tolerance=60 : How many minutes wide the send window is}
                            {--dry-run : Report what would be sent without mailing anything}';

    protected $description = 'Email customers whose invoice falls due today';

    protected bool $dryRun = false;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        $sent = 0;

        $this->dueInvoices()->each(function (Invoice $invoice) use (&$sent) {
            if ($this->dryRun) {
                $sent++;

                return;
            }

            try {
                Mail::to($invoice->user->email)->queue(new InvoiceReminderEmail($invoice->user, $invoice));

                $sent++;
            } catch (\Throwable $exception) {
                // One bad address must not stop the rest of the run.
                Log::error('Invoice reminder failed to queue', [
                    'invoice_id' => $invoice->id,
                    'message' => $exception->getMessage(),
                ]);
            }

            $this->line(sprintf('  <fg=green>%s</> · %s', $invoice->reference, $invoice->user->name));
        });

        $this->info(sprintf('%s %d reminder(s).', $this->dryRun ? 'Would send' : 'Sent', $sent));

        return self::SUCCESS;
    }

    /**
     * Invoices falling due inside the window.
     *
     * @return Collection<int, Invoice>
     */
    protected function dueInvoices(): Collection
    {
        $ids = (array) $this->option('invoice');
        $tolerance = max(1, (int) $this->option('tolerance'));

        return Invoice::query()
            ->with('user:id,name,email')
            ->where('status', StatusInvoice::ISSUED)
            ->when(
                $ids,
                fn ($query) => $query->whereIn('id', $ids),
                fn ($query) => $query->whereBetween('due_at', [now()->subMinutes($tolerance), now()]),
            )
            ->orderBy('due_at')
            ->get();
    }
}
```

```php
// routes/console.php
Schedule::command('finance:send-invoice-reminders')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
```

Create with `php artisan make:command SendInvoiceReminderCommand --no-interaction`.

## Avoid

- A command without a domain prefix in its signature.
- `handle(): void` — return `int`.
- Options with no description.
- A bulk sender without `--dry-run`.
- Read-then-write idempotency checks instead of `insertOrIgnore` on a unique index.
- Unbounded "everything before now" queries.
- A batch that aborts on the first failure.
- `Schedule::command()` without `->withoutOverlapping()`.
- `->runInBackground()` on a command whose ordering relative to another matters.
- Business logic in the command that belongs in a service — the command orchestrates,
  queries, and reports.
- Interactive prompts in a scheduled command.
