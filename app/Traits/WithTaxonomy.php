<?php

namespace App\Traits;

use App\Enums\CategoryGroupEnum;
use App\Models\Category;
use App\Models\Tag;
use App\Services\CategoryService;
use App\Services\TagService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * The category and tag pickers, for any screen that edits a record carrying them.
 *
 * Posts use it today. Anything else that morphs the same taxonomy — products,
 * pages — gets both pickers by using this trait and naming its own group, because
 * nothing here knows what a post is.
 *
 * @property-read Collection<int, Category> $categoryOptions
 * @property-read Collection<int, string> $tagOptions
 */
trait WithTaxonomy
{
    /**
     * Which vocabulary the category picker draws from. Set it in the page's
     * mount(): it is deliberately left uninitialised, so a screen that forgets
     * fails loudly instead of quietly offering blog categories to a product.
     */
    public CategoryGroupEnum $category_group;

    /**
     * @var array<int, int>
     */
    public array $category_ids = [];

    /**
     * Tags are held by name rather than id: a chip the writer has just typed has
     * no row yet, and TagService resolves the whole list the same way on save.
     *
     * @var array<int, string>
     */
    public array $tag_names = [];

    public string $new_tag = '';

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categoryOptions(): Collection
    {
        return app(CategoryService::class)->categoriesFor($this->category_group);
    }

    /**
     * Every chip the tag picker shows: the tags already in the library, plus any
     * typed on this screen and not saved yet.
     *
     * The ones already on the record come first, because the picker collapses a
     * long list behind a "show more" — anything ticked has to sit above that fold,
     * and a tag just typed has to be visible where it was added.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function tagOptions(): Collection
    {
        return collect($this->tag_names)
            ->merge(Tag::query()->active()->alphabetical()->pluck('name'))
            ->unique(fn (string $name) => kSlug($name))
            ->values();
    }

    /**
     * Stage a typed tag as a ticked chip.
     *
     * Nothing is written until the record itself is saved, so a form that is
     * opened, played with and abandoned leaves no orphan tags behind.
     */
    public function addTag(): void
    {
        $name = trim($this->new_tag);

        if ($name === '') {
            return;
        }

        // Matched on the slug, the same way TagService resolves them on save, so
        // typing one that already exists ticks that chip instead of adding a twin.
        $existing = $this->tagOptions->first(fn (string $option) => kSlug($option) === kSlug($name));

        $this->tag_names = collect($this->tag_names)
            ->push($existing ?? $name)
            ->unique(fn (string $tag) => kSlug($tag))
            ->values()
            ->all();

        $this->new_tag = '';

        unset($this->tagOptions);
    }

    /**
     * Fill both pickers from the record being edited.
     */
    protected function loadTaxonomy(Model $record): void
    {
        $this->category_ids = $record->categories->pluck('id')->all();
        $this->tag_names = $record->tags->pluck('name')->all();
    }

    /**
     * Write both pickers back. Runs after the record's own save(), because a new
     * one has no id to attach anything to before that.
     */
    protected function syncTaxonomy(Model $record): void
    {
        $record->categories()->sync($this->category_ids);
        $record->tags()->sync(app(TagService::class)->resolveTags($this->tag_names)->pluck('id'));
    }

    /**
     * Spread into the host page's own rules().
     *
     * The group is part of the category rule rather than trusted from the form:
     * the ids arrive from the browser, and nothing else stops a product category
     * being posted to a blog screen.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function taxonomyRules(): array
    {
        return [
            'category_ids' => ['array'],
            'category_ids.*' => [
                'integer',
                Rule::exists('categories', 'id')->where('category_group', $this->category_group->value),
            ],
            'tag_names' => ['array'],
            'tag_names.*' => ['string', 'max:255'],
            'new_tag' => ['nullable', 'string', 'max:255'],
        ];
    }
}
