<?php

namespace App\Services;

use App\Contracts\DynamicContentProvider;
use App\Enums\CategoryGroupEnum;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Post as a dynamic content source for the email builder. Everything here is a thin
 * read layer over BlogService::publishedQuery()/relatedTo() — never a duplicate
 * publishing rule — so a post that is a draft or scheduled is never eligible here
 * either.
 */
#[Singleton]
class PostDynamicContentProvider implements DynamicContentProvider
{
    public function key(): string
    {
        return 'post';
    }

    public function label(): string
    {
        return 'Post';
    }

    public function latest(int $limit): Collection
    {
        return app(BlogService::class)->publishedQuery()->limit($limit)->get();
    }

    public function byCategory(int $categoryId, int $limit): Collection
    {
        $category = Category::query()->find($categoryId);

        if (! $category) {
            return collect();
        }

        return app(BlogService::class)->publishedQuery(category: $category)->limit($limit)->get();
    }

    public function byTag(int $tagId, int $limit): Collection
    {
        $tag = Tag::query()->find($tagId);

        if (! $tag) {
            return collect();
        }

        return app(BlogService::class)->publishedQuery(tag: $tag)->limit($limit)->get();
    }

    public function find(int $id): ?Model
    {
        /** @var ?Post $post */
        $post = Post::query()->live()->find($id);

        return $post;
    }

    public function categories(): array
    {
        return Category::query()
            ->active()
            ->where('category_group', CategoryGroupEnum::BLOG)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function tags(): array
    {
        return Tag::query()->active()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function toCard(Model $item): array
    {
        /** @var Post $item */
        return [
            'id' => $item->id,
            'title' => $item->title,
            'excerpt' => (string) Str::limit(strip_tags((string) $item->excerpt), 160),
            'image_url' => $item->image?->url(),
            'url' => route('blog.show', $item->slug),
            'date' => $item->published_at?->format('M j, Y'),
            'category' => $item->categories->first()?->name,
        ];
    }

    public function related(Model $item, int $limit): Collection
    {
        /** @var Post $item */
        return app(BlogService::class)->relatedTo($item, $limit);
    }
}
