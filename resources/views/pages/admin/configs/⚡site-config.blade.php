<?php

use App\Enums\GateAccessEnum;
use App\Rules\ImageRule;
use App\Rules\PhoneRule;
use App\Services\CloudStorageService;
use App\Traits\WithGateProps;
use App\Traits\WithSiteConfigProcessor;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads, WithGateProps, WithSiteConfigProcessor;

    public mixed $logoUpload = null;

    public mixed $logoDarkUpload = null;

    public mixed $faviconUpload = null;

    protected function configSubject(): string
    {
        return 'site info';
    }

    public function mount(): void
    {
        kSetSiteTitle('config', 'site-info');
        $this->setPageGate('config.site-info');
    }

    /**
     * A switch that is off takes its dependent fields off the screen with it, so
     * those fields are only validated while they are actually being shown.
     *
     * Leaving them in the list unconditionally would fail the save on a field the
     * administrator cannot see — and there is nothing to enforce anyway, because
     * nothing reads a dependent value while its feature is off.
     */
    protected function rules(): array
    {
        return [
            'config.name' => ['required', 'string', 'max:150'],
            'config.phone' => [new PhoneRule(required: false)],
            'config.email' => ['nullable', 'email', 'max:190'],
            'config.contact-email' => ['nullable', 'email', 'max:190'],
            'config.address' => ['nullable', 'string', 'max:500'],
            // A GA4 web stream's measurement ID; AnalyticsService ignores anything else.
            'config.analytics.ga4-id' => ['nullable', 'string', 'regex:/^G-[A-Za-z0-9]{4,20}$/'],
            'logoUpload' => [new ImageRule(required: false, size: 1024)],
            'logoDarkUpload' => [new ImageRule(required: false, size: 1024)],
            'faviconUpload' => [new ImageRule(required: false, size: 512, addMimes: ['ico'])],
        ];
    }

    protected function messages(): array
    {
        return [
            'config.analytics.ga4-id.regex' => 'That is not a GA4 measurement ID — it starts with G-, like G-AB12CD34EF.',
        ];
    }

    public function save(): bool
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        // Pasted IDs arrive with stray spaces and in any case; stored the way
        // Google prints them, so the comparison with the saved config is fair.
        if (filled(data_get($this->config, 'analytics.ga4-id'))) {
            data_set($this->config, 'analytics.ga4-id', strtoupper(trim(data_get($this->config, 'analytics.ga4-id'))));
        }

        $this->validate();

        $this->respondPrimary(
            if: $this->config === $this->currentConfig &&
            ! $this->logoUpload &&
            ! $this->logoDarkUpload &&
            ! $this->faviconUpload
        );

        // Written to whichever disk is active. The old file is removed from both,
        // because a path in the config carries no record of the disk it went to.
        $storage = app(CloudStorageService::class);

        // Logo
        if ($this->logoUpload) {
            // Store new logo and delete old one
            $filename = kStoreFile($this->logoUpload, filename: 'site-logo', path: 'site-config', disk: $storage->disk());
            $storage->delete(data_get($this->config, 'logo'));

            // Update config with new logo path for saving to database
            $this->config['logo'] = $filename;
        }

        // Logo Dark
        if ($this->logoDarkUpload) {
            // Store new logo and delete old one
            $filename = kStoreFile($this->logoDarkUpload, filename: 'site-logo-dark', path: 'site-config', disk: $storage->disk());
            $storage->delete(data_get($this->config, 'logo-dark'));

            // Update config with new logo dark path for saving to database
            $this->config['logo-dark'] = $filename;
        }

        // Favicon
        if ($this->faviconUpload) {
            // Store new favicon and delete old one
            $filename = kStoreFile($this->faviconUpload, filename: 'site-favicon', path: 'site-config', disk: $storage->disk());
            $storage->delete(data_get($this->config, 'favicon'));

            // Update config with new favicon path for saving to database
            $this->config['favicon'] = $filename;
        }

        $this->saveConfig(true);

        $this->reset('logoUpload', 'logoDarkUpload', 'faviconUpload');

        $this->redirectRoute('admin.config.site', navigate: true);

        return $this->respondSuccess();
    }

    /**
     * The saved logo and favicon, as the previews show them. Read from the cached
     * config, which already knows which disk each one is on; a file never
     * uploaded previews as the empty placeholder rather than the kit's artwork.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function brandAssets(): array
    {
        return collect(CloudStorageService::CONFIG_FILE_KEYS)
            ->mapWithKeys(fn (string $key) => [
                $key => filled(data_get($this->currentConfig, $key)) ? (string) kSiteConfig($key) : kSafeImage(),
            ])
            ->all();
    }
};
?>

<form wire:submit="save" class="space-y-6">
    <flux:card class="space-y-6">
        <div>
            <flux:heading level="2" size="lg">Site Configurations</flux:heading>
            <flux:text class="mt-1"></flux:text>
        </div>

        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
            <div class="space-y-6 grow">
                <flux:input wire:model="config.name" placeholder="Your site name" label="Site name" />
            </div>
        </div>
    </flux:card>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <flux:card class="space-y-6 lg:col-span-2">
            <div>
                <flux:heading level="2" size="lg">Contact details</flux:heading>
                <flux:text class="mt-1">Use these details wherever visitors need to reach you.</flux:text>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <x-form.phone-field wire:model="config.phone" />

                <flux:field>
                    <flux:label>Primary email</flux:label>
                    <flux:input type="email" wire:model="config.email" placeholder="hello@example.com" />
                    <flux:error name="config.email" />
                </flux:field>

                <flux:field>
                    <flux:label>Contact email</flux:label>
                    <flux:input type="email" wire:model="config.contact-email" placeholder="support@example.com" />
                    <flux:error name="config.contact-email" />
                </flux:field>

                <flux:field>
                    <flux:label>Address</flux:label>
                    <flux:input wire:model="config.address" placeholder="123 Main Street" />
                    <flux:error name="config.address" />
                </flux:field>
            </div>
        </flux:card>

        <flux:card class="space-y-6">
            <div>
                <flux:heading level="2" size="lg">Analytics</flux:heading>
                <flux:text class="mt-1">
                    Google Analytics 4 loads on the public pages only, and only for visitors who allow it on the
                    cookie banner. Neither workspace is ever measured.
                </flux:text>
            </div>

            <flux:input
                wire:model="config.analytics.ga4-id"
                label="Measurement ID"
                placeholder="G-AB12CD34EF"
                description:trailing="In Google Analytics: Admin › Data streams › your web stream. Leave empty to turn analytics off."
            />
        </flux:card>
    </div>

    <flux:card class="space-y-6">
        <div>
            <flux:heading level="2" size="lg">Brand assets</flux:heading>
            <flux:text class="mt-1">Upload the logo variants and favicon used by the site.</flux:text>
        </div>

        <div class="grid gap-6 md:grid-cols-3">
            <x-form.image-field
                wire:model="logoUpload"
                label="Logo"
                max-size="1 MB"
                :default="$this->brandAssets['logo']"
                :temporary="$logoUpload?->temporaryUrl()"
            />

            <x-form.image-field
                wire:model="logoDarkUpload"
                label="Dark logo"
                max-size="1 MB"
                :default="$this->brandAssets['logo-dark']"
                :temporary="$logoDarkUpload?->temporaryUrl()"
            />

            <x-form.image-field
                wire:model="faviconUpload"
                label="Favicon"
                formats="ICO, JPG, JPEG, PNG, WEBP"
                max-size="512 KB"
                accept="image/x-icon,image/jpeg,image/png,image/webp"
                :default="$this->brandAssets['favicon']"
                :temporary="$faviconUpload?->temporaryUrl()"
            />
        </div>
    </flux:card>

    <x-util.floating-actions>
        <x-dashboard.gate.button :gate="$pageGate" :level="$gateModify" type="submit" variant="primary" icon="check">
            Save changes
        </x-dashboard.gate.button>
    </x-util.floating-actions>
</form>
