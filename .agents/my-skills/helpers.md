# helpers.md

## Rule

Global helper functions live in `app/Helpers/`, are **all prefixed `k`**, and are
autoloaded through `composer.json`:

```json
"files": [
    "app/Helpers/navigations.php",
    "app/Helpers/string-helpers.php",
    "app/Helpers/helper-functions.php",
    "app/Helpers/money-helpers.php"
]
```

Most are wrapped in `if (! function_exists('kName'))`. Every one carries a full
docblock with `@param` lines — they are global and have no class to explain them.

**Adding a new helper file requires `composer dump-autoload`.** Prefer adding to an
existing file.

---

## The full inventory

### `string-helpers.php`

| Function | Signature | Purpose |
| --- | --- | --- |
| `kSlug` | `(string $value, string $separator = '-'): string` | **The** slug function. Maps `&`→`and`, `@`→`at` |
| `kBreakText` | `(?string $text, bool $titleCase = true, bool $lowercase = false): ?string` | `-`/`_` → spaces, optional title case. Powers `Enum::label()` |
| `kTextCompare` | `(mixed $firstPart, ?string $text = '', bool $compare = true): bool` | Case-insensitive compare, or "all array values equal" |
| `kReferenceId` | `(string $prefix = 'TXN-', bool $alphanumeric = false, bool $mixed = false): string` | Public reference — `WTH-4A7B2C20260731` |
| `kGreeting` | `(string $name = 'Guest', bool $exclamation = true): string` | "Good Morning, Kingsley!" |
| `kPluralize` | `(string $string, int $count, string\|bool $prepend = true, bool $format = true): string` | "3 weeks", "week" |
| `kConvertToString` | `(mixed $value): string` | Any value → string (JSON for arrays/objects) |
| `kRemoveUnicode` | `(string $text): string` | Strip emoji / non-ASCII |
| `kStripDomainProtocols` | `(?string $url = null, ?string $prefix = null, string $character = '@', ?string $protocol = null): string` | `https://site.test` → `site.test`, or `info@site.test`. Any port is dropped, so a local `APP_URL` still yields a valid address |
| `kMarkdownFormat` | `(): array` | The markdown cheat-sheet rendered in `x-form.markdown-field` |

### `money-helpers.php`

| Function | Signature | Purpose |
| --- | --- | --- |
| `kMoney` | `(float $number, int $decimals = 2): string` | Number with trailing zeros trimmed |
| `kMoneyFormat` | `(float $amount, ?string $default = null, bool $decodeHtml = false, int $decimals = 2): string` | **Currency output.** Returns `&#8358;12,500` by default |
| `kPointFormat` | `(?float $point): string` | "12 pv" or `-` |

`kSiteConfig()` is **never empty** once the app has booted — the cache layer injects
resolved `logo`, `favicon` and support-link keys even from an empty file. To ask whether
an administrator has actually saved a configuration, read the stored file instead:

```php
app(SiteConfigurationService::class)->getConfigs(raw: true)
```

**Never read an on/off switch with `kSiteConfig()`.** Its `$default` is applied whenever
the value is *falsy*, so a switch an administrator deliberately turned **off** comes back
as the default — which for anything defaulting to true means "off" silently reads as
"on". Use `kSiteFlag()`, which checks whether the key is present:

```php
// Wrong: an admin who turned two-factor off still gets true.
kSiteConfig('security', keys: ['two-factor'], default: true);

// Right: unset falls back, a stored false is honoured.
kSiteFlag('security', 'two-factor', true);
```

Security switches default to the **strict** value, so an install nobody has configured
yet is the safe one rather than the permissive one.

`kMoneyFormat()` returns an **HTML entity**, so Blade must use `{!! !!}`.
Pass `decodeHtml: true` when the string goes somewhere HTML is not rendered (a
plain-text email subject, an aria-label, JSON).

### `helper-functions.php`

| Function | Signature | Purpose |
| --- | --- | --- |
| `kSafeImage` | `(?string $name = null, ?string $altImage = null, bool $useStorage = true, bool $prependSiteAddress = false, string $disk = 'public'): string` | A URL that is **never broken** — falls back to `images/image.png` or `images/user.png` |
| `kStoreFile` | `($file, ?string $filename = null, string $path = '/', string $disk = 'public'): string` | Store an upload; named files get `slug_YmdHi.ext`. Throws `RuntimeException` if the disk refuses the write |
| `kDeleteFile` | `(?string $file = null, string $disk = 'public'): bool` | Delete, null-safe |
| `kDatetimeConverter` | `(Carbon\|string\|null $datetime, ?User $user = null, bool $dateFormat = false, bool $dtFormat = false, bool $diffForHumans = false, bool $compareGT = false, bool $compareLT = false, bool $showTZ = false, ?string $timezone = null, ?string $format = null, bool $addDaySymbol = false): bool\|Carbon\|string` | **The** date function. Timezone-aware, returns `-` for null |
| `kSiteConfig` | `(string $key = '', array $keys = [], mixed $default = [])` | Read site configuration |
| `kSiteFlag` | `(string $group, string $key, mixed $default = false)` | Read an on/off switch out of a config group |
| `kStoreComparePrice` | `(float $price, ?float $compare_price = 0): float` | Returns the higher of the two |

### Gates — see [gates.md](gates.md)

| Function | Signature | Notes |
| --- | --- | --- |
| `kGate` | `(string $resource, GateAccessEnum\|string $level = VIEW, ?User $user = null): bool` | May this account reach the screen, at least this far? Ask for the **lowest** level you need |
| `kGateAccess` | `(string $resource, ?User $user = null): GateAccessEnum` | The level itself, for a screen that renders differently at each one |
| `kPageGate` | `(string $resource, GateAccessEnum\|string $required = VIEW): void` | **Call in every admin `mount()`**, next to `kSetSiteTitle()`. Aborts 404. No-ops off an `admin.*` route |

### `navigations.php`

| Function | Purpose |
| --- | --- |
| `kPageNavigationLinks(string $key = 'admin', bool $strict = true, bool $grouped = false): array` | **The sidebar tree.** Add new pages here |
| `kNavigationStrictAction(array $construct, string $key = ''): array` | Filters the tree by `check` keys and, for `'admin'`, by gates |
| `kCheckActiveTitle(string $page, bool $checkParent = true, string $pageTitle = ''): bool` | Sidebar highlight, by comparing slugged title segments |
| `kSetSiteTitle(string $parent, ?string $child = null, ?string $grandchild = null, ?string $print = null, ?string $subtitle = null, bool $format = true): void` | **Call in every `mount()`** |
| `kSetMetaData(...$rest): void` | SEO / OG metadata |
| `kUpdateSiteTitle(...$rest): void` | Amend the current title |
| `kDestructSiteTitle(): array` | Title → `['parent' => …, 'child' => …]` |
| `kPrintSiteTitle(): string` | Title for on-page display |
| `kPauseSiteTitle(): void` | Suppress the title block |
| `kBackForwardLink(string $routeName, array $params = [], bool $forward = false, string $label = 'Back', bool $spa = true): void` | The back link in the top bar |
| `kBuildLink(array $data, string $route, string $param): array` | Build a link set from values |
| `kLinkBuilder(string $name): array` | Pre-configured link sets |

---

## Usage rules

**Slugs** — always `kSlug()`, never `Str::slug()`:

```php
$this->training->slug = kSlug($this->training->name);
```

**Money** — always `kMoneyFormat()` or `->fooMoney()`, never manual `number_format`:

```blade
<flux:table.cell>{!! kMoneyFormat($order->total) !!}</flux:table.cell>
<flux:table.cell>{!! $transaction->amountMoney() !!}</flux:table.cell>
```

```php
'price' => kMoneyFormat($next->fee, decodeHtml: true),   // for a JSON/array payload
```

**Dates** — always the model's magic accessor in Blade, `kDatetimeConverter()` in PHP:

```blade
{{ $item->createdAtHuman() }}
{{ $session->startsAtDatetimeHuman() }}
{{ $log->createdAtDiffForHumans() }}
```

**Files**:

```php
$filename = kStoreFile($this->logoUpload, filename: 'site-logo', path: 'site-config');
kDeleteFile(data_get($this->config, 'logo'));
$url = kSafeImage($model->avatar, altImage: 'user');
```

**Site config**:

```php
$name = kSiteConfig('name');
$config = kSiteConfig('email-settings');
$configs = kSiteConfig(keys: ['logo', 'name', 'favicon']);
$days = kSiteConfig('user.account-deletion-days', default: 30);
```

Dotted paths work. Always pass a `default:` for a value you branch on.

**Titles** — first line of every `mount()`:

```php
kSetSiteTitle('config', 'faqs');
kSetSiteTitle('training', 'cohorts', $this->cohort->name);
kSetSiteTitle($title, format: false);   // do not title-case hand-written copy
```

**Comparisons**:

```php
if (kTextCompare($altImage, 'user')) { … }
```

## Why

- The `k` prefix guarantees no collision with Laravel's own global helpers and makes
  every project helper findable with one grep.
- One date function means the whole app respects the user's timezone
  (`$user->timezone`, defaulting to `kSiteConfig('timezone')`) and renders `-` rather
  than a fatal for null dates.
- `kSafeImage()` means no page ever ships a broken image; the fallback is decided once.
- `kMoneyFormat()` returning an entity keeps the naira symbol correct regardless of
  font/encoding; `decodeHtml: true` is the escape hatch for non-HTML contexts.
- `kSlug()`'s `&`→`and` dictionary keeps "UI & UX" from slugging to `ui-ux`.

## Example

`kDatetimeConverter()` is the engine behind every date in the app:

```php
public function dateHuman(
    string $column = 'created_at',
    bool $user = false,
    bool $datetime = false,
    bool $diffForHumans = false,
    ?string $format = null,
    bool $addDaySymbol = false
): ?string {
    if (! isset($this->{$column})) {
        return null;
    }

    return kDatetimeConverter(
        $this->{$column},
        $user ? auth()->user() : null,
        dateFormat: ! $datetime,
        dtFormat: $datetime,
        diffForHumans: $diffForHumans,
        format: $format,
        addDaySymbol: $addDaySymbol
    );
}
```

## Template

```php
// append to app/Helpers/string-helpers.php

if (! function_exists('kInvoiceLabel')) {
    /**
     * Build the human label for an invoice reference.
     *
     * @param  string  $reference  The invoice reference, e.g. "INV-4A7B2C20260731".
     * @param  bool  $short  Return only the trailing serial.
     * @return string The label shown in listings and emails.
     */
    function kInvoiceLabel(string $reference, bool $short = false): string
    {
        return $short ? (string) str($reference)->after('-') : "Invoice {$reference}";
    }
}
```

Adding to an existing file needs no autoload change. A **new** file must be added to
`composer.json` → `autoload.files`, followed by `composer dump-autoload`.

## Avoid

- A global function without the `k` prefix.
- A helper without a docblock.
- `Str::slug()`, manual `number_format` on money, `Carbon::parse(...)->format(...)` in
  Blade — all three have a helper.
- `{{ kMoneyFormat(...) }}` — money needs `{!! !!}`.
- Business logic in a helper. Helpers format, compare, and read config; they do not
  query, write, or send.
- A new helper file when an existing one fits.
- Calling a helper that hits the database inside a Blade loop.
- `config('_setups.title')` directly — use `kSetSiteTitle()` / `kDestructSiteTitle()`.
- Hard-coding the site name, email, or currency — `kSiteConfig()`.
