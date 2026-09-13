<?php

use App\Models\User;
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

    /**
     * @param  ?User  $user  Whose library to show. Only an administrator may
     *                       name one, and only through the per-member route.
     *                       Every other entry point leaves it null and gets
     *                       the ordinary visibility rules.
     */
    public function mount(?User $user = null): void
    {
        kSetSiteTitle('content', 'video-library');
        kPageGate('content.video-library');

        if ($user) {
            // Re-checked here rather than trusted to the route: this is the
            // boundary, and middleware only says which workspace they are in.
            abort_unless(auth()->user()->isAdmin(), 404);
            abort_unless($user->isUser(), 404);

            $this->ownerId = $user->id;

            kSetSiteTitle('content', 'video-library', $user->name);
        }
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
