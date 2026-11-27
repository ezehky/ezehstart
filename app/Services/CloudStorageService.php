<?php

namespace App\Services;

use App\Models\Image;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Where uploaded files live: the local public disk, or a Cloudflare R2 bucket.
 *
 * Optional, and off by default. Configuration › File storage has the switch
 * (`storage.cloud`) and the bucket's public address (`storage.public-url`); the
 * access keys, bucket and endpoint stay in .env, the same as the mail keys, so a
 * secret is never written to the site config or shown on a screen.
 *
 * The switch decides where a *new* upload goes. Flipping it never moves anything
 * on its own: an image row records the disk it was written to, so every existing
 * file goes on working where it is. Moving them is the sync, which an
 * administrator runs on purpose from the same screen, in small batches so no
 * single request has to carry the whole library.
 *
 * The site logo and favicon have no row to carry a disk, so they are found by
 * looking — the active disk first, then the other one.
 */
#[Singleton]
class CloudStorageService
{
    public const LOCAL_DISK = 'public';

    public const CLOUD_DISK = 'r2';

    /**
     * Files moved per sync request. Small on purpose: every file is a download and
     * an upload inside one PHP request, and a library of 20 MB images at twenty a
     * batch is how a request times out halfway through a copy.
     */
    public const SYNC_BATCH = 10;

    /**
     * The site-configuration keys that hold a stored file path.
     */
    public const CONFIG_FILE_KEYS = ['logo', 'logo-dark', 'favicon'];

    /**
     * The .env keys R2 needs, by the disk option each one fills.
     */
    public const ENV_KEYS = [
        'key' => 'R2_ACCESS_KEY_ID',
        'secret' => 'R2_SECRET_ACCESS_KEY',
        'bucket' => 'R2_BUCKET',
        'endpoint' => 'R2_ENDPOINT',
    ];

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // STATE

    /**
     * Point the R2 disk at the public address saved on File storage, when one is
     * saved; R2_PUBLIC_URL stands otherwise. Called at boot, before the site
     * config is cached, because that cache resolves the logo's URL through this
     * disk. Read raw for the same reason — and only on an install holding the
     * keys, so every other install pays nothing for it.
     */
    public function apply(): void
    {
        if ($this->missingEnv()) {
            return;
        }

        try {
            $url = data_get(app(SiteConfigurationService::class)->getConfigs(raw: true), 'storage.public-url');
        } catch (Throwable) {
            return;
        }

        if (filled($url)) {
            config(['filesystems.disks.'.self::CLOUD_DISK.'.url' => rtrim((string) $url, '/')]);
        }
    }

    /**
     * Whether new uploads go to R2: switched on, and the install can reach a
     * bucket. A switch on over missing keys sends uploads to this server, as if
     * it were off, rather than into a write that can never succeed.
     */
    public function isEnabled(): bool
    {
        return (bool) kSiteFlag('storage', 'cloud', false) && $this->isConfigured();
    }

    /**
     * Whether this install can use R2: the keys in .env, and a public address. The
     * address counts — a file written to a bucket the browser has no address for
     * is a file nobody can see.
     */
    public function isConfigured(): bool
    {
        return ! $this->missingEnv() && filled($this->publicUrl());
    }

    /**
     * The .env keys still to fill, for the screen to name.
     *
     * @return list<string>
     */
    public function missingEnv(): array
    {
        return collect(self::ENV_KEYS)
            ->reject(fn (string $env, string $option) => filled(config('filesystems.disks.'.self::CLOUD_DISK.'.'.$option)))
            ->values()
            ->all();
    }

    public function publicUrl(): ?string
    {
        $url = config('filesystems.disks.'.self::CLOUD_DISK.'.url');

        return filled($url) ? (string) $url : null;
    }

    /**
     * The disk a new upload is written to.
     */
    public function disk(): string
    {
        return $this->isEnabled() ? self::CLOUD_DISK : self::LOCAL_DISK;
    }

    /**
     * The disk a sync moves files away from.
     */
    public function otherDisk(): string
    {
        return $this->disk() === self::CLOUD_DISK ? self::LOCAL_DISK : self::CLOUD_DISK;
    }

    public function diskLabel(?string $disk = null): string
    {
        return ($disk ?? $this->disk()) === self::CLOUD_DISK ? 'Cloudflare R2' : 'this server';
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // FILES

    /**
     * Hand a freshly stored local file over to the active disk.
     *
     * Uploads always land locally first, because measuring, converting and
     * optimising an image needs a real path on this machine. Returns the disk the
     * file ends up on — still the local one if R2 is off or refused the write, so
     * an R2 outage costs an upload its destination rather than costing the upload.
     */
    public function promote(string $path): string
    {
        if (! $this->isEnabled()) {
            return self::LOCAL_DISK;
        }

        return $this->move($path, self::LOCAL_DISK, self::CLOUD_DISK)
            ? self::CLOUD_DISK
            : self::LOCAL_DISK;
    }

    /**
     * Bring a copy of a file down to the local disk, leaving the original where it
     * is, for work that needs a real path — a crop. True when the local copy is
     * there; a file already local is trivially so.
     */
    public function pull(string $path, string $from): bool
    {
        if ($from === self::LOCAL_DISK) {
            return Storage::disk(self::LOCAL_DISK)->exists($path);
        }

        try {
            $stream = Storage::disk($from)->readStream($path);

            if (! $stream || ! Storage::disk(self::LOCAL_DISK)->writeStream($path, $stream)) {
                throw new RuntimeException('The download was refused.');
            }

            if (\is_resource($stream)) {
                fclose($stream);
            }

            return true;
        } catch (Throwable $e) {
            Log::channel('ezeh')->warning("Could not copy a file down from {$from}.", ['path' => $path, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Send a local working copy back to the disk it came from, over the original,
     * and remove the copy. The counterpart of pull(). A file that lives locally is
     * already home.
     */
    public function push(string $path, string $to): bool
    {
        if ($to === self::LOCAL_DISK) {
            return true;
        }

        return $this->move($path, self::LOCAL_DISK, $to, overwrite: true);
    }

    /**
     * Which disk holds this path, or null when neither does.
     *
     * Only for the handful of files with no row to remember it — see the class
     * note. Each call is a network round trip once R2 is involved, so it belongs
     * at save and cache time, never on every page render.
     */
    public function locate(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        foreach ($this->candidateDisks() as $disk) {
            try {
                if (Storage::disk($disk)->exists($path)) {
                    return $disk;
                }
            } catch (Throwable $e) {
                Log::channel('ezeh')->warning("Could not check a file on {$disk}.", ['path' => $path, 'error' => $e->getMessage()]);
            }
        }

        return null;
    }

    /**
     * Delete a file wherever it is. For the same row-less files as locate():
     * deleting a key that is not there is a no-op on both disks, so this is one
     * call per disk rather than a lookup followed by a delete.
     */
    public function delete(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        foreach ($this->candidateDisks() as $disk) {
            try {
                kDeleteFile($path, $disk);
            } catch (Throwable $e) {
                Log::channel('ezeh')->warning("Could not delete a file on {$disk}.", ['path' => $path, 'error' => $e->getMessage()]);
            }
        }
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // SYNC

    /**
     * How many library images are not on the active disk. A count on one column,
     * cheap enough to show on every render of the File storage screen.
     *
     * @param  array<int, int>  $skipIds
     */
    public function pendingCount(array $skipIds = []): int
    {
        return Image::query()
            ->where('disk', '!=', $this->disk())
            ->whereNotIn('id', $skipIds)
            ->count();
    }

    /**
     * Move the next batch of files onto the active disk.
     *
     * $skipIds are images an earlier batch could not move. They are carried by the
     * caller and excluded here, because a file missing from both disks would
     * otherwise be first in line on every batch and the sync would never finish.
     *
     * The site-config files go with the first batch — there are three of them.
     *
     * @param  array<int, int>  $skipIds
     * @return array{moved: int, failed: array<int, int>, remaining: int}
     */
    public function syncBatch(array $skipIds = [], bool $includeConfigFiles = false): array
    {
        $target = $this->disk();
        $moved = 0;
        $failed = [];

        if ($includeConfigFiles) {
            $moved += $this->syncConfigFiles($target);
        }

        $images = Image::query()
            ->where('disk', '!=', $target)
            ->whereNotIn('id', $skipIds)
            ->orderBy('id')
            ->limit(self::SYNC_BATCH)
            ->get();

        foreach ($images as $image) {
            $source = $image->disk ?: self::LOCAL_DISK;

            if (! $this->move($image->file_path, $source, $target)) {
                $failed[] = $image->id;

                continue;
            }

            // Saved quietly: a sync is one logged action, not one per image, and
            // the row's updated_at should still say when somebody last edited it.
            $image->disk = $target;
            $image->saveQuietly();

            $moved++;
        }

        return [
            'moved' => $moved,
            'failed' => $failed,
            'remaining' => $this->pendingCount([...$skipIds, ...$failed]),
        ];
    }

    /**
     * The logo and favicon, moved to the target disk. Returns how many moved.
     */
    private function syncConfigFiles(string $target): int
    {
        $service = app(SiteConfigurationService::class);
        $config = $service->getConfigs(raw: true);
        $moved = 0;

        foreach (self::CONFIG_FILE_KEYS as $key) {
            $path = data_get($config, $key);
            $source = $this->locate($path);

            if ($source && $source !== $target && $this->move($path, $source, $target)) {
                $moved++;
            }
        }

        // The cached config holds resolved URLs, and those still point at the old
        // disk until it is rebuilt.
        if ($moved) {
            cache()->forget('site_configuration');
            $service->cacheSiteConfig(injectConfig: true);
        }

        return $moved;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PRIVATE

    /**
     * Copy a file across, confirm the copy, then remove the original.
     *
     * The original goes only once the copy is there at the same size. Keeping both
     * would look safer, but an image deleted later is deleted from the disk its row
     * names — the stale copy would outlive the image, and the account it belonged to.
     *
     * A file already on the target (an earlier sync that died after the copy) is
     * treated as moved rather than copied again — unless $overwrite, which is a
     * cropped copy coming home to replace the original.
     */
    private function move(string $path, string $from, string $to, bool $overwrite = false): bool
    {
        try {
            $source = Storage::disk($from);
            $target = Storage::disk($to);

            if (! $source->exists($path)) {
                return ! $overwrite && $target->exists($path);
            }

            $stream = $source->readStream($path);

            if (! $stream || ! $target->writeStream($path, $stream)) {
                throw new RuntimeException('The write was refused.');
            }

            if (\is_resource($stream)) {
                fclose($stream);
            }

            if ($target->size($path) !== $source->size($path)) {
                if (! $overwrite) {
                    $target->delete($path);
                }

                throw new RuntimeException('The copy did not match the original in size.');
            }

            $source->delete($path);

            return true;
        } catch (Throwable $e) {
            Log::channel('ezeh')->warning("Could not move a file from {$from} to {$to}.", ['path' => $path, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The disks worth looking on: this server, and R2 once there are keys to reach
     * it with.
     *
     * Deliberately blind to the switch. locate() runs while the site config is
     * being cached, and asking the switch there would read the very config being
     * built — a loop. Which disk is "active" does not matter for finding a file
     * anyway; a path lives on one disk or the other.
     *
     * @return list<string>
     */
    private function candidateDisks(): array
    {
        return $this->isConfigured()
            ? [self::LOCAL_DISK, self::CLOUD_DISK]
            : [self::LOCAL_DISK];
    }
}
