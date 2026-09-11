<?php

use App\Traits\WithFormResponseMessage;
use App\Traits\WithVideoLibrary;
use Livewire\Component;

/**
 * The video library as a full page.
 *
 * Everything it does lives in WithVideoLibrary and the library._video-library partial,
 * which the picker uses too — the two screens are deliberately not able to drift
 * apart. All this file adds is the page heading.
 */
new class extends Component
{
    use WithFormResponseMessage, WithVideoLibrary;

    public function mount(): void
    {
        kSetSiteTitle('content', 'video-library');
        kPageGate('content.video-library');
    }
};
?>

<div class="space-y-6">
    <div>
        <flux:heading level="1" size="xl">Video library</flux:heading>
        <flux:text class="mt-1">Paste a link once, embed it anywhere.</flux:text>
    </div>

    <flux:card>
        @include('components.library._video-library')
    </flux:card>
</div>
