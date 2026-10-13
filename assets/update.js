/*
 * SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Updates the databases of a provider through the REST routes: one request
 * asks the provider for the latest releases and queues the databases to
 * download, then one request per database, the progress showing which.
 * Each provider has its box; the updates run one after the other, and the
 * page reloads once the last is over. A box starts by itself when the page
 * says the settings saved queued downloads. Without this script, the button
 * posts its form, and the scheduled event does the rest.
 */
(() => {
    const boxes = document.querySelectorAll('.gecka-geolocation-update');

    if (!boxes.length || !window.fetch) {
        return;
    }

    const fill = (template, values) => template.replace(/%(\d)\$s/g, (match, index) => values[index - 1] ?? match);

    const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    let queue = Promise.resolve();
    let waiting = 0;
    let failed = false;
    let finishedAny = false;

    const setup = (box) => {
        const form = box.querySelector('form');
        const button = form.querySelector('button[type="submit"]');
        const progress = box.querySelector('progress');
        const status = box.querySelector('.gecka-geolocation-status');

        const post = async (url) => {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': box.dataset.nonce },
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            return response.json();
        };

        // Another request, the scheduled event or another tab, holds the
        // lock: the step is posted again every 5 s, for five minutes at
        // most, since a download can take that long.
        const step = async (url, label) => {
            for (let tries = 0; ; tries++) {
                const data = await post(url);

                if (!data.busy) {
                    return data;
                }

                if (tries >= 60) {
                    throw new Error('busy');
                }

                status.textContent = box.dataset.busy;
                await wait(5000);
                status.textContent = label;
            }
        };

        const run = async (clicked) => {
            // After a click, the focus leaves the button it disables for the
            // status, which tells the progress.
            if (clicked) {
                status.tabIndex = -1;
                status.focus();
            }

            progress.hidden = false;
            progress.removeAttribute('value');
            status.textContent = box.dataset.checking;

            try {
                const plan = await step(box.dataset.check, box.dataset.checking);
                const databases = plan.pending || [];

                failed = failed || Boolean(plan.error) || Boolean(plan.failed);
                progress.max = Math.max(1, databases.length);
                progress.value = 0;

                for (let index = 0; index < databases.length; index++) {
                    const label = fill(box.dataset.progress, [databases[index].label, index + 1, databases.length]);

                    status.textContent = label;

                    const result = await step(box.dataset.next, label);

                    failed = failed || Boolean(result.error);
                    progress.value = index + 1;

                    if (result.done) {
                        break;
                    }
                }

                return true;
            } catch (error) {
                failed = true;
                progress.hidden = true;
                status.textContent = box.dataset.error;
                button.disabled = false;

                if (clicked) {
                    button.focus();
                }

                return false;
            }
        };

        // Runs after the updates already queued; the page reloads once none
        // is left and at least one went to its end, the interrupted ones
        // counting as failed.
        const enqueue = (clicked) => {
            button.disabled = true;
            waiting++;
            queue = queue.then(async () => {
                finishedAny = (await run(clicked)) || finishedAny;

                if (--waiting === 0 && finishedAny) {
                    const done = new URL(box.dataset.done, window.location.href);

                    done.searchParams.set('updated', failed ? 'failed' : '1');
                    window.location.assign(done.toString());
                }
            });
        };

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            enqueue(true);
        });

        if (box.dataset.autostart) {
            enqueue(false);
        }
    };

    boxes.forEach(setup);
})();
