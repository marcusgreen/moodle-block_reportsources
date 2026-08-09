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
 * Block that renders a local_reportsources report inline.
 *
 * @package   block_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_reportsources\local\query;

/**
 * Surfaces one published report (table or chart) on course pages, the site front page, or the
 * Dashboard. The report's per-user / teacher-course row filters are applied by the shared fetch
 * path, so one shared block instance shows each viewer only their own rows.
 */
class block_reportsources extends block_base {

    /**
     * Set the default block title.
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_reportsources');
    }

    /**
     * Allow several instances on a page (e.g. one per report).
     *
     * @return bool
     */
    public function instance_allow_multiple(): bool {
        return true;
    }

    /**
     * This block stores per-instance config (chosen report, display mode, …).
     *
     * @return bool
     */
    public function instance_allow_config(): bool {
        return true;
    }

    /**
     * Where the block may be added.
     *
     * @return array<string, bool>
     */
    public function applicable_formats(): array {
        return [
            'course-view' => true,
            'site'        => true,
            'my'          => true,
        ];
    }

    /**
     * Override the title with the configured custom title, or the chosen report's name.
     */
    public function specialization(): void {
        if (!empty($this->config->title)) {
            $this->title = format_string($this->config->title);
            return;
        }
        if (!empty($this->config->queryid)) {
            $menu = query::published_menu();
            if (isset($menu[(int) $this->config->queryid])) {
                $this->title = $menu[(int) $this->config->queryid];
            }
        }
    }

    /**
     * Build the block body.
     *
     * @return stdClass|null
     */
    public function get_content(): ?stdClass {
        global $OUTPUT;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text   = '';
        $this->content->footer = '';

        $queryid = (int) ($this->config->queryid ?? 0);
        if (!$queryid) {
            // Unconfigured: prompt only while editing, otherwise stay empty so the block hides.
            if ($this->page->user_is_editing()) {
                $this->content->text = get_string('notconfigured', 'block_reportsources');
            }
            return $this->content;
        }

        try {
            $query = query::get($queryid);
        } catch (\dml_missing_record_exception $e) {
            return $this->content;
        }

        // Honour the same access as the RB report viewer. No access → empty content → block hides.
        if (!$query->current_user_can_view_report()) {
            return $this->content;
        }

        $rec      = $query->record();
        $rowlimit = max(1, min(100, (int) ($this->config->rowlimit ?? 10)));

        // On a course page, pass the current course id so a query with a pagecoursecolumn shows
        // only that course's rows. Off a course (Dashboard/site front page) the page course is
        // SITEID, which is not a real course scope — pass 0 so the page-course filter is skipped
        // and the viewer sees all rows their other filters/audience allow.
        $pagecourseid = (int) $this->page->course->id;
        if ($pagecourseid === SITEID) {
            $pagecourseid = 0;
        }

        try {
            $rows = $query->fetch_rows_for_viewer($rowlimit, $pagecourseid);
        } catch (\moodle_exception $e) {
            // Misconfigured filter column (fails closed). Show a neutral message, never raw rows.
            $this->content->text = get_string('errdata', 'block_reportsources');
            return $this->content;
        }

        $chartmeta = $rec->chartmeta ? json_decode($rec->chartmeta, true) : [];
        $haschart  = !empty($chartmeta['type']) && $chartmeta['type'] !== 'none';
        $mode      = $this->config->displaymode ?? 'auto';

        if ($rows && ($mode === 'chart' || ($mode === 'auto' && $haschart))) {
            $this->content->text = $this->render_chart($rows, $chartmeta, $query);
        } else {
            $this->content->text = $this->render_table($rows, $query);
        }

        if (!empty($rec->reportid)) {
            $url = new moodle_url('/reportbuilder/view.php', ['id' => (int) $rec->reportid]);
            $this->content->footer = html_writer::link($url, get_string('viewfull', 'block_reportsources'));
        }

        return $this->content;
    }

    /**
     * Render the rows as a simple table.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return string
     */
    protected function render_table(array $rows, \local_reportsources\local\query $query): string {
        if (!$rows) {
            return html_writer::tag('p', get_string('norows', 'block_reportsources'), ['class' => 'text-muted']);
        }
        // Per-column display metadata, keyed lower-case so the lookup is case-insensitive against the
        // row keys. Drives the same %%TIMESTAMP() date formatting and %%CASE() text-case transforms
        // the RB data report applies via column callbacks — both are display-only (the stored value is
        // raw), so without this the block table diverges from the report.
        $meta = array_change_key_case($query->columns_meta());

        $table = new html_table();
        $table->head = array_map('s', array_keys($rows[0]));
        $table->attributes['class'] = 'table table-sm';
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $col => $v) {
                $m = $meta[strtolower((string) $col)] ?? [];
                if (($m['type'] ?? '') === 'timestamp') {
                    // Raw epoch → formatted date, using the column's saved format (else the default).
                    $cells[] = ($v === null || $v === '')
                        ? ''
                        : s(userdate((int) $v, \local_reportsources\local\query::strftime_format(
                            (string) ($m['dateformat'] ?? '')), 99, false));
                } else if (!empty($m['textcase'])) {
                    // Display-only case transform on the raw text.
                    $cells[] = s(\local_reportsources\local\query::format_textcase((string) $v, (string) $m['textcase']));
                } else {
                    // Cells may contain author-authored HTML (e.g. a CONCAT'd course link), exactly as
                    // the RB report renders it. format_text() keeps anchors but strips scripts, so the
                    // block is no less safe than the report. Filters are off so plain values pass through.
                    $cells[] = self::open_links_in_new_tab(
                        format_text((string) $v, FORMAT_HTML, ['filter' => false])
                    );
                }
            }
            $table->data[] = $cells;
        }
        return html_writer::table($table);
    }

    /**
     * Force every anchor in cleaned cell HTML to open in a new tab. clean_text() strips author
     * target attributes, so links would otherwise navigate the current tab (losing the dashboard).
     * rel="noopener noreferrer" closes the reverse-tabnabbing hole that a bare target="_blank" opens.
     *
     * @param string $html Cleaned cell HTML.
     * @return string
     */
    protected static function open_links_in_new_tab(string $html): string {
        return preg_replace(
            '/<a\b(?![^>]*\btarget=)/i',
            '<a target="_blank" rel="noopener noreferrer"',
            $html
        );
    }

    /**
     * Render the rows as a chart, using the report's saved chart config.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $chartmeta
     * @param \local_reportsources\local\query $query The bound query (for %%CASE%% display transforms).
     * @return string
     */
    protected function render_chart(array $rows, array $chartmeta, \local_reportsources\local\query $query): string {
        $xcol = (string) ($chartmeta['xcol'] ?? '');
        $ycol = (string) ($chartmeta['ycol'] ?? '');
        if ($xcol === '' || $ycol === '') {
            return $this->render_table($rows, $query);
        }

        // Apply the x-column's %%CASE%% transform to the labels, matching chart.php / the RB chart
        // report — the transform is display-only (the stored value is raw), so the block must apply
        // it too or its labels differ from every other surface.
        [$labels, $values] = \local_reportsources\local\query::chart_series(
            $rows, $xcol, $ycol, $query->column_textcase($xcol));
        $type = (string) $chartmeta['type'];

        // Category/legend label font size, saved with the chart config. Clamped to the same range as
        // the edit form and the RB chart report so the block matches those surfaces on republish.
        $labelsize = max(11, min(48, (int) ($chartmeta['labelsize'] ?? 16)));

        // Render through local_reportsources' shared chart renderer — the same code path the RB chart
        // report uses — so the block always mirrors any change to chart display (image + optional
        // "Show data table"). Server-side SVG: no JavaScript, no Chart.js; the <img> holds a base64
        // data URI and cannot execute script.
        $out = \local_reportsources\local\query::chart_figure_html($type, $labels, $values, $xcol, $ycol, [
            'labelsize' => $labelsize,
            'showdata'  => !empty($chartmeta['showdata']),
            'alt'       => $this->title,
        ]);

        // A chart is cramped in a narrow block region. Offer an in-page "Expand" that opens a modal
        // showing a larger copy of the same SVG (rendered here, no client-side charting library).
        $large = \local_reportsources\local\chart_svg::render(
            $type,
            $labels,
            $values,
            '',
            ['width' => 900, 'height' => 560, 'labelsize' => $labelsize]
        );
        $this->page->requires->js_call_amd('block_reportsources/expand', 'init');
        $out .= html_writer::tag(
            'button',
            get_string('expandchart', 'block_reportsources'),
            [
                'type'                => 'button',
                'class'               => 'btn btn-link btn-sm p-0 mt-1',
                'data-rsblock-expand' => '1',
                'data-chart-src'      => 'data:image/svg+xml;base64,' . base64_encode($large),
                'data-title'          => $this->title,
            ]
        );

        return $out;
    }
}
