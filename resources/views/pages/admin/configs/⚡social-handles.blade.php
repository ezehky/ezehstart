<?php

use App\Enums\SocialHandleEnum;
use App\Services\SiteConfigurationService;
use App\Traits\WithFormResponseMessage;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage;

    public array $socialHandles = [];

    public array $socialHandle = [
        'platform' => '',
        'url' => '',
    ];

    public ?int $editingIndex = null;

    public function mount(): void
    {
        kSetSiteTitle('config', 'social-handles');

        $this->socialHandles = app(SiteConfigurationService::class)
            ->getConfigs('social-handles', default: [], raw: true);
    }

    protected function rules(): array
    {
        return [
            'socialHandle.platform' => ['required', Rule::enum(SocialHandleEnum::class)],
            'socialHandle.url' => ['required', 'url', 'max:500'],
        ];
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->reset('editingIndex');
        $this->socialHandle = [
            'platform' => '',
            'url' => '',
        ];

        Flux::modal('socialHandleModal')->show();
    }

    public function edit(int $index): void
    {
        $this->resetValidation();
        $this->editingIndex = $index;
        $this->socialHandle = $this->socialHandles[$index];

        Flux::modal('socialHandleModal')->show();
    }

    public function save(): bool
    {
        $this->validate();

        $this->respondPrimary(
            if: collect($this->socialHandles)
                ->except($this->editingIndex === null ? [] : [$this->editingIndex])
                ->contains('platform', $this->socialHandle['platform']),
            message: 'This social platform has already been added.'
        );

        if ($this->editingIndex === null) {
            $this->socialHandles[] = $this->socialHandle;
        } else {
            $this->socialHandles[$this->editingIndex] = $this->socialHandle;
        }

        $this->persistSocialHandles();

        Flux::modal('socialHandleModal')->close();

        return $this->respondSuccess(
            $this->editingIndex === null ? 'Social handle added.' : 'Social handle updated.'
        );
    }

    public function delete(int $index): bool
    {
        unset($this->socialHandles[$index]);
        $this->socialHandles = array_values($this->socialHandles);
        $this->persistSocialHandles();

        return $this->respondSuccess('Social handle deleted.');
    }

    private function persistSocialHandles(): void
    {
        $service = app(SiteConfigurationService::class);
        $config = $service->getConfigs(raw: true);
        $config['social-handles'] = $this->socialHandles;

        $service->update($config);
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading level="2" size="lg">Social handles</flux:heading>
                <flux:text class="mt-1">Manage the social links displayed across your site.</flux:text>
            </div>

            <flux:button variant="primary" icon="plus" wire:click="create">
                Add social handle
            </flux:button>
        </div>

        <flux:table class="w-full text-left text-sm">
            <flux:table.columns>
                <flux:table.column>Platform</flux:table.column>
                <flux:table.column>URL</flux:table.column>
                <flux:table.column class="w-24 text-right">Actions</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($socialHandles as $index => $handle)
                    @php($platform = SocialHandleEnum::tryFrom($handle['platform'] ?? ''))

                    <flux:table.row wire:key="social-handle-{{ $index }}">
                        <flux:table.cell>{{ $platform?->label() ?? $handle['platform'] }}</flux:table.cell>
                        <flux:table.cell>
                            <a href="{{ $handle['url'] }}" target="_blank" rel="noopener noreferrer" class="text-accent hover:underline">
                                {{ $handle['url'] }}
                            </a>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end gap-1">
                                <flux:tooltip content="Edit social handle">
                                    <flux:button variant="ghost" size="sm" icon="pencil-square" wire:click="edit({{ $index }})" />
                                </flux:tooltip>

                                <flux:tooltip content="Delete social handle">
                                    <flux:button variant="ghost" size="sm" icon="trash" wire:click="delete({{ $index }})" wire:confirm="Delete this social handle?" />
                                </flux:tooltip>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="3" class="py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                            No social handles have been added yet.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="socialHandleModal" class="md:w-120">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingIndex === null ? 'Add social handle' : 'Edit social handle' }}</flux:heading>
                <flux:text class="mt-1">Choose the platform and enter its public link.</flux:text>
            </div>

            <flux:field>
                <flux:label>Platform</flux:label>
                <flux:select wire:model="socialHandle.platform" placeholder="Select a platform">
                    @foreach (SocialHandleEnum::cases() as $platform)
                        <flux:select.option value="{{ $platform->value }}">{{ $platform->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="socialHandle.platform" />
            </flux:field>

            <flux:field>
                <flux:label>URL</flux:label>
                <flux:input type="url" wire:model="socialHandle.url" placeholder="https://example.com/profile" />
                <flux:error name="socialHandle.url" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                    Save handle
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
