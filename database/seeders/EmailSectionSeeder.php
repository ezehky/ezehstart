<?php

namespace Database\Seeders;

use App\Enums\EmailBlockTypeEnum;
use App\Enums\EmailSectionTypeEnum;
use App\Enums\StatusYes;
use App\Models\EmailSection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A starter Header, Footer, CTA and Promotional section, so the email builder's
 * "Saved" palette and a template/campaign's Footer picker have something to offer
 * on a fresh install rather than an empty library.
 *
 * Guarded per type rather than as a whole-table check: an admin who has already
 * built their own Header but never touched Footer/CTA/Promo still gets the rest
 * filled in, and re-running never duplicates a type that already has a row.
 * Custom has no starter content — it is the free-form type, nothing to seed it as.
 */
class EmailSectionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (EmailSectionTypeEnum::cases() as $type) {
            if ($type->isCustom() || EmailSection::query()->ofType($type)->exists()) {
                continue;
            }

            EmailSection::create([
                'name' => $this->names()[$type->value],
                'content' => ['blocks' => $this->blocksFor($type)],
                'email_section_type' => $type,
                'is_default' => StatusYes::YES,
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function names(): array
    {
        return [
            EmailSectionTypeEnum::HEADER->value => 'Default Header',
            EmailSectionTypeEnum::FOOTER->value => 'Default Footer',
            EmailSectionTypeEnum::CTA->value => 'Default CTA',
            EmailSectionTypeEnum::PROMO->value => 'Default Promo',
        ];
    }

    /**
     * @return array<int, array{id: string, type: string, data: array<string, mixed>}>
     */
    private function blocksFor(EmailSectionTypeEnum $type): array
    {
        return match ($type) {
            // Text, not an IMAGE block: an IMAGE block points at an uploaded
            // Image row from the media library, and there is none at seed time.
            // An admin with a logo swaps this block for one themselves; seeding
            // a bare `image_id: null` would render nothing at all.
            EmailSectionTypeEnum::HEADER => [
                $this->block(EmailBlockTypeEnum::HEADING, [
                    'text' => '{{site.name}}',
                    'level' => 'h2',
                    'align' => 'center',
                ]),
            ],

            // The line every footer needs: legal copy, a support address, and an
            // unsubscribe link an admin can otherwise reach no other way — see
            // WithRichTextSanitizer/EmailVariableService::resolve(html: true).
            // The social row is its own HTML block because it is entirely
            // token-built and empty on an install with no handles configured.
            EmailSectionTypeEnum::FOOTER => [
                $this->block(EmailBlockTypeEnum::PARAGRAPH, [
                    'text' => '<p>&copy; {{site.name}}. All rights reserved. Need help? Contact us at '
                        .'<a href="mailto:{{site.contact_email}}">{{site.contact_email}}</a>. If you no longer wish '
                        .'to receive these emails, <a href="{{unsubscribe_url}}">unsubscribe here</a>.</p>',
                    'align' => 'center',
                    'color' => '#94A3B8',
                ]),
                $this->block(EmailBlockTypeEnum::HTML, [
                    'html' => '<div style="text-align:center;font-family:Arial,sans-serif;font-size:12px;color:#94A3B8;">{{site.social_links}}</div>',
                ]),
            ],

            EmailSectionTypeEnum::CTA => [
                $this->block(EmailBlockTypeEnum::HEADING, ['text' => 'You might like this', 'align' => 'center']),
                $this->block(EmailBlockTypeEnum::PARAGRAPH, [
                    'text' => 'Say what this is about in a line or two, then send the reader on with the button below.',
                    'align' => 'center',
                ]),
                $this->block(EmailBlockTypeEnum::BUTTON, [
                    'text' => 'Learn more',
                    'url' => '{{site.url}}',
                    'align' => 'center',
                ]),
            ],

            EmailSectionTypeEnum::PROMO => [
                $this->block(EmailBlockTypeEnum::HEADING, ['text' => 'Special offer', 'align' => 'center']),
                $this->block(EmailBlockTypeEnum::PARAGRAPH, [
                    'text' => 'Describe the offer and who it is for, then let the button below do the asking.',
                    'align' => 'center',
                ]),
                $this->block(EmailBlockTypeEnum::BUTTON, [
                    'text' => 'Shop now',
                    'url' => '{{site.url}}',
                    'align' => 'center',
                ]),
            ],

            EmailSectionTypeEnum::CUSTOM => [],
        };
    }

    /**
     * One block, in the exact shape WithBlockEditor::addBlock() builds — a saved
     * section's content is not a special format, just blocks an admin chose to keep.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{id: string, type: string, data: array<string, mixed>}
     */
    private function block(EmailBlockTypeEnum $type, array $overrides): array
    {
        return [
            'id' => (string) Str::uuid(),
            'type' => $type->value,
            'data' => [...$type->defaultData(), ...$overrides],
        ];
    }
}
