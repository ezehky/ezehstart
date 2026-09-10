<?php

use App\Traits\WithFormResponseMessage;
use App\Traits\WithImageLibrary;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The image library as a full page.
 *
 * Everything it does lives in WithImageLibrary and the lv._library partial, which
 * the picker uses too — the two screens were allowed to drift apart once and are
 * deliberately not able to again. All this file adds is the page heading.
 */
new class extends Component
{
    use WithFormResponseMessage, WithImageLibrary;

    public function mount(): void
    {
        kSetSiteTitle('content', 'image-library');
        kPageGate('content.image-library');
    }

    /**
     * @param  array<int, int>  $ids
     */
    #[On('imagesUploaded')]
    public function whenUploaded(array $ids): void
    {
        $this->afterUpload($ids);
    }
};
?>

<div class="space-y-6">
    <div>
        <flux:heading level="1" size="xl">Image library</flux:heading>
        <flux:text class="mt-1">Upload once, reuse anywhere.</flux:text>
    </div>

    <flux:card>
        @include('components.lv._library')
    </flux:card>
</div>
