// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Opens a block's chart full size in a modal. The chart is a server-rendered SVG image (see
 * local_reportsources\local\chart_svg), so the modal only enlarges the same picture — no
 * client-side charting library. A fullscreen toggle lets the chart fill the viewport.
 *
 * @module     block_reportsources/expand
 * @copyright  2026 Marcus Green
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import Notification from 'core/notification';
import {getString} from 'core/str';

/**
 * Bind every Expand trigger that has not already been wired up.
 */
export const init = () => {
    document.querySelectorAll('[data-rsblock-expand]').forEach((btn) => {
        if (btn.dataset.rsbound) {
            return;
        }
        btn.dataset.rsbound = '1';
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            openChart(btn).catch(Notification.exception);
        });
    });
};

/**
 * Build the modal, drop the enlarged SVG into it, and wire the fullscreen toggle.
 *
 * @param {HTMLElement} btn The Expand trigger carrying the SVG data URI in data-chart-src.
 * @returns {Promise<void>}
 */
const openChart = async(btn) => {
    const src = btn.dataset.chartSrc;
    const title = btn.dataset.title || '';
    const [enter, exit, download, svglabel, pnglabel] = await Promise.all([
        getString('fullscreen', 'block_reportsources'),
        getString('exitfullscreen', 'block_reportsources'),
        getString('download', 'block_reportsources'),
        getString('downloadsvg', 'block_reportsources'),
        getString('downloadpng', 'block_reportsources'),
    ]);

    const img = document.createElement('img');
    img.src = src;
    img.alt = title;
    img.className = 'img-fluid';
    img.style.maxWidth = '100%';
    img.style.height = 'auto';

    const body =
        '<div class="block_reportsources-chartmodal">' +
            '<div class="block_reportsources-charttoolbar">' +
                '<button type="button" class="btn btn-sm btn-secondary" data-rsblock-fullscreen>' + enter + '</button>' +
                '<div class="dropdown d-inline-block ml-2">' +
                    '<button type="button" class="btn btn-sm btn-secondary dropdown-toggle" ' +
                        'data-toggle="dropdown" data-bs-toggle="dropdown" aria-haspopup="true" ' +
                        'aria-expanded="false" data-rsblock-download>' + download + '</button>' +
                    '<div class="dropdown-menu">' +
                        '<button type="button" class="dropdown-item" data-rsblock-dl="svg">' + svglabel + '</button>' +
                        '<button type="button" class="dropdown-item" data-rsblock-dl="png">' + pnglabel + '</button>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="chart-image" role="img"></div>' +
        '</div>';

    const modal = await Modal.create({
        title: title,
        body: body,
        large: true,
        removeOnClose: true,
    });
    await modal.show();

    const wrapper = modal.getBody()[0].querySelector('.block_reportsources-chartmodal');
    const fsbtn = wrapper.querySelector('[data-rsblock-fullscreen]');
    wrapper.querySelector('.chart-image').appendChild(img);

    // When the report source has "Show data table" enabled, the block renders that table beside the
    // Expand button (shared markup, class local-reportsources-chart-data). Mirror it into the modal
    // below the enlarged image by cloning the existing DOM — so the modal always matches the inline
    // table with no second builder. Appended after .chart-image (not inside it — that has role=img).
    const dataTable = btn.parentElement && btn.parentElement.querySelector('.local-reportsources-chart-data');
    if (dataTable) {
        const clone = dataTable.cloneNode(true);
        clone.classList.add('mt-3');
        wrapper.appendChild(clone);
        // The default layout gives the image the whole (overflow-hidden) box, which would clip the
        // table below it. This class switches the modal to a scrolling flow with a natural-height
        // image so both the chart and the table are visible. See styles.css.
        wrapper.classList.add('has-datatable');
    }

    const filename = (title.replace(/[^\w.-]+/g, '_').replace(/^_+|_+$/g, '') || 'chart');
    wrapper.querySelectorAll('[data-rsblock-dl]').forEach((item) => {
        item.addEventListener('click', () => {
            downloadChart(src, filename, item.dataset.rsblockDl).catch(Notification.exception);
        });
    });

    fsbtn.addEventListener('click', () => {
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else if (wrapper.requestFullscreen) {
            wrapper.requestFullscreen().catch(Notification.exception);
        }
    });

    // Keep the button label in step with the actual fullscreen state (Esc also exits).
    document.addEventListener('fullscreenchange', () => {
        fsbtn.textContent = document.fullscreenElement === wrapper ? exit : enter;
    });
};

/**
 * Download the chart in the requested format.
 *
 * SVG is the source image, saved verbatim. PNG is rasterised in the browser: the SVG is painted
 * onto a canvas at its natural size (on a white ground so transparency does not turn black) and
 * exported. Nothing leaves the browser.
 *
 * @param {String} src The SVG data URI.
 * @param {String} filename Base name (no extension).
 * @param {String} format 'svg' or 'png'.
 * @returns {Promise<void>}
 */
const downloadChart = async(src, filename, format) => {
    if (format === 'svg') {
        triggerDownload(src, filename + '.svg');
        return;
    }
    const png = await new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = image.naturalWidth || 900;
            canvas.height = image.naturalHeight || 560;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(image, 0, 0, canvas.width, canvas.height);
            resolve(canvas.toDataURL('image/png'));
        };
        image.onerror = reject;
        image.src = src;
    });
    triggerDownload(png, filename + '.png');
};

/**
 * Click a transient anchor to save a data URI to disk.
 *
 * @param {String} href The data URI.
 * @param {String} name The download filename.
 */
const triggerDownload = (href, name) => {
    const link = document.createElement('a');
    link.download = name;
    link.href = href;
    link.click();
};
