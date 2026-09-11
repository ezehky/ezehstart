<?php

namespace App\Services;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * A listing, as a file the administrator can keep.
 *
 * Three formats and one shape: a set of columns and the rows under them. CSV and XLSX
 * go through SpreadsheetService; a PDF is the same rows rendered through a print view,
 * because a PDF is a document rather than a sheet and wants a heading and a date on it.
 *
 * The page side — which rows, which columns, and the gate over them — is WithDataTable.
 */
#[Singleton]
class ExportService
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    // Getters

    /**
     * The labels a format picker shows.
     *
     * @return array<string, string>
     */
    public function formatOptions(): array
    {
        return [
            'csv' => 'CSV (.csv)',
            'xlsx' => 'Excel (.xlsx)',
            'pdf' => 'PDF (.pdf)',
        ];
    }

    // Actions

    /**
     * Build the download.
     *
     * @param  array<string, string>  $headers  Column key => heading
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta  Heading and subheading for the PDF page
     */
    public function download(string $name, array $headers, iterable $rows, string $format = 'csv', array $meta = []): Response
    {
        $format = mb_strtolower($format);

        if (! in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException("Unsupported export format: {$format}");
        }

        $filename = $this->filename($name, $format);

        if ($format === 'pdf') {
            return $this->pdf($filename, $headers, $rows, $meta);
        }

        // Written to a real file and sent from there rather than streamed: openspout
        // writes a zip archive for XLSX, which has to seek, and a streamed response
        // cannot. The file is deleted the moment the response has been sent.
        $path = tempnam(sys_get_temp_dir(), 'export').'.'.$format;

        app(SpreadsheetService::class)->write($path, $headers, $rows, $format);

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    // Tools

    /**
     * The name the file lands under: the screen it came from and the day it was taken.
     */
    public function filename(string $name, string $format): string
    {
        return kSlug($name).'-'.now()->format('Y-m-d').'.'.$format;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     */
    private function pdf(string $filename, array $headers, iterable $rows, array $meta): Response
    {
        // A driver is the developer's choice — dompdf needs nothing installed beyond
        // its own package, browsershot needs Node and Chrome — and none of them ships
        // with the kit. Whichever is configured, a missing one must read as a setting
        // that has not been made rather than as a crash on a download button.
        try {
            return Pdf::view('exports.table', [
                'headers' => $headers,
                'rows' => $rows instanceof Collection ? $rows : collect($rows),
                'heading' => $meta['heading'] ?? 'Export',
                'subheading' => $meta['subheading'] ?? null,
            ])
                ->landscape()
                ->download($filename)
                ->toResponse(request());
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'PDF export is not set up on this install: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
