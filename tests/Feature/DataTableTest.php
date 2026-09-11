<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\TransactionGroupEnum;
use App\Enums\TransactionTypeEnum;
use App\Enums\TransactionViaEnum;
use App\Enums\UserTypeEnum;
use App\Models\ActivityLog;
use App\Models\Tag;
use App\Models\Transaction;
use App\Services\DashboardManagerService;
use App\Services\SpreadsheetService;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * WithDataTable and the table components, exercised through the transaction ledger —
 * the first screen to carry them, and the one with money to total.
 */
beforeEach(function () {
    $this->admin = userOfType(UserTypeEnum::ADMIN);
    $this->member = userOfType(UserTypeEnum::USER);
});

/**
 * A transaction of a given amount, optionally dated in the past.
 */
function ledgerRow(float $amount, ?string $date = null): Transaction
{
    $transaction = app(TransactionService::class)->record(
        test()->member,
        TransactionTypeEnum::CREDIT,
        TransactionGroupEnum::DEPOSIT,
        $amount,
        'A deposit of '.$amount,
    );

    if ($date) {
        $transaction->forceFill(['created_at' => $date])->saveQuietly();
    }

    return $transaction->refresh();
}

/**
 * @return Testable
 */
function ledger()
{
    return Livewire::actingAs(test()->admin)->test('pages::admin.transactions');
}

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE COLUMN MANAGER

test('every column is on screen until the account puts one away', function () {
    ledgerRow(100);

    ledger()
        ->assertSee('Group')
        ->assertSee('Via');
});

test('a column put away leaves the header and the rows together', function () {
    ledgerRow(100);

    $component = ledger()->call('toggleColumn', 'via');

    // Both the header cell and every body cell read the same list, so a column that
    // goes takes its cells with it rather than shifting the row out of line. What is
    // *in* the cell is the needle: the column's own label stays on screen either way,
    // in the manager's list of what can be brought back.
    $component
        ->assertDontSee(TransactionViaEnum::PLATFORM->label())
        ->assertSee('A deposit of 100');

    expect($component->get('hiddenColumns'))->toBe(['via']);
});

test('a column the account put away is still there on the next visit', function () {
    ledgerRow(100);

    ledger()->call('toggleColumn', 'via');

    expect(ledger()->get('hiddenColumns'))->toBe(['via']);
});

test('the arrangement is filed per account and per screen', function () {
    ledger()->call('toggleColumn', 'via');

    $file = app(DashboardManagerService::class)->file($this->admin);

    expect(Storage::exists($file))->toBeTrue();

    $saved = json_decode(Storage::get($file), true);

    expect($saved['column-manager']['pages::admin.transactions'])->toBe(['via'])
        // Another account has its own file and has arranged nothing.
        ->and(Storage::exists(app(DashboardManagerService::class)->file($this->member)))->toBeFalse();
});

test('showing them all clears the arrangement rather than saving an empty one', function () {
    ledger()->call('toggleColumn', 'via');

    $component = ledger()->call('resetColumns');

    expect($component->get('hiddenColumns'))->toBe([]);

    $saved = json_decode(Storage::get(app(DashboardManagerService::class)->file($this->admin)), true);

    expect($saved['column-manager'] ?? [])->not->toHaveKey('pages::admin.transactions');
});

test('the column that says which row it is cannot be put away', function () {
    ledger()
        ->call('toggleColumn', 'reference')
        ->assertHasErrors();

    expect(ledger()->get('hiddenColumns'))->toBe([]);
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// SORTING

test('a column sorts, and sorts the other way on a second click', function () {
    $component = ledger()->call('sortBy', 'amount');

    expect($component->get('sortColumn'))->toBe('amount')
        ->and($component->get('sortDirection'))->toBe('asc');

    $component->call('sortBy', 'amount');

    expect($component->get('sortDirection'))->toBe('desc');
});

test('the sort actually orders the rows', function () {
    ledgerRow(10);
    ledgerRow(500);
    ledgerRow(250);

    $ordered = ledger()
        ->call('sortBy', 'amount')
        ->get('transactions')
        ->pluck('amount')
        ->map(fn ($amount) => (float) $amount)
        ->all();

    expect($ordered)->toBe([10.0, 250.0, 500.0]);
});

test('a column that is not sortable is refused', function () {
    ledger()
        ->call('sortBy', 'user')
        ->assertHasErrors();
});

test('a sort named in the url is ignored unless the screen declared it', function () {
    ledgerRow(100);

    // The query string is not a safe place to name a column, so applySort() checks it
    // against the declared list before it reaches the database.
    ledger()
        ->set('sortColumn', 'password')
        ->assertHasNoErrors()
        ->assertSee('A deposit of 100');
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE DATE RANGE

test('the date range narrows the result at both ends', function () {
    ledgerRow(100, '2026-01-10 10:00:00');
    ledgerRow(200, '2026-02-10 10:00:00');
    ledgerRow(300, '2026-03-10 10:00:00');

    expect(ledger()->set('dateFrom', '2026-02-01')->get('transactions')->total())->toBe(2);
    expect(ledger()->set('dateTo', '2026-02-01')->get('transactions')->total())->toBe(1);

    expect(
        ledger()->set('dateFrom', '2026-02-01')->set('dateTo', '2026-02-28')->get('transactions')->total()
    )->toBe(1);
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// SELECTION

test('the header checkbox takes the rows on the page', function () {
    $first = ledgerRow(100);
    $second = ledgerRow(200);

    $component = ledger()->set('selectPage', true);

    expect($component->get('selected'))
        ->toHaveCount(2)
        ->toContain((string) $first->id)
        ->toContain((string) $second->id);

    $component->set('selectPage', false);

    expect($component->get('selected'))->toBe([]);
});

test('the header checkbox takes the rows the table is showing, not a second query of its own', function () {
    // Two pages of them on purpose. tableQuery() carries the filters but not the
    // ordering, so a re-query with an offset agrees with the screen on page one by
    // luck and picks a different five rows on page two.
    collect(range(1, 25))->each(fn (int $amount) => ledgerRow($amount * 10));

    $component = ledger()->call('gotoPage', 2)->set('selectPage', true);

    $onScreen = collect($component->get('transactions')->items())
        ->map(fn ($transaction) => (string) $transaction->id)
        ->all();

    expect($component->get('selected'))->toBe($onScreen);
});

test('the header checkbox lets go of a row unticked by hand, and takes the last one back', function () {
    $first = ledgerRow(100);
    $second = ledgerRow(200);

    $component = ledger()->set('selectPage', true);

    expect($component->get('selectPage'))->toBeTrue();

    // One row put back, so this is no longer the whole page.
    $component->set('selected', [(string) $first->id]);

    expect($component->get('selectPage'))->toBeFalse();

    // And the last outstanding row ticked by hand is the whole page again.
    $component->set('selected', [(string) $first->id, (string) $second->id]);

    expect($component->get('selectPage'))->toBeTrue();
});

test('touching a single row drops "everything that matches"', function () {
    $first = ledgerRow(100);
    ledgerRow(200);

    $component = ledger()->call('selectAllMatching');

    expect($component->get('selectMatching'))->toBeTrue();

    $component->set('selected', [(string) $first->id]);

    // Hand-picking is the opposite of the whole filtered result, and a bulk delete
    // that quietly kept working off the query would take both rows.
    expect($component->get('selectMatching'))->toBeFalse()
        ->and($component->get('selectedCount'))->toBe(1);
});

test('selecting everything that matches counts the whole filtered result', function () {
    ledgerRow(100, '2026-01-10 10:00:00');
    ledgerRow(200, '2026-02-10 10:00:00');
    ledgerRow(300, '2026-03-10 10:00:00');

    $component = ledger()->set('dateFrom', '2026-02-01')->call('selectAllMatching');

    expect($component->get('selectMatching'))->toBeTrue()
        // Two match the filter, not the three in the table.
        ->and($component->get('selectedCount'))->toBe(2);
});

test('changing a filter drops the selection rather than acting on rows nobody can see', function () {
    ledgerRow(100);

    $component = ledger()->set('selectPage', true);

    expect($component->get('selected'))->toHaveCount(1);

    $component->set('dateFrom', '2030-01-01');

    expect($component->get('selected'))->toBe([])
        ->and($component->get('selectPage'))->toBeFalse();
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE SUMMARY

test('an amount column totals the whole filtered result, not the page', function () {
    ledgerRow(100.50);
    ledgerRow(200.25);

    expect(ledger()->get('tableSummary')['amount'])->toBe(kMoneyFormat(300.75, decodeHtml: true));
});

test('the total follows the filters', function () {
    ledgerRow(100, '2026-01-10 10:00:00');
    ledgerRow(250, '2026-03-10 10:00:00');

    expect(ledger()->set('dateFrom', '2026-02-01')->get('tableSummary')['amount'])
        ->toBe(kMoneyFormat(250, decodeHtml: true));
});

test('a screen with nothing to total has no summary row', function () {
    expect(ledger()->get('tableSummary'))->toHaveKey('amount');
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// EXPORT

test('an export hands back a file named for the screen and the day', function () {
    ledgerRow(100);

    $response = ledger()->call('export', 'csv')->effects['download'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['name'])->toBe('transactions-'.now()->format('Y-m-d').'.csv');
});

test('the file carries the rows and the columns that are on screen', function () {
    ledgerRow(100);

    $path = exportToDisk('csv');

    $rows = app(SpreadsheetService::class)->rows($path, 'csv');

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toHaveKey('reference')
        ->and($rows[0])->toHaveKey('via')
        // The relationship column is the account's name rather than a model.
        ->and($rows[0]['account'])->toBe($this->member->name);
});

test('a column put away is out of the file too, unless every column was asked for', function () {
    ledgerRow(100);

    $component = ledger()->call('toggleColumn', 'via');

    expect(array_keys($component->get('tableExportHeaders')))->not->toContain('via');

    $component->set('exportAllColumns', true);

    expect(array_keys($component->get('tableExportHeaders')))->toContain('via');
});

test('an export with rows ticked takes only those rows', function () {
    $first = ledgerRow(100);
    ledgerRow(200);

    $path = exportToDisk('csv', fn ($component) => $component->set('selected', [(string) $first->id]));

    expect(app(SpreadsheetService::class)->rows($path, 'csv'))->toHaveCount(1);
});

test('an xlsx export is a workbook that reads back', function () {
    ledgerRow(100);

    $path = exportToDisk('xlsx');

    expect(app(SpreadsheetService::class)->rows($path, 'xlsx'))->toHaveCount(1);
});

test('a format nobody supports is refused by name', function () {
    ledger()->call('export', 'docx');
})->throws(InvalidArgumentException::class, 'Unsupported export format: docx');

/**
 * Run an export and put the file it produced somewhere a reader can open it. The
 * response streams from a temp file that is deleted once sent, so the test asks the
 * response to send and catches the bytes.
 */
function exportToDisk(string $format, ?callable $arrange = null): string
{
    $component = ledger();

    if ($arrange) {
        $arrange($component);
    }

    $component->call('export', $format);

    $download = $component->effects['download'];

    $path = tempnam(sys_get_temp_dir(), 'exported').'.'.$format;

    file_put_contents($path, base64_decode($download['content']));

    return $path;
}

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// BULK ACTIONS

test('a screen that does not offer deleting refuses it however it is reached', function () {
    $transaction = ledgerRow(100);

    ledger()
        ->set('selected', [(string) $transaction->id])
        ->call('bulkDelete')
        ->assertHasErrors();

    expect(Transaction::query()->count())->toBe(1);
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// BULK DELETE, ON A SCREEN THAT OFFERS IT

/**
 * @return Testable
 */
function tagList()
{
    return Livewire::actingAs(test()->admin)->test('pages::admin.content.tags');
}

test('the ticked rows are deleted together', function () {
    $first = Tag::query()->create(['name' => 'winter', 'slug' => 'winter']);
    $second = Tag::query()->create(['name' => 'skincare', 'slug' => 'skincare']);
    Tag::query()->create(['name' => 'routine', 'slug' => 'routine']);

    tagList()
        ->set('selected', [(string) $first->id, (string) $second->id])
        ->call('bulkDelete')
        ->assertHasNoErrors();

    expect(Tag::query()->pluck('name')->all())->toBe(['routine']);
});

test('deleting everything that matches takes the whole filtered result', function () {
    Tag::query()->create(['name' => 'winter sale', 'slug' => 'winter-sale']);
    Tag::query()->create(['name' => 'winter care', 'slug' => 'winter-care']);
    Tag::query()->create(['name' => 'skincare', 'slug' => 'skincare']);

    tagList()
        ->set('search', 'winter')
        ->call('selectAllMatching')
        ->call('bulkDelete');

    expect(Tag::query()->pluck('name')->all())->toBe(['skincare']);
});

test('deleting nothing is refused rather than quietly doing nothing', function () {
    Tag::query()->create(['name' => 'winter', 'slug' => 'winter']);

    tagList()->call('bulkDelete')->assertHasErrors();

    expect(Tag::query()->count())->toBe(1);
});

test('an account without full access cannot delete in bulk', function () {
    $tag = Tag::query()->create(['name' => 'winter', 'slug' => 'winter']);

    $restricted = adminWithRoles(roleWithGates('Editor', ['content.tags' => GateAccessEnum::MODIFY->value]));

    Livewire::actingAs($restricted)
        ->test('pages::admin.content.tags')
        ->set('selected', [(string) $tag->id])
        ->call('bulkDelete')
        ->assertHasErrors();

    expect(Tag::query()->count())->toBe(1);
});

test('a bulk delete is written to the audit trail with its count', function () {
    $first = Tag::query()->create(['name' => 'winter', 'slug' => 'winter']);
    $second = Tag::query()->create(['name' => 'skincare', 'slug' => 'skincare']);

    tagList()
        ->set('selected', [(string) $first->id, (string) $second->id])
        ->call('bulkDelete');

    $log = ActivityLog::query()->latest('id')->first();

    expect($log->activity_log_action)->toBe(ActivityActionEnum::TAG_DELETE)
        ->and($log->description)->toContain('2 tags');
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// PDF

test('a pdf export with no driver installed says so rather than breaking the screen', function () {
    ledgerRow(100);

    // No driver package ships with the kit — the developer picks one and installs it —
    // so this is the path an install takes before that choice has been made.
    ledger()
        ->call('export', 'pdf')
        ->assertHasErrors();
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// WHAT THE TABLE ACTUALLY RENDERS

test('the totals sit inside the table so they line up under their columns', function () {
    ledgerRow(100);

    // A separate table below would size its own columns and leave every figure lined
    // up with nothing, so the row is part of the table it totals.
    $html = ledger()->html();

    $tableEnd = strpos($html, '</table>');
    $totals = strpos($html, 'Totals');

    expect($totals)->not->toBeFalse()
        ->and($totals)->toBeLessThan($tableEnd);
});

test('a cell given a link opens the record it names', function () {
    ledgerRow(100);

    ledger()->assertSee(route('admin.user', $this->member), escape: false);
});

test('no blade directive is left printed into the markup', function () {
    ledgerRow(100);

    // A bare @if inside a component tag's attribute list is read as an attribute
    // called "@if" and printed into the page, which is how a sortable header ends up
    // rendering its own source. Nothing on this screen should contain one.
    expect(ledger()->html())
        ->not->toContain('@if')
        ->not->toContain('@endif');
});

test('a sortable header carries the call that sorts it', function () {
    ledgerRow(100);

    ledger()->assertSee('wire:click="sortBy(\'reference\')"', escape: false);
});
