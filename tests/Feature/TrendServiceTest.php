<?php

use App\Enums\StatusTransaction;
use App\Enums\TransactionGroupEnum;
use App\Enums\TransactionTypeEnum;
use App\Enums\TrendPeriodEnum;
use App\Enums\UserTypeEnum;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use App\Services\TrendService;

beforeEach(function () {
    $this->trends = app(TrendService::class);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE WINDOW

test('every bucket in the window is present whether anything landed in it or not', function () {
    User::query()->delete();
    userOfType(UserTypeEnum::USER, ['created_at' => now()]);

    $series = $this->trends->trend(User::query());

    expect($series)->toHaveCount(6)
        ->and(collect($series)->pluck('total')->all())->toBe([0, 0, 0, 0, 0, 1]);
});

test('the window is oldest first and carries both labels a chart reads', function () {
    $series = $this->trends->trend(User::query());
    $points = collect($series);

    expect($points->first()->period)->toBe(now()->subMonths(5)->format('Y-m'))
        ->and($points->last()->period)->toBe(now()->format('Y-m'))
        ->and($points->last()->label)->toBe(now()->format('F Y'))
        ->and($points->last()->short)->toBe(now()->format('M'));
});

test('a day trend buckets by day and a year trend by year', function () {
    User::query()->delete();
    userOfType(UserTypeEnum::USER, ['created_at' => now()->subDays(2)]);

    $daily = $this->trends->trend(User::query(), periods: 7, period: TrendPeriodEnum::DAY);

    expect($daily)->toHaveCount(7)
        ->and(collect($daily)->firstWhere('period', now()->subDays(2)->format('Y-m-d'))->total)->toBe(1);

    $yearly = $this->trends->trend(User::query(), periods: 2, period: TrendPeriodEnum::YEAR);

    expect($yearly)->toHaveCount(2)
        ->and(collect($yearly)->last()->total)->toBe(1);
});

test('anything older than the window is left out', function () {
    User::query()->delete();
    userOfType(UserTypeEnum::USER, ['created_at' => now()->subMonths(9)]);

    expect(collect($this->trends->trend(User::query()))->sum('total'))->toBe(0);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// SERIES

test('several series come off one read and each is matched on its own columns', function () {
    $member = userOfType(UserTypeEnum::USER);
    $service = app(TransactionService::class);

    $deposit = $service->record($member, TransactionTypeEnum::CREDIT, TransactionGroupEnum::DEPOSIT, 120.50, 'In');
    $deposit->update(['status' => StatusTransaction::CONFIRMED]);

    $service->record($member, TransactionTypeEnum::DEBIT, TransactionGroupEnum::WITHDRAWAL, 40, 'Out');

    $series = $this->trends->trends(
        Transaction::query(),
        splitBy: ['transaction_group', 'status'],
        series: [
            'all' => [],
            'deposits' => [
                'match' => [
                    'transaction_group' => TransactionGroupEnum::DEPOSIT,
                    'status' => StatusTransaction::CONFIRMED,
                ],
                'sum' => 'amount',
                'divideBy' => 100,
            ],
        ],
    );

    expect(collect($series['all'])->last()->total)->toBe(2)
        // The match is given as enum cases against raw grouped rows, and lands.
        ->and(collect($series['deposits'])->last()->total)->toBe(120.50)
        ->and(collect($series['deposits'])->sum('total'))->toBe(120.50);
});

test('a count is an integer and a summed series is a float', function () {
    userOfType(UserTypeEnum::USER);

    $counted = collect($this->trends->trend(User::query()))->last()->total;
    $summed = collect($this->trends->trend(User::query(), sum: 'id'))->last()->total;

    expect($counted)->toBeInt()->and($summed)->toBeFloat();
});

test('the whole thing is one query however many series are asked for', function () {
    DB::enableQueryLog();

    $this->trends->trends(
        User::query(),
        splitBy: ['user_type'],
        series: [
            'all' => [],
            'member' => ['match' => ['user_type' => UserTypeEnum::USER]],
            'admin' => ['match' => ['user_type' => UserTypeEnum::ADMIN]],
        ],
    );

    expect(DB::getQueryLog())->toHaveCount(1);

    DB::disableQueryLog();
});
