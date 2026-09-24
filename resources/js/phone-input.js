/**
 * The country picker behind <x-form.phone-field>.
 *
 * intl-tel-input owns the <input> and the flag dropdown; this component owns the
 * conversation with Livewire. The field sits inside wire:ignore, so Livewire never
 * morphs the markup intl-tel-input injected, and the values travel through
 * entangled properties instead of wire:model on the input itself.
 *
 * Up to three properties are kept in step:
 *   - number   — the national number as typed, digits only ("08012345678"), or
 *                the full international number when there is no dialCode
 *                property to carry the country separately
 *   - dialCode — "+234", when the form stores it
 *   - iso2     — "ng", when the form stores it
 */
import intlTelInput from "intl-tel-input";
import "intl-tel-input/styles";

export default (config = {}) => ({
    number: config.number ?? null,

    dialCode: config.dialCode ?? null,

    iso2: config.iso2 ?? null,

    /**
     * Whether the form stores the country apart from the number. Decided once,
     * from whether a dialCode property was entangled at all.
     */
    get split() {
        return config.dialCode !== undefined;
    },

    init() {
        const input = this.$refs.input;

        // Alpine's x-data wraps every returned property — this.iti included — in a
        // reactive proxy, and intl-tel-input's instance methods read private class
        // fields (#selectedCountry etc.) straight off `this`. A method called
        // through that proxy throws "Cannot read private member ... from an object
        // whose class did not declare it", because a Proxy never passes a private-
        // field brand check. intl-tel-input already stashes the raw instance on the
        // input element itself (`input.iti = iti`), and elements are never proxied,
        // so every call below goes through that instead of the reactive this.iti.
        intlTelInput(input, {
            // The countries table stores iso2 in capitals; intl-tel-input wants lower.
            initialCountry: (this.iso2 || config.defaultCountry || "ng").toLowerCase(),
            separateDialCode: true,
            countrySearch: true,
            // Validation and as-you-type formatting come from libphonenumber, which
            // is a large file. Loaded on first use rather than bundled, so a page
            // without a phone field never pays for it.
            loadUtils: () => import("intl-tel-input/utils"),
        });

        if (this.number) {
            input.iti.setNumber(this.number);
        }

        // A form that stores no dialling code still has to learn which country
        // the field opened on, or the first save would carry a number and no code.
        this.sync();

        input.addEventListener("input", () => this.sync());
        input.addEventListener("countrychange", () => this.sync());

        // A change from the server — a cleared form after a save, or a modal
        // opened on a different record — has to reach the field, which Livewire
        // cannot patch through wire:ignore. The country goes first, because a
        // national number means nothing until the picker knows whose it is.
        this.$watch("iso2", () => this.apply());
        this.$watch("number", () => this.apply());
    },

    apply() {
        const input = this.$refs.input;
        const current = this.read();

        if (this.split && this.iso2 && this.iso2 !== current.iso2) {
            input.iti.setSelectedCountry(this.iso2.toLowerCase());
        }

        if (this.number !== this.read().number) {
            input.iti.setNumber(this.number ?? "");
        }
    },

    /**
     * Read the field as the properties want it.
     */
    read() {
        const input = this.$refs.input;
        const country = input.iti.getSelectedCountry();
        const typed = input.value.replace(/[^\d+]/g, "");

        // getNumber() throws until intl-tel-input's lazily-loaded utils chunk has
        // resolved, which a fast typist can easily outrun — fall back to the raw
        // digits rather than letting that throw skip the sync() assignment.
        let full = null;

        if (typed && !this.split) {
            try {
                full = input.iti.getNumber();
            } catch {
                full = null;
            }
        }

        return {
            number: !typed ? null : this.split ? typed : full || typed,
            dialCode: country ? `+${country.dialCode}` : null,
            iso2: country?.iso2?.toUpperCase() ?? null,
        };
    },

    sync() {
        const { number, dialCode, iso2 } = this.read();

        this.number = number;

        if (this.split) {
            this.dialCode = dialCode;
            this.iso2 = iso2;
        }
    },

    destroy() {
        this.$refs.input.iti?.destroy();
    },
});
