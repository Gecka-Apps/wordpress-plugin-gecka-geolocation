/*
 * SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Reveals and masks the secrets typed on the tab of a provider. A secret is
 * typed in a text field the style sheet masks; the button in the field
 * toggles the mask, its icon and its label following. Without this script,
 * the field stays masked and the button stays hidden.
 */
(() => {
    document.querySelectorAll('.gecka-geolocation-secret').forEach((wrap) => {
        const button = wrap.querySelector('.gecka-geolocation-reveal');
        const input = wrap.querySelector('input');
        const icon = button?.querySelector('.dashicons');

        if (!button || !input) {
            return;
        }

        button.addEventListener('click', () => {
            const revealed = wrap.classList.toggle('is-revealed');

            button.setAttribute('aria-pressed', revealed ? 'true' : 'false');
            button.setAttribute('aria-label', revealed ? button.dataset.hide : button.dataset.show);
            icon?.classList.toggle('dashicons-visibility', !revealed);
            icon?.classList.toggle('dashicons-hidden', revealed);
            input.focus();
        });
    });
})();
