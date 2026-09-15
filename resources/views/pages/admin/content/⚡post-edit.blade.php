<?php

use App\Enums\ActivityActionEnum;
use App\Enums\CategoryGroupEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusPost;
use App\Enums\StatusYes;
use App\Models\Post;
use App\Services\ActivityLogService;
use App\Services\BlogService;
use App\Services\VideoLibraryService;
use App\Traits\WithGateProps;
use App\Traits\WithImagePicker;
use App\Traits\WithTaxonomy;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    use WithGateProps, WithImagePicker, WithTaxonomy;

    public ?Post $post = null;

    public string $title;

    public string $excerpt;

    public string $content;

    public ?int $image_id = null;

    public ?string $meta_title = null;

    public ?string $meta_description = null;

    public bool $is_featured = false;

    /**
     * Whether saving this post should email the subscribers. Publishing on its own
     * no longer does — see BlogService::announce(). Only ever offered while the
     * status actually reaches the public, and only while the post has not been
     * announced already, because there is no un-sending it.
     */
    public bool $send_email = false;

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
            $this->send_email = $this->post->send_email?->boolValue() ?? false;
            $this->published_at = $this->post->published_at?->format('Y-m-d\TH:i');

            $this->loadTaxonomy($this->post);
            $this->loadImageSlots($this->post);
        }

        kSetSiteTitle('content', 'blogs', $this->post ? 'edit post' : 'new post');

        // Opening the editor at all is a write, so it asks for the level the save
        // will need rather than for VIEW: a reader who cannot save has no business
        // filling this form in and being refused at the end of it.
        $this->setPageGate('content.blogs', $this->requiredAccess());

        // An author reaches their own drafts and nothing else. A 404 rather than a
        // message, for the same reason the page gate returns one — which posts exist
        // is not something a refused account gets to map.
        abort_if(
            $this->post?->exists
                && app(BlogService::class)->editBlockedReason($this->post, auth()->user()) !== null,
            404,
        );
    }

    /**
     * Editing an existing post is MODIFY; starting one is CREATE. The page gate and
     * the save ask the same question, so it is answered in one place.
     */
    protected function requiredAccess(): GateAccessEnum
    {
        return $this->post?->exists ? GateAccessEnum::MODIFY : GateAccessEnum::CREATE;
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
            'send_email' => ['boolean'],
            'status' => ['required', Rule::enum(StatusPost::class)],
            'published_at' => ['nullable', 'date'],
        ];
    }

    public function save()
    {
        $blog = app(BlogService::class);

        // The page gate already refused anybody who cannot be here, which stops
        // nobody who can open a console and post at this component directly.
        $this->checkGate($this->requiredAccess(), 'You do not have access to save posts.');

        if ($this->post?->exists) {
            $reason = $blog->editBlockedReason($this->post, auth()->user());
            $this->respondError($reason ?? '', if: $reason !== null);
        }

        $this->validate();

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
            // A draft carries the tick forward untouched, so scheduling a post
            // now and letting the sweep publish it still sends what was asked for.
            'send_email' => StatusYes::from((int) $this->send_email),
        ]);

        $this->post->read_minutes = Post::estimateReadMinutes($this->post->content);

        // An explicit date wins over the automatic stamp, which is what makes
        // scheduling and back-dating possible. Applied before the status, because
        // applyStatus() reads the date to decide whether a scheduled post is
        // still waiting or is already due.
        if ($this->published_at) {
            $this->post->published_at = $this->published_at;
        }

        $blog->applyStatus($this->post, $this->status);

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

        // Publishing by hand tells the subscribers, exactly as the scheduler
        // does — and only when the author asked for it. announce() is the one that
        // decides whether there is anything to say, so re-saving a live post does
        // not send it twice.
        $reached = $blog->announce($this->post);

        $this->respondSuccess($reached
            ? "The post has been saved and {$reached} subscriber(s) notified."
            : 'The post has been saved.');

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

                <flux:select wire:model.live="status" label="Status">
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

                {{-- Sending is opt-in, and only offered where it can actually
                     happen: a draft has no audience, and an announced post has
                     already gone out. --}}
                @if ($status->isPublished() || $status->isScheduled())
                    @if ($post?->announced_at)
                        <flux:callout icon="check-circle" color="lime" class="text-xs">
                            Emailed to subscribers on {{ $post->announced_at->format('M d, Y 	 H:i') }}.
                        </flux:callout>
                    @else
                        <flux:switch
                            wire:model="send_email"
                            label="Email this post to subscribers"
                            description="Sent once, when the post goes live. Leave off to publish quietly."
                        />
                    @endif
                @endif

                <flux:button type="submit" variant="primary" icon="check" class="w-full">Save post</flux:button>
            </flux:card>
        </div>
    </form>

    <livewire:livewire.library.image-picker />

    {{-- The editor's video button opens this. It names no slot, so it answers with
         a browser event carrying a player URL rather than with ids. --}}
    <livewire:livewire.library.video-picker />
</div>
