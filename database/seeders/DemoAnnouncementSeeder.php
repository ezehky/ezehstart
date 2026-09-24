<?php

namespace Database\Seeders;

use App\Enums\AnnouncementLayoutEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusYes;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One live announcement, so the popup on the public pages has something in it
 * beyond the plain newsletter sign-up.
 *
 * No picture: the image library is emptied at the start of every demo run, and
 * the popup is built to stand on its copy alone. Matched on its title, so a
 * second run rewrites it rather than stacking another behind it — only the
 * latest live row is ever shown anyway.
 */
class DemoAnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        Announcement::query()->updateOrCreate(
            ['title' => 'Fresh from the blog'],
            [
                'user_id' => User::query()->admins()->value('id'),
                'body' => 'New guides and product notes go up every week. Have a read, or leave your address and we will send the best of them to you.',
                'link_url' => route('blog.index'),
                'link_label' => 'Read the blog',
                'show_newsletter' => StatusYes::YES,
                'layout' => AnnouncementLayoutEnum::STACKED,
                'starts_at' => null,
                'ends_at' => null,
                'status' => StatusDefault::ACTIVE,
            ]
        );
    }
}
