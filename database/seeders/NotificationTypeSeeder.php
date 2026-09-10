<?php

namespace Database\Seeders;

use App\Enums\NotificationTypeEnum;
use App\Models\NotificationType;
use Illuminate\Database\Seeder;

class NotificationTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Matched on notification_type, the natural key, so re-seeding refreshes the
     * copy rather than stacking duplicates. Only the wording is refreshed: status
     * and flow_order are left alone, because an administrator who silenced a type
     * or reordered the list did that on purpose and a deploy must not undo it.
     */
    public function run(): void
    {
        foreach (NotificationTypeEnum::cases() as $index => $type) {
            $existing = NotificationType::query()
                ->where('notification_type', $type)
                ->first();

            NotificationType::updateOrCreate(
                ['notification_type' => $type],
                [
                    'title' => $type->label(),
                    'description' => $type->description(),
                    'flow_order' => $existing?->flow_order ?? $index,
                ]
            );
        }
    }
}
