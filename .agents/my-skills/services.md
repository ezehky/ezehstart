# services.md

## Rule

All business logic that is shared, transactional, or spans more than one aggregate
lives in `app/Services/`. Nineteen ship with the starter:

| Service | Owns |
| --- | --- |
| `ActivityLogService` | The audit trail — `affectedColumns()` and `logActivity()` |
| `AdminActionService` | The "waiting on you" queues on the admin dashboard |
| `UserService` | Sessions, last-seen, profile settings, notification-preference backfill, the workspace middleware check |
| `RoleService` | Role records, who is on which, account type, and the guards on each |
| `NotificationService` | Database notifications behind the bell menu |
| `SiteConfigurationService` | The site-configuration JSON file and its cache |
| `MarkdownService` | Stored markdown → HTML, with raw HTML escaped |
| `PolicyContentService` | Compiling a policy's markdown into its numbered sections |
| `AccountDeletionService` | Anonymising or hard-deleting an account |
| `AccountOtpService` | Codes confirming a sensitive account change |
| `EmailVerificationOtpService` | Welcome mail and email-verification codes |
| `PasswordlessOtpService` | Sign-in codes keyed by address, with throttle and attempt limits |
| `PasswordSecurityService` | The strength rule and the reuse history — the only place a password is written |
| `TwoFactorService` | TOTP secrets, QR codes, recovery codes, remembered devices |
| `SocialAccountService` | Resolving a provider identity to a local account, and linking/unlinking |
| `ImageLibraryService` | Uploads, folders, visibility, the delete guard, usage tracking |
| `BlogService` | Post HTML sanitising, tag resolution, publishing, the public feed |
| `TransactionService` | The ledger: balances, settling, charges, manual adjustments |
| `TrendService` | Series over time — every sparkline and chart. See [dashboard.md](dashboard.md) |

```php
<?php

namespace App\Services;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
class ThingService
{
    // Getters

    // Actions

    // Tools
}
```

### `#[Singleton]`

**Every service carries `#[Singleton]`** (`Illuminate\Container\Attributes\Singleton`).
One instance per request.

### Resolution — always through the container

```php
app(ActivityLogService::class)->logActivity(…);
$serviceInstance = app(ActivityLogService::class);
```

Services that need a subject take it as a promoted constructor property and are
resolved with container arguments:

```php
#[Singleton]
class UserService
{
    public function __construct(private ?User $user = null) {}
}

app(UserService::class, ['user' => $user])->updateLastSeen();
```

Wrapped in a trait when a page needs it repeatedly:

```php
trait WithTrainerResource
{
    protected function trainerService()
    {
        return app(TrainerService::class, ['user' => auth()->user()]);
    }
}
```

**Never `new ThingService()`.**

### Section markers

Long services are divided with plain comment headers. The vocabulary in use:

```php
    // Getters      — read-only queries returning models/collections/scalars
    // Actions      — writes
    // Tools        — private/protected helpers
    // Senders      — notification/mail dispatch (NotificationService)
    // Queues       — pending-work queries (AdminActionService)
    // PRIVATE      — older uppercase style (SiteConfigurationService)
```

### Return conventions

| Situation | Return |
| --- | --- |
| Pure query | the model / `Collection` / scalar |
| Write that can fail with a user-facing reason | `string\|Model` — the **string is the error message** |
| Guard check | `?string` — `null` means allowed, a string is the reason it is blocked |
| Boolean write | `bool` — `true` if something changed |
| Fire-and-forget | `void` |
| Middleware check | `string\|null\|array` — string = flash + login redirect, array = redirect spec, null = pass |

The `string|Model` idiom is the project's error channel out of a service. The caller
tests with `is_string()`:

```php
$complete = app(TrainingService::class)->paymentGatewayConfirmation($transaction, $response);

if (\is_string($complete)) {
    return to_route($routeName, $cohort)->with('error', $complete);
}
```

In a Livewire page it feeds `respondError()`:

```php
$checkOwnership = $this->serviceInstance()->ensureCohortOwnership($cohort, $model);
$this->respondError($checkOwnership, \is_string($checkOwnership));
```

The `?string` guard idiom lets the UI show *why* a control is disabled and the action
refuse for the same reason:

```php
$reason = $service->grantBlockedReason($user, $enum);
$this->respondError($reason ?? '', if: $reason !== null);
```

### Transactions

Any write touching more than one table is wrapped:

```php
DB::transaction(function () use ($user, $wallet, $amount) {
    // Lock the user row to prevent race conditions
    $user->lockForUpdate();
    $bankAccount->lockForUpdate();

    …

    return $transaction;
});
```

A retry count is passed where contention is expected:

```php
DB::transaction(function () use ($userProfile, $transaction) { … }, 3);
```

Money and balance mutations **must** `lockForUpdate()` the affected rows.
Mail is sent **after** the commit — either outside the closure, or via
`$this->afterCommit()` in the Mailable constructor.

The transaction is assigned so the caller keeps the created record:

```php
$transaction = DB::transaction(function () use (…) { … return $transaction; });
```

### Error handling

Wrap fallible service work in `try`/`catch (\Throwable $e)`, log with context, and
return the user-facing message or `null`:

```php
try {
    $user = DB::transaction(function () use (…) { … });
    …
    return $user;
} catch (\Throwable $e) {
    // Log the error for debugging purposes
    Log::channel('ezeh')->error('Error creating user: '.$e->getMessage(), [
        'exception' => $e,
        'data' => $data,
    ]);
}

return null;
```

Gateway services log to a dedicated channel:

```php
Log::channel($vendor->value)->error("{$vendor->label()}: Transaction verification failed.", [
    'transaction' => $transaction->toArray(),
]);
```

### Class docblock

Services with a non-obvious purpose open with a paragraph explaining the *design
decision*, not the mechanics:

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

### The existing services — check here before writing new logic

| Service | Owns |
| --- | --- |
| `UserService` | page access, last-seen, logout, profile defaults, consent, middleware check, workspace nav sharing |
| `RoleService` | role CRUD, `assign()`, `changeType()`, and the `*BlockedReason()` guards |
| `TrainingService` | cohorts, enrolment, admissions, payments, refunds, status sync (1040 lines — the domain core) |
| `TransactionService` | wallet mutations, withdrawals, charges, transaction meta |
| `AffiliateService` | referral bonuses, commission credits |
| `ActivityLogService` | the audit trail — `affectedColumns()` + `logActivity()` |
| `NotificationService` | database notifications in and out of the bell menu |
| `AdminActionService` | live "work waiting on an admin" queue |
| `SiteConfigurationService` | the JSON site config, its cache, and uploads |
| `PolicyContentService` | current policy version, consent set, markdown sections |
| `MarkdownService` | markdown → safe HTML |
| `CurriculumImportService` | bulk curriculum import |
| `TrainerService` | trainer-scoped resources |
| `AccountDeletionService` | anonymise / delete an account |
| `AccountOtpService`, `EmailVerificationOtpService`, `PasswordlessOtpService` | OTP issue + verify |
| `PaystackService`, `FlutterwaveService`, `KoraService` | payment gateways (`GatewayAbstract`) |

## Why

- `#[Singleton]` keeps expensive per-request state (site config cache, resolved user)
  from being rebuilt on every call site.
- Returning `string|Model` rather than throwing keeps the happy path un-nested in
  Livewire pages, where an exception would need a try/catch around every action.
- `?string` guard methods mean the *reason* an action is unavailable is written once
  and used twice: to disable the button and to refuse the request.
- `lockForUpdate()` inside `DB::transaction()` is mandatory for balances — two
  concurrent withdrawals would otherwise both read the same starting balance.

## Example

`app/Services/MarkdownService.php` — a small, complete service:

```php
<?php

namespace App\Services;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;

/**
 * Renders the markdown administrators write — policy bodies, FAQ answers — into the
 * HTML the public pages show.
 *
 * Raw HTML in the source is escaped rather than rendered: the copy is written by
 * administrators, but escaping means a compromised account cannot inject scripts
 * into a page every visitor sees.
 */
#[Singleton]
class MarkdownService
{
    /**
     * Render markdown to HTML.
     */
    public function toHtml(?string $markdown): string
    {
        $markdown = trim((string) $markdown);

        if ($markdown === '') {
            return '';
        }

        $html = Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return trim($this->wrapTables($html));
    }

    /**
     * Wrap tables so a wide one scrolls inside its own container instead of forcing
     * the whole page to scroll sideways on a phone.
     */
    protected function wrapTables(string $html): string
    {
        return (string) preg_replace(
            '/<table>(.*?)<\/table>/s',
            '<div class="table-scroll"><table>$1</table></div>',
            $html
        );
    }
}
```

`NotificationService` — the section-marker style:

```php
#[Singleton]
class NotificationService
{
    // Senders

    /**
     * @param  array<string, mixed>  $data  Extra payload, e.g. a "url" to open.
     */
    public function notifyUser(User $user, NotificationTopicEnum $topic, string $message, array $data = []): void
    {
        $user->notify(new GeneralNotification($topic->value, $message, $data ?: null));
    }

    // Getters

    public function unreadCountFor(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    // Actions

    public function markAllAsRead(User $user): void
    {
        $user->unreadNotifications()->update(['read_at' => now()]);
    }
}
```

## Template

```php
<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\StatusInvoice;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One paragraph on why this service exists and what decision it owns.
 */
#[Singleton]
class InvoiceService
{
    // Getters

    /**
     * The invoices a user still owes on, oldest first.
     *
     * @return Collection<int, Invoice>
     */
    public function outstandingFor(User $user): Collection
    {
        return Invoice::query()
            ->where('user_id', $user->id)
            ->where('status', StatusInvoice::ISSUED)
            ->oldest()
            ->get();
    }

    // Actions

    /**
     * Issue an invoice against a user's account.
     *
     * @return string|Invoice The invoice, or the reason it could not be issued.
     */
    public function issue(User $user, float $amount): string|Invoice
    {
        if ($amount <= 0) {
            return 'An invoice must be for more than zero.';
        }

        try {
            $invoice = DB::transaction(function () use ($user, $amount) {
                $user->lockForUpdate();

                $invoice = Invoice::query()->create([
                    'user_id' => $user->id,
                    'reference' => kReferenceId('INV-'),
                    'amount' => $amount,
                    'status' => StatusInvoice::ISSUED,
                    'issued_at' => now(),
                ]);

                return $invoice;
            });
        } catch (\Throwable $exception) {
            Log::channel('ezeh')->error('Error issuing invoice: '.$exception->getMessage(), [
                'exception' => $exception,
                'user_id' => $user->id,
            ]);

            return 'The invoice could not be issued. Please try again.';
        }

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::INVOICE_CREATE,
            " invoice: {$invoice->reference}",
            model: $invoice,
        );

        return $invoice;
    }

    /**
     * Why this invoice cannot be voided, or null when it can.
     */
    public function voidBlockedReason(Invoice $invoice): ?string
    {
        if ($invoice->status->isPaid()) {
            return 'A paid invoice cannot be voided.';
        }

        return null;
    }

    // Tools

    private function reference(): string
    {
        return kReferenceId('INV-');
    }
}
```

## Avoid

- A service without `#[Singleton]`.
- `new SomeService()` anywhere.
- Throwing bare exceptions for expected business failures — return the message.
- Multi-table writes outside `DB::transaction()`.
- Balance or wallet writes without `lockForUpdate()`.
- Sending mail or notifications **inside** a transaction closure.
- Interfaces or abstract base classes for services. The only abstraction in the project
  is `App\Contracts\GatewayAbstract`, which exists because there are three
  interchangeable payment vendors resolved by `VendorEnum::getServiceInstance()`.
- Service classes that render Blade or touch `request()` for anything except `ip()` /
  `userAgent()` in an audit context.
- Putting a service call inside a Blade loop.
