/**
 * The formatted amount behind <x-form.money-field>.
 *
 * Two values, kept apart on purpose. `display` is what the person sees and
 * types — "12,500.50", thousands separated by the $money mask Livewire already
 * bundles. `value` is what Livewire gets — 12500.5, a plain number or null —
 * entangled to the wire:model property, so validation, MoneyRule and MoneyCast
 * never see a comma.
 *
 * Each side only rewrites the other when they actually disagree. Without that
 * check a server round trip would reformat the text under the caret mid-typing,
 * and "12,500." would snap back to "12,500" before the cents could be entered.
 */
export default (config = {}) => ({
    value: config.value ?? null,

    decimals: config.decimals ?? 2,

    display: "",

    init() {
        this.display = this.format(this.value);

        this.$watch("display", (text) => {
            const parsed = this.parse(text);

            if (parsed !== this.normalise(this.value)) {
                this.value = parsed;
            }
        });

        this.$watch("value", (value) => {
            if (this.normalise(value) !== this.parse(this.display)) {
                this.display = this.format(value);
            }
        });
    },

    /**
     * Text to number. An empty box, or one holding only a point, is null rather
     * than zero — "no amount" and "an amount of nothing" are different answers
     * to a required field.
     */
    parse(text) {
        const clean = String(text ?? "").replace(/,/g, "").trim();

        if (clean === "" || clean === ".") return null;

        const number = Number(clean);

        return Number.isFinite(number) ? number : null;
    },

    normalise(value) {
        if (value === null || value === undefined || value === "") return null;

        const number = Number(value);

        return Number.isFinite(number) ? number : null;
    },

    format(value) {
        const number = this.normalise(value);

        if (number === null) return "";

        return number.toLocaleString("en-US", {
            minimumFractionDigits: 0,
            maximumFractionDigits: this.decimals,
        });
    },
});
