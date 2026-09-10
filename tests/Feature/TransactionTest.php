<?php

use App\Enums\StatusTransaction;
use App\Enums\TransactionChargeEnum;
use App\Enums\TransactionGroupEnum;
use App\Enums\TransactionTypeEnum;
use App\Enums\TransactionViaEnum;
use App\Enums\TransactionWalletEnum;
use App\Enums\UserRoleEnum;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->member = userWithRole(UserRoleEnum::USER, ['email_verified_at' => now()]);
    $this->admin = userWithRole(UserRoleEnum::ADMIN, ['email_verified_at' => now()]);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// MONEY STORAGE

test('money is stored in minor units and read back in major ones', function () {
    $transaction = app(TransactionService::class)->record(
        $this->member,
        TransactionTypeEnum::CREDIT,
        TransactionGroupEnum::DEPOSIT,
        150.75,
        'A deposit',
    );

    // The cast hides the conversion from application code; the column is integer
    // kobo/cents, which is what keeps the arithmetic exact.
    expect((float) $transaction->amount)->toBe(150.75)
        ->and(DB::table('transactions')->where('id', $transaction->id)->value('amount'))->toBe(15075);
});

test('a reference is generated and is unique', function () {
    $service = app(TransactionService::class);

    $first = $service->record($this->member, TransactionTypeEnum::CREDIT, TransactionGroupEnum::DEPOSIT, 10, 'One');
    $second = $service->record($this->member, TransactionTypeEnum::CREDIT, TransactionGroupEnum::DEPOSIT, 10, 'Two');

    expect($first->reference)->toStartWith('TXN-')
        ->and($first->reference)->not->toBe($second->reference);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// BALANCES

test('a balance is derived from confirmed rows only', function () {
    $service = app(TransactionService::class);

    $service->record($this->member, TransactionTypeEnum::CREDIT, TransactionGroupEnum::DEPOSIT, 100, 'In');
    $service->record($this->member, TransactionTypeEnum::DEBIT, TransactionGroupEnum::PURCHASE, 30, 'Out');

    // Queued money has not moved and must not count.
    $service->record(
        $this->member,
        TransactionTypeEnum::CREDIT,
        TransactionGroupEnum::DEPOSIT,
        500,
        'Pending',
        status: StatusTransaction::QUEUED,
    );

    expect($service->balanceFor($this->member))->toBe(70.0);
});

test('a direct payment does not touch the wallet balance', function () {
    $service = app(TransactionService::class);

    $service->record($this->member, TransactionTypeEnum::CREDIT, TransactionGroupEnum::DEPOSIT, 100, 'In');

    $service->record(
        $this->member,
        TransactionTypeEnum::DIRECT,
        TransactionGroupEnum::PURCHASE,
        40,
        'Card payment',
        wallet: TransactionWalletEnum::NONE,
    );

    expect($service->balanceFor($this->member))->toBe(100.0);
});

test('a confirmed movement records the balance either side of it', function () {
    $service = app(TransactionService::class);

    $service->record($this->member, TransactionTypeEnum::CREDIT, TransactionGroupEnum::DEPOSIT, 100, 'In');
    $second = $service->record($this->member, TransactionTypeEnum::DEBIT, TransactionGroupEnum::PURCHASE, 25, 'Out');

    expect((float) $second->balance->balance_before)->toBe(100.0)
        ->and((float) $second->balance->balance_after)->toBe(75.0)
        ->and($second->balance->delta())->toBe(-25.0);
});

test('a pending transaction gets no balance snapshot', function () {
    $transaction = app(TransactionService::class)->record(
        $this->member,
        TransactionTypeEnum::CREDIT,
        TransactionGroupEnum::DEPOSIT,
        100,
        'Pending',
        status: StatusTransaction::QUEUED,
    );

    expect($transaction->balance)->toBeNull();
});

test('one balance snapshot per transaction is enforced by the database', function () {
    $transaction = app(TransactionService::class)->record(
        $this->member,
        TransactionTypeEnum::CREDIT,
        TransactionGroupEnum::DEPOSIT,
        100,
        'In',
    );

    // A second row would mean the movement was applied twice.
    expect(fn () => $transaction->balance()->create([
        'balance_before' => 0,
        'balance_after' => 100,
    ]))->toThrow(QueryException::class);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// CHARGES

test('charges are their own rows and are summed separately from the amount', function () {
    $transaction = app(TransactionService::class)->record(
        $this->member,
        TransactionTypeEnum::DEBIT,
        TransactionGroupEnum::WITHDRAWAL,
        100,
        'Payout',
        charges: [
            TransactionChargeEnum::FEE->value => 2.50,
            TransactionChargeEnum::TAX->value => 1.00,
        ],
    );

    expect($transaction->charges)->toHaveCount(2)
        ->and($transaction->totalCharges())->toBe(3.5)
        // A debit costs the amount plus the charges.
        ->and($transaction->netAmount())->toBe(103.5);
});

test('a zero charge is not recorded', function () {
    $transaction = app(TransactionService::class)->record(
        $this->member,
        TransactionTypeEnum::DEBIT,
        TransactionGroupEnum::WITHDRAWAL,
        100,
        'Payout',
        charges: [TransactionChargeEnum::FEE->value => 0],
    );

    expect($transaction->charges)->toBeEmpty();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// SETTLING

test('settling a queued transaction confirms it and moves the balance', function () {
    $service = app(TransactionService::class);

    $transaction = $service->record(
        $this->member,
        TransactionTypeEnum::CREDIT,
        TransactionGroupEnum::DEPOSIT,
        200,
        'Bank transfer',
        via: TransactionViaEnum::BANK_TRANSFER,
        status: StatusTransaction::QUEUED,
    );

    expect($service->balanceFor($this->member))->toBe(0.0);

    $this->actingAs($this->admin);

    expect($service->settle($transaction, StatusTransaction::CONFIRMED))->toBeNull()
        ->and($service->balanceFor($this->member->fresh()))->toBe(200.0)
        ->and($transaction->fresh()->balance)->not->toBeNull();
});

test('a settled transaction cannot be settled again', function () {
    $service = app(TransactionService::class);

    $transaction = $service->record(
        $this->member,
        TransactionTypeEnum::CREDIT,
        TransactionGroupEnum::DEPOSIT,
        200,
        'Deposit',
        status: StatusTransaction::QUEUED,
    );

    $this->actingAs($this->admin);

    $service->settle($transaction, StatusTransaction::CONFIRMED);

    // A webhook arriving twice must be a no-op, not a second credit.
    expect($service->settle($transaction->fresh(), StatusTransaction::CONFIRMED))->not->toBeNull()
        ->and($service->balanceFor($this->member->fresh()))->toBe(200.0);
});

test('rejecting leaves the balance alone', function () {
    $service = app(TransactionService::class);

    $transaction = $service->record(
        $this->member,
        TransactionTypeEnum::CREDIT,
        TransactionGroupEnum::DEPOSIT,
        200,
        'Deposit',
        status: StatusTransaction::QUEUED,
    );

    $this->actingAs($this->admin);

    $service->settle($transaction, StatusTransaction::REJECTED);

    expect($service->balanceFor($this->member->fresh()))->toBe(0.0)
        ->and($transaction->fresh()->balance)->toBeNull();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// ADJUSTMENTS

test('a manual adjustment is recorded and logged', function () {
    $this->actingAs($this->admin);

    $transaction = app(TransactionService::class)->adjust($this->member, 50, 'Refund for duplicate charge');

    expect($transaction->transaction_group)->toBe(TransactionGroupEnum::ADJUSTMENT)
        ->and(app(TransactionService::class)->balanceFor($this->member->fresh()))->toBe(50.0);

    $this->assertDatabaseHas('activity_logs', ['action' => 'transaction.create']);
});

test('a debit adjustment takes money away', function () {
    $this->actingAs($this->admin);

    $service = app(TransactionService::class);

    $service->record($this->member, TransactionTypeEnum::CREDIT, TransactionGroupEnum::DEPOSIT, 100, 'In');
    $service->adjust($this->member->fresh(), 30, 'Correction', credit: false);

    expect($service->balanceFor($this->member->fresh()))->toBe(70.0);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE SCREENS

test('a member sees their statement and nobody else', function () {
    $service = app(TransactionService::class);

    $service->record($this->member, TransactionTypeEnum::CREDIT, TransactionGroupEnum::DEPOSIT, 100, 'Mine');

    $stranger = userWithRole(UserRoleEnum::USER, ['email_verified_at' => now()]);
    $service->record($stranger, TransactionTypeEnum::CREDIT, TransactionGroupEnum::DEPOSIT, 100, 'Theirs');

    Livewire::actingAs($this->member)
        ->test('pages::user.transactions')
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});

test('an admin sees the whole ledger', function () {
    app(TransactionService::class)->record(
        $this->member,
        TransactionTypeEnum::CREDIT,
        TransactionGroupEnum::DEPOSIT,
        100,
        'A deposit',
    );

    $this->actingAs($this->admin)->get(route('admin.transactions'))->assertSuccessful()->assertSee('A deposit');
});

test('a member cannot reach the admin ledger', function () {
    $this->actingAs($this->member)->get(route('admin.transactions'))->assertNotFound();
});

test('an admin can settle from the screen', function () {
    $transaction = app(TransactionService::class)->record(
        $this->member,
        TransactionTypeEnum::CREDIT,
        TransactionGroupEnum::DEPOSIT,
        200,
        'Bank transfer',
        status: StatusTransaction::QUEUED,
    );

    Livewire::actingAs($this->admin)
        ->test('pages::admin.transactions')
        ->call('confirmSettle', $transaction->id, StatusTransaction::CONFIRMED->value)
        ->call('settle')
        ->assertHasNoErrors();

    expect(Transaction::query()->find($transaction->id)->status)->toBe(StatusTransaction::CONFIRMED);
});
