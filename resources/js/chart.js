/**
 * The chart engine behind <x-chart>.
 *
 * There is no charting library here, and there is not meant to be one. A chart is
 * an <svg> whose geometry is arithmetic over the rows you already queried: the
 * parent measures itself, works out where a value sits in pixels, and each child
 * component asks it for the shape it needs. That keeps the bundle at Livewire +
 * Alpine, gives dark mode for free through currentColor, and leaves the data in
 * PHP where the rest of the project keeps it.
 *
 * Children hand back SVG markup rather than nesting <template x-for> inside the
 * <svg>. An HTML parser puts <template> in the SVG namespace, where it has no
 * .content for Alpine to clone, so x-for silently renders nothing there.
 */

const DEFAULT_TICK_COUNT = 5;

const ESCAPES = {
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#39;",
};

/** Rows arrive from JSON or an entangled property, so a value may be a string. */
function toNumber(value) {
    const number = Number(value);

    return Number.isFinite(number) ? number : 0;
}

/** Labels come from user data and are written into innerHTML, so they are escaped. */
function escapeText(value) {
    return String(value ?? "").replace(/[&<>"']/g, (character) => ESCAPES[character]);
}

/** "85%" against a band, or a flat pixel width. */
function resolveWidth(width, band) {
    if (typeof width === "string" && width.trim().endsWith("%")) {
        return (parseFloat(width) / 100) * band;
    }

    return width === null || width === undefined ? band * 0.65 : toNumber(width);
}

function looksLikeDate(value) {
    return typeof value === "string" && /^\d{4}-\d{2}(-\d{2})?/.test(value);
}

/**
 * Monotone cubic interpolation — the curve d3 calls curveMonotoneX.
 *
 * A plain Catmull-Rom spline overshoots on a spike, drawing a dip below zero
 * between two rising points. On a sign-up chart that reads as a bug, so the
 * tangents are flattened wherever the data changes direction.
 */
function smoothPath(points) {
    const count = points.length;
    const slopes = [];
    const spans = [];
    const tangents = [];

    for (let index = 0; index < count - 1; index++) {
        spans[index] = points[index + 1].x - points[index].x;
        slopes[index] =
            spans[index] === 0 ? 0 : (points[index + 1].y - points[index].y) / spans[index];
    }

    tangents[0] = slopes[0];

    for (let index = 1; index < count - 1; index++) {
        if (slopes[index - 1] * slopes[index] <= 0) {
            tangents[index] = 0;

            continue;
        }

        const before = 2 * spans[index] + spans[index - 1];
        const after = spans[index] + 2 * spans[index - 1];

        tangents[index] =
            (before + after) / (before / slopes[index - 1] + after / slopes[index]);
    }

    tangents[count - 1] = slopes[count - 2];

    let path = `M${points[0].x},${points[0].y}`;

    for (let index = 0; index < count - 1; index++) {
        const control = spans[index] / 3;

        path +=
            `C${points[index].x + control},${points[index].y + control * tangents[index]}` +
            ` ${points[index + 1].x - control},${points[index + 1].y - control * tangents[index + 1]}` +
            ` ${points[index + 1].x},${points[index + 1].y}`;
    }

    return path;
}

export default function chart({ data = [], gutter = [8, 8, 8, 8] } = {}) {
    return {
        rows: data,
        gutter,
        width: 0,
        height: 0,
        svgEl: null,
        observer: null,

        /** Filled by x-init on the mark and axis components as Alpine walks the tree. */
        series: [],
        axes: {},

        /** Index of the row under the pointer, or null. */
        hover: null,

        // ||||||||||||||||||||||||||
        // MEASURING

        /**
         * Widths come from CSS, so they are only knowable in the browser. Every
         * geometry getter below reads this.width, which makes the whole chart
         * redraw through Alpine's reactivity the moment the box changes.
         */
        measure(element) {
            this.svgEl = element;

            this.observer = new ResizeObserver(() => {
                const box = element.getBoundingClientRect();

                this.width = box.width;
                this.height = box.height;
            });

            this.observer.observe(element);
        },

        destroy() {
            this.observer?.disconnect();
        },

        registerSeries(series) {
            const already = this.series.some(
                (existing) => existing.field === series.field && existing.type === series.type,
            );

            if (already) {
                return;
            }

            this.series.push(series);
        },

        registerAxis(name, config) {
            this.axes[name] = config;
        },

        // ||||||||||||||||||||||||||
        // SCALES

        get count() {
            return this.rows.length;
        },

        get plot() {
            const [top, right, bottom, left] = this.gutter;

            return {
                x: left,
                y: top,
                w: Math.max(0, this.width - left - right),
                h: Math.max(0, this.height - top - bottom),
            };
        },

        /** Bars occupy a band; a line drawn beside them has to sit on the same centres. */
        get banded() {
            return this.series.some((series) => series.type === "bar");
        },

        get bandWidth() {
            return this.count ? this.plot.w / this.count : 0;
        },

        posX(index) {
            if (this.banded) {
                return this.plot.x + this.bandWidth * (index + 0.5);
            }

            if (this.count <= 1) {
                return this.plot.x + this.plot.w / 2;
            }

            return this.plot.x + (index * this.plot.w) / (this.count - 1);
        },

        /**
         * The y domain spans every registered series so two lines share one scale,
         * rounded out to a step that divides into readable ticks.
         */
        get domain() {
            const configured = this.axes.y ?? {};
            let min = null;
            let max = null;

            for (const row of this.rows) {
                for (const series of this.series) {
                    const value = toNumber(row[series.field]);

                    min = min === null ? value : Math.min(min, value);
                    max = max === null ? value : Math.max(max, value);
                }
            }

            if (min === null) {
                min = 0;
                max = 1;
            }

            // A bar chart that does not start at zero misstates its own comparison.
            if (min > 0) {
                min = 0;
            }

            if (max === min) {
                max = min + 1;
            }

            if (configured.min !== null && configured.min !== undefined) {
                min = toNumber(configured.min);
            }

            if (configured.max !== null && configured.max !== undefined) {
                max = toNumber(configured.max);
            }

            return this.niceDomain(min, max, configured.tickCount ?? DEFAULT_TICK_COUNT);
        },

        /** Round the step to 1, 2, 5 or 10 × a power of ten so ticks read as round numbers. */
        niceDomain(min, max, tickCount) {
            const rawStep = (max - min) / Math.max(1, tickCount - 1);
            const magnitude = Math.pow(10, Math.floor(Math.log10(rawStep || 1)));
            const normalised = rawStep / magnitude;
            const step =
                (normalised <= 1 ? 1 : normalised <= 2 ? 2 : normalised <= 5 ? 5 : 10) * magnitude;

            return {
                min: Math.floor(min / step) * step,
                max: Math.ceil(max / step) * step,
                step,
            };
        },

        posY(value) {
            const domain = this.domain;
            const span = domain.max - domain.min || 1;

            return (
                this.plot.y + this.plot.h - ((toNumber(value) - domain.min) / span) * this.plot.h
            );
        },

        /** Where a bar or area closes: the zero line, or the floor if everything is positive. */
        get baseline() {
            return this.posY(Math.max(this.domain.min, 0));
        },

        pointsFor(field) {
            return this.rows.map((row, index) => ({
                x: this.posX(index),
                y: this.posY(row[field]),
            }));
        },

        // ||||||||||||||||||||||||||
        // MARKS

        linePath(field, curve = "smooth") {
            const points = this.pointsFor(field);

            if (!points.length) {
                return "";
            }

            if (points.length === 1) {
                return `M${points[0].x},${points[0].y}`;
            }

            return curve === "smooth"
                ? smoothPath(points)
                : "M" + points.map((point) => `${point.x},${point.y}`).join("L");
        },

        renderLine(field, { curve, width }) {
            const path = this.linePath(field, curve);

            if (!path) {
                return "";
            }

            return (
                `<path d="${path}" fill="none" stroke="currentColor" stroke-width="${width}"` +
                ` stroke-linecap="round" stroke-linejoin="round" />`
            );
        },

        renderArea(field, { curve, opacity }) {
            const points = this.pointsFor(field);

            if (points.length < 2) {
                return "";
            }

            const base = this.baseline;
            const path =
                this.linePath(field, curve) +
                `L${points[points.length - 1].x},${base}L${points[0].x},${base}Z`;

            return `<path d="${path}" fill="currentColor" fill-opacity="${opacity}" stroke="none" />`;
        },

        renderBars(field, { radius, width }) {
            const barWidth = Math.max(0, resolveWidth(width, this.bandWidth));
            const base = this.baseline;

            return this.rows
                .map((row, index) => {
                    const y = this.posY(row[field]);
                    const top = Math.min(y, base);
                    // A zero-height rect reads as a rendering fault, so keep a sliver.
                    const height = Math.max(1, Math.abs(base - y));

                    return (
                        `<rect x="${this.posX(index) - barWidth / 2}" y="${top}"` +
                        ` width="${barWidth}" height="${height}" rx="${radius}" fill="currentColor" />`
                    );
                })
                .join("");
        },

        renderPoints(field, { radius, strokeWidth }) {
            return this.rows
                .map((row, index) => {
                    const stroke = strokeWidth
                        ? ` stroke="var(--color-white)" stroke-width="${strokeWidth}"`
                        : "";

                    return (
                        `<circle cx="${this.posX(index)}" cy="${this.posY(row[field])}"` +
                        ` r="${radius}" fill="currentColor"${stroke} />`
                    );
                })
                .join("");
        },

        // ||||||||||||||||||||||||||
        // AXES

        yTickValues() {
            const domain = this.domain;
            const values = [];

            for (
                let value = domain.min;
                value <= domain.max + domain.step / 1000;
                value += domain.step
            ) {
                values.push(value);
            }

            return values;
        },

        /** Thin the x ticks rather than letting twelve months overlap into mush. */
        xTickIndexes() {
            const wanted = this.axes.x?.tickCount ?? this.count;
            const stride = Math.max(1, Math.ceil(this.count / Math.max(1, wanted)));
            const indexes = [];

            for (let index = 0; index < this.count; index += stride) {
                indexes.push(index);
            }

            return indexes;
        },

        axisLabel(name, indexOrValue) {
            const axis = this.axes[name] ?? {};

            if (name === "y") {
                return this.formatValue(indexOrValue, axis.format, "linear", axis);
            }

            const row = this.rows[indexOrValue];
            const raw = axis.field ? row?.[axis.field] : indexOrValue;

            return this.formatValue(raw, axis.format, axis.scale, axis);
        },

        formatValue(value, options, scale, axis = {}) {
            if (value === null || value === undefined) {
                return "";
            }

            let formatted;

            if (scale === "time" || (options && looksLikeDate(value))) {
                const date = new Date(value);

                formatted = Number.isNaN(date.getTime())
                    ? String(value)
                    : new Intl.DateTimeFormat(undefined, options ?? {}).format(date);
            } else if (typeof value === "number" || scale === "linear") {
                formatted = new Intl.NumberFormat(undefined, options ?? {}).format(toNumber(value));
            } else {
                formatted = String(value);
            }

            return `${axis.tickPrefix ?? ""}${formatted}${axis.tickSuffix ?? ""}`;
        },

        renderGrid(name) {
            const plot = this.plot;

            if (name === "y") {
                return this.yTickValues()
                    .map((value) => {
                        const y = this.posY(value);

                        return (
                            `<line x1="${plot.x}" y1="${y}" x2="${plot.x + plot.w}" y2="${y}"` +
                            ` stroke="currentColor" />`
                        );
                    })
                    .join("");
            }

            return this.xTickIndexes()
                .map((index) => {
                    const x = this.posX(index);

                    return (
                        `<line x1="${x}" y1="${plot.y}" x2="${x}" y2="${plot.y + plot.h}"` +
                        ` stroke="currentColor" />`
                    );
                })
                .join("");
        },

        renderAxisLine(name) {
            const plot = this.plot;

            if (name === "y") {
                return (
                    `<line x1="${plot.x}" y1="${plot.y}" x2="${plot.x}" y2="${plot.y + plot.h}"` +
                    ` stroke="currentColor" />`
                );
            }

            const y = plot.y + plot.h;

            return (
                `<line x1="${plot.x}" y1="${y}" x2="${plot.x + plot.w}" y2="${y}"` +
                ` stroke="currentColor" />`
            );
        },

        renderTicks(name) {
            const plot = this.plot;

            if (name === "y") {
                return this.yTickValues()
                    .map((value) => {
                        const label = escapeText(this.axisLabel("y", value));

                        return (
                            `<text x="${plot.x - 8}" y="${this.posY(value)}" text-anchor="end"` +
                            ` dominant-baseline="middle">${label}</text>`
                        );
                    })
                    .join("");
            }

            return this.xTickIndexes()
                .map((index) => {
                    const label = escapeText(this.axisLabel("x", index));

                    return (
                        `<text x="${this.posX(index)}" y="${plot.y + plot.h + 18}"` +
                        ` text-anchor="middle">${label}</text>`
                    );
                })
                .join("");
        },

        // ||||||||||||||||||||||||||
        // POINTER

        /**
         * Snap to the nearest column rather than tracking the cursor freely — the
         * reading is per row, so a tooltip between two months would be a lie.
         */
        onPointer(event) {
            if (!this.count || !this.svgEl) {
                return;
            }

            const x = event.clientX - this.svgEl.getBoundingClientRect().left;
            let nearest = 0;
            let shortest = Infinity;

            for (let index = 0; index < this.count; index++) {
                const distance = Math.abs(this.posX(index) - x);

                if (distance < shortest) {
                    shortest = distance;
                    nearest = index;
                }
            }

            this.hover = nearest;
        },

        clearPointer() {
            this.hover = null;
        },

        get hovering() {
            return this.hover !== null && this.rows[this.hover] !== undefined;
        },

        get hoverRow() {
            return this.hovering ? this.rows[this.hover] : null;
        },

        renderCursor(type) {
            if (!this.hovering) {
                return "";
            }

            const plot = this.plot;

            if (type === "area") {
                const width = this.banded ? this.bandWidth : plot.w / Math.max(1, this.count);

                return (
                    `<rect x="${this.posX(this.hover) - width / 2}" y="${plot.y}"` +
                    ` width="${width}" height="${plot.h}" fill="currentColor" />`
                );
            }

            const x = this.posX(this.hover);

            return (
                `<line x1="${x}" y1="${plot.y}" x2="${x}" y2="${plot.y + plot.h}"` +
                ` stroke="currentColor" />`
            );
        },

        /**
         * Kept inside the box so a tooltip on the first or last column is not
         * clipped. Visibility is x-show's job, so this never returns a display —
         * the two would fight over the same style attribute.
         */
        get tooltipStyle() {
            if (!this.hovering) {
                return "left:0px;top:0px";
            }

            const x = Math.min(Math.max(this.posX(this.hover), 72), Math.max(72, this.width - 72));

            return `left:${x}px;top:${this.plot.y}px`;
        },

        cell(field, options, scale) {
            const row = this.hoverRow;

            return row ? this.formatValue(row[field], options, scale) : "";
        },
    };
}
