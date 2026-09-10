<?php

use App\Enums\ActivityActionEnum;
use App\Enums\CategoryGroupEnum;
use App\Enums\StatusPost;
use App\Enums\StatusYes;
use App\Models\Post;
use App\Services\ActivityLogService;
use App\Services\BlogService;
use App\Services\VideoLibraryService;
use App\Traits\WithFormResponseMessage;
use App\Traits\WithImagePicker;
use App\Traits\WithTaxonomy;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage, WithImagePicker, WithTaxonomy;

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
     * The cover is the only image a post holds directly. It names image_id, so the
     * column the posts table already carries stays the record of it and the
     * usage row is what stops the file being deleted out from under a live post.
     *
     * A second slot is a line here — 'gallery' => ['multiple' => true, 'max' => 6]
     * needs no column and no migration, because a slot with no property lives in
     * image_usages.
     */
    protected function imageSlots(): array
    {
        return [
            'cover' => ['multiple' => false, 'property' => 'image_id'],
        ];
    }

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
            $this->loadImageSlots($this->post);
        }

        kSetSiteTitle('content', 'blogs', $this->post ? 'edit post' : 'new post');
        kPageGate('content.blogs');
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
            ...$this->imagePickerRules(),
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

        // The usage rows are what stop somebody deleting an image that is on a
        // published post. After the save, for the same reason as the taxonomy.
        $this->syncImageSlots($this->post);

        // Videos embedded in the body are claimed the same way, but read back out
        // of the saved HTML rather than from a slot: the editor drops them in
        // freely, so the finished content is the only thing that actually knows
        // what the post ended up embedding.
        app(VideoLibraryService::class)->syncFromHtml($this->post->content, $this->post, 'body');

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

            <x-form.image-slot
                name="cover"
                label="Cover image"
                :images="$this->slotImages('cover')"
                error="image_id"
            />

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

    {{-- The editor's video button opens this. It names no slot, so it answers with
         a browser event carrying a player URL rather than with ids. --}}
    <livewire:lv.video-picker />
</div>
