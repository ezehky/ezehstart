<?php

use App\Rules\ImageRule;
use App\Services\SiteConfigurationService;
use App\Traits\WithFormResponseMessage;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads, WithFormResponseMessage;

    public array $config;

    public array $currentConfig = [];

    public mixed $logoUpload = null;

    public mixed $logoDarkUpload = null;

    public mixed $faviconUpload = null;

    public function mount(): void
    {
        kSetSiteTitle('config', 'site-config');

        $service = app(SiteConfigurationService::class);
        $this->config = $service->getConfigs(mergeInitial: true, raw: true);
        $this->currentConfig = $service->getConfigs(raw: true);
    }

    protected function rules(): array
    {
        return [
            'config.name' => ['required', 'string', 'max:150'],
            'config.phone' => ['nullable', 'string', 'max:40'],
            'config.email' => ['nullable', 'email', 'max:190'],
            'config.contact-email' => ['nullable', 'email', 'max:190'],
            'config.address' => ['nullable', 'string', 'max:500'],
            'logoUpload' => [new ImageRule(required: false, size: 1024)],
            'logoDarkUpload' => [new ImageRule(required: false, size: 1024)],
            'faviconUpload' => [new ImageRule(required: false, size: 512, addMimes: ['ico'])],

            'config.email-settings.verification' => ['required', 'boolean'],
            'config.email-settings.verification-strict' => ['required', 'boolean'],

            // User
            'config.user.account-deletion' => ['required', 'boolean'],
            'config.user.account-deletion-days' => ['required', 'integer'],
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

        // Logo Dark
        if ($this->logoDarkUpload) {
            // Store new logo and delete old one
            $filename = kStoreFile($this->logoDarkUpload, filename: 'site-logo-dark', path: 'site-config');
            kDeleteFile(data_get($this->config, 'logo-dark'));

            // Update config with new logo dark path for saving to database
            $this->config['logo-dark'] = $filename;
        }

        // Favicon
        if ($this->faviconUpload) {
            // Store new favicon and delete old one
            $filename = kStoreFile($this->faviconUpload, filename: 'site-favicon', path: 'site-config');
            kDeleteFile(data_get($this->config, 'favicon'));

            // Update config with new favicon path for saving to database
            $this->config['favicon'] = $filename;
        }

        app(SiteConfigurationService::class)->update($this->config);

        $this->reset('logoUpload', 'logoDarkUpload', 'faviconUpload');

        $this->redirectRoute('admin.site-config', navigate: true);

        return $this->respondSuccess();
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

        <div class="">
            <flux:card class="space-y-6">
                <div class="space-y-2">
                    <flux:switch wire:model="config.email-settings.verification" label="Email verification" />
                    <flux:switch wire:model="config.email-settings.verification-strict" label="Strict email verification" />
                </div>

                <div class="space-y-2">
                    <flux:heading level="2" size="lg">Account deletion</flux:heading>
                    <flux:switch wire:model="config.user.account-deletion" label="Allow account deletion" />
                    <x-form.number-field wire:model="config.user.account-deletion-days" label="Account deletion days" min="1" max="365" />
                </div>
            </flux:card>
        </div>
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
                :default="kSafeImage(data_get($config, 'logo'), useStorage: true)"
                :temporary="$logoUpload?->temporaryUrl()"
            />

            <x-form.image-field
                wire:model="logoDarkUpload"
                label="Dark logo"
                max-size="1 MB"
                :default="kSafeImage(data_get($config, 'logo-dark'), useStorage: true)"
                :temporary="$logoDarkUpload?->temporaryUrl()"
            />

            <x-form.image-field
                wire:model="faviconUpload"
                label="Favicon"
                formats="ICO, JPG, JPEG, PNG, WEBP"
                max-size="512 KB"
                accept="image/x-icon,image/jpeg,image/png,image/webp"
                :default="kSafeImage(data_get($config, 'favicon'), useStorage: true)"
                :temporary="$faviconUpload?->temporaryUrl()"
            />
        </div>
    </flux:card>

    <div class="flex justify-end">
        <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save, logoUpload, logoDarkUpload, faviconUpload">
            Save changes
        </flux:button>
    </div>
</form>
