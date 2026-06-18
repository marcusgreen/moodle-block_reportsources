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
 * Opens a block's chart full size in a modal, rendered client-side from its JSON definition.
 * The modal offers a fullscreen toggle so the whole chart can fill the viewport.
 *
 * @module     block_reportsources/expand
 * @copyright  2026 Marcus Green
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import Builder from 'core/chart_builder';
import Output from 'core/chart_output_chartjs';
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
 * Build the modal, render the chart into it, and wire the fullscreen toggle.
 *
 * @param {HTMLElement} btn The Expand trigger carrying the chart JSON in data-chart.
 * @returns {Promise<void>}
 */
const openChart = async(btn) => {
    const data = JSON.parse(btn.dataset.chart);
    const [enter, exit] = await Promise.all([
        getString('fullscreen', 'block_reportsources'),
        getString('exitfullscreen', 'block_reportsources'),
    ]);

    const body =
        '<div class="block_reportsources-chartmodal">' +
            '<div class="block_reportsources-charttoolbar">' +
                '<button type="button" class="btn btn-sm btn-secondary" data-rsblock-fullscreen>' + enter + '</button>' +
            '</div>' +
            '<div class="chart-image" role="img"></div>' +
        '</div>';

    const modal = await Modal.create({
        title: btn.dataset.title || '',
        body: body,
        large: true,
        removeOnClose: true,
    });
    await modal.show();

    const wrapper = modal.getBody()[0].querySelector('.block_reportsources-chartmodal');
    const fsbtn = wrapper.querySelector('[data-rsblock-fullscreen]');

    const chart = await Builder.make(data);
    new Output(modal.getBody().find('.chart-image'), chart);

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
