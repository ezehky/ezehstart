<?php

namespace Database\Seeders;

use App\Enums\StatusTransaction;
use App\Enums\TransactionGroupEnum;
use App\Enums\TransactionTypeEnum;
use App\Enums\TransactionViaEnum;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Database\Seeder;

/**
 * A ledger with enough in it to page through, total, filter by date and export.
 *
 * Written through TransactionService rather than straight into the table, so every row
 * carries the reference, the balance movement and the charges a real one would — a
 * demo ledger whose balances do not add up teaches the wrong thing about the screen.
 *
 * Re-running is a no-op once the ledger is this size: money is not something to make
 * twice by accident.
 */
class DemoTransactionSeeder extends Seeder
{
    public const ROWS = 140;

    public function run(): void
    {
        if (Transaction::query()->count() >= self::ROWS) {
            $this->command?->info('The demo ledger already has '.self::ROWS.' rows or more — leaving it alone.');

            return;
        }

        $members = User::query()->users()->get();

        if ($members->isEmpty()) {
            $this->command?->warn('No member accounts to record against. Run DemoUserSeeder first.');

            return;
        }

        $service = app(TransactionService::class);

        $groups = [
            TransactionGroupEnum::DEPOSIT,
            TransactionGroupEnum::WITHDRAWAL,
            TransactionGroupEnum::PURCHASE,
            TransactionGroupEnum::REFUND,
            TransactionGroupEnum::ADJUSTMENT,
        ];

        $vias = [TransactionViaEnum::PLATFORM, TransactionViaEnum::PAYMENT_GATEWAY, TransactionViaEnum::BANK_TRANSFER];

        for ($index = Transaction::query()->count(); $index < self::ROWS; $index++) {
            $group = $groups[array_rand($groups)];

            // A deposit or a refund puts money in; everything else takes it out.
            $type = in_array($group, [TransactionGroupEnum::DEPOSIT, TransactionGroupEnum::REFUND], true)
                ? TransactionTypeEnum::CREDIT
                : TransactionTypeEnum::DEBIT;

            // A fifth are left unsettled, because the queue waiting on a decision is
            // half of what this screen is for.
            $status = $index % 5 === 0
                ? StatusTransaction::QUEUED
                : ($index % 17 === 0 ? StatusTransaction::REJECTED : StatusTransaction::CONFIRMED);

            $transaction = $service->record(
                $members->random(),
                $type,
                $group,
                round(rand(500, 250000) / 100, 2),
                fake()->sentence(4),
                $vias[array_rand($vias)],
                status: $status,
            );

            // Spread across the year so the date filter and the totals have something
            // to narrow. Written quietly: the row is already complete, and touching
            // updated_at here would only make the audit trail lie.
            $transaction->forceFill([
                'created_at' => now()->subDays(rand(0, 330))->subMinutes(rand(0, 1400)),
            ])->saveQuietly();
        }
    }
}
