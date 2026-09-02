# mail.md

## Rule

Every email is a **Mailable** in `app/Mail/`, named `{Subject}Email`, queued, and using
`WithEmailResolver`.

```php
<?php

namespace App\Mail;

use App\Models\User;
use App\Traits\WithEmailResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

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

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to '.$this->getEmailConfig()['name'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.welcome',
        );
    }
}
```

Non-negotiables:

- `implements ShouldQueue`
- `use Queueable, SerializesModels, WithEmailResolver;` in that order
- **`$this->afterCommit();` in the constructor** — the mail must not go out if the
  surrounding transaction rolls back
- Promoted **public** constructor properties (they are the view data)
- `envelope()` and `content()` methods; no `build()`

### `WithEmailResolver`

Injects `$emailConfig` into every mail view, so templates never call `kSiteConfig()`:

```php
trait WithEmailResolver
{
    /**
     * @return string|array{name: string, logo: string|null, contactEmail: string|null, email: string|null}
     */
    protected function getEmailConfig(string $key = ''): string|array
    {
        $keys = $key ? [] : ['name', 'logo', 'contact-email', 'email'];

        return kSiteConfig($key, $keys);
    }

    /**
     * Override buildViewData to inject emailConfig
     * MUST BE PUBLIC to match Illuminate\Mail\Mailable
     */
    public function buildViewData(): array
    {
        return [
            ...parent::buildViewData(),
            'emailConfig' => $this->getEmailConfig(),
        ];
    }
}
```

Use `$this->getEmailConfig()['name']` in subjects; `$emailConfig` in views.

### Subjects

Built in `envelope()`, interpolating real data. Branching subjects use a ternary or
`match`:

```php
public function envelope(): Envelope
{
    $subject = $this->reminder->isHourBefore()
        ? "Starting soon: {$this->title()} begins in 1 hour"
        : "Reminder: {$this->title()} starts {$this->reminder->lead()}";

    return new Envelope(subject: $subject);
}
```

### Extra view data

Pass computed values through `Content::with`, keeping the constructor to real inputs:

```php
public function content(): Content
{
    return new Content(
        view: 'emails.training.class-reminder',
        with: [
            'title' => $this->title(),
            'cohort' => $this->session->cohort,
        ],
    );
}

/**
 * What to call the class in subject lines and copy — the curriculum topic it
 * teaches, else the cohort it belongs to.
 */
protected function title(): string
{
    return $this->session->title();
}
```

### Views

`resources/views/emails/{domain}/{kebab-case}.blade.php`, grouped by domain:

```
emails/auth/welcome.blade.php
emails/auth/login.blade.php
emails/auth/account-otp.blade.php
emails/auth/email-verification-otp.blade.php
emails/auth/password-reset-otp.blade.php
emails/auth/passwordless-otp.blade.php
emails/training/class-reminder.blade.php
emails/training/enrollment-success.blade.php
emails/training/refund-status.blade.php
emails/finance/withdrawal-status.blade.php
emails/affiliate/commission.blade.php
```

Every view wraps in the shared shell:

```blade
<x-layouts.email>
    …
</x-layouts.email>
```

with `x-layouts.email.theme` for the inline-styled palette and
`x-layouts.email.label-value` for label/value rows.

### Sending

**Always `queue()`, never `send()`:**

```php
Mail::to($user->email)->queue(new LoginEmail($user, request()->ip()));

Mail::to($row['user']->email)->queue(
    new ClassReminderEmail($row['user'], $session, $reminder, $row['isTrainer'])
);
```

Sending in a loop is wrapped so one bad address cannot stop the batch:

```php
foreach ($recipients as $row) {
    try {
        Mail::to($row['user']->email)->queue(new ClassReminderEmail(…));

        $queued++;
    } catch (\Throwable $exception) {
        // One bad address must not stop the rest of the cohort.
        Log::channel('code')->error('Class reminder failed to queue', [
            'class_session_id' => $session->id,
            'user_id' => $row['user']->id,
            'reminder' => $reminder->value,
            'message' => $exception->getMessage(),
        ]);
    }
}
```

### Send **after** the transaction

```php
$user = DB::transaction(function () use (…) { … });

// Send welcome email after the transaction is committed
app(EmailVerificationOtpService::class)->sendWelcomeEmail($user, $sendOtp);
```

`afterCommit()` in the constructor is the belt; sending outside the closure is the
braces. Do both.

### The catalogue

| Mailable | Sent when |
| --- | --- |
| `WelcomeEmail` | registration |
| `LoginEmail` | every successful sign-in (security alert with IP) |
| `EmailVerificationOtpEmail` | email verification code |
| `PasswordlessOtpEmail` | passwordless sign-in code |
| `PasswordResetOtpEmail` | password reset code |
| `AccountOtpEmail` | sensitive account change |
| `EnrollmentSuccessEmail` | admission confirmed |
| `ClassReminderEmail` | 1 day / 5 hours / 1 hour before a class |
| `RefundStatusEmail` | refund status change |
| `WithdrawalStatusEmail` | withdrawal status change |
| `AffiliateCommissionEmail` | commission credited |

Sending is owned by a **service or command**, not by a Livewire page — OTP services,
`TrainingService`, `TransactionService`, `AffiliateService`,
`SendClassReminderCommand`.

## Why

- `ShouldQueue` + `queue()` keeps every request fast; a slow SMTP handshake never
  blocks a form submit.
- `afterCommit()` prevents the classic bug of emailing "your account is ready" for a
  transaction that then rolled back.
- `WithEmailResolver` means the site name, logo, and contact address in every email come
  from the live site configuration rather than `.env` or hard-coded copy — an admin
  rebrand updates all eleven templates.
- One Mailable per purpose (rather than one generic one) keeps subject construction and
  view data next to the thing they describe.

## Example

`ClassReminderEmail` — the fullest example:

```php
class ClassReminderEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithEmailResolver;

    public function __construct(
        public User $user,
        public ClassSession $session,
        public ClassReminderEnum $reminder,
        public bool $isTrainer = false,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $subject = $this->reminder->isHourBefore()
            ? "Starting soon: {$this->title()} begins in 1 hour"
            : "Reminder: {$this->title()} starts {$this->reminder->lead()}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.training.class-reminder',
            with: [
                'title' => $this->title(),
                'cohort' => $this->session->cohort,
            ],
        );
    }

    protected function title(): string
    {
        return $this->session->title();
    }
}
```

## Template

```php
<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\User;
use App\Traits\WithEmailResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceIssuedEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithEmailResolver;

    public function __construct(
        public User $user,
        public Invoice $invoice,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your invoice {$this->invoice->reference} from ".$this->getEmailConfig()['name'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.finance.invoice-issued',
            with: [
                'amount' => $this->invoice->amountMoney(),
                'dueAt' => $this->invoice->dueAtHuman(),
            ],
        );
    }
}
```

```blade
{{-- resources/views/emails/finance/invoice-issued.blade.php --}}
<x-layouts.email>
    <p>Hello {{ $user->firstName() }},</p>

    <p>Your invoice is ready.</p>

    <x-layouts.email.label-value label="Reference" :value="$invoice->reference" />
    <x-layouts.email.label-value label="Amount" :value="$amount" />
    <x-layouts.email.label-value label="Due" :value="$dueAt" />

    <p>Thank you,<br>{{ $emailConfig['name'] }}</p>
</x-layouts.email>
```

```php
// sending, from the service, after the commit
Mail::to($user->email)->queue(new InvoiceIssuedEmail($user, $invoice));
```

## Avoid

- A Mailable without `ShouldQueue`.
- Omitting `$this->afterCommit()`.
- `Mail::send()` instead of `Mail::queue()`.
- Sending inside a `DB::transaction()` closure.
- A `build()` method — use `envelope()` + `content()`.
- `Notification` with a `mail` channel for transactional email — write a Mailable.
- `config('app.name')` or a hard-coded site name in a subject — use
  `getEmailConfig()['name']`.
- Calling `kSiteConfig()` inside a mail view — `$emailConfig` is already injected.
- Sending mail from a Livewire page — a service or command owns it.
- An unguarded loop of `Mail::queue()` calls.
- A mail view that does not wrap in `<x-layouts.email>`.
