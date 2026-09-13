<?php

use App\Models\User;
use App\Traits\WithFormResponseMessage;
use App\Traits\WithImageLibrary;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The image library as a full page.
 *
 * Everything it does lives in WithImageLibrary and the library._library partial, which
 * the picker uses too — the two screens were allowed to drift apart once and are
 * deliberately not able to again. All this file adds is the page heading.
 */
new class extends Component
{
    use WithFormResponseMessage, WithImageLibrary;

    /**
     * @param  ?User  $user  Whose library to show. Only an administrator may
     *                       name one, and only through the per-member route.
     *                       Every other entry point leaves it null and gets
     *                       the ordinary visibility rules.
     */
    public function mount(?User $user = null): void
    {
        kSetSiteTitle('content', 'image-library');
        kPageGate('content.image-library');

        if ($user) {
            // Re-checked here rather than trusted to the route: this is the
            // boundary, and middleware only says which workspace they are in.
            abort_unless(auth()->user()->isAdmin(), 404);
            abort_unless($user->isUser(), 404);

            $this->ownerId = $user->id;

            kSetSiteTitle('content', 'image-library', $user->name);
        }
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
        @include('components.library._library')
    </flux:card>
</div>
