<?php
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
 * Instance configuration form for block_reportsources.
 *
 * @package   block_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_reportsources\local\query;

/**
 * Per-instance settings: which report to show, how to render it, and how many rows.
 */
class block_reportsources_edit_form extends block_edit_form {

    /**
     * Define the block-specific config fields.
     *
     * @param MoodleQuickForm $mform
     */
    protected function specific_definition($mform): void {
        $mform->addElement('header', 'configheader', get_string('blocksettings', 'block'));

        $menu = ['' => get_string('choosereport', 'block_reportsources')] + query::published_menu();
        $mform->addElement('select', 'config_queryid', get_string('report', 'block_reportsources'), $menu);
        $mform->setType('config_queryid', PARAM_INT);
        $mform->addHelpButton('config_queryid', 'report', 'block_reportsources');

        $mform->addElement('select', 'config_displaymode', get_string('displaymode', 'block_reportsources'), [
            'auto'  => get_string('modeauto', 'block_reportsources'),
            'table' => get_string('modetable', 'block_reportsources'),
            'chart' => get_string('modechart', 'block_reportsources'),
        ]);
        $mform->setType('config_displaymode', PARAM_ALPHA);
        $mform->setDefault('config_displaymode', 'auto');

        $mform->addElement('text', 'config_rowlimit', get_string('rowlimit', 'block_reportsources'));
        $mform->setType('config_rowlimit', PARAM_INT);
        $mform->setDefault('config_rowlimit', 10);

        $mform->addElement('text', 'config_title', get_string('blocktitle', 'block_reportsources'));
        $mform->setType('config_title', PARAM_TEXT);
    }
}
