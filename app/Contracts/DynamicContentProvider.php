<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One source of dynamic content an email block can pull from — a Post today, a
 * Product or a Service later. The builder, the renderer, and the block editors only
 * ever talk to this contract, never to a concrete model, which is what lets a new
 * content type slot in as a provider class + one config line rather than a rewrite.
 *
 * Every method that returns items returns them already trimmed to what a block is
 * allowed to show — a draft post has no business surfacing in a "latest posts" block
 * because an admin happened to be looking at drafts when they built the campaign.
 */
interface DynamicContentProvider
{
    /**
     * The registry key this provider answers to, e.g. "post". Matches the key it is
     * registered under in config/email-content-types.php.
     */
    public function key(): string;

    /**
     * What the block picker calls this content type, e.g. "Post".
     */
    public function label(): string;

    /**
     * The most recent items, newest first.
     *
     * @return Collection<int, Model>
     */
    public function latest(int $limit): Collection;

    /**
     * @return Collection<int, Model>
     */
    public function byCategory(int $categoryId, int $limit): Collection;

    /**
     * @return Collection<int, Model>
     */
    public function byTag(int $tagId, int $limit): Collection;

    /**
     * One specific item, or null if it no longer exists or is no longer eligible
     * (e.g. a post that was unpublished after it was picked).
     */
    public function find(int $id): ?Model;

    /**
     * The categories this content type can be filtered by, for the "by category"
     * picker — [id => label].
     *
     * @return array<int, string>
     */
    public function categories(): array;

    /**
     * The tags this content type can be filtered by — [id => label].
     *
     * @return array<int, string>
     */
    public function tags(): array;

    /**
     * One item, reduced to what a block's card layout needs to render.
     *
     * @return array{id: int, title: string, excerpt: string, image_url: ?string, url: string, date: ?string, category: ?string}
     */
    public function toCard(Model $item): array;

    /**
     * Items related to one item — same category first, backfilled with the newest
     * otherwise. Backs the "Related Content" block.
     *
     * @return Collection<int, Model>
     */
    public function related(Model $item, int $limit): Collection;
}
