<?php

namespace App\Traits;

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Services\ActivityLogService;
use App\Services\DashboardManagerService;
use App\Services\ExportService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\Response;

/**
 * The parts of a listing that are the same on every listing: choosing rows, acting on
 * the chosen ones, deciding which columns to look at, sorting, a date range, and
 * taking the whole thing away as a file.
 *
 * Everything here is optional. A page that declares its columns and its query gets the
 * column manager, the sort and the export; it gets checkboxes and a bulk bar only
 * where it renders them, and bulk delete only where tableDeletable() says so. Nothing
 * appears on a screen that did not ask for it.
 *
 * The page supplies three things — what the columns are, what the query is, and what
 * it ended up rendering:
 *
 *     use WithDataTable, WithPagination;
 *
 *     protected function tableColumns(): array
 *     {
 *         return [
 *             'reference' => $this->columnMaker('Reference', locked: true, sortable: true),
 *             'amount' => $this->columnMaker('Amount', sortable: true, summary: 'sum', money: true),
 *             'created_at' => $this->columnMaker('Date', sortable: true),
 *         ];
 *     }
 *
 *     protected function tableQuery(): Builder
 *     {
 *         return Transaction::query()->when(...);
 *     }
 *
 *     protected function tableRows(): iterable
 *     {
 *         return $this->transactions;
 *     }
 *
 * @property-read array<string, array<string, mixed>> $tableColumnList
 * @property-read array<string, array<string, mixed>> $tableExportOptions
 * @property-read array<string, array<string, string>> $tableActiveFilters
 * @property-read array<string, string> $tableExportHeaders
 * @property-read array<string, string> $tableSummary
 * @property-read int $tableTotalCount
 * @property-read int $selectedCount
 */
trait WithDataTable
{
    use WithGateProps, WithMetrics;

    /**
     * The chip that stands for both ends of the date range at once. Spelt with a
     * dash so it can never collide with a page's own filter property.
     */
    public const DATE_FILTER = 'date-range';

    /**
     * The chosen rows, by key. Strings because a checkbox value arrives as one, and
     * comparing them as strings is what keeps the box ticked after a re-render.
     *
     * @var array<int, string>
     */
    public array $selected = [];

    /** The header checkbox: every row on the page being shown. */
    public bool $selectPage = false;

    /**
     * Set once "select everything the filters match" has been used, so the bulk bar
     * can say so and the actions can work off the query rather than off the page.
     */
    public bool $selectMatching = false;

    /**
     * Rows unticked by hand while "everything the filters match" is on, by key.
     *
     * Kept as the exceptions rather than turning the selection back into a list:
     * somebody who chose eleven hundred rows and then dropped one meant eleven
     * hundred less one, and re-reading the whole result into `selected` to take a
     * row out of it is a list nobody wanted the app to hold.
     *
     * @var array<int, string>
     */
    public array $excluded = [];

    /**
     * Columns the account has put away, by key.
     *
     * Hidden rather than visible on purpose: a column added to a screen next month
     * should appear for everybody, including the accounts that have saved an
     * arrangement. Storing what is visible would hide every new column from exactly
     * the people who use the screen most.
     *
     * @var array<int, string>
     */
    public array $hiddenColumns = [];

    #[Url]
    public string $sortColumn = '';

    #[Url]
    public string $sortDirection = 'desc';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public string $exportFormat = 'csv';

    /**
     * The columns this export carries, by key. Seeded from the table on screen and
     * then whatever the account ticked in the export dialog.
     *
     * @var array<int, string>
     */
    public array $exportColumns = [];

    /**
     * The heading each exported column lands under, by key. A column is called one
     * thing on a dashboard and another in the spreadsheet somebody has to hand to
     * an accountant, and renaming it here beats renaming it in Excel afterwards.
     *
     * @var array<string, string>
     */
    public array $exportLabels = [];

    public function mountWithDataTable(): void
    {
        $this->hiddenColumns = (array) app(DashboardManagerService::class)
            ->get('column-manager', $this->tableKey(), []);

        // Seeded on the way in rather than only when the dialog opens, so export()
        // called straight off a button still has columns to carry.
        $this->resetExportColumns();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // COLUMNS

    /**
     * Every column with its settings resolved, in the order the page declared them.
     *
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function tableColumnList(): array
    {
        return collect($this->tableColumns())
            ->map(fn (array $column, string $key) => [
                'label' => $column['label'] ?? (string) str($key)->headline(),
                'sortable' => $column['sortable'] ?? false,
                // A locked column is the one that says which row this is. Hiding it
                // leaves a table of attributes belonging to nothing.
                'locked' => $column['locked'] ?? false,
                'summary' => $column['summary'] ?? null,
                'money' => $column['money'] ?? false,
                'exportable' => $column['exportable'] ?? true,
                'visible' => ($column['locked'] ?? false)
                    ? true
                    : ! \in_array($key, $this->hiddenColumns, true),
            ])
            ->all();
    }

    /**
     * One column's settings, named rather than spelled.
     *
     *     'amount' => $this->columnMaker('Amount', sortable: true, summary: 'sum', money: true),
     *
     * The array form still works and is what this returns — but a key typed wrong in
     * an array is silently ignored, and a column that quietly stopped summing is not
     * a thing anybody notices. Named arguments make the same mistake a fatal error,
     * and the editor lists what is on offer.
     *
     * @param  string|null  $label  What the header says. Defaults to the key, headlined
     * @param  bool  $sortable  Whether the header sorts
     * @param  bool  $locked  The column that says which row this is — never hidden
     * @param  'sum'|'avg'|'count'|null  $summary  The total under the table
     * @param  bool  $money  The value is minor units, so format it as money
     * @param  bool  $exportable  Whether it goes into an export
     * @return array<string, mixed>
     */
    protected function columnMaker(
        ?string $label = null,
        bool $sortable = false,
        bool $locked = false,
        ?string $summary = null,
        bool $money = false,
        bool $exportable = true,
    ): array {
        // Caught here rather than when the footer renders: a declaration is where the
        // typo is, and a page that boots and then breaks on one row of the footer is
        // the harder thing to place.
        if ($summary !== null && ! \in_array($summary, ['sum', 'avg', 'count'], true)) {
            throw new \InvalidArgumentException("Unknown column summary: {$summary}");
        }

        return [
            'label' => $label,
            'sortable' => $sortable,
            'locked' => $locked,
            'summary' => $summary,
            'money' => $money,
            'exportable' => $exportable,
        ];
    }

    /**
     * One filter's settings, named rather than spelled.
     *
     *     'status' => $this->filterMaker('Status', StatusTransaction::forSelect()),
     *     'search' => $this->filterMaker('Search'),
     *
     * The key is the **property** the filter is bound to, and the chip reads that
     * property to say what is currently on.
     *
     * @param  string|null  $label  What the chip calls it. Defaults to the key, headlined
     * @param  array<array-key, string>  $options  The same list the select is built from, so the
     *                                             chip says "Status: Confirmed" rather than "Status: 2"
     * @return array<string, mixed>
     */
    protected function filterMaker(?string $label = null, array $options = []): array
    {
        return [
            'label' => $label,
            'options' => $options,
        ];
    }

    public function isColumnVisible(string $column): bool
    {
        return (bool) data_get($this->tableColumnList, "{$column}.visible", true);
    }

    /**
     * Put a column away, or bring it back. Saved as it happens — an arrangement that
     * needed a Save button is one nobody keeps.
     */
    public function toggleColumn(string $column): void
    {
        $this->respondError(
            'That column cannot be hidden.',
            if: (bool) data_get($this->tableColumnList, "{$column}.locked", false),
        );

        $this->hiddenColumns = in_array($column, $this->hiddenColumns, true)
            ? array_values(array_diff($this->hiddenColumns, [$column]))
            : [...$this->hiddenColumns, $column];

        app(DashboardManagerService::class)->put('column-manager', $this->tableKey(), $this->hiddenColumns);

        unset($this->tableColumnList, $this->tableExportOptions, $this->tableExportHeaders);

        // An export follows the table by default, so a column put away goes out of
        // the file with it until somebody says otherwise in the export dialog.
        $this->resetExportColumns();
    }

    /**
     * Back to every column. The key is dropped rather than written as an empty list,
     * so an account that has never arranged the screen and one that has reset it read
     * back identically.
     */
    public function resetColumns(): void
    {
        $this->hiddenColumns = [];

        app(DashboardManagerService::class)->forget('column-manager', $this->tableKey());

        unset($this->tableColumnList, $this->tableExportOptions, $this->tableExportHeaders);

        $this->resetExportColumns();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // SORTING

    /**
     * Sort by a column, or turn the current sort around.
     */
    public function sortBy(string $column): void
    {
        $this->respondError(
            'That column cannot be sorted on.',
            if: ! data_get($this->tableColumnList, "{$column}.sortable", false),
        );

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    /**
     * The sort, applied. Called by the page's query so the ordering sits in the same
     * chain as its filters rather than being bolted on after them.
     *
     * A sort arriving from the URL is checked against the declared columns before it
     * reaches the database — the query string is not a safe place to name a column.
     */
    protected function applySort(Builder $query, ?string $fallbackColumn = null, string $fallbackDirection = 'desc'): Builder
    {
        $sortable = data_get($this->tableColumnList, "{$this->sortColumn}.sortable", false);

        if ($this->sortColumn === '' || ! $sortable) {
            return $fallbackColumn
                ? $query->orderBy($fallbackColumn, $fallbackDirection)
                : $query;
        }

        return $query->orderBy($this->sortColumn, $this->sortDirection === 'asc' ? 'asc' : 'desc');
    }

    /**
     * The date range, applied. Both ends are optional and each stands on its own, so
     * "everything since March" needs only the one field.
     */
    protected function applyDateRange(Builder $query, ?string $column = null): Builder
    {
        $column ??= $this->tableDateColumn();

        return $query
            ->when($this->dateFrom !== '', fn (Builder $inner) => $inner->whereDate($column, '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn (Builder $inner) => $inner->whereDate($column, '<=', $this->dateTo));
    }

    public function updatedDateFrom(): void
    {
        $this->afterFilterChange();
    }

    public function updatedDateTo(): void
    {
        $this->afterFilterChange();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // THE FILTERS, AS CHIPS

    /**
     * The filters currently narrowing the listing, ready to be shown and taken off
     * one at a time.
     *
     * Both ends of the date range are one chip: they were chosen together in one
     * field, and "From the 3rd" with no far end is half a sentence rather than a
     * filter of its own.
     *
     * @return array<string, array<string, string>>
     */
    #[Computed]
    public function tableActiveFilters(): array
    {
        $active = collect($this->tableFilters())
            ->filter(fn (array $filter, string $key) => (string) $this->{$key} !== '')
            ->map(fn (array $filter, string $key) => [
                'label' => $filter['label'] ?? (string) str($key)->headline(),
                // A select shows a label and files a value; the chip has to say the
                // label back, or a status filter reads as "Status: 2". Read as a
                // plain key rather than through data_get(), which would take a
                // search term with a full stop in it for a nested path.
                'value' => (string) ($filter['options'][$this->{$key}] ?? $this->{$key}),
            ])
            ->all();

        if ($range = $this->tableDateRangeLabel()) {
            $active[self::DATE_FILTER] = ['label' => $this->tableDateLabel(), 'value' => $range];
        }

        return $active;
    }

    /**
     * Take one filter off.
     */
    public function clearFilter(string $key): void
    {
        if ($key === self::DATE_FILTER) {
            $this->reset('dateFrom', 'dateTo');
        } else {
            // The key arrives over the wire, and reset() aimed at an arbitrary
            // property would put any of them back — the sort, the gate, the page.
            // Only what the screen declared as a filter can be cleared from here.
            $this->respondError(
                'That filter is not on this screen.',
                if: ! array_key_exists($key, $this->tableFilters()),
            );

            $this->reset($key);
        }

        $this->afterFilterChange();
    }

    /**
     * Every filter off at once.
     *
     * The sort and the column arrangement are left where they are: those are how
     * the account reads this screen rather than what it is being shown, and losing
     * them to a Clear button is not what anybody pressing it meant.
     */
    public function clearFilters(): void
    {
        if ($filters = array_keys($this->tableFilters())) {
            $this->reset($filters);
        }

        $this->reset('dateFrom', 'dateTo');

        $this->afterFilterChange();
    }

    /**
     * What every filter change does: let go of rows that are about to leave the
     * screen, and go back to the first page of what is left.
     */
    protected function afterFilterChange(): void
    {
        $this->clearSelection();
        $this->resetPage();

        unset($this->tableActiveFilters, $this->tableTotalCount);
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // SELECTION

    /**
     * Tick the rows of whichever page is now on screen, while "everything that
     * matches" is on.
     *
     * `selected` only ever holds the keys of the page being looked at — a selection
     * of eleven hundred rows is a count, not a list — so page two arrives with its
     * boxes empty and the header still ticked unless its own keys are put in before
     * it renders. That is the state the screen was showing: a bar saying twenty-four
     * rows are chosen above a page where none of them appear to be.
     */
    public function renderingWithDataTable(): void
    {
        if (! $this->selectMatching) {
            return;
        }

        $keys = $this->tablePageKeys();

        $this->selected = array_values(array_diff($keys, $this->excluded));
        $this->selectPage = $keys !== [] && $this->selected === $keys;
    }

    /**
     * The header checkbox. It governs the rows on screen and nothing else — the
     * whole result is what the bulk bar offers separately.
     */
    public function updatedSelectPage(bool $value): void
    {
        $keys = $this->tablePageKeys();

        if ($this->selectMatching) {
            // While the whole result is chosen, the header sets this page aside or
            // brings it back, and every other page stays chosen.
            $this->excluded = $value
                ? array_values(array_diff($this->excluded, $keys))
                : array_values(array_unique([...$this->excluded, ...$keys]));

            $this->selected = $value ? $keys : [];

            unset($this->selectedCount);

            return;
        }

        // Added to what is already chosen rather than replacing it: somebody paging
        // through and ticking each header means all of those pages, and a header
        // that started over would quietly drop the ones behind them.
        $this->selected = $value
            ? array_values(array_unique([...$this->selected, ...$keys]))
            : array_values(array_diff($this->selected, $keys));

        unset($this->selectedCount);
    }

    /**
     * A row checkbox, ticked or unticked by hand.
     *
     * The header box is derived from the rows rather than driving them: unticking one
     * row of twenty has to let go of "the whole page", and ticking the last outstanding
     * row has to take it back, or the header ends up saying something the table is not.
     * Without this the header stays ticked over a half-chosen page, which is also the
     * state that makes "select everything that matches" read as already done.
     */
    public function updatedSelected(): void
    {
        $keys = $this->tablePageKeys();

        if ($this->selectMatching) {
            // A row put back while the whole result is chosen is an exception to it
            // rather than the end of it. Falling back to the page on screen — which
            // is what this used to do — turned eleven hundred rows into twelve
            // without saying so, and the bar went on reading as a selection.
            $this->excluded = array_values(array_unique([
                ...array_diff($this->excluded, $keys),
                ...array_diff($keys, $this->selected),
            ]));
        }

        $this->selectPage = $keys !== [] && array_diff($keys, $this->selected) === [];

        unset($this->selectedCount);
    }

    /**
     * Every row the current filters match, however many pages that is. The keys are
     * not collected here — a bulk action reads the query again — so this stays cheap
     * on a result of any size.
     */
    public function selectAllMatching(): void
    {
        $this->selectMatching = true;
        $this->selectPage = true;
        $this->excluded = [];
        $this->selected = $this->tablePageKeys();

        unset($this->selectedCount);
    }

    public function clearSelection(): void
    {
        $this->reset('selected', 'selectPage', 'selectMatching', 'excluded');

        unset($this->selectedCount);
    }

    /**
     * How many rows the current filters match. Also what "Select all N" offers, so
     * the number on the link is the number the bar will read once it is used.
     */
    #[Computed]
    public function tableTotalCount(): int
    {
        return $this->tableQuery()->toBase()->getCountForPagination();
    }

    /**
     * How many rows an action is about to touch.
     */
    #[Computed]
    public function selectedCount(): int
    {
        return $this->selectMatching
            ? max($this->tableTotalCount - count($this->excluded), 0)
            : count($this->selected);
    }

    /**
     * The rows an action works on: the whole filtered result less anything put back
     * by hand once "select all matching" has been used, and the ticked keys
     * otherwise.
     */
    protected function selectionQuery(): Builder
    {
        if (! $this->selectMatching) {
            return $this->tableQuery()->whereKey($this->selected);
        }

        return $this->tableQuery()
            ->when($this->excluded !== [], fn (Builder $query) => $query->whereKeyNot($this->excluded));
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // BULK ACTIONS

    public function confirmBulkDelete(): void
    {
        $this->respondError('Deleting from here is not available on this screen.', if: ! $this->tableDeletable());

        $this->checkGate(GateAccessEnum::FULL, 'You do not have delete access in this area.');

        $this->respondError('Choose the rows to delete first.', if: $this->selectedCount === 0);

        Flux::modal('bulkDeleteModal')->show();
    }

    public function bulkDelete(): bool
    {
        $this->respondError('Deleting from here is not available on this screen.', if: ! $this->tableDeletable());

        $this->checkGate(GateAccessEnum::FULL, 'You do not have delete access in this area.');

        $count = $this->selectedCount;

        $this->respondError('Choose the rows to delete first.', if: $count === 0);

        $deleted = 0;
        $blocked = [];

        // Deleted through the models rather than with one delete statement: a model
        // with a delete guard, a soft delete or children to cascade has to be given
        // the chance to run, and a bulk button is exactly where that gets forgotten.
        $this->selectionQuery()->get()->each(function (Model $item) use (&$deleted, &$blocked) {
            if ($reason = $this->tableDeleteBlocked($item)) {
                $blocked[] = $reason;

                return;
            }

            $item->delete();
            $deleted++;
        });

        $this->respondError(
            'Nothing was deleted. '.reset($blocked),
            if: $deleted === 0 && $blocked !== [],
        );

        app(ActivityLogService::class)->logActivity(
            $this->tableDeleteAction(),
            ' '.number_format($deleted).' '.$this->tableSubject(),
        );

        Flux::modal('bulkDeleteModal')->close();

        $this->clearSelection();
        $this->afterBulkAction();

        $message = number_format($deleted).' '.$this->tableSubject().' deleted.';

        // A row that was refused has to be said out loud. A count that quietly comes
        // up short reads as a bug, and the administrator would go looking for one.
        if ($blocked !== []) {
            $message .= ' '.count($blocked).' left alone: '.reset($blocked);
        }

        return $this->respondSuccess($message);
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // EXPORT

    /**
     * Every column that can go into a file, whether or not it is on screen.
     *
     * The dialog offers the hidden ones as well: a column put away because it makes
     * the table too wide is still a column somebody wants in the spreadsheet.
     *
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function tableExportOptions(): array
    {
        return collect($this->tableColumnList)
            ->filter(fn (array $column) => $column['exportable'])
            ->all();
    }

    /**
     * Open the dialog that says which columns go into the file and what each one is
     * called in it.
     *
     * The choice is re-seeded from the table every time it opens, so the dialog
     * always starts as the screen behind it. A rename belongs to the export being
     * taken rather than to the screen — the account that wants "Member" to read
     * "Full name" this once should not find it renamed on the table next week.
     */
    public function openExportModal(): void
    {
        $this->checkGate(GateAccessEnum::VIEW, 'You do not have access to export from this area.');

        $this->resetExportColumns();

        Flux::modal('exportModal')->show();
    }

    /**
     * Back to the table as it stands: the columns on screen, under the headings
     * they carry there.
     */
    public function resetExportColumns(): void
    {
        $this->exportColumns = collect($this->tableExportOptions)
            ->filter(fn (array $column) => $column['visible'])
            ->keys()
            ->all();

        $this->exportLabels = collect($this->tableExportOptions)
            ->map(fn (array $column) => $column['label'])
            ->all();

        unset($this->tableExportHeaders);
    }

    /**
     * The columns an export carries, each under the heading it was given.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function tableExportHeaders(): array
    {
        return collect($this->tableExportOptions)
            // Filtered out of the declared list rather than read off exportColumns,
            // so the file keeps the screen's column order however the boxes were
            // ticked. A spreadsheet whose columns moved about is not the table
            // anybody asked for.
            ->filter(fn (array $column, string $key) => in_array($key, $this->exportColumns, true))
            ->map(fn (array $column, string $key) => $this->exportHeading($key) ?: $column['label'])
            ->all();
    }

    /**
     * One heading as the account typed it: trimmed, and cut to a length a
     * spreadsheet column can still show.
     */
    private function exportHeading(string $column): string
    {
        return mb_substr(trim((string) ($this->exportLabels[$column] ?? '')), 0, 60);
    }

    /**
     * Hand the listing over as a file. Nothing ticked means the whole filtered result,
     * which is what somebody pressing Export on an untouched table means.
     */
    public function export(?string $format = null): ?Response
    {
        $this->checkGate(GateAccessEnum::VIEW, 'You do not have access to export from this area.');

        $format = $format ?: $this->exportFormat;

        // The format is a bound property as well as an argument, so it arrives from
        // the wire. An unsupported one is somebody's dialog, not a broken screen.
        $this->respondError(
            'That export format is not available.',
            if: ! in_array($format, ExportService::FORMATS, true),
        );

        $headers = $this->tableExportHeaders;

        $this->respondError('Choose at least one column to export.', if: $headers === []);

        // Nothing ticked at all means the whole filtered result, which is what
        // somebody pressing Export on an untouched table means.
        $query = ($this->selected === [] && ! $this->selectMatching)
            ? $this->tableQuery()
            : $this->selectionQuery();

        $rows = $query
            ->get()
            ->map(fn (Model $item) => collect($headers)
                ->keys()
                ->mapWithKeys(fn (string $key) => [$key => $this->tableExportValue($item, $key)])
                ->all());

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::EXPORT,
            ' '.number_format($rows->count()).' '.$this->tableSubject().' as '.mb_strtoupper($format),
        );

        try {
            $response = app(ExportService::class)->download(
                $this->tableSubject(),
                $headers,
                $rows,
                $format,
                ['heading' => kBreakText($this->tableSubject()), 'subheading' => $this->tableExportSubheading()],
            );
        } catch (\RuntimeException $exception) {
            // A PDF driver that is not installed is a setting nobody has made yet
            // rather than a broken screen.
            $this->respondError($exception->getMessage(), if: true);

            return null;
        }

        Flux::modal('exportModal')->close();

        $this->respondSuccess(number_format($rows->count()).' '.$this->tableSubject().' exported.');

        return $response;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // SUMMARY

    /**
     * The totals under the table, already formatted. Read from the whole filtered
     * result rather than from the page on screen: a total of what page two happens to
     * hold is not a total of anything.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function tableSummary(): array
    {
        return collect($this->tableColumnList)
            ->filter(fn (array $column) => $column['summary'] !== null)
            ->map(function (array $column, string $key) {
                $query = $this->tableQuery();

                $value = match ($column['summary']) {
                    'sum' => (float) $query->sum($key),
                    'avg' => (float) $query->avg($key),
                    'count' => (float) $query->count(),
                    default => throw new \InvalidArgumentException("Unknown column summary: {$column['summary']}"),
                };

                // Money is stored in minor units, so it is divided exactly once, here.
                return $column['money']
                    ? kMoneyFormat($value / 100, decodeHtml: true)
                    : number_format($value);
            })
            ->all();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // WHAT THE PAGE SUPPLIES

    /**
     * The columns, in the order they are shown. Build each with `columnMaker()`,
     * which names the settings rather than leaving them to be spelled.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected function tableColumns(): array;

    /**
     * The listing's query, filters and all, without its ordering or its pagination.
     * The bulk actions, the summary and the export all run it again, so it has to be
     * the same question the table on screen is asking.
     */
    abstract protected function tableQuery(): Builder;

    /**
     * The rows the page has on screen — its paginator, or its collection where the
     * whole listing fits on one page:
     *
     *     protected function tableRows(): iterable
     *     {
     *         return $this->transactions;
     *     }
     *
     * Abstract rather than derived, because the trait cannot know a page's ordering
     * and a guess at it silently ticks the wrong rows.
     *
     * @return iterable<int, Model>
     */
    abstract protected function tableRows(): iterable;

    /**
     * Where this screen's arrangement is filed.
     *
     * The component's own name — "pages::admin.transactions" — rather than the route.
     * A column is toggled over the wire, and that request arrives on livewire.update
     * rather than on the page's route, so a route-derived key would save the
     * arrangement somewhere mount() never looks for it again.
     */
    protected function tableKey(): string
    {
        return $this->getName();
    }

    /**
     * What the rows are, in the plural. Used in the export filename, the audit entry
     * and every toast.
     */
    protected function tableSubject(): string
    {
        return 'records';
    }

    /**
     * The column a date filter narrows on.
     */
    protected function tableDateColumn(): string
    {
        return 'created_at';
    }

    /**
     * What the date range is called on this screen. The chip says it back, so
     * "Joined between" reads as "Joined: 1st to 5th" rather than as "Date".
     */
    protected function tableDateLabel(): string
    {
        return 'Date';
    }

    /**
     * The filters this screen offers, by property name, so they can be shown as
     * chips and taken off one at a time:
     *
     *     protected function tableFilters(): array
     *     {
     *         return [
     *             'search' => $this->filterMaker('Search'),
     *             'accountStatus' => $this->filterMaker('Status', StatusUser::forSelect()),
     *         ];
     *     }
     *
     * Build each with `filterMaker()`. Its `options` is the same list the select is
     * built from — the chip needs it to say the label back rather than the value that
     * was filed. Nothing is declared here on a screen with no filters, and the chip
     * bar renders nothing.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function tableFilters(): array
    {
        return [];
    }

    /**
     * Whether this screen offers bulk delete at all. Off unless a page says otherwise:
     * a ledger has no delete, and a button that appears by default is one somebody
     * ships without meaning to.
     */
    protected function tableDeletable(): bool
    {
        return false;
    }

    protected function tableDeleteAction(): ActivityActionEnum
    {
        return ActivityActionEnum::DELETE;
    }

    /**
     * Why this particular row cannot go, or null if it can.
     *
     * The guard a single-row delete does by hand has to hold here too, or a bulk
     * button becomes the way round it — a category with posts filed under it is
     * refused one at a time and would otherwise sail through in a selection of
     * twenty. Blocked rows are left where they are and named in the toast.
     */
    protected function tableDeleteBlocked(Model $item): ?string
    {
        return null;
    }

    /**
     * One cell on the way into a file. The default reads the column off the model,
     * which is right for a plain column; a page overrides it for the ones that are a
     * relationship, an enum label or a formatted amount.
     */
    protected function tableExportValue(Model $item, string $column): mixed
    {
        return $this->defaultExportValue($item, $column);
    }

    /**
     * The default reading of a cell, kept separate so an override can fall back to it
     * for the columns it has nothing special to say about:
     *
     *     return match ($column) {
     *         'user' => $item->user?->name,
     *         default => $this->defaultExportValue($item, $column),
     *     };
     */
    protected function defaultExportValue(Model $item, string $column): mixed
    {
        $value = data_get($item, $column);

        return $value instanceof \BackedEnum && method_exists($value, 'label')
            ? $value->label()
            : $value;
    }

    /**
     * The date range in words, or null where neither end has been set. Read both by
     * the chip that takes it off and by the line under an exported PDF's heading.
     */
    protected function tableDateRangeLabel(): ?string
    {
        return match (true) {
            $this->dateFrom !== '' && $this->dateTo !== '' => "{$this->dateFrom} to {$this->dateTo}",
            $this->dateFrom !== '' => "From {$this->dateFrom}",
            $this->dateTo !== '' => "Up to {$this->dateTo}",
            default => null,
        };
    }

    /**
     * The line under the heading on an exported PDF. Says what the file is a view of,
     * because a filtered export looks like a complete one once it has left the screen.
     */
    protected function tableExportSubheading(): ?string
    {
        return $this->tableDateRangeLabel();
    }

    /**
     * The keys on the page being shown, read off what the page actually rendered.
     *
     * Never a second query. tableQuery() carries the filters but not the ordering —
     * each page applies its own sort and fallback in the computed it paginates — so
     * running it again with a forPage() offset asks the database for "the first
     * twenty" of an unordered result. That is a different twenty rows from the ones
     * on screen, which is how the header checkbox came to tick rows nobody could see
     * and leave rows in plain sight untouched.
     *
     * @return array<int, string>
     */
    protected function tablePageKeys(): array
    {
        $keys = [];

        // Iterated rather than collect()'d. A paginator is Arrayable, and collect()
        // reads that before it reads Traversable — so it would take toArray(), which
        // is the page metadata with the rows buried in a 'data' key, not the rows.
        foreach ($this->tableRows() as $item) {
            $keys[] = (string) $item->getKey();
        }

        return $keys;
    }

    protected function tablePerPage(): int
    {
        return 20;
    }

    /**
     * Refresh whatever the host page renders once a bulk action finished. Pages
     * override this.
     */
    protected function afterBulkAction(): void {}
}
