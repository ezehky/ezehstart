<?php

namespace Database\Seeders;

use App\Enums\CategoryGroupEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusPost;
use App\Enums\StatusYes;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Categories, tags and posts — enough for the blog screens, the taxonomy pickers and
 * the public pages to have something real-shaped in them.
 *
 * Posts are spread across draft, scheduled and published, and a third of them are
 * written by the demo author, so the "an author only sees their own" path has
 * something on both sides of it.
 */
class DemoContentSeeder extends Seeder
{
    public const POSTS = 18;

    public function run(): void
    {
        $categories = $this->categories();
        $tags = $this->tags();
        $authors = $this->authors();

        for ($index = 1; $index <= self::POSTS; $index++) {
            $title = str(fake()->unique()->sentence(rand(4, 8)))->rtrim('.')->toString();

            $status = match (true) {
                $index % 7 === 0 => StatusPost::DRAFT,
                $index % 11 === 0 => StatusPost::ARCHIVED,
                default => StatusPost::PUBLISHED,
            };

            $post = Post::query()->updateOrCreate(
                ['slug' => kSlug($title)],
                [
                    'user_id' => $authors->random()->id,
                    'title' => $title,
                    'excerpt' => fake()->sentence(18),
                    'content' => $this->body(),
                    'read_minutes' => rand(2, 9),
                    'views' => rand(0, 4000),
                    'status' => $status,
                    'is_featured' => $index <= 3 ? StatusYes::YES : StatusYes::NO,
                    // One post is deliberately scheduled: published, but not yet due,
                    // which is the state the public feed has to hold back.
                    'published_at' => $status->isPublished()
                        ? ($index === 5 ? now()->addWeek() : now()->subDays(rand(1, 240)))
                        : null,
                    'created_at' => now()->subDays(rand(1, 280)),
                ]
            );

            $post->categories()->sync(
                $categories->random(rand(1, 2))->pluck('id')->all()
            );

            $post->tags()->sync(
                $tags->random(rand(1, 4))->pluck('id')->all()
            );
        }
    }

    /**
     * Two vocabularies, because categories are polymorphic and a screen that only ever
     * shows one group hides that.
     *
     * @return Collection<int, Category>
     */
    private function categories()
    {
        $blog = ['Announcements', 'Guides', 'Case studies', 'Product notes', 'Behind the scenes'];
        $product = ['Everyday', 'Premium', 'Clearance'];

        $made = collect();

        foreach ([CategoryGroupEnum::BLOG->value => $blog, CategoryGroupEnum::PRODUCT->value => $product] as $group => $names) {
            foreach ($names as $order => $name) {
                $category = Category::query()->updateOrCreate(
                    ['category_group' => $group, 'slug' => kSlug($name)],
                    [
                        'name' => $name,
                        'description' => fake()->sentence(12),
                        'flow_order' => $order,
                        'status' => StatusDefault::ACTIVE,
                    ]
                );

                if ($group === CategoryGroupEnum::BLOG->value) {
                    $made->push($category);
                }
            }
        }

        return $made;
    }

    /**
     * @return Collection<int, Tag>
     */
    private function tags()
    {
        return collect([
            'launch', 'how-to', 'interview', 'roadmap', 'security',
            'performance', 'design', 'community', 'release', 'tips',
        ])->map(fn (string $name) => Tag::query()->updateOrCreate(
            ['slug' => kSlug($name)],
            ['name' => $name, 'status' => StatusDefault::ACTIVE]
        ));
    }

    /**
     * Whoever can hold a byline: the demo author first, and any other administrator
     * to spread the rest across.
     *
     * @return Collection<int, User>
     */
    private function authors()
    {
        $author = User::query()->where('email', 'author@example.test')->first();

        $others = User::query()->admins()->whereKeyNot($author?->id)->get();

        return collect([$author])->filter()->merge($others)->whenEmpty(
            fn () => User::query()->admins()->get()
        );
    }

    /**
     * A body with the shapes the editor actually produces — headings, a list and a
     * quote — so the rich-text styles have something to style.
     */
    private function body(): string
    {
        $paragraphs = collect(range(1, 4))
            ->map(fn () => '<p>'.fake()->paragraph(6).'</p>')
            ->implode("\n");

        return <<<HTML
        <h2>{$this->heading()}</h2>
        {$paragraphs}
        <ul><li>{$this->heading()}</li><li>{$this->heading()}</li><li>{$this->heading()}</li></ul>
        <blockquote><p>{$this->heading()}</p></blockquote>
        HTML;
    }

    private function heading(): string
    {
        return str(fake()->sentence(rand(3, 7)))->rtrim('.')->toString();
    }
}
