<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusYes;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Currencies and the rates between them.
 *
 * The ledger is written in the default currency and nothing else. Every other
 * currency is a way of *reading* those amounts, converted on the way out by
 * kMoneyFormat() — so changing somebody's currency never touches a stored row.
 */
#[Singleton]
class CurrencyService
{
    private const CACHE_KEY = 'currencies.active';

    /** Where a visitor's pick is kept before they have an account to keep it on. */
    public const SESSION_KEY = 'currency';

    /**
     * What an install with no currency rows yet reads amounts in. The naira,
     * because that is what kMoneyFormat() hard-coded before currencies were rows,
     * so a fresh clone looks the same before and after it is seeded.
     *
     * @var array{id: int|null, name: string, code: string, symbol: string, symbol_position: string, rate: float, is_default: bool}
     */
    public const FALLBACK = [
        'id' => null,
        'name' => 'Naira',
        'code' => 'NGN',
        'symbol' => '&#8358;',
        'symbol_position' => 'before',
        'rate' => 1.0,
        'is_default' => true,
    ];

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // GETTERS

    /**
     * Every currency that may be picked, default included, as plain arrays keyed
     * by id. Cached forever and flushed on every write through this service.
     *
     * An install that has not been migrated yet — a unit test, a first
     * `composer setup` — gets an empty list rather than an exception, because
     * this is read by a formatting helper that runs on nearly every page.
     *
     * @return Collection<int, array>
     */
    public function active(): Collection
    {
        try {
            $rows = cache()->rememberForever(self::CACHE_KEY, fn () => Currency::query()
                ->active()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (Currency $currency) => [$currency->id => $currency->toCurrencyArray()])
                ->all());
        } catch (\Throwable) {
            return collect();
        }

        return collect($rows);
    }

    /**
     * The currency the ledger is written in.
     *
     * @return array{id: int|null, name: string, code: string, symbol: string, symbol_position: string, rate: float, is_default: bool}
     */
    public function default(): array
    {
        // A default somebody switched off is not in the active list, so this falls
        // through to the constant rather than handing back a currency nobody may pick.
        return $this->active()->firstWhere('is_default', true) ?? self::FALLBACK;
    }

    /**
     * The currency this account reads amounts in: its own pick while that is
     * still switched on, otherwise the site default. With no account, the pick
     * this browser made from the site header.
     *
     * The session is read for a guest only. An account's pick is written to the
     * session as well, but the account is the record — a member who changes it
     * from their settings must not be overruled by what the header said last.
     *
     * Resolved from the cached list rather than the relation, so formatting a
     * table of fifty amounts does not cost fifty queries.
     *
     * @return array{id: int|null, name: string, code: string, symbol: string, symbol_position: string, rate: float, is_default: bool}
     */
    public function forUser(?User $user): array
    {
        $pickedId = $user ? $user->currency_id : session(self::SESSION_KEY);

        if ($pickedId && $picked = $this->active()->get($pickedId)) {
            return $picked;
        }

        return $this->default();
    }

    /**
     * Whether the header offers a choice at all. One currency is no choice.
     */
    public function isSwitcherEnabled(): bool
    {
        return $this->active()->count() > 1;
    }

    /**
     * An amount in one currency expressed in another. Rates are all quoted
     * against the default, so the amount goes back to the default first and out
     * again from there.
     */
    public function convert(float $amount, array $to, ?array $from = null): float
    {
        $from ??= $this->default();

        $fromRate = (float) data_get($from, 'rate', 1) ?: 1;
        $toRate = (float) data_get($to, 'rate', 1) ?: 1;

        return $amount / $fromRate * $toRate;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // ACTIONS

    /**
     * Point an account at a currency, or back at the default with null.
     *
     * A switched-off currency is refused: the picker never offers one, and a
     * request that names one anyway is not something to honour quietly.
     */
    public function setForUser(User $user, ?Currency $currency): bool
    {
        if ($currency && ! $currency->status->isActive()) {
            return false;
        }

        // The default is stored as null rather than its id, so a later change of
        // default carries this account with it instead of stranding it on the old one.
        $user->forceFill(['currency_id' => $currency?->isDefault() ? null : $currency?->id])->save();

        return true;
    }

    /**
     * Remember a visitor's pick from the site header. In the session always, so
     * it holds before sign-in; on the account as well when there is one, the same
     * as picking it from the account settings.
     */
    public function choose(Currency $currency, ?User $user): bool
    {
        if (! $currency->status->isActive()) {
            return false;
        }

        // The default is kept as nothing, like on the account, so a later change of
        // default carries the visitor along instead of stranding them on the old one.
        $currency->isDefault()
            ? session()->forget(self::SESSION_KEY)
            : session()->put(self::SESSION_KEY, $currency->id);

        return $user ? $this->setForUser($user, $currency) : true;
    }

    /**
     * Make this currency the one the ledger is read in, rebasing every rate so
     * the new default is 1 and every other currency keeps its real value.
     *
     * The ledger itself is not converted. Its amounts were written in the old
     * default and are now read in the new one — which is why the screen asks
     * before it calls this, and why it is meant for a site being set up rather
     * than one with money already on the books.
     */
    public function makeDefault(Currency $currency): void
    {
        $old = Currency::query()->siteDefault()->first();

        if ($old?->is($currency)) {
            return;
        }

        $base = (float) $currency->rate ?: 1;

        DB::transaction(function () use ($currency, $base) {
            Currency::query()->each(function (Currency $row) use ($currency, $base) {
                $row->forceFill([
                    'rate' => $row->is($currency) ? 1 : (float) $row->rate / $base,
                    'is_default' => $row->is($currency) ? StatusYes::YES : StatusYes::NO,
                    // A default nobody may pick would leave the site reading money in
                    // a currency the picker hides, so it is switched on with the flag.
                    'status' => $row->is($currency) ? StatusDefault::ACTIVE : $row->status,
                ])->save();
            });

            // An account that had picked the new default would now be pointing at
            // it by id. Null is how "the default" is stored, so it goes back to that.
            User::query()->where('currency_id', $currency->id)->update(['currency_id' => null]);
        });

        $this->flush();

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::CURRENCY_DEFAULT,
            ($old ? "{$old->code} to " : '').$currency->code,
            model: $currency,
        );
    }

    /**
     * Drop the cached list. Every write to the currencies table goes through
     * here, including the admin screen's own saves.
     */
    public function flush(): void
    {
        cache()->forget(self::CACHE_KEY);
    }
}
