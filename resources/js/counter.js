/**
 * The count-up behind <x-util.counter>.
 *
 * The number runs from `from` to `to` once the element scrolls into view — on
 * the first entry only, or every time it comes back, as `replay` says. Leaving
 * the viewport under replay "always" winds it back to the start, so the next
 * entry has somewhere to count up from.
 *
 * Measured against the frame clock rather than stepped by a fixed amount, so a
 * slow device finishes on time with fewer frames instead of running long.
 */

/**
 * The same grouping number_format() prints on the server — commas for
 * thousands, a point for decimals — so the figure the markup arrived with and the
 * one the count lands on are the same string.
 */
export function formatCount(value, decimals) {
    return Number(value).toLocaleString("en-US", {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

/**
 * Somebody who asked their device for less motion gets the final number and no
 * animation, on every entry.
 */
function prefersReducedMotion() {
    return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}

export default function counter({ from, to, duration, decimals, replay }) {
    return {
        // The markup arrives showing the final number, which is what anybody
        // without JavaScript, or with reduced motion, is left reading.
        display: formatCount(to, decimals),

        frame: null,

        played: false,

        init() {
            if (!prefersReducedMotion()) {
                this.display = formatCount(from, decimals);
            }
        },

        destroy() {
            cancelAnimationFrame(this.frame);
        },

        enter() {
            if (prefersReducedMotion() || (this.played && replay === "once")) {
                return;
            }

            this.played = true;
            this.run();
        },

        leave() {
            if (replay !== "always" || prefersReducedMotion()) {
                return;
            }

            cancelAnimationFrame(this.frame);
            this.display = formatCount(from, decimals);
        },

        run() {
            cancelAnimationFrame(this.frame);

            const start = performance.now();

            const step = (now) => {
                const progress = Math.min(1, (now - start) / duration);

                // Ease-out cubic: fast off the mark, settling onto the number,
                // which is where the eye is by the time it lands.
                const eased = 1 - Math.pow(1 - progress, 3);

                this.display = formatCount(from + (to - from) * eased, decimals);

                if (progress < 1) {
                    this.frame = requestAnimationFrame(step);
                }
            };

            this.frame = requestAnimationFrame(step);
        },
    };
}
