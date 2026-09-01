# file_uploads.md

## Rule

```php
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads, WithFormResponseMessage;

    public mixed $logoUpload = null;
    …
};
```

Four pieces, always together:

1. `use WithFileUploads;`
2. A `public mixed $xUpload = null;` property — **named `{thing}Upload`**
3. An `ImageRule` in `rules()`
4. `kStoreFile()` + `kDeleteFile()` in `save()`

### The property

```php
public mixed $logoUpload = null;

public mixed $logoDarkUpload = null;

public mixed $faviconUpload = null;
```

Type is `mixed` (it is a `TemporaryUploadedFile` before submit, `null` after), and the
name carries the `Upload` suffix so it is never confused with the stored path.

### Validation — `ImageRule`

```php
'logoUpload' => [new ImageRule(required: false, size: 1024)],
'logoDarkUpload' => [new ImageRule(required: false, size: 1024)],
'faviconUpload' => [new ImageRule(required: false, size: 512, addMimes: ['ico'])],
'avatar' => [new ImageRule(size: 300)],
```

```php
public function __construct(
    private readonly bool $required = true,
    private readonly int $size = 300,       // KB
    private readonly array|string|null $addMimes = null,
    private readonly array $freshMimes = [],
) {}
```

Defaults: required, 300 KB, `jpeg png jpg webp`.
`addMimes` extends the list; `freshMimes` replaces it.
The rule checks it is a real `UploadedFile`, the extension, and the size — in that
order, returning after the first failure.

### Storing — `kStoreFile()` / `kDeleteFile()`

```php
if ($this->logoUpload) {
    // Store new logo and delete old one
    $filename = kStoreFile($this->logoUpload, filename: 'site-logo', path: 'site-config');
    kDeleteFile(data_get($this->config, 'logo'));

    // Update config with new logo path for saving to database
    $this->config['logo'] = $filename;
}
```

```php
function kStoreFile($file, ?string $filename = null, string $path = '/', string $disk = 'public'): string
```

- Passing `filename:` produces `{kSlug(name)}_{YmdHi}.{ext}` — predictable, sortable,
  and cache-busting on re-upload.
- Omitting it uses Laravel's random hash name — right for user content where the
  original name is irrelevant.
- Disk is `public` by default.

**Always delete the old file before overwriting the column**, and always in that
order — store the new one first so a failed upload does not destroy the existing file.

### Reading back

Never build a storage URL by hand. `WithDynamicModelFormatting` supplies `->fooUrl()`:

```blade
<img src="{{ $user->avatarUrl() }}" alt="{{ $user->name }} avatar" class="size-full object-cover" />
```

It resolves in this order: already a full URL → return it; an image extension →
`kSafeImage()` (which falls back to `images/image.png`, or `images/user.png` for
`avatar`); anything else → `Storage::url()` or `#`.

Image columns are detected by name: `image`, `avatar`, `photo`, `banner`, `thumbnail`,
`logo`, `cover`, `picture`, `background`, `poster`, `evidence`, `flag`. **Name your
column accordingly** and the fallback works automatically.

### Reset after save

```php
$this->reset('logoUpload', 'logoDarkUpload', 'faviconUpload');
```

### The UI — `x-form.image-field`

```blade
<x-form.image-field
    label="Site logo"
    wire:model="logoUpload"
    :default="kSafeImage(data_get($config, 'logo'))"
    :temporary="$logoUpload?->temporaryUrl()"
    formats="JPG, JPEG, PNG, WEBP"
    maxSize="1 MB"
/>
```

The component handles everything:

- a hidden `<input type="file" class="sr-only">` clicked through `$refs.input`
- a live progress bar driven by Livewire's upload events:

```blade
<div
    x-data="{ uploading: false, progress: 0, fileName: null }"
    x-on:livewire-upload-start="uploading = true"
    x-on:livewire-upload-finish="uploading = false"
    x-on:livewire-upload-cancel="uploading = false"
    x-on:livewire-upload-error="uploading = false"
    x-on:livewire-upload-progress="progress = $event.detail.progress"
>
```

- a thumbnail from `$temporary ?? $default`
- the error line, resolved from the bound property:

```blade
@php
    $errorName = $attributes->get('wire:model') ?? $attributes->get('name');
@endphp

@if ($errorName)
    <flux:error name="{{ $errorName }}" />
@endif
```

Use `<x-form.file-field>` for non-image uploads.

### Where files go

| Content | `path:` | Named? |
| --- | --- | --- |
| Site config (logo, favicon) | `site-config` | yes — `site-logo`, `site-favicon` |
| User avatars | `avatars` | no — random name |
| Transaction evidence | `evidence` | no |
| Curriculum imports | temp, deleted after processing | n/a |

Everything lives on the `public` disk. `storage/app/private/` holds the site
configuration JSON and `disposable_domains.txt`, not uploads.

## Why

- The `Upload` suffix keeps the *incoming file* and the *stored path* as two separate
  properties, so a validation failure never leaves a half-written column.
- `ImageRule` over `'image|max:1024'` because the message wording, the mime list, and
  the required/nullable behaviour are then defined once and reused across every upload.
- Named files with a timestamp (`site-logo_202607311422.png`) are predictable enough to
  debug and unique enough to bust a CDN cache.
- Deleting **after** storing means an upload failure leaves the existing asset intact.
- `kSafeImage()` means no page in the app can ship a broken image.

## Example

`⚡site-config.blade.php` — three uploads in one form:

```php
use WithFileUploads, WithFormResponseMessage;

public mixed $logoUpload = null;

public mixed $logoDarkUpload = null;

public mixed $faviconUpload = null;

protected function rules(): array
{
    return [
        …
        'logoUpload' => [new ImageRule(required: false, size: 1024)],
        'logoDarkUpload' => [new ImageRule(required: false, size: 1024)],
        'faviconUpload' => [new ImageRule(required: false, size: 512, addMimes: ['ico'])],
    ];
}

public function save(): bool
{
    $this->validate();

    $this->respondPrimary(
        if: $this->config === $this->currentConfig &&
        ! $this->logoUpload &&
        ! $this->logoDarkUpload &&
        ! $this->faviconUpload
    );

    // Logo
    if ($this->logoUpload) {
        // Store new logo and delete old one
        $filename = kStoreFile($this->logoUpload, filename: 'site-logo', path: 'site-config');
        kDeleteFile(data_get($this->config, 'logo'));

        // Update config with new logo path for saving to database
        $this->config['logo'] = $filename;
    }

    // … logo-dark, favicon identically …

    app(SiteConfigurationService::class)->update($this->config);

    $this->reset('logoUpload', 'logoDarkUpload', 'faviconUpload');

    $this->redirectRoute('admin.site-config', navigate: true);

    return $this->respondSuccess();
}
```

Note the "no changes" check accounts for **all three** uploads.

## Template

```php
use App\Rules\ImageRule;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads, WithFormResponseMessage;

    public ?Invoice $invoice = null;

    public mixed $attachmentUpload = null;

    protected function rules(): array
    {
        return [
            'attachmentUpload' => [new ImageRule(required: false, size: 2048, addMimes: ['pdf'])],
        ];
    }

    public function save(): bool
    {
        $this->validate();

        if ($this->attachmentUpload) {
            // Store the new attachment, then drop the one it replaces.
            $path = kStoreFile($this->attachmentUpload, path: 'invoices');
            kDeleteFile($this->invoice->attachment);

            $this->invoice->attachment = $path;
        }

        $this->respondPrimary(if: $this->invoice->isClean());

        $this->invoice->save();

        $this->reset('attachmentUpload');

        return $this->respondSuccess('The invoice has been saved.');
    }
};
```

```blade
<x-form.image-field
    label="Attachment"
    wire:model="attachmentUpload"
    :default="$invoice?->attachmentUrl()"
    :temporary="$attachmentUpload?->temporaryUrl()"
    formats="JPG, PNG, WEBP, PDF"
    maxSize="2 MB"
/>
```

## Avoid

- `use WithFileUploads;` missing while a file property exists.
- A property named after the column (`$logo`) instead of `$logoUpload`.
- `'image|max:1024'` string rules instead of `ImageRule`.
- `$file->store(...)` / `Storage::put(...)` directly — use `kStoreFile()`.
- `Storage::url($model->avatar)` in Blade — use `$model->avatarUrl()`.
- `asset('storage/'.$path)` — bypasses the disk configuration and the fallback.
- Deleting the old file before the new one is stored.
- Forgetting `$this->reset('xUpload')` after save (the temp file lingers in the payload).
- A raw `<input type="file">` instead of `x-form.image-field` / `x-form.file-field`.
- Omitting the upload properties from the "no changes" check on a settings page.
- Naming an image column something outside the detected list — the fallback stops
  working.
- Uploading to a non-`public` disk without a signed-URL plan.
