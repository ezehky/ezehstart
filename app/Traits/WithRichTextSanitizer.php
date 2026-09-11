<?php

namespace App\Traits;

use App\Enums\VideoProviderEnum;

/**
 * Clean tiptap HTML before it is stored.
 *
 * Every screen that puts <x-form.rich-text> in front of an author produces the same
 * untrusted HTML and needs the same pass over it, so the pass lives here rather than
 * on the one service that happened to need it first. A host that allows more or less
 * than the default overrides a hook — `allowedTags()`, `minImageWidth()`,
 * `maxImageWidth()` — instead of reimplementing `sanitize()`.
 *
 * HTML from a form is untrusted however trusted the person typing it is: an author
 * account is exactly what an attacker would go for to get a script onto every
 * reader's page.
 */
trait WithRichTextSanitizer
{
    /**
     * The tags rich-text content may contain.
     *
     * Override on the host to widen or narrow it. Anything added here is a tag whose
     * attributes strip_tags will hand through untouched, so a tag that can carry a
     * URL or a handler needs a normaliser below as well as a place on this list.
     */
    protected function allowedTags(): string
    {
        return '<p><br><strong><b><em><i><u><s><sub><sup><a><ul><ol><li><h2><h3><h4>'
            .'<blockquote><code><pre><img><hr><figure><figcaption>'
            .'<table><colgroup><col><thead><tbody><tr><th><td><iframe>';
    }

    /**
     * The range a stored image width is allowed to fall in.
     *
     * The editor already clamps a drag to the column it is dragged in, but the width
     * arrives as an attribute on submitted HTML like any other, so it is clamped
     * again rather than trusted. Anything outside the range, or not a number at all,
     * loses the attribute and renders at its natural size.
     */
    protected function minImageWidth(): int
    {
        return 80;
    }

    protected function maxImageWidth(): int
    {
        return 2000;
    }

    /**
     * Clean editor HTML before it is stored.
     *
     * strip_tags handles the elements; the regex pass removes the two attribute
     * families that carry script — inline handlers and javascript: URLs — which
     * strip_tags leaves alone on tags it keeps.
     */
    public function sanitize(?string $html): string
    {
        $clean = strip_tags((string) $html, $this->allowedTags());

        // on* handlers, quoted or not.
        $clean = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';

        // javascript:, vbscript: and data: URLs in href/src.
        $clean = preg_replace(
            '/\s(href|src)\s*=\s*("|\')?\s*(javascript|vbscript|data):[^"\'>\s]*("|\')?/i',
            ' $1="#"',
            $clean
        ) ?? '';

        $clean = $this->normalizeLinks($clean);

        $clean = $this->normalizeImages($clean);

        // Last, so nothing above can rewrite what it produces.
        $clean = $this->rebuildEmbeds($clean);

        return trim($clean);
    }

    /**
     * Put `rel="noopener"` on every anchor that opens a new tab.
     *
     * The editor writes the pair together, but `target` arrives as an attribute on
     * submitted HTML like any other, and strip_tags hands attributes through
     * untouched on a tag it keeps — so a hand-written `target="_blank"` would
     * otherwise reach the page on its own. A tab opened that way can reach back
     * through `window.opener` and navigate the one that opened it.
     *
     * Only an anchor actually carrying the target is touched, and an existing rel
     * is extended rather than replaced, so an author's `nofollow` survives.
     */
    protected function normalizeLinks(string $html): string
    {
        return preg_replace_callback(
            '/<a\b[^>]*>/i',
            function (array $match): string {
                if (! preg_match('/\starget\s*=\s*("_blank"|\'_blank\'|_blank)/i', $match[0])) {
                    return $match[0];
                }

                preg_match('/\srel\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $match[0], $rel);

                $values = array_values(array_filter(
                    preg_split('/\s+/', trim($rel[2] ?? $rel[3] ?? $rel[4] ?? '')) ?: []
                ));

                if (in_array('noopener', array_map('strtolower', $values), true)) {
                    return $match[0];
                }

                $values[] = 'noopener';

                // Rebuilt without the attribute rather than patched in place, so the
                // line below is adding one back to a tag that has none.
                $tag = rtrim(rtrim(
                    preg_replace('/\srel\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $match[0]) ?? $match[0],
                    '>'
                ), ' /');

                return $tag.' rel="'.implode(' ', $values).'">';
            },
            $html
        ) ?? '';
    }

    /**
     * Hold every image's width to something a reader can be served.
     *
     * The width is the one thing the editor lets an author set on an image, and
     * strip_tags hands attributes through untouched on a tag it keeps — so this is
     * the only place a hand-edited `width="99999"` gets caught. The attribute is
     * stripped and written again from the clamped integer rather than patched in
     * place, which is also what drops a width carrying units, a percentage, or
     * anything else that is not a plain number.
     */
    protected function normalizeImages(string $html): string
    {
        $min = $this->minImageWidth();
        $max = $this->maxImageWidth();

        return preg_replace_callback(
            '/<img\b[^>]*>/i',
            function (array $match) use ($min, $max): string {
                preg_match('/\swidth\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $match[0], $width);

                $value = $width[2] ?? $width[3] ?? $width[4] ?? null;

                // Rebuilt without the attribute rather than patched in place, so the
                // numeric case below is adding one back to a tag that has none.
                $tag = rtrim(rtrim(
                    preg_replace('/\swidth\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $match[0]) ?? $match[0],
                    '>'
                ), ' /');

                if ($value === null || ! is_numeric(trim($value))) {
                    return $tag.'>';
                }

                $clamped = max($min, min((int) $value, $max));

                return $tag.' width="'.$clamped.'">';
            },
            $html
        ) ?? '';
    }

    /**
     * Replace every iframe with one this application built.
     *
     * strip_tags keeps the attributes on a tag it allows, and an iframe is the one
     * element where that is not survivable: a src nobody checked is a frame on our
     * page pointing at someone else's, and sandbox, allow and referrerpolicy are
     * all attributes an author could otherwise set for us.
     *
     * So none of the author's markup survives. The src is run back through
     * VideoProviderEnum, which yields a provider and an id or nothing at all, and
     * the tag is written again from those. An iframe pointing anywhere else — any
     * host with no case in that enum — is dropped rather than cleaned, because
     * there is no version of it we can vouch for.
     */
    protected function rebuildEmbeds(string $html): string
    {
        return preg_replace_callback(
            '/<iframe\b[^>]*>.*?<\/iframe>|<iframe\b[^>]*\/?>/is',
            function (array $match): string {
                preg_match('/\ssrc\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $match[0], $src);

                $url = $src[2] ?? $src[3] ?? $src[4] ?? null;
                $resolved = VideoProviderEnum::resolve($url === null ? null : html_entity_decode($url, ENT_QUOTES));

                if ($resolved === null) {
                    return '';
                }

                $embed = $resolved['provider']->embedUrl($resolved['id']);

                return '<iframe src="'.e($embed).'" loading="lazy" allowfullscreen'
                    .' referrerpolicy="strict-origin-when-cross-origin"'
                    .' allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>';
            },
            $html
        ) ?? '';
    }
}
