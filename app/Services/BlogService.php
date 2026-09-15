<?php

namespace App\Services;

use App\Enums\GateAccessEnum;
use App\Enums\NotificationTopicEnum;
use App\Enums\NotificationTypeEnum;
use App\Enums\StatusPost;
use App\Mail\NewPostEmail;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Traits\WithRichTextSanitizer;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Posts, and the polymorphic taxonomy they hang off.
 */
#[Singleton]
class BlogService
{
    use WithRichTextSanitizer;

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // TAXONOMY

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PUBLISHING

    /**
     * Move a post to a status, stamping published_at the first time it goes live.
     *
     * The stamp is set once and left alone afterwards, so fixing a typo three
     * months later does not shove the post back to the top of the feed.
     */
    public function applyStatus(Post $post, StatusPost $status): void
    {
        $post->status = $status;

        if ($status->isPublished() && $post->published_at === null) {
            $post->published_at = now();
        }

        // A scheduled post whose date is in the past has nothing to wait for, so
        // it is published rather than left for the sweep to pick up a tick later.
        if ($status->isScheduled() && $post->published_at !== null && $post->published_at->isPast()) {
            $post->status = StatusPost::PUBLISHED;
        }
    }

    /**
     * Tell the subscribers a post is up, once.
     *
     * The stamp is written before anything is sent and is the claim: two
     * overlapping scheduler ticks cannot both announce the same post, and a post
     * taken down and put back is not news a second time.
     *
     * Publishing does not send anything on its own. The author ticks "Email this
     * post to subscribers" alongside the status, and that tick is what this reads —
     * so the scheduled sweep sends exactly what the editor asked for, and a post
     * published quietly stays quiet.
     *
     * Returns how many accounts were reached, or null when there was nothing to
     * announce — not asked for, already announced, or not actually live yet.
     */
    public function announce(Post $post): ?int
    {
        if (! $post->send_email?->isYes()) {
            return null;
        }

        if (! $post->status->isPublished() || $post->announced_at !== null) {
            return null;
        }

        if ($post->published_at === null || $post->published_at->isFuture()) {
            return null;
        }

        // Claimed through a conditional update rather than a read-then-write: the
        // where clause is what makes two simultaneous callers resolve to one.
        $claimed = Post::query()
            ->whereKey($post->id)
            ->whereNull('announced_at')
            ->update(['announced_at' => now()]);

        if ($claimed === 0) {
            return null;
        }

        $post->refresh();

        return app(NotificationSubscriberService::class)->broadcast(
            NotificationTypeEnum::ANNOUNCEMENTS,
            NotificationTopicEnum::NEW_POST,
            $post->title,
            ['url' => route('blog.show', $post->slug)],
            fn (User $recipient) => new NewPostEmail($recipient, $post),
        );
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // AUTHORSHIP

    /**
     * Is this account held to the posts it wrote?
     *
     * An author is. Anybody else reaching the blog is not — a Media role with create
     * access is a desk that runs the whole blog, and narrowing it to its own drafts
     * would be a surprise nobody asked for. FULL is the way out: an editor-in-chief
     * holds the author role *and* full access, and answers no to this.
     */
    public function authorRestricted(User $user): bool
    {
        return $user->isAuthor() && ! kGate('content.blogs', GateAccessEnum::FULL, $user);
    }

    /**
     * Narrow a post query to the rows this account may work on.
     *
     * Applied to the listing as well as to the editor, so a restricted author never
     * sees a row they would be refused on opening. The counts above the table go
     * through it too — a metric that disagrees with the table below it reads as a bug.
     */
    public function authorScope(Builder $query, User $user): Builder
    {
        return $query->when(
            $this->authorRestricted($user),
            fn (Builder $inner) => $inner->where('user_id', $user->id),
        );
    }

    /**
     * Why this account cannot work on that post, or null when it can.
     *
     * A post with no author left — the account was deleted — is nobody's to claim, so
     * a restricted author is refused it rather than inheriting it.
     */
    public function editBlockedReason(Post $post, User $user): ?string
    {
        if (! $this->authorRestricted($user)) {
            return null;
        }

        return $post->user_id === $user->id
            ? null
            : 'You can only work on posts you wrote.';
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PUBLIC READS

    /**
     * The public feed. Everything the site shows a reader starts here.
     */
    public function publishedQuery(?Category $category = null, ?Tag $tag = null, ?string $search = null): Builder
    {
        return Post::query()
            ->live()
            ->with(['image', 'user', 'categories', 'tags'])
            ->when($category, fn (Builder $query) => $query->inCategory($category))
            ->when($tag, fn (Builder $query) => $query->taggedWith($tag))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->searchMacro(['title', 'excerpt'], $search)))
            ->newestFirst();
    }

    /**
     * Count a read.
     *
     * Deliberately not a model event and deliberately not inside a transaction:
     * an increment is a single atomic statement, it must not touch updated_at,
     * and a view counter is never worth failing a page render over.
     */
    public function recordView(Post $post): void
    {
        // toBase() drops to the query builder, which is what keeps this off
        // updated_at — the Eloquent builder's increment() stamps it, and a reader
        // opening a page is not an edit to the post.
        Post::query()->whereKey($post->id)->toBase()->increment('views');
    }

    /**
     * Posts to read next: same categories first, newest otherwise.
     *
     * @return Collection<int, Post>
     */
    public function relatedTo(Post $post, int $limit = 3): Collection
    {
        $categoryIds = $post->categories->pluck('id');

        $related = $categoryIds->isEmpty()
            ? collect()
            : $this->publishedQuery()
                ->whereKeyNot($post->id)
                ->whereHas('categories', fn (Builder $query) => $query->whereIn('categories.id', $categoryIds))
                ->limit($limit)
                ->get();

        if ($related->count() >= $limit) {
            return $related;
        }

        // Topping up rather than showing two cards where three fit.
        return $related->concat(
            $this->publishedQuery()
                ->whereKeyNot($post->id)
                ->whereKeyNot($related->pluck('id')->all() ?: [0])
                ->limit($limit - $related->count())
                ->get()
        )->take($limit);
    }
}
