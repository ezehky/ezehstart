<?php

namespace App\Services;

use App\Enums\PolicyTypeEnum;
use App\Models\Policy;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

/**
 * Resolves which version of a policy is in force, and compiles its markdown into
 * the linkable sections the legal page renders.
 *
 * Each level-2 heading starts a new section and gets its own anchor. A heading may
 * carry an explicit one as `## 5. Refunds {#refunds}`, which is what keeps a deep
 * link such as /terms#refunds working after somebody rewords the heading — and
 * legal pages are linked to from contracts and emails that nobody can go back and
 * edit.
 */
#[Singleton]
class PolicyContentService
{
    // Getters

    /**
     * The version of a policy currently in force, if any.
     */
    public function getCurrent(PolicyTypeEnum $type): ?Policy
    {
        return Policy::query()
            ->where('policy_type', $type)
            ->live()
            ->latest('effective_at')
            ->latest('id')
            ->first();
    }

    /**
     * Every policy currently in force that a user has to accept.
     *
     * @return Collection<int, Policy>
     */
    public function getCurrentRequiringConsent(): Collection
    {
        return collect(PolicyTypeEnum::cases())
            ->map(fn (PolicyTypeEnum $type) => $this->getCurrent($type))
            ->filter(fn (?Policy $policy) => (bool) $policy?->requiresConsent())
            ->values();
    }

    /**
     * The version number to offer when drafting the next version of a policy.
     *
     * Whole numbers only. A point release invites the idea that a small change
     * needs no fresh consent, and that is not a call this layer should make.
     */
    public function getNextVersion(PolicyTypeEnum $type): string
    {
        $latest = Policy::query()
            ->where('policy_type', $type)
            ->orderByDesc('id')
            ->value('version');

        return $latest
            ? (string) (((int) floor((float) $latest)) + 1).'.0'
            : '1.0';
    }

    // Tools

    /**
     * Compile markdown into sections.
     *
     * A section with a null title is content that appeared before the first
     * heading — it renders, but it is left out of the contents sidebar, where an
     * untitled entry would be a link with nothing to say.
     *
     * @return array<int, array{id: string, title: ?string, html: string}>
     */
    public function sections(?string $markdown): array
    {
        $html = app(MarkdownService::class)->toHtml($markdown);

        if ($html === '') {
            return [];
        }

        // The capturing group is what makes this work: the split yields preamble,
        // heading, body, heading, body… rather than dropping the headings.
        $parts = preg_split('/<h2>(.*?)<\/h2>/s', $html, flags: PREG_SPLIT_DELIM_CAPTURE);

        $sections = [];
        $preamble = trim((string) array_shift($parts));

        if ($preamble !== '') {
            $sections[] = ['id' => 'introduction', 'title' => null, 'html' => $preamble];
        }

        foreach (array_chunk($parts, 2) as $index => [$heading, $body]) {
            [$title, $anchor] = $this->splitAnchor(trim($heading));

            $sections[] = [
                'id' => $anchor ?: $this->slug($title, $index),
                'title' => $title,
                'html' => trim((string) $body),
            ];
        }

        return $this->deduplicateIds($sections);
    }

    /**
     * Split a trailing `{#anchor}` off a heading.
     *
     * @return array{0: string, 1: ?string} The heading text, and the explicit anchor.
     */
    protected function splitAnchor(string $heading): array
    {
        if (preg_match('/^(.*?)\s*\{#([\w-]+)\}$/s', $heading, $matches)) {
            return [trim($matches[1]), $matches[2]];
        }

        return [$heading, null];
    }

    /**
     * Derive an anchor from a heading, falling back to its position when the
     * heading slugs to nothing — a heading of only digits or punctuation would
     * otherwise produce an empty anchor and break every link on the page.
     */
    protected function slug(string $title, int $index): string
    {
        // Leading numbering is dropped, so "3. What we collect" gives
        // "what-we-collect" and renumbering the sections does not move the anchors.
        $slug = kSlug((string) preg_replace('/^\s*\d+[.)]\s*/', '', strip_tags($title)));

        return $slug !== '' ? $slug : 'section-'.($index + 1);
    }

    /**
     * Ensure every section has a unique id. Two sections sharing an anchor would
     * break the contents sidebar and send every deep link to the first of them.
     *
     * @param  array<int, array{id: string, title: ?string, html: string}>  $sections
     * @return array<int, array{id: string, title: ?string, html: string}>
     */
    protected function deduplicateIds(array $sections): array
    {
        $seen = [];

        foreach ($sections as $index => $section) {
            $id = $section['id'];

            if (isset($seen[$id])) {
                $id = $id.'-'.(++$seen[$section['id']]);
            }

            $seen[$section['id']] ??= 1;
            $sections[$index]['id'] = $id;
        }

        return $sections;
    }
}
