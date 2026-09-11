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
 *             'reference' => ['label' => 'Reference', 'locked' => true, 'sortable' => true],
 *             'amount' => ['label' => 'Amount', 'sortable' => true, 'summary' => 'sum', 'money' => true],
 *             'created_at' => ['label' => 'Date', 'sortable' => true],
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
 * @property-read array<string, string> $tableExportHeaders
 * @property-read array<string, string> $tableSummary
 * @property-read int $selectedCount
 */
trait WithDataTable
{
    use WithGateProps;

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
     * Whether an export takes the columns on screen or all of them. Off by default:
     * the spreadsheet somebody wants is usually the table they are looking at.
     */
    public bool $exportAllColumns = false;

    public function mountWithDataTable(): void
    {
        $this->hiddenColumns = (array) app(DashboardManagerService::class)
            ->get('column-manager', $this->tableKey(), []);
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
                    : ! in_array($key, $this->hiddenColumns, true),
            ])
            ->all();
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

        unset($this->tableColumnList, $this->tableExportHeaders);
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

        unset($this->tableColumnList, $this->tableExportHeaders);
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
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // SELECTION

    /**
     * The header checkbox. Ticking it takes the rows on screen — not the whole result,
     * which is what the bulk bar offers separately once this is on.
     */
    public function updatedSelectPage(bool $value): void
    {
        $this->selectMatching = false;

        $this->selected = $value ? $this->tablePageKeys() : [];

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
        // Hand-picking rows is the opposite of "everything the filters match", so the
        // wider selection is dropped the moment one row is touched.
        $this->selectMatching = false;

        $keys = $this->tablePageKeys();

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
        $this->selected = $this->tablePageKeys();
    }

    public function clearSelection(): void
    {
        $this->reset('selected', 'selectPage', 'selectMatching');

        unset($this->selectedCount);
    }

    /**
     * How many rows an action is about to touch.
     */
    #[Computed]
    public function selectedCount(): int
    {
        return $this->selectMatching
            ? $this->tableQuery()->toBase()->getCountForPagination()
            : count($this->selected);
    }

    /**
     * The rows an action works on: the whole filtered result once "select all
     * matching" has been used, and the ticked keys otherwise.
     */
    protected function selectionQuery(): Builder
    {
        return $this->selectMatching
            ? $this->tableQuery()
            : $this->tableQuery()->whereKey($this->selected);
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
     * The columns an export carries: what is on screen, or everything the page
     * declared where the account asked for all of it.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function tableExportHeaders(): array
    {
        return collect($this->tableColumnList)
            ->filter(fn (array $column) => $column['exportable'])
            ->filter(fn (array $column) => $this->exportAllColumns || $column['visible'])
            ->map(fn (array $column) => $column['label'])
            ->all();
    }

    /**
     * Hand the listing over as a file. Nothing ticked means the whole filtered result,
     * which is what somebody pressing Export on an untouched table means.
     */
    public function export(?string $format = null): ?Response
    {
        $this->checkGate(GateAccessEnum::VIEW, 'You do not have access to export from this area.');

        $format = $format ?: $this->exportFormat;

        $headers = $this->tableExportHeaders;

        $this->respondError('Choose at least one column to export.', if: $headers === []);

        $query = ($this->selected === [] || $this->selectMatching)
            ? $this->tableQuery()
            : $this->tableQuery()->whereKey($this->selected);

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
     * The columns, in the order they are shown. Each carries its label and, where it
     * is not a plain column, any of: sortable, locked, summary, money, exportable.
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
     * The line under the heading on an exported PDF. Says what the file is a view of,
     * because a filtered export looks like a complete one once it has left the screen.
     */
    protected function tableExportSubheading(): ?string
    {
        return match (true) {
            $this->dateFrom !== '' && $this->dateTo !== '' => "{$this->dateFrom} to {$this->dateTo}",
            $this->dateFrom !== '' => "From {$this->dateFrom}",
            $this->dateTo !== '' => "Up to {$this->dateTo}",
            default => null,
        };
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
