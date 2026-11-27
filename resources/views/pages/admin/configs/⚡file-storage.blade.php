<?php

use App\Enums\ActivityActionEnum;
use App\Services\ActivityLogService;
use App\Services\CloudStorageService;
use App\Traits\WithGateProps;
use App\Traits\WithSiteConfigProcessor;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Configuration › File storage: whether new uploads stay on this server or go to
 * a Cloudflare R2 bucket, and the sync that moves what is already uploaded.
 *
 * Optional, and off until somebody turns it on. The access keys, bucket and
 * endpoint live in .env and are never shown here; this screen holds only the
 * switch and the bucket's public address, which is not a secret — it is in
 * every image URL the site prints. See CloudStorageService.
 */
new class extends Component
{
    use WithGateProps, WithSiteConfigProcessor;

    /**
     * Whether a sync is running. While it is, the screen polls syncNext() and
     * each poll moves one small batch — the whole library in one request is how
     * a sync dies halfway through a copy.
     */
    public bool $syncing = false;

    public int $syncMoved = 0;

    /**
     * Images a batch could not move, so the next batch does not retry them forever.
     *
     * @var array<int, int>
     */
    public array $syncFailedIds = [];

    protected function configSubject(): string
    {
        return 'file storage';
    }

    public function mount(): void
    {
        kSetSiteTitle('config', 'file-storage');
        $this->setPageGate('config.file-storage');
        $this->setConfigInitial();
    }

    /**
     * The address is only asked for while the switch is on, and only required
     * when .env does not already give one.
     */
    protected function rules(): array
    {
        $rules = [
            'config.storage.cloud' => ['required', 'boolean'],
        ];

        if (data_get($this->config, 'storage.cloud')) {
            $rules['config.storage.public-url'] = ['nullable', 'url:https', 'max:255'];
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'config.storage.public-url.url' => 'Enter the bucket\'s https address, like https://media.example.com.',
        ];
    }

    public function save(): bool
    {
        $this->checkGate();

        if (filled(data_get($this->config, 'storage.public-url'))) {
            data_set($this->config, 'storage.public-url', rtrim(trim((string) data_get($this->config, 'storage.public-url')), '/'));
        }

        $this->saveConfig();

        // A full reload rather than wire:navigate: the address is applied to the
        // disk when the app boots, and the status below should read it.
        $this->redirectRoute('admin.config.file-storage');

        return $this->respondSuccess('File storage saved.');
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // STATUS

    /**
     * Everything the status panel shows, asked of the service once per render.
     *
     * @return array{active: string, configured: bool, missing: list<string>, publicUrl: ?string, pending: int, target: string, source: string}
     */
    #[Computed]
    public function status(): array
    {
        $storage = app(CloudStorageService::class);

        return [
            'active' => $storage->diskLabel(),
            'configured' => $storage->isConfigured(),
            'missing' => $storage->missingEnv(),
            'publicUrl' => $storage->publicUrl(),
            'pending' => $storage->pendingCount($this->syncFailedIds),
            'target' => $storage->diskLabel(),
            'source' => $storage->diskLabel($storage->otherDisk()),
        ];
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // SYNC

    /**
     * Start moving every file onto the disk new uploads go to.
     */
    public function startSync(): void
    {
        $this->checkGate();

        $this->respondError(
            'The R2 keys are not all set, so there is nothing to sync with.',
            ! app(CloudStorageService::class)->isConfigured(),
        );

        $this->reset('syncMoved', 'syncFailedIds');
        $this->syncing = true;

        $this->syncNext(first: true);
    }

    /**
     * One batch. Called by the poll on the screen until nothing is left.
     */
    public function syncNext(bool $first = false): void
    {
        // Re-checked on every batch: the poll is a request like any other.
        $this->checkGate();

        if (! $this->syncing) {
            return;
        }

        $storage = app(CloudStorageService::class);
        $result = $storage->syncBatch($this->syncFailedIds, includeConfigFiles: $first);

        $this->syncMoved += $result['moved'];
        $this->syncFailedIds = [...$this->syncFailedIds, ...$result['failed']];

        unset($this->status);

        if ($result['remaining'] > 0) {
            return;
        }

        $this->syncing = false;

        $target = $storage->diskLabel();
        $moved = kPluralize('file', $this->syncMoved);

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::STORAGE_SYNC, "{$moved} to {$target}");

        $failed = \count($this->syncFailedIds);

        // A toast rather than respondError(): that throws, and the batch has already
        // done its work — the count is the news, not a failure of the request.
        $failed ?
            Flux::toast(text: "Moved {$moved} to {$target}. {$failed} could not be moved; the log has the reason.", variant: 'warning') :
            $this->respondSuccess("Moved {$moved} to {$target}.");
    }
};
?>

<div class="space-y-6">
    <x-dashboard.page-header icon="cloud" subtitle="Where uploaded files are kept: this server, or a Cloudflare R2 bucket." />

    @php($status = $this->status)

    <div class="grid gap-6 lg:grid-cols-3">
        <form wire:submit="save" class="space-y-6 lg:col-span-2">
            <flux:card class="space-y-6">
                <div>
                    <flux:heading level="2" size="lg">Cloudflare R2</flux:heading>
                    <flux:text class="mt-1">
                        Optional. Turned on, new uploads — library images, the logo and the favicon — go to your
                        R2 bucket instead of this server. Turning it on or off moves nothing by itself: files already
                        uploaded keep working where they are until you sync them.
                    </flux:text>
                </div>

                <flux:switch
                    wire:model.live="config.storage.cloud"
                    label="Store new uploads on Cloudflare R2"
                    description="Needs the keys in .env. Until they are all there, uploads stay on this server whatever this says."
                />

                @if (data_get($config, 'storage.cloud'))
                    <flux:input
                        wire:model="config.storage.public-url"
                        label="Public address"
                        placeholder="https://media.example.com"
                        description:trailing="The bucket's r2.dev address or your custom domain, which browsers load images from. Leave empty to use R2_PUBLIC_URL from .env."
                    />
                @endif
            </flux:card>

            <x-util.floating-actions position="end">
                <x-dashboard.gate.button :gate="$pageGate" :level="$gateModify" type="submit" variant="primary" icon="check">
                    Save storage
                </x-dashboard.gate.button>
            </x-util.floating-actions>
        </form>

        <div class="space-y-6">
            <flux:card class="space-y-4">
                <div>
                    <flux:heading level="2" size="lg">Status</flux:heading>
                    <flux:text class="mt-1">New uploads go to <strong>{{ $status['active'] }}</strong>.</flux:text>
                </div>

                @if ($status['missing'])
                    <flux:callout icon="key" color="amber" class="text-sm">
                        Not set in .env: {{ implode(', ', $status['missing']) }}. After changing .env, run
                        <code class="rounded bg-slate-100 px-1 dark:bg-white/10">php artisan config:clear</code> if config is cached.
                    </flux:callout>
                @elseif (! $status['publicUrl'])
                    <flux:callout icon="globe-alt" color="amber" class="text-sm">
                        The keys are in place, but there is no public address yet. Give one here or as R2_PUBLIC_URL.
                    </flux:callout>
                @else
                    <flux:callout icon="check-circle" color="emerald" class="text-sm">
                        R2 is reachable at {{ $status['publicUrl'] }}.
                    </flux:callout>
                @endif
            </flux:card>

            @if ($status['configured'])
                <flux:card class="space-y-4">
                    <div>
                        <flux:heading level="2" size="lg">Sync to {{ $status['target'] }}</flux:heading>
                        <flux:text class="mt-1">
                            @if ($syncing)
                                Moving… {{ kPluralize('file', $syncMoved) }} so far,
                                {{ number_format($status['pending']) }} to go. Keep this page open.
                            @elseif ($status['pending'])
                                {{ kPluralize('image', $status['pending']) }} still on {{ $status['source'] }}.
                                Each one is copied, checked, then removed from where it was.
                            @else
                                Every library image is already on {{ $status['target'] }}.
                                Syncing still checks the logo and favicon.
                            @endif
                        </flux:text>
                    </div>

                    <x-dashboard.gate.button
                        :gate="$pageGate"
                        :level="$gateModify"
                        type="button"
                        variant="filled"
                        icon="arrow-path"
                        x-on:click="$flux.modal('syncModal').show()"
                        :disabled="$syncing"
                    >
                        Sync now
                    </x-dashboard.gate.button>

                    @if ($syncing)
                        <div wire:poll.750ms="syncNext"></div>
                    @endif
                </flux:card>
            @endif
        </div>
    </div>

    <x-dashboard.confirm-modal
        name="syncModal"
        title="Move every file to {{ $status['target'] }}?"
        icon="arrow-path"
        tone="amber"
        variant="primary"
        confirm="Start the sync"
        confirm-icon="arrow-path"
        wire:click="startSync"
    >
        Each file is copied to {{ $status['target'] }}, checked, and only then removed from {{ $status['source'] }}.
        Keep this page open until it finishes.
    </x-dashboard.confirm-modal>
</div>
