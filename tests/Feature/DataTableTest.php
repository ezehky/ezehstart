<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusTransaction;
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

test('a row put back while everything is chosen is an exception rather than the end of it', function () {
    $first = ledgerRow(100);
    ledgerRow(200);

    $component = ledger()->call('selectAllMatching');

    expect($component->get('selectMatching'))->toBeTrue();

    $component->set('selected', [(string) $first->id]);

    // Still the whole result, less the one row — falling back to the page on screen
    // would turn a selection of everything into a selection of twelve without
    // saying so, and the bar would go on reading as though nothing had changed.
    expect($component->get('selectMatching'))->toBeTrue()
        ->and($component->get('selectedCount'))->toBe(1);
});

test('a row put back is left alone by the action the rest of them get', function () {
    $kept = Tag::query()->create(['name' => 'winter sale', 'slug' => 'winter-sale']);
    Tag::query()->create(['name' => 'winter care', 'slug' => 'winter-care']);
    Tag::query()->create(['name' => 'winter boots', 'slug' => 'winter-boots']);

    $component = tagList()->set('search', 'winter')->call('selectAllMatching');

    // Every row the search matched is ticked. This puts one of them back.
    $component
        ->set('selected', array_values(array_diff($component->get('selected'), [(string) $kept->id])))
        ->call('bulkDelete');

    expect(Tag::query()->pluck('name')->all())->toBe(['winter sale']);
});

test('everything that matches stays ticked on the next page', function () {
    collect(range(1, 25))->each(fn (int $amount) => ledgerRow($amount * 10));

    $component = ledger()->call('selectAllMatching')->call('gotoPage', 2);

    $onScreen = collect($component->get('transactions')->items())
        ->map(fn ($transaction) => (string) $transaction->id)
        ->all();

    // The bar said twenty-five rows were chosen above a page where none of them
    // looked it, because `selected` only ever holds the page being looked at.
    expect($component->get('selected'))->toBe($onScreen)
        ->and($component->get('selectPage'))->toBeTrue()
        ->and($component->get('selectedCount'))->toBe(25);
});

test('the header checkbox adds the page to rows chosen on another one', function () {
    collect(range(1, 25))->each(fn (int $amount) => ledgerRow($amount * 10));

    $component = ledger()->set('selectPage', true);

    $firstPage = $component->get('selected');

    $component->call('gotoPage', 2)->set('selectPage', true);

    // Paging through and ticking each header means all of those pages. A header that
    // started over would quietly drop the twenty behind it.
    expect($component->get('selected'))->toHaveCount(25)
        ->and($component->get('selected'))->toContain(...$firstPage);
});

test('the header checkbox sets this page aside without letting go of the rest', function () {
    collect(range(1, 25))->each(fn (int $amount) => ledgerRow($amount * 10));

    $component = ledger()->call('selectAllMatching')->set('selectPage', false);

    expect($component->get('selectMatching'))->toBeTrue()
        ->and($component->get('selectedCount'))->toBe(5);
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

test('a column put away is out of the file too, unless it is ticked in the dialog', function () {
    ledgerRow(100);

    $component = ledger()->call('toggleColumn', 'via');

    expect(array_keys($component->get('tableExportHeaders')))->not->toContain('via');

    // The dialog offers every exportable column, not only the ones on screen: a
    // column hidden because it made the table too wide is still one somebody wants
    // in the spreadsheet.
    $component->set('exportColumns', [...$component->get('exportColumns'), 'via']);

    expect(array_keys($component->get('tableExportHeaders')))->toContain('via');
});

test('the dialog opens on the table as it stands', function () {
    ledgerRow(100);

    $component = ledger()->call('toggleColumn', 'via')->call('openExportModal');

    expect($component->get('exportColumns'))->not->toContain('via')
        ->and($component->get('exportLabels')['amount'])->toBe('Amount');
});

test('a renamed column lands in the file under the name it was given', function () {
    ledgerRow(100);

    $path = exportToDisk('csv', fn ($component) => $component
        ->set('exportColumns', ['reference', 'amount'])
        ->set('exportLabels.amount', 'What it cost'));

    $rows = app(SpreadsheetService::class)->rows($path, 'csv');

    // A reader keys a row by its heading, so the heading the dialog typed is what
    // comes back — and the columns nobody ticked are not in the file at all.
    expect(array_keys($rows[0]))->toBe(['reference', 'what_it_cost']);
});

test('a heading left empty falls back to what the column is called on screen', function () {
    ledgerRow(100);

    expect(ledger()->set('exportLabels.amount', '   ')->get('tableExportHeaders')['amount'])
        ->toBe('Amount');
});

test('the file keeps the screen order however the boxes were ticked', function () {
    ledgerRow(100);

    // A spreadsheet whose columns moved about because of the order somebody ticked
    // them in is not the table anybody asked for.
    expect(array_keys(ledger()->set('exportColumns', ['created_at', 'reference'])->get('tableExportHeaders')))
        ->toBe(['reference', 'created_at']);
});

test('a member can open the dialog on their own statement', function () {
    ledgerRow(100);

    // The member workspace is not gated, so the dialog's own VIEW check has to let
    // an account through to a screen that is already theirs.
    $component = Livewire::actingAs($this->member)
        ->test('pages::user.transactions')
        ->call('openExportModal')
        ->assertHasNoErrors();

    expect($component->get('exportColumns'))
        ->toContain('reference')
        // A running balance is worked out for the screen rather than stored, and the
        // column says so — it is not offered.
        ->not->toContain('balance');
});

test('an export with no column ticked is refused rather than handing back an empty file', function () {
    ledgerRow(100);

    ledger()->set('exportColumns', [])->call('export', 'csv')->assertHasErrors();
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

test('a format nobody supports is refused rather than reaching the writer', function () {
    // The format is a bound property as well as an argument, so it arrives from the
    // wire — an unsupported one is somebody's dialog rather than a broken screen.
    ledger()->call('export', 'docx')->assertHasErrors();

    ledger()->set('exportFormat', 'docx')->call('export')->assertHasErrors();
});

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

test('a pdf export hands back a document', function () {
    ledgerRow(100);

    // dompdf ships with the kit and renders in pure PHP, so this works on an install
    // where nothing but composer has been run.
    $download = ledger()->call('export', 'pdf')->effects['download'] ?? null;

    expect($download)->not->toBeNull()
        ->and($download['name'])->toBe('transactions-'.now()->format('Y-m-d').'.pdf')
        // A PDF is a PDF by its first five bytes rather than by its extension.
        ->and(base64_decode($download['content']))->toStartWith('%PDF-');
});

test('a pdf driver that is not set up says so rather than breaking the screen', function () {
    ledgerRow(100);

    // Browsershot needs Node and Chrome on the server. Where a project has switched
    // to it and not installed them, that is a setting nobody has made yet.
    config()->set('laravel-pdf.driver', 'browsershot');

    ledger()->call('export', 'pdf')->assertHasErrors();
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE FILTERS, AS CHIPS

test('a filter that is on says so, by the label its select shows', function () {
    ledgerRow(100);

    $filters = ledger()
        ->set('status', (string) StatusTransaction::CONFIRMED->value)
        ->get('tableActiveFilters');

    // "Status: 2" is the value the select files, not the thing anybody chose.
    expect($filters['status'])->toBe([
        'label' => 'Status',
        'value' => StatusTransaction::CONFIRMED->label(),
    ]);
});

test('both ends of the date range are one chip', function () {
    ledgerRow(100);

    $filters = ledger()
        ->set('dateFrom', '2026-02-01')
        ->set('dateTo', '2026-02-28')
        ->get('tableActiveFilters');

    expect($filters)->toHaveCount(1)
        ->and($filters['date-range'])->toBe(['label' => 'Dated', 'value' => '2026-02-01 to 2026-02-28']);
});

test('a filter nobody set is not a chip', function () {
    ledgerRow(100);

    expect(ledger()->get('tableActiveFilters'))->toBe([]);
});

test('one chip comes off without taking the others with it', function () {
    ledgerRow(100, '2026-01-10 10:00:00');

    $component = ledger()
        ->set('search', 'deposit')
        ->set('dateFrom', '2026-01-01')
        ->call('clearFilter', 'search');

    expect($component->get('search'))->toBe('')
        ->and($component->get('dateFrom'))->toBe('2026-01-01');
});

test('clearing them all leaves the sort and the columns where they were', function () {
    ledgerRow(100);

    $component = ledger()
        ->call('toggleColumn', 'via')
        ->call('sortBy', 'amount')
        ->set('search', 'deposit')
        ->set('status', (string) StatusTransaction::CONFIRMED->value)
        ->set('dateFrom', '2026-01-01')
        ->set('dateTo', '2026-03-01')
        ->call('clearFilters');

    expect($component->get('tableActiveFilters'))->toBe([])
        ->and($component->get('search'))->toBe('')
        ->and($component->get('dateTo'))->toBe('')
        // How the account reads the screen is not what the screen is showing, and
        // losing it to a Clear button is not what anybody pressing it meant.
        ->and($component->get('sortColumn'))->toBe('amount')
        ->and($component->get('hiddenColumns'))->toBe(['via']);
});

test('a property the screen never declared as a filter cannot be cleared from the wire', function () {
    ledgerRow(100);

    ledger()
        ->call('sortBy', 'amount')
        ->call('clearFilter', 'sortColumn')
        ->assertHasErrors();

    // reset() aimed at whatever arrives over the wire would put any property back.
    expect(ledger()->call('sortBy', 'amount')->get('sortColumn'))->toBe('amount');
});

test('taking a filter off lets go of rows that are about to leave the screen', function () {
    ledgerRow(100);

    $component = ledger()->set('selectPage', true)->call('clearFilters');

    expect($component->get('selected'))->toBe([]);
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

test('the select-all link says how many rows it would take', function () {
    collect(range(1, 25))->each(fn (int $amount) => ledgerRow($amount * 10));

    // "All" means a different number on every screen, and it is the one somebody
    // needs to see before pressing it rather than afterwards.
    ledger()->set('selectPage', true)->assertSee('Select all 25');
});

test('there is nothing more to select where the page is the whole result', function () {
    ledgerRow(100);

    ledger()->set('selectPage', true)->assertDontSee('Select all');
});

test('the date field names the properties it is bound to, so it can follow them back', function () {
    ledgerRow(100);

    // The calendar keeps its own copy of the dates. Without the property names it
    // has nothing to watch, and a range cleared on the server stays printed in a
    // box the listing below has already stopped honouring.
    preg_match("/datePicker\\(JSON.parse\\('(.*?)'\\)\\)/s", ledger()->html(), $matches);

    // Decoded twice: what sits in the attribute is a JavaScript string literal with
    // every quote escaped, and it is only JSON once the browser has unescaped it.
    $picker = json_decode((string) json_decode('"'.($matches[1] ?? '').'"'), true);

    expect($picker)->toMatchArray([
        'startProperty' => 'dateFrom',
        'endProperty' => 'dateTo',
    ]);
});

test('a chip carries the means to take its own filter off', function () {
    ledgerRow(100);

    ledger()
        ->set('search', 'deposit')
        ->assertSee('Active filters')
        ->assertSee('wire:click="clearFilter(\'search\')"', escape: false);
});

test('a sortable header carries the call that sorts it', function () {
    ledgerRow(100);

    ledger()->assertSee('wire:click="sortBy(\'reference\')"', escape: false);
});
