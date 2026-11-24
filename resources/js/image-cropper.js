/**
 * The browser half of cropping a library image.
 *
 * Nothing is cut here. The box is kept as fractions of the picture (0 to 1), so
 * it means the same thing however large the preview happens to be drawn, and
 * the server turns those fractions into pixels against the stored file. A
 * preview scaled down to fit a phone still crops the full-size original.
 *
 * `ratio` is width over height, or null for a free box. Changing it re-fits the
 * box around its own centre rather than resetting it, so somebody who has lined
 * up a face does not lose it by picking 1:1.
 */

const MIN_SIZE = 0.05;

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

export default function imageCropper({ width = 0, height = 0 } = {}) {
    return {
        box: { x: 0.1, y: 0.1, w: 0.8, h: 0.8 },

        ratio: null,

        /** The stored picture's own shape, so a 1:1 box is square on the image and not on the preview. */
        imageRatio: width && height ? width / height : 1,

        drag: null,

        get style() {
            const { x, y, w, h } = this.box;

            return `left:${x * 100}%;top:${y * 100}%;width:${w * 100}%;height:${h * 100}%`;
        },

        /** Read by the save button, which hands the four numbers to the component. */
        get rect() {
            const round = (value) => Math.round(value * 10000) / 10000;

            return [round(this.box.x), round(this.box.y), round(this.box.w), round(this.box.h)];
        },

        setRatio(ratio) {
            this.ratio = ratio;

            if (!ratio) {
                return;
            }

            // A ratio on the picture is a different ratio on the fractions,
            // because the fractions of a wide image are not square.
            const fractional = ratio / this.imageRatio;
            const centreX = this.box.x + this.box.w / 2;
            const centreY = this.box.y + this.box.h / 2;

            let w = this.box.w;
            let h = w / fractional;

            if (h > 1) {
                h = 1;
                w = h * fractional;
            }

            if (w > 1) {
                w = 1;
                h = w / fractional;
            }

            this.box = {
                w,
                h,
                x: clamp(centreX - w / 2, 0, 1 - w),
                y: clamp(centreY - h / 2, 0, 1 - h),
            };
        },

        reset() {
            this.box = { x: 0, y: 0, w: 1, h: 1 };
            this.setRatio(this.ratio);
        },

        /**
         * `handle` is "move" for the body of the box, or the corner being pulled:
         * "nw", "ne", "sw", "se". The opposite corner stays where it is.
         */
        start(event, handle) {
            event.preventDefault();

            const frame = this.$refs.frame.getBoundingClientRect();

            this.drag = { handle, frame, startX: event.clientX, startY: event.clientY, box: { ...this.box } };

            event.target.setPointerCapture?.(event.pointerId);
        },

        move(event) {
            if (!this.drag) {
                return;
            }

            const { handle, frame, startX, startY, box } = this.drag;
            const dx = (event.clientX - startX) / frame.width;
            const dy = (event.clientY - startY) / frame.height;

            if (handle === "move") {
                this.box = {
                    ...box,
                    x: clamp(box.x + dx, 0, 1 - box.w),
                    y: clamp(box.y + dy, 0, 1 - box.h),
                };

                return;
            }

            const anchorX = handle.includes("w") ? box.x + box.w : box.x;
            const anchorY = handle.includes("n") ? box.y + box.h : box.y;
            const pointerX = clamp((handle.includes("w") ? box.x : box.x + box.w) + dx, 0, 1);
            const pointerY = clamp((handle.includes("n") ? box.y : box.y + box.h) + dy, 0, 1);

            let w = Math.max(Math.abs(pointerX - anchorX), MIN_SIZE);
            let h = Math.max(Math.abs(pointerY - anchorY), MIN_SIZE);

            if (this.ratio) {
                const fractional = this.ratio / this.imageRatio;

                // The longer pull wins, and the other side follows it. Then the box
                // is held inside the picture on whichever side it grows towards.
                w / h > fractional ? (h = w / fractional) : (w = h * fractional);

                const roomX = handle.includes("w") ? anchorX : 1 - anchorX;
                const roomY = handle.includes("n") ? anchorY : 1 - anchorY;

                if (w > roomX) {
                    w = roomX;
                    h = w / fractional;
                }

                if (h > roomY) {
                    h = roomY;
                    w = h * fractional;
                }
            } else {
                w = Math.min(w, handle.includes("w") ? anchorX : 1 - anchorX);
                h = Math.min(h, handle.includes("n") ? anchorY : 1 - anchorY);
            }

            this.box = {
                w,
                h,
                x: handle.includes("w") ? anchorX - w : anchorX,
                y: handle.includes("n") ? anchorY - h : anchorY,
            };
        },

        stop() {
            this.drag = null;
        },
    };
}
