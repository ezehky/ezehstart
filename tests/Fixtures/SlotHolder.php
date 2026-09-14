<?php

namespace Tests\Fixtures;

use App\Models\Post;
use App\Traits\WithFormResponseMessage;
use App\Traits\WithImagePicker;
use Livewire\Component;

/**
 * A stand-in for any screen that holds images: one slot backed by a column, one
 * that takes several and lives only in image_usages. The real screens hold one
 * or two, so this is where the combinations WithImagePicker promises are put
 * through their paces.
 */
class SlotHolder extends Component
{
    use WithFormResponseMessage;
    use WithImagePicker;

    public ?int $image_id = null;

    public ?int $record_id = null;

    protected function imageSlots(): array
    {
        return [
            'cover' => ['multiple' => false, 'property' => 'image_id'],
            'gallery' => ['multiple' => true, 'max' => 3],
        ];
    }

    public function loadFrom(): void
    {
        $this->loadImageSlots(Post::query()->findOrFail($this->record_id));
    }

    public function persist(): void
    {
        $this->syncImageSlots(Post::query()->findOrFail($this->record_id));
    }

    public function render()
    {
        return '<div></div>';
    }
}
