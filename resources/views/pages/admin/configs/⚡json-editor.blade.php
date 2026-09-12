<?php

use App\Enums\GateAccessEnum;
use App\Traits\WithGateProps;
use App\Traits\WithSiteConfigProcessor;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    use WithGateProps, WithSiteConfigProcessor;

    #[Validate(['required', 'string'])]
    public string $rawConfig;

    public function mount(): void
    {
        abort_unless(request()->has('ezeh'), 404);

        kSetSiteTitle('config', 'json');

        $this->setPageGate('config.json');
        $this->setConfigInitial();

        $this->rawConfig = json_encode($this->config, JSON_PRETTY_PRINT);
    }

    public function save(): bool
    {
        $this->checkGate(GateAccessEnum::FULL);

        $this->validate();

        try {
            $this->config = json_decode($this->rawConfig, true, 512, JSON_THROW_ON_ERROR);

            $this->saveConfig(true);
        }
        // Handle JSON decode errors
        catch (JsonException $e) {
            $this->respondError('Invalid JSON: '.$e->getMessage(), true);
        }

        $this->redirectRoute('admin.config.json', ['ezeh' => 1]);

        return $this->respondSuccess('Configuration updated successfully.');
    }
};
?>
<div>
    <div class="mb-6">
        <flux:heading size="xl" level="1">Raw Json</flux:heading>
        <p>
            View and edit the raw JSON configuration for the site. Be cautious when making changes here,
            as incorrect configurations can lead to site issues.
        </p>
    </div>

    <form wire:submit="save" class="space-y-4">
        <flux:textarea
            label="Config"
            placeholder="{...}"
            rows="auto"
            wire:model="rawConfig" />
        <flux:button type="submit" variant="primary">
            Update
        </flux:button>
    </form>
</div>
