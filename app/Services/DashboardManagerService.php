<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Storage;

/**
 * What one account has arranged for itself in the dashboard — which table columns it
 * keeps, and anything else that is a preference rather than a record.
 *
 * A JSON file per account on the local disk, the same shape the site configuration
 * uses, rather than a table. These are preferences: nothing joins on them, nothing
 * reports on them, and a column somebody hid is not worth a migration, an index and a
 * row per account per screen.
 *
 * The file is a bag of sections, each keyed by whatever owns it:
 *
 *     {
 *         "column-manager": {
 *             "admin.transactions": ["gateway", "settled_at"]
 *         }
 *     }
 */
#[Singleton]
class DashboardManagerService
{
    /**
     * Files already read this request, keyed by account. The service is a singleton,
     * so a screen asking for three sections reads the disk once.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $loaded = [];

    // Getters

    /**
     * One section, or one key inside it.
     *
     * Both are read as literal array keys rather than as a dotted path: a key here is
     * a screen's name — "pages::admin.transactions" — and a dotted lookup would take
     * the full stop in it for a level of nesting and file the value two levels deep
     * under a section nobody named.
     */
    public function get(string $section, ?string $key = null, mixed $default = null, ?User $user = null): mixed
    {
        $data = $this->read($user);

        if ($key === null) {
            return $data[$section] ?? $default;
        }

        return $data[$section][$key] ?? $default;
    }

    // Actions

    /**
     * Write one key inside a section, leaving everything else in the file alone.
     */
    public function put(string $section, string $key, mixed $value, ?User $user = null): bool
    {
        $data = $this->read($user);

        $data[$section] = [...($data[$section] ?? []), $key => $value];

        return $this->write($data, $user);
    }

    /**
     * Drop one key, or a whole section. Used by "reset to defaults" — an absent key
     * and a key set to its default are the same thing to every reader, and the absent
     * one does not go stale when the default changes.
     */
    public function forget(string $section, ?string $key = null, ?User $user = null): bool
    {
        $data = $this->read($user);

        if ($key === null) {
            unset($data[$section]);
        } else {
            unset($data[$section][$key]);
        }

        return $this->write($data, $user);
    }

    // Tools

    /**
     * The account's file. Named by id on a private disk — there is nothing here worth
     * hiding from the account it belongs to, and a guessable name costs nothing when
     * the directory is not served.
     */
    public function file(?User $user = null): string
    {
        $user ??= auth()->user();

        return 'dashboard-manager/user-'.($user?->getKey() ?? 'guest').'.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function read(?User $user = null): array
    {
        $file = $this->file($user);

        if (array_key_exists($file, $this->loaded)) {
            return $this->loaded[$file];
        }

        if (! Storage::exists($file)) {
            return $this->loaded[$file] = [];
        }

        $data = json_decode((string) Storage::get($file), true);

        // A preferences file that has been corrupted is not worth an exception on a
        // dashboard: the account loses its arrangement, not its session.
        return $this->loaded[$file] = json_last_error() === JSON_ERROR_NONE && is_array($data)
            ? $data
            : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(array $data, ?User $user = null): bool
    {
        $file = $this->file($user);

        $this->loaded[$file] = $data;

        return Storage::put($file, json_encode($data, JSON_PRETTY_PRINT));
    }
}
