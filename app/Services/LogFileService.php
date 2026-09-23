<?php

namespace App\Services;

use App\Enums\LogChannelEnum;
use App\Enums\LogLevelEnum;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reads the application's log files for the admin log screen.
 *
 * Modelled on opcodesio/log-viewer, without its index: a file is parsed on each read,
 * so only its tail is taken. A log nobody has cleared can run to gigabytes, and the
 * entries anybody opens this screen for are the recent ones.
 */
#[Singleton]
class LogFileService
{
    /**
     * How much of the end of a file is read. Everything older is left on disk and
     * reachable by downloading the file.
     */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * The start of a Laravel log entry: `[2026-09-23 10:00:00] local.ERROR: `. The
     * timestamp may carry microseconds and an offset, and the environment is optional
     * — the same allowances log-viewer makes for Monolog's line formatter.
     */
    private const ENTRY_PATTERN = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\]\s(?:(\w+)\.)?(\w+):\s?/m';

    /**
     * The files a channel has written, newest first.
     *
     * A daily channel writes `laravel-2026-09-23.log` beside the `laravel.log` a
     * single channel writes, so both shapes are matched on the file's stem.
     *
     * @return Collection<int, array{name: string, path: string, size: int, modified: Carbon}>
     */
    public function files(LogChannelEnum $channel): Collection
    {
        $path = $channel->path();
        $stem = pathinfo($path, PATHINFO_FILENAME);

        return collect(glob(\dirname($path).DIRECTORY_SEPARATOR.$stem.'*.log') ?: [])
            // "laravel*.log" would also take in a channel named "laravel-mail", so the
            // suffix is held to a date, the only thing a daily channel adds.
            ->filter(fn (string $file) => preg_match('/^'.preg_quote($stem, '/').'(-\d{4}-\d{2}-\d{2})?\.log$/', basename($file)))
            ->map(fn (string $file) => [
                'name' => basename($file),
                'path' => $file,
                'size' => (int) filesize($file),
                'modified' => Carbon::createFromTimestamp((int) filemtime($file)),
            ])
            ->sortByDesc('modified')
            ->values();
    }

    /**
     * One of a channel's files by name, or null.
     *
     * The name arrives from the browser, so it is only ever looked up in files() and
     * never joined onto a directory — "../../.env" matches nothing and goes nowhere.
     *
     * @return array{name: string, path: string, size: int, modified: Carbon}|null
     */
    public function find(LogChannelEnum $channel, ?string $name = null): ?array
    {
        $files = $this->files($channel);

        return $name ? $files->firstWhere('name', $name) : $files->first();
    }

    /**
     * The entries in a file, newest first, narrowed by level and a search.
     *
     * @return Collection<int, array{id: string, datetime: ?Carbon, environment: ?string, level: ?LogLevelEnum, message: string, context: string, mail: ?array}>
     */
    public function entries(string $path, ?LogLevelEnum $level = null, string $search = ''): Collection
    {
        return $this->parse($this->tail($path))
            ->when($level, fn (Collection $entries) => $entries->filter(fn (array $entry) => $entry['level'] === $level))
            ->when($search !== '', fn (Collection $entries) => $entries->filter(
                fn (array $entry) => str_contains(mb_strtolower($entry['message'].' '.$entry['context']), mb_strtolower($search))
            ))
            ->reverse()
            ->values();
    }

    /**
     * One entry in a file by the id parse() gave it, or null once it has gone —
     * the file was cleared, or rotated past the part of it that is read.
     *
     * @return array{id: string, datetime: ?Carbon, environment: ?string, level: ?LogLevelEnum, message: string, context: string, mail: ?array}|null
     */
    public function entry(string $path, string $id): ?array
    {
        return $this->parse($this->tail($path))->firstWhere('id', $id);
    }

    /**
     * The readable parts of an email the "log" mailer wrote: its headers, and the
     * HTML and plain-text bodies decoded out of their transfer encoding.
     *
     * The log mailer writes the raw MIME message as the entry, so without this the
     * screen shows quoted-printable soup ("=3D", soft line breaks) instead of the
     * email somebody was trying to check.
     *
     * @param  array{message: string, context: string, mail: ?array}  $entry
     * @return array{headers: array<string, string>, html: ?string, text: ?string}|null
     */
    public function mail(array $entry): ?array
    {
        if (! $entry['mail']) {
            return null;
        }

        [$headers, $body] = $this->splitMimePart($entry['message']."\n".$entry['context']);

        $parts = ['html' => null, 'text' => null];
        $this->collectMimeBodies($headers, $body, $parts);

        return ['headers' => $headers, ...$parts];
    }

    /**
     * How many entries each level holds, for the counts on the level filter.
     *
     * @param  Collection<int, array{level: ?LogLevelEnum}>  $entries
     * @return array<string, int>
     */
    public function levelCounts(Collection $entries): array
    {
        $counts = $entries->countBy(fn (array $entry) => $entry['level']?->value);

        return collect(LogLevelEnum::cases())
            ->mapWithKeys(fn (LogLevelEnum $level) => [$level->value => (int) $counts->get($level->value, 0)])
            ->all();
    }

    /**
     * Whether a file is larger than the part of it that is read.
     */
    public function isTruncated(string $path): bool
    {
        return is_file($path) && filesize($path) > self::MAX_BYTES;
    }

    /**
     * Empty a file without removing it. The channel keeps writing to the same path,
     * and a file deleted under a running worker is one it goes on writing into
     * where nobody can read it.
     */
    public function clear(string $path): bool
    {
        return is_file($path) && file_put_contents($path, '') !== false;
    }

    /**
     * Whether an entry is an email the "log" mailer wrote, and if so who it went to
     * and what it was about — enough for the listing without decoding the body.
     *
     * @return array{to: string, subject: string}|null
     */
    protected function mailSummary(string $message, string $context): ?array
    {
        if (! str_starts_with($message, 'From: ') || ! str_contains($context, 'MIME-Version:')) {
            return null;
        }

        [$headers] = $this->splitMimePart($message."\n".$context);

        return [
            'to' => $headers['to'] ?? '',
            'subject' => $headers['subject'] ?? '',
        ];
    }

    /**
     * Headers and body of one MIME part. Header names are lowercased, and a header
     * folded over several lines is joined back into one.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    protected function splitMimePart(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        [$head, $body] = array_pad(explode("\n\n", $raw, 2), 2, '');

        $headers = [];
        $current = null;

        foreach (explode("\n", $head) as $line) {
            if ($current && preg_match('/^\s+/', $line)) {
                $headers[$current] .= ' '.trim($line);

                continue;
            }

            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $current = strtolower(trim($name));
                $headers[$current] = iconv_mime_decode(trim($value), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: trim($value);
            }
        }

        return [$headers, $body];
    }

    /**
     * Walk a MIME part, descending into multipart bodies, and keep the first HTML
     * and plain-text body found.
     *
     * @param  array<string, string>  $headers
     * @param  array{html: ?string, text: ?string}  $parts
     */
    protected function collectMimeBodies(array $headers, string $body, array &$parts): void
    {
        $type = strtolower($headers['content-type'] ?? 'text/plain');

        if (str_starts_with($type, 'multipart/')) {
            if (! preg_match('/boundary="?([^";]+)"?/i', $headers['content-type'], $boundary)) {
                return;
            }

            // The first section is the preamble before any boundary, and the last one
            // opens with the "--" of the closing marker. Neither is a part.
            foreach (\array_slice(explode('--'.$boundary[1], $body), 1) as $section) {
                $section = ltrim($section, "\n");

                if ($section === '' || str_starts_with($section, '--')) {
                    continue;
                }

                [$partHeaders, $partBody] = $this->splitMimePart($section);

                $this->collectMimeBodies($partHeaders, $partBody, $parts);
            }

            return;
        }

        $decoded = match (strtolower($headers['content-transfer-encoding'] ?? '')) {
            'quoted-printable' => quoted_printable_decode($body),
            'base64' => (string) base64_decode($body),
            default => $body,
        };

        $key = str_starts_with($type, 'text/html') ? 'html' : (str_starts_with($type, 'text/plain') ? 'text' : null);

        if ($key && $parts[$key] === null) {
            $parts[$key] = trim($decoded);
        }
    }

    /**
     * Split raw log text into entries, oldest first.
     *
     * Everything between one entry's header and the next belongs to it — the JSON
     * context and the stack trace Laravel writes on the lines after an exception.
     * Text before the first header is dropped: after tail() it is the back half of
     * an entry whose start was not read.
     *
     * The id is a hash of the entry's text rather than its position, so it still
     * finds the same entry after new lines are written above it in the listing.
     *
     * @return Collection<int, array{id: string, datetime: ?Carbon, environment: ?string, level: ?LogLevelEnum, message: string, context: string, mail: ?array}>
     */
    public function parse(string $contents): Collection
    {
        if (! preg_match_all(self::ENTRY_PATTERN, $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return collect();
        }

        $entries = [];

        foreach ($matches as $index => $match) {
            $start = $match[0][1] + \strlen($match[0][0]);
            $end = isset($matches[$index + 1]) ? $matches[$index + 1][0][1] : \strlen($contents);
            $body = rtrim(substr($contents, $start, $end - $start));

            [$message, $context] = array_pad(explode("\n", $body, 2), 2, '');

            $message = trim($message);
            $context = trim($context);

            $entries[] = [
                'id' => md5($match[0][0].$body),
                'datetime' => rescue(fn () => Carbon::parse($match[1][0]), report: false),
                'environment' => $match[2][0] ?: null,
                'level' => LogLevelEnum::tryFrom(strtolower($match[3][0])),
                'message' => $message,
                'context' => $context,
                'mail' => $this->mailSummary($message, $context),
            ];
        }

        return collect($entries);
    }

    /**
     * The last MAX_BYTES of a file, or all of it when it is smaller.
     */
    protected function tail(string $path): string
    {
        if (! is_file($path) || ! $handle = fopen($path, 'rb')) {
            return '';
        }

        $size = (int) filesize($path);

        if ($size > self::MAX_BYTES) {
            fseek($handle, $size - self::MAX_BYTES);
        }

        $contents = (string) stream_get_contents($handle);

        fclose($handle);

        return $contents;
    }
}
