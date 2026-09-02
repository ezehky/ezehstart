<?php

namespace App\Services;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;

/**
 * Renders the markdown administrators write — policy bodies, FAQ answers — into the
 * HTML the public pages show.
 *
 * Raw HTML in the source is escaped rather than rendered: the copy is written by
 * administrators, but escaping means a compromised account cannot inject scripts
 * into a page every visitor sees.
 */
#[Singleton]
class MarkdownService
{
    /**
     * Render markdown to HTML.
     */
    public function toHtml(?string $markdown): string
    {
        $markdown = trim((string) $markdown);

        if ($markdown === '') {
            return '';
        }

        // Str::markdown() uses the GitHub-flavoured converter, so pipe tables,
        // strikethrough, and autolinks are all supported.
        $html = Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return trim($this->wrapTables($html));
    }

    /**
     * Wrap tables so a wide one scrolls inside its own container instead of forcing
     * the whole page to scroll sideways on a phone.
     */
    protected function wrapTables(string $html): string
    {
        return (string) preg_replace(
            '/<table>(.*?)<\/table>/s',
            '<div class="table-scroll"><table>$1</table></div>',
            $html
        );
    }
}
