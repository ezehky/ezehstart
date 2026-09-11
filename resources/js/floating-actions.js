/**
 * The bar behind <x-util.floating-actions>.
 *
 * A full-page form puts its save button at the bottom, which is where it belongs
 * once you are reading the bottom of the form and nowhere useful while you are
 * editing the top of it. This lifts that same bar out of the flow and pins it to
 * the viewport while its resting place is below the fold, then drops it back the
 * moment you scroll far enough to see where it lives.
 *
 * The bar is moved rather than copied. A duplicate would mean two elements
 * carrying the same wire:click, and Livewire would then have two buttons racing
 * to submit one form.
 *
 * It floats only while the dock is *below* the viewport. Scrolled past it, the
 * form is behind you and the buttons have nothing left to act on, so pinning them
 * there would only cover the page.
 */
export default function floatingActions({ offset = 0, enabled = true }) {
    return {
        floating: false,

        /** The height the dock holds open while the bar is pinned, so nothing jumps. */
        height: 0,

        observer: null,

        resizeObserver: null,

        init() {
            if (!enabled) {
                return;
            }

            this.measure();

            // Watched rather than measured once: the bar wraps to two rows on a
            // narrow screen, and a button that gains a spinner grows.
            this.resizeObserver = new ResizeObserver(() => this.measure());
            this.resizeObserver.observe(this.$refs.bar);

            this.observer = new IntersectionObserver(
                ([entry]) => {
                    this.floating =
                        !entry.isIntersecting && entry.boundingClientRect.top > 0;
                },
                { rootMargin: `0px 0px ${-offset}px 0px`, threshold: 0 },
            );

            this.observer.observe(this.$refs.dock);
        },

        destroy() {
            this.observer?.disconnect();
            this.resizeObserver?.disconnect();
        },

        /**
         * Only while docked — measuring a pinned bar would read the height it has
         * against the viewport and write that back into a dock holding nothing.
         */
        measure() {
            if (!this.floating) {
                this.height = this.$refs.bar.offsetHeight;
            }
        },
    };
}
