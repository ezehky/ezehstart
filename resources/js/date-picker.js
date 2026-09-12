/**
 * The calendar behind <x-form.date-field>.
 *
 * Flux's free tier has no date picker, so this is the project's own. It writes
 * plain YYYY-MM-DD strings into a hidden input and lets Livewire pick them up the
 * way it would any other field, which is what keeps a date filter working with the
 * same ->when($this->from !== '', ...) clause every other filter uses.
 *
 * Every date here is built and read in local parts, never through Date.parse or
 * toISOString. "2026-03-01" parsed as a date is UTC midnight, which in any negative
 * offset is the 28th of February, and a calendar that quietly shifts a day is worse
 * than no calendar at all.
 */

const WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

const MONTHS = [
    "January",
    "February",
    "March",
    "April",
    "May",
    "June",
    "July",
    "August",
    "September",
    "October",
    "November",
    "December",
];

/** A local Date from YYYY-MM-DD, or null for anything that is not one. */
/**
 * The ranges a preset stands for, and what each is called.
 *
 * Keys match the ones Flux Pro's picker uses, so a field written against its
 * documentation reads the same here.
 */
const PRESET_LABELS = {
    today: "Today",
    yesterday: "Yesterday",
    thisWeek: "This week",
    lastWeek: "Last week",
    last7Days: "Last 7 days",
    last30Days: "Last 30 days",
    thisMonth: "This month",
    lastMonth: "Last month",
    thisQuarter: "This quarter",
    thisYear: "This year",
    yearToDate: "Year to date",
    allTime: "All time",
};

/** A date with the time stripped, so every comparison here is day-to-day. */
function atMidnight(date) {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function addDays(date, by) {
    const shifted = atMidnight(date);

    shifted.setDate(shifted.getDate() + by);

    return shifted;
}

function startOfWeek(date, weekStart = 0) {
    return addDays(date, -((date.getDay() - weekStart + 7) % 7));
}

/**
 * The two ends a preset resolves to, as [from, to]. "All time" is the one that
 * answers with nothing, because no range at all is exactly what it means.
 */
export function presetRange(key, weekStart = 0, today = new Date()) {
    const year = today.getFullYear();
    const month = today.getMonth();

    switch (key) {
        case "today":
            return [atMidnight(today), atMidnight(today)];
        case "yesterday":
            return [addDays(today, -1), addDays(today, -1)];
        case "thisWeek":
            return [startOfWeek(today, weekStart), atMidnight(today)];
        case "lastWeek": {
            const start = addDays(startOfWeek(today, weekStart), -7);

            return [start, addDays(start, 6)];
        }
        case "last7Days":
            return [addDays(today, -6), atMidnight(today)];
        case "last30Days":
            return [addDays(today, -29), atMidnight(today)];
        case "thisMonth":
            return [new Date(year, month, 1), atMidnight(today)];
        case "lastMonth":
            // Day zero of a month is the last day of the one before it.
            return [new Date(year, month - 1, 1), new Date(year, month, 0)];
        case "thisQuarter":
            return [new Date(year, Math.floor(month / 3) * 3, 1), atMidnight(today)];
        case "thisYear":
        case "yearToDate":
            return [new Date(year, 0, 1), atMidnight(today)];
        case "allTime":
            return [null, null];
        default:
            return null;
    }
}

export function parseISO(value) {
    if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return null;
    }

    const [year, month, day] = value.split("-").map(Number);
    const date = new Date(year, month - 1, day);

    // Rejects the 31st of February rather than rolling it into March.
    return date.getMonth() === month - 1 ? date : null;
}

/** YYYY-MM-DD from a Date's local parts. */
export function toISO(date) {
    if (!date) {
        return "";
    }

    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, "0"),
        String(date.getDate()).padStart(2, "0"),
    ].join("-");
}

/**
 * The cells of one month, padded to whole weeks.
 *
 * Leading and trailing blanks are nulls rather than the neighbouring month's days:
 * a grid that renders them invites clicking one, and a date picker that jumps you
 * to a different month on a mis-click is a bug people report as "it picked the
 * wrong date".
 */
export function monthGrid(year, month, weekStart = 0) {
    const first = new Date(year, month, 1);
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const lead = (first.getDay() - weekStart + 7) % 7;

    const cells = Array(lead).fill(null);

    for (let day = 1; day <= daysInMonth; day++) {
        cells.push(new Date(year, month, day));
    }

    while (cells.length % 7 !== 0) {
        cells.push(null);
    }

    return cells;
}

export default function datePicker({
    mode = "single",
    start = "",
    end = "",
    min = "",
    max = "",
    weekStart = 0,
    months = 1,
    format = "medium",
    presets = [],
    startProperty = "",
    endProperty = "",
}) {
    return {
        open: false,

        /** The committed values, as YYYY-MM-DD. The hidden inputs mirror these. */
        start,

        end,

        /** The day under the cursor, so a half-picked range previews as you move. */
        hovering: null,

        /** The left-hand month on show. */
        viewYear: new Date().getFullYear(),

        viewMonth: new Date().getMonth(),

        weekdays: [],

        init() {
            this.weekdays = [
                ...WEEKDAYS.slice(weekStart),
                ...WEEKDAYS.slice(0, weekStart),
            ];

            // Livewire renders the bound value onto the hidden input, so adopt
            // whatever is already there rather than opening on today's month when
            // a date has been chosen. A filtered URL is loaded exactly this way.
            this.start = this.start || this.$refs.startInput?.value || "";
            this.end = this.end || this.$refs.endInput?.value || "";

            this.syncViewToSelection();

            // However the near end came to change, the months on show follow it.
            this.$watch("start", () => this.syncViewToSelection());

            // Livewire owns these values as much as the calendar does: a filter
            // chip, a Clear button or a fresh URL changes them on the server, and
            // what is printed in the box has to be what the listing is actually
            // filtered by. Nothing here reads back on its own -- wire:model writes
            // the new value onto the hidden input and this component never hears of
            // it -- which is how clearing the date filter left the old range sitting
            // in a field the table below had already stopped honouring.
            this.followProperty(startProperty, (value) => (this.start = value));
            this.followProperty(endProperty, (value) => (this.end = value));
        },

        /**
         * Follow a Livewire property for as long as this field is on the page.
         *
         * A date field rendered outside a Livewire component has no $wire and
         * nothing to follow, which is why this asks rather than assumes.
         */
        followProperty(property, apply) {
            if (!property || typeof this.$wire?.$watch !== "function") {
                return;
            }

            this.$wire.$watch(property, (value) => apply(value ?? ""));
        },

        // ||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
        // WHAT IS ON SHOW

        get panels() {
            return Array.from({ length: months }, (_, offset) => {
                const date = new Date(this.viewYear, this.viewMonth + offset, 1);

                return {
                    year: date.getFullYear(),
                    month: date.getMonth(),
                    label: MONTHS[date.getMonth()] + " " + date.getFullYear(),
                    cells: monthGrid(date.getFullYear(), date.getMonth(), weekStart),
                };
            });
        },

        get label() {
            if (mode === "range") {
                if (!this.start && !this.end) {
                    return "";
                }

                return [this.start, this.end]
                    .map((value) => (value ? this.formatted(value) : "..."))
                    .join(" - ");
            }

            return this.start ? this.formatted(this.start) : "";
        },

        get hasValue() {
            return Boolean(this.start || this.end);
        },

        /** The presets this field was given, in the order they were asked for. */
        get presetList() {
            return presets
                .filter((key) => PRESET_LABELS[key])
                .map((key) => ({ key, label: PRESET_LABELS[key] }));
        },

        /**
         * Jump the selection to a named range.
         *
         * A single-date field takes the near end of it and closes; a range takes
         * both and stays open, so the choice can be nudged by hand afterwards.
         */
        applyPreset(key) {
            const range = presetRange(key, weekStart);

            if (!range) {
                return;
            }

            const [from, to] = range;

            if (!from) {
                this.clear();

                return;
            }

            this.start = toISO(from);
            this.end = mode === "range" ? toISO(to) : "";
            this.hovering = null;

            this.commit();
            this.syncViewToSelection();

            if (mode !== "range") {
                this.open = false;
            }
        },

        /** Whether what is currently picked is exactly what a preset stands for. */
        isPreset(key) {
            const range = presetRange(key, weekStart);

            if (!range) {
                return false;
            }

            const [from, to] = range;

            if (!from) {
                return !this.hasValue;
            }

            return (
                this.start === toISO(from) &&
                (mode === "range" ? this.end === toISO(to) : true)
            );
        },

        formatted(value) {
            const date = parseISO(value);

            if (!date) {
                return "";
            }

            if (format === "iso") {
                return value;
            }

            return date.toLocaleDateString(undefined, {
                year: "numeric",
                month: format === "long" ? "long" : "short",
                day: "numeric",
            });
        },

        // ||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
        // MOVING AROUND

        syncViewToSelection() {
            const anchor = parseISO(this.start) ?? parseISO(this.end) ?? new Date();

            this.viewYear = anchor.getFullYear();
            this.viewMonth = anchor.getMonth();
        },

        shiftMonth(by) {
            const shifted = new Date(this.viewYear, this.viewMonth + by, 1);

            this.viewYear = shifted.getFullYear();
            this.viewMonth = shifted.getMonth();
        },

        // ||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
        // THE STATE OF ONE DAY

        disabled(date) {
            if (!date) {
                return true;
            }

            const iso = toISO(date);

            return Boolean((min && iso < min) || (max && iso > max));
        },

        isSelected(date) {
            if (!date) {
                return false;
            }

            const iso = toISO(date);

            return iso === this.start || iso === this.end;
        },

        isToday(date) {
            return date ? toISO(date) === toISO(new Date()) : false;
        },

        /**
         * Between the two ends of the range -- including, while only one end is
         * picked, the stretch out to whatever the cursor is over, so the shape of
         * the selection is visible before it is committed.
         */
        inRange(date) {
            if (mode !== "range" || !date || !this.start) {
                return false;
            }

            const iso = toISO(date);
            const far = this.end || this.hovering;

            if (!far) {
                return false;
            }

            const [from, to] =
                this.start <= far ? [this.start, far] : [far, this.start];

            return iso > from && iso < to;
        },

        // ||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
        // PICKING

        select(date) {
            if (this.disabled(date)) {
                return;
            }

            const iso = toISO(date);

            if (mode !== "range") {
                this.start = iso;
                this.commit();
                this.open = false;

                return;
            }

            // A range picks its two ends in order. A click while both are already
            // set starts a new range rather than stretching the old one, which is
            // what people expect from every other calendar they have used.
            if (!this.start || this.end) {
                this.start = iso;
                this.end = "";
            } else if (iso < this.start) {
                this.end = this.start;
                this.start = iso;
            } else {
                this.end = iso;
            }

            this.commit();

            if (this.start && this.end) {
                this.open = false;
            }
        },

        clear() {
            this.start = "";
            this.end = "";
            this.hovering = null;

            this.commit();
            this.open = false;
        },

        /**
         * Hand the values to Livewire.
         *
         * Written onto the hidden inputs and announced with a real input event,
         * because that is the one thing wire:model listens for -- setting .value
         * from script alone fires nothing and the server never hears about it.
         */
        commit() {
            [
                [this.$refs.startInput, this.start],
                [this.$refs.endInput, this.end],
            ].forEach(([input, value]) => {
                if (!input || input.value === value) {
                    return;
                }

                input.value = value;
                input.dispatchEvent(new Event("input", { bubbles: true }));
                input.dispatchEvent(new Event("change", { bubbles: true }));
            });
        },
    };
}
