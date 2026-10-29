/**
 * The browser half of the image library's folder import.
 *
 * Folders reach the page two ways: the folder picker (one folder per pick, its
 * files carrying webkitRelativePath) and a drop, which can carry several folders
 * and has to be walked entry by entry. Both end up as the same list of
 * { file, path } pairs, and the path is all the server is told about folders.
 *
 * Nothing is uploaded until Import is pressed. Then the server fixes the settings
 * (startImport), the files go up a batch at a time — each one uploaded, then
 * stored, before the next is sent — and finishImport reports what landed.
 */

const IMAGE_FILE = /\.(jpe?g|png|webp)$/i;

/** Where a file sits on disk: the folder somebody chose, or nothing for a loose file. */
function topOf(path) {
    return path.includes("/") ? path.split("/")[0] : "";
}

function readableSize(bytes) {
    return bytes >= 1048576
        ? `${(bytes / 1048576).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/** A dropped directory hands out its children in pages, so keep asking until one comes back empty. */
async function walk(entry, prefix, found) {
    const path = prefix + entry.name;

    if (entry.isFile) {
        const file = await new Promise((resolve, reject) => entry.file(resolve, reject));

        found.push({ file, path });

        return;
    }

    if (!entry.isDirectory) {
        return;
    }

    const reader = entry.createReader();
    let page;

    do {
        page = await new Promise((resolve, reject) => reader.readEntries(resolve, reject));

        for (const child of page) {
            await walk(child, `${path}/`, found);
        }
    } while (page.length);
}

export default function folderImport({ maxSize, batch = 10 }) {
    return {
        /** @type {Array<{file: File, path: string}>} */
        entries: [],
        ignored: 0,
        dragging: false,
        running: false,
        sent: 0,
        total: 0,

        get progress() {
            return this.total ? Math.round((this.sent / this.total) * 100) : 0;
        },

        /** One row per chosen folder, with loose files gathered under their own row. */
        get groups() {
            const groups = new Map();

            for (const { file, path } of this.entries) {
                const name = topOf(path);
                const group = groups.get(name) ?? { name, count: 0, size: 0, folders: new Set() };

                group.count++;
                group.size += file.size;
                group.folders.add(path.split("/").slice(0, -1).join("/"));
                groups.set(name, group);
            }

            return [...groups.values()].map((group) => ({
                name: group.name,
                label: group.name || "Loose images",
                summary: [
                    `${group.count} image(s)`,
                    group.name && group.folders.size > 1 ? `${group.folders.size - 1} subfolder(s)` : null,
                    readableSize(group.size),
                ]
                    .filter(Boolean)
                    .join(" · "),
            }));
        },

        pick(event) {
            this.add(
                Array.from(event.target.files).map((file) => ({
                    file,
                    path: file.webkitRelativePath || file.name,
                })),
            );

            // Cleared so choosing the same folder again still fires a change.
            event.target.value = "";
        },

        async drop(event) {
            this.dragging = false;

            if (this.running) {
                return;
            }

            // Taken synchronously: the browser empties dataTransfer once the
            // handler yields to the first await.
            const roots = Array.from(event.dataTransfer.items ?? [])
                .map((item) => item.webkitGetAsEntry?.())
                .filter(Boolean);

            const found = [];

            for (const root of roots) {
                await walk(root, "", found);
            }

            this.add(found);
        },

        /** Images under the size ceiling join the list; everything else is counted and left behind. */
        add(found) {
            const known = new Set(this.entries.map(({ file, path }) => `${path}:${file.size}`));

            for (const entry of found) {
                if (!IMAGE_FILE.test(entry.file.name) || entry.file.size / 1024 > maxSize) {
                    this.ignored++;

                    continue;
                }

                const key = `${entry.path}:${entry.file.size}`;

                if (!known.has(key)) {
                    known.add(key);
                    this.entries.push(entry);
                }
            }
        },

        remove(name) {
            this.entries = this.entries.filter(({ path }) => topOf(path) !== name);
        },

        clear() {
            this.entries = [];
            this.ignored = 0;
        },

        upload(files) {
            return new Promise((resolve, reject) => {
                this.$wire.uploadMultiple("importFiles", files, resolve, reject, () => {}, reject, false);
            });
        },

        async start() {
            if (this.running || !this.entries.length) {
                return;
            }

            this.running = true;
            this.sent = 0;
            this.total = this.entries.length;

            let started = false;

            try {
                started = (await this.$wire.startImport()) === true;

                if (!started) {
                    return;
                }

                for (let index = 0; index < this.total; index += batch) {
                    const slice = this.entries.slice(index, index + batch);

                    await this.upload(slice.map(({ file }) => file));

                    const keepGoing = await this.$wire.importChunk(slice.map(({ path }) => path));

                    this.sent = Math.min(index + batch, this.total);

                    if (keepGoing === false) {
                        break;
                    }
                }

                await this.$wire.finishImport();

                this.clear();
            } catch {
                // Whatever arrived before the failure is kept and reported.
                if (started) {
                    await this.$wire.finishImport(true);
                }
            } finally {
                this.running = false;
            }
        },
    };
}
