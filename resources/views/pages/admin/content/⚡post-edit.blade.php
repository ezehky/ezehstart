<?php

use App\Enums\ActivityActionEnum;
use App\Enums\CategoryGroupEnum;
use App\Enums\StatusPost;
use App\Enums\StatusYes;
use App\Models\Image;
use App\Models\Post;
use App\Services\ActivityLogService;
use App\Services\BlogService;
use App\Services\ImageLibraryService;
use App\Traits\WithFormResponseMessage;
use App\Traits\WithTaxonomy;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage, WithTaxonomy;

    public ?Post $post = null;

    public string $title;

    public string $excerpt;

    public string $content;

    public ?int $image_id = null;

    public ?string $meta_title = null;

    public ?string $meta_description = null;

    public bool $is_featured = false;

    public StatusPost $status = StatusPost::DRAFT;

    public ?string $published_at = null;

    /**
     * Set while the cover slot is the thing waiting on the picker. Without it,
     * choosing an image for the body would silently replace the cover too.
     */
    public bool $choosingCover = false;

    /**
     * The post is bound by route model, so a missing one is a new post rather
     * than a 404 — the same screen writes both.
     */
    public function mount(): void
    {
        // Which vocabulary the category picker offers. WithTaxonomy leaves it unset
        // on purpose, so every screen using the trait has to say what it edits.
        $this->category_group = CategoryGroupEnum::BLOG;

        if ($this->post?->exists) {

            $this->fill($this->post->only([
                'title', 'excerpt', 'content', 'image_id',
                'meta_title', 'meta_description',
                'status',
            ]));

            $this->is_featured = $this->post->is_featured->boolValue();
            $this->published_at = $this->post->published_at?->format('Y-m-d\TH:i');

            $this->loadTaxonomy($this->post);
        }

        kSetSiteTitle('content', 'blogs', $this->post ? 'edit post' : 'new post');
    }

    #[Computed]
    public function coverImage(): ?Image
    {
        return $this->image_id ? Image::query()->find($this->image_id) : null;
    }

    /**
     * The picker announces a choice to whatever is listening. This screen uses it
     * for the cover image only — the editor takes its own from the same event on
     * the browser side.
     */
    #[On('imageSelected')]
    public function setCoverImage(int $imageId): void
    {
        // Only when the cover slot actually asked. Without the flag, choosing an
        // image for the body would silently replace the cover as well.
        if (! $this->choosingCover) {
            return;
        }

        $this->image_id = $imageId;
        $this->choosingCover = false;

        unset($this->coverImage);
    }

    public function chooseCover(): void
    {
        $this->choosingCover = true;

        $this->dispatch('open-image-picker');
    }

    public function clearCover(): void
    {
        $this->image_id = null;

        unset($this->coverImage);
    }

    protected function rules(): array
    {
        return [
            'title' => [
                'required',
                'string',
                'max:255',
                Rule::unique(Post::class, 'title')->ignore($this->post?->id),
            ],
            'excerpt' => ['required', 'string', 'max:500'],
            'content' => ['required', 'string'],
            'image_id' => ['nullable', 'integer', Rule::exists('images', 'id')],
            ...$this->taxonomyRules(),
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'is_featured' => ['boolean'],
            'status' => ['required', Rule::enum(StatusPost::class)],
            'published_at' => ['nullable', 'date'],
        ];
    }

    public function save()
    {
        $this->validate();

        $blog = app(BlogService::class);
        $activity = app(ActivityLogService::class);

        $isNew = ! $this->post;
        $wasPublished = (bool) $this->post?->status->isPublished();

        $this->post ??= Post::make(['user_id' => auth()->id()]);

        $this->post->fill([
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            // Editor HTML is untrusted however trusted the author is.
            'content' => $blog->sanitize($this->content),
            'image_id' => $this->image_id,
            'meta_title' => $this->meta_title ?: null,
            'meta_description' => $this->meta_description ?: null,
            'is_featured' => StatusYes::from((int) $this->is_featured),
        ]);

        $this->post->read_minutes = Post::estimateReadMinutes($this->post->content);

        $blog->applyStatus($this->post, $this->status);

        // An explicit date wins over the automatic stamp, which is what makes
        // scheduling and back-dating possible.
        if ($this->published_at) {
            $this->post->published_at = $this->published_at;
        }

        $affected = $activity->affectedColumns($this->post);

        if ($this->post->isDirty('title')) {
            $this->post->slug = kSlug($this->title);
        }

        $this->post->save();

        // Taxonomy after the save, because a new post has no id before it.
        $this->syncTaxonomy($this->post);

        // The cover's usage row is what stops somebody deleting an image that is
        // on a published post.
        app(ImageLibraryService::class)->syncSingle($this->coverImage, $this->post, 'cover');

        $action = match (true) {
            $isNew => ActivityActionEnum::POST_CREATE,
            $this->status->isPublished() && ! $wasPublished => ActivityActionEnum::POST_PUBLISH,
            default => ActivityActionEnum::POST_UPDATE,
        };

        $activity->logActivity($action, " post: {$this->post->title}", $affected, model: $this->post);

        $this->respondSuccess('The post has been saved.');

        return $this->redirectRoute('admin.blog.blogs', navigate: true);
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading level="1" size="xl">{{ $post ? 'Edit post' : 'New post' }}</flux:heading>
            <flux:text class="mt-1">Write it, tag it, publish when it is ready.</flux:text>
        </div>
        <flux:button href="{{ route('admin.blog.blogs') }}" variant="ghost" icon="arrow-left">Back to blogs</flux:button>
    </div>

    <form wire:submit="save" class="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <div class="space-y-6">
            <flux:card class="space-y-4">
                <flux:input wire:model.live.blur="title" label="Title" placeholder="What is this post about?" />
                <flux:textarea
                    wire:model="excerpt"
                    label="Excerpt"
                    rows="2"
                    description="Shown on cards and in search results."
                    placeholder="Type..."
                />
            </flux:card>

            <flux:card>
                <x-form.rich-text wire:model="content" label="Body" name="content" placeholder="Start writing…" />
            </flux:card>

            <flux:card class="space-y-4">
                <flux:heading level="2" size="sm">Search engine listing</flux:heading>
                <flux:input wire:model="meta_title" label="Meta title" description="Falls back to the post title." />
                <flux:textarea wire:model="meta_description" label="Meta description" rows="2" description="Falls back to the excerpt." />
            </flux:card>
        </div>

        <div class="space-y-6">

            <flux:card class="space-y-3">
                <flux:heading level="2" size="sm">Cover image</flux:heading>

                @if ($this->coverImage)
                    <img
                        src="{{ $this->coverImage->url() }}"
                        alt="{{ $this->coverImage->title }}"
                        class="aspect-video w-full rounded-lg object-cover"
                    />
                    <div class="flex gap-2">
                        <flux:button size="sm" wire:click="chooseCover" type="button">Replace</flux:button>
                        <flux:button size="sm" variant="ghost" wire:click="clearCover" type="button">Remove</flux:button>
                    </div>
                @else
                    <flux:button size="sm" icon="photo" wire:click="chooseCover" type="button" class="w-full">
                        Choose from library
                    </flux:button>
                @endif
                <flux:error name="image_id" />
            </flux:card>

            <x-form.category-field
                wire:model="category_ids"
                :categories="$this->categoryOptions"
                :empty-href="route('admin.categories', ['category_group' => $category_group])"
            />

            <x-form.tag-field wire:model="tag_names" :tags="$this->tagOptions" />

            <flux:card class="space-y-4">
                <flux:heading level="2" size="sm">Publishing</flux:heading>

                <flux:select wire:model="status" label="Status">
                    @foreach (StatusPost::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input
                    type="datetime-local"
                    wire:model="published_at"
                    label="Publish at"
                    description="Leave empty to publish the moment you set the status to published."
                />

                <flux:switch wire:model="is_featured" label="Feature this post" />

                <flux:button type="submit" variant="primary" icon="check" class="w-full">Save post</flux:button>
            </flux:card>
        </div>
    </form>

    <livewire:lv.image-picker />
</div>
