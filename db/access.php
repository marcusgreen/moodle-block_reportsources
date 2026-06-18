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
 * Capabilities for block_reportsources.
 *
 * @package   block_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Add the block on a course (or any non-dashboard) page. Allowed for editing teachers by
    // default so teachers can self-serve; the report's own RB access still gates the data.
    'block/reportsources:addinstance' => [
        'riskbitmask'          => RISK_SPAM | RISK_XSS,
        'captype'              => 'write',
        'contextlevel'         => CONTEXT_BLOCK,
        'archetypes'           => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/site:manageblocks',
    ],

    // Add the block to the Dashboard (/my). Allowed for all users so each person can pin a report
    // to their own dashboard.
    'block/reportsources:myaddinstance' => [
        'captype'              => 'write',
        'contextlevel'         => CONTEXT_SYSTEM,
        'archetypes'           => [
            'user' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/my:manageblocks',
    ],
];
