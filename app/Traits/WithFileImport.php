<?php

namespace App\Traits;

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Services\ActivityLogService;
use App\Services\SpreadsheetService;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;

/**
 * The import half of a listing: a CSV or XLSX goes up, and the page writes one row at
 * a time.
 *
 * The file work belongs to SpreadsheetService — this owns what a screen has to do
 * around it: the upload field, the gate, the guard rails on size and shape, the audit
 * entry, and what to say afterwards.
 *
 * A bad row does not stop the run. Half a file rejected because line 40 has a typo is
 * an import nobody finishes, so each row is tried on its own and the ones that failed
 * come back named in $importSkipped for the screen to show. Where a file genuinely has
 * to land whole, wrap importRow() calls in a transaction on the page.
 *
 *     use WithFileImport, WithPagination;
 *
 *     protected function importColumns(): array
 *     {
 *         return ['name'];
 *     }
 *
 *     protected function importRow(array $row, int $line): bool
 *     {
 *         if (Tag::query()->where('slug', kSlug($row['name']))->exists()) {
 *             return false;
 *         }
 *
 *         Tag::query()->create(['name' => $row['name'], 'slug' => kSlug($row['name'])]);
 *
 *         return true;
 *     }
 */
trait WithFileImport
{
    use WithFileUploads, WithGateProps;

    public mixed $importFile = null;

    /**
     * The rows the file could not use, already written out as sentences. Shown on the
     * screen after a run — an import that quietly dropped four rows is worse than one
     * that says which four.
     *
     * @var array<int, string>
     */
    public array $importSkipped = [];

    public int $importedCount = 0;

    public function import(): bool
    {
        // The upload button is hidden from an account that cannot use it, which is a
        // courtesy. This is the boundary.
        $this->checkGate(GateAccessEnum::CREATE, 'You do not have access to import into this area.');

        $this->validate(['importFile' => $this->importFileRule()]);

        $limit = $this->importRowLimit();

        // One more than the limit, so a file over it is visible here rather than
        // being silently cut short by the reader.
        $rows = app(SpreadsheetService::class)->rows(
            $this->importFile->getRealPath(),
            $this->importFile->getClientOriginalExtension(),
            $limit + 1,
        );

        $this->respondError('That file has no rows in it.', if: $rows->isEmpty());

        $this->respondError(
            'An import is limited to '.number_format($limit).' rows. Split the file and send it up in parts.',
            if: $rows->count() > $limit,
        );

        // Named against the header row rather than the first record, so a file whose
        // first line happens to be blank in one column is still checked properly.
        $missing = array_diff($this->importColumns(), array_keys($rows->first()));

        $this->respondError(
            'That file is missing a column it needs: '.implode(', ', $missing).'.',
            if: $missing !== [],
        );

        $this->importedCount = 0;
        $this->importSkipped = [];

        foreach ($rows as $index => $row) {
            // The header row, and a spreadsheet counts from one — so the second line
            // of the file is the first record.
            $line = $index + 2;

            try {
                if ($this->importRow($row, $line)) {
                    $this->importedCount++;
                }
            } catch (\Throwable $exception) {
                $this->importSkipped[] = "Line {$line}: ".$this->importFailure($exception);
            }
        }

        app(ActivityLogService::class)->logActivity(
            $this->importAction(),
            $this->importDescription(),
        );

        $this->reset('importFile');
        $this->afterImport();

        return $this->respondSuccess($this->importMessage());
    }

    /**
     * What the upload field accepts. Public so a page can fold it into its own
     * rules() where the import shares a form with something else.
     *
     * @return array<int, string>
     */
    public function importFileRule(): array
    {
        return [
            'required',
            'file',
            'mimes:'.implode(',', SpreadsheetService::FORMATS),
            'max:'.$this->importMaxSize(),
        ];
    }

    /**
     * Write one row. Return false for a row that was understood but not needed — a
     * duplicate — and throw for one that could not be used at all; the message is
     * what the screen shows against that line.
     *
     * @param  array<string, mixed>  $row
     */
    abstract protected function importRow(array $row, int $line): bool;

    /**
     * Columns the file must carry, in the snake_case the reader hands back. Anything
     * else in the file is passed through to importRow() untouched.
     *
     * @return array<int, string>
     */
    protected function importColumns(): array
    {
        return [];
    }

    /**
     * How many rows one upload may carry. This runs in a web request, so the ceiling
     * is about how long an administrator will sit on a spinner, not about memory.
     */
    protected function importRowLimit(): int
    {
        return 2000;
    }

    /**
     * The upload ceiling, in kilobytes.
     */
    protected function importMaxSize(): int
    {
        return 2048;
    }

    /**
     * What the audit trail calls the run. A listing with an import case of its own
     * overrides this.
     */
    protected function importAction(): ActivityActionEnum
    {
        return ActivityActionEnum::IMPORT;
    }

    /**
     * What the screen is importing, in the plural — "tags", "members". Used in the
     * log entry and the toast.
     */
    protected function importSubject(): string
    {
        return 'records';
    }

    /**
     * The audit entry. The count and the skipped total both belong in it: an import
     * that took 6 of 10 rows is a different event from one that took all ten.
     */
    protected function importDescription(): string
    {
        $description = $this->importedCount.' '.$this->importSubject();

        return $this->importSkipped === []
            ? $description
            : $description.' ('.count($this->importSkipped).' skipped)';
    }

    /**
     * The toast.
     */
    protected function importMessage(): string
    {
        if ($this->importedCount === 0) {
            return 'Nothing in that file was new.';
        }

        return $this->importedCount.' '.str($this->importSubject())->singular()->plural($this->importedCount).' imported.';
    }

    /**
     * A row failure as one readable line. A page that refuses a row with
     * respondError() throws a ValidationException, whose own message says nothing —
     * the sentence the page wrote is inside it.
     */
    protected function importFailure(\Throwable $exception): string
    {
        if (! $exception instanceof ValidationException) {
            return $exception->getMessage();
        }

        return collect($exception->errors())->flatten()->first() ?? 'The row could not be used.';
    }

    /**
     * Refresh whatever the host page renders once an import finished. Pages override this.
     */
    protected function afterImport(): void {}
}
