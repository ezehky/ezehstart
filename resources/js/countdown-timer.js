/**
 * The clock behind <x-util.countdown>.
 *
 * Registered as an Alpine component rather than wired up per page, so a Livewire
 * re-render reuses the registration instead of leaving a second timer ticking
 * against the same element.
 *
 * Every tick is measured against the browser's clock rather than counted down
 * from a starting number, because a timer is not a reliable metronome: a
 * backgrounded tab throttles it to once a minute, a sleeping laptop stops it
 * altogether, and a page restored from the back/forward cache resumes it mid
 * count. Reading the difference each time means all three come back showing the
 * truth instead of however far behind they fell.
 */

const UNIT_SECONDS = {
    days: 86400,
    hours: 3600,
    minutes: 60,
    seconds: 1,
};

/**
 * Spread the remaining seconds across the units on show, largest first.
 *
 * The largest one carries everything above it — hide days from a three-day
 * countdown and it reads 72 hours rather than losing them — and the smallest
 * truncates rather than rounds, so the display never shows a minute that has
 * not started. Only the first is left unpadded: "3" days beside "04" hours is
 * how a countdown reads, "03" days is not.
 */
export function splitRemaining(remaining, units) {
    let rest = Math.max(0, remaining);

    return units.reduce((parts, unit, index) => {
        const size = UNIT_SECONDS[unit];
        const value = Math.floor(rest / size);

        rest -= value * size;
        parts[unit] = index === 0 ? String(value) : String(value).padStart(2, "0");

        return parts;
    }, {});
}

export default function countdownTimer({ target, units, expire }) {
    return {
        expired: false,

        parts: splitRemaining(0, units),

        timer: null,

        init() {
            this.tick();
        },

        destroy() {
            clearTimeout(this.timer);
        },

        tick() {
            const remaining = Math.floor((target - Date.now()) / 1000);

            this.parts = splitRemaining(remaining, units);

            if (remaining <= 0) {
                this.finish();

                return;
            }

            // Line the next tick up with the turn of the second on screen. A flat
            // 1000ms gap drifts against it, and the display then skips a number
            // every minute or so.
            this.timer = setTimeout(() => this.tick(), 1000 - (Date.now() % 1000));
        },

        /**
         * Runs once, on the tick that crosses zero — a countdown that was already
         * over when the page rendered never boots, which is what stops
         * expire="reload" from reloading into itself forever.
         *
         * The event goes out whatever the action, so a page that needs to do more
         * than the three can listen for it:
         *
         *   <div x-on:countdown-expired.window="$wire.$refresh()">
         */
        finish() {
            this.expired = true;
            this.$dispatch("countdown-expired", { target });

            if (expire === "reload") {
                window.location.reload();
            }
        },
    };
}
