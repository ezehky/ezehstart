<?php

namespace App\Services;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Spreadsheets in and out — the file half of every import screen and of every export.
 *
 * CSV and XLSX arrive as the same thing here: a header row that names the columns and
 * rows keyed by those names. A page importing accounts should not care which of the
 * two somebody exported from, and openspout streams either one a row at a time rather
 * than loading the workbook into memory.
 *
 * The page side of an import — the upload field, the gate, the per-row write and the
 * toast — is WithFileImport. Turning a listing into a download, in any of the three
 * formats, is ExportService, which calls write() here for two of them.
 */
#[Singleton]
class SpreadsheetService
{
    /**
     * What an upload field accepts. XLS is deliberately absent: the old binary format
     * is a different reader and nothing exports it by choice any more.
     */
    public const FORMATS = ['csv', 'txt', 'xlsx'];

    // Getters

    /**
     * Every row of the first sheet, keyed by the header row.
     *
     * Headers are snake-cased so they land on the column names they came from —
     * "Full Name" reads back as `full_name`. Rows short of the header are padded and
     * rows past it are dropped, so a caller can index a key without checking it
     * exists first.
     *
     * @param  int  $limit  Stop after this many data rows. The caller asks for one
     *                      more than it will allow, so an over-long file is visible
     *                      rather than silently cut short.
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(string $path, ?string $format = null, int $limit = 2000): Collection
    {
        $reader = $this->reader($format ?? pathinfo($path, PATHINFO_EXTENSION));
        $reader->open($path);

        $headers = [];
        $rows = collect();

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = array_map($this->value(...), $row->toArray());

                // A blank line in the middle of a file is a typing artefact, not a
                // record, and it must not become a row of empty strings.
                if ($cells === [] || collect($cells)->filter(fn ($value) => $value !== '' && $value !== null)->isEmpty()) {
                    continue;
                }

                if ($headers === []) {
                    $headers = $this->headerKeys($cells);

                    continue;
                }

                $rows->push($this->combine($headers, $cells));

                if ($rows->count() >= $limit) {
                    break 2;
                }
            }

            // Only the first sheet. A workbook with a second one is a lookup table or
            // somebody's notes, and importing it would be a guess.
            break;
        }

        $reader->close();

        return $rows;
    }

    /**
     * The column names a file carries, without reading the rest of it. For the screen
     * that shows what it is about to import before it commits.
     *
     * @return array<int, string>
     */
    public function headers(string $path, ?string $format = null): array
    {
        $reader = $this->reader($format ?? pathinfo($path, PATHINFO_EXTENSION));
        $reader->open($path);

        $headers = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $headers = $this->headerKeys(array_map($this->value(...), $row->toArray()));

                break 2;
            }
        }

        $reader->close();

        return $headers;
    }

    // Actions

    /**
     * Write a sheet to a path, and return the path.
     *
     * The header row is written first and every row after it is taken in the header's
     * order, so a caller that hands rows keyed differently — or short of a column —
     * still gets a square sheet.
     *
     * @param  array<string, string>  $headers  Column key => heading
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function write(string $path, array $headers, iterable $rows, ?string $format = null): string
    {
        $writer = $this->writer($format ?? pathinfo($path, PATHINFO_EXTENSION));
        $writer->openToFile($path);

        $writer->addRow(Row::fromValues(array_values($headers)));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(
                collect($headers)
                    ->keys()
                    ->map(fn (string $key) => $this->cell($row[$key] ?? ''))
                    ->all()
            ));
        }

        $writer->close();

        return $path;
    }

    // Tools

    /**
     * The reader for a file extension.
     */
    protected function reader(string $format): ReaderInterface
    {
        return match (mb_strtolower($format)) {
            'xlsx' => new XlsxReader,
            'csv', 'txt' => new CsvReader,
            default => throw new \InvalidArgumentException("Unsupported spreadsheet format: {$format}"),
        };
    }

    /**
     * One cell, as something a database column will take. A date cell comes back as a
     * DateTimeImmutable and a number as int|float; both are left as they are, because
     * the page knows what its own column wants better than this does.
     */
    protected function value(mixed $cell): mixed
    {
        return is_string($cell) ? trim($cell) : $cell;
    }

    /**
     * Header cells as array keys. A column with no heading keeps its position, so a
     * file with a stray empty column does not shift every key after it.
     *
     * @param  array<int, mixed>  $cells
     * @return array<int, string>
     */
    protected function headerKeys(array $cells): array
    {
        return collect($cells)
            ->map(fn (mixed $cell, int $index) => filled($cell)
                ? (string) str((string) $cell)->squish()->snake()
                : "column_{$index}")
            ->values()
            ->toArray();
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, mixed>  $cells
     * @return array<string, mixed>
     */
    protected function combine(array $headers, array $cells): array
    {
        $cells = array_slice(array_pad($cells, count($headers), ''), 0, count($headers));

        return array_combine($headers, $cells);
    }

    /**
     * The writer for a file extension.
     */
    protected function writer(string $format): WriterInterface
    {
        return match (mb_strtolower($format)) {
            'xlsx' => new XlsxWriter,
            'csv', 'txt' => new CsvWriter,
            default => throw new \InvalidArgumentException("Unsupported spreadsheet format: {$format}"),
        };
    }

    /**
     * One cell on the way out. openspout takes scalars and dates; anything else — an
     * enum, a model, an array of tag names — is the caller's own shape and is written
     * as the string it renders to rather than refused.
     */
    protected function cell(mixed $value): string|int|float|bool|\DateTimeInterface|null
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface,
            is_scalar($value),
            $value === null => $value,
            is_array($value) => implode(', ', $value),
            default => (string) $value,
        };
    }
}
