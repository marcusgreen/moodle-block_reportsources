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

        try {
            $rows = $query->fetch_rows_for_viewer($rowlimit);
        } catch (\moodle_exception $e) {
            // Misconfigured filter column (fails closed). Show a neutral message, never raw rows.
            $this->content->text = get_string('errdata', 'block_reportsources');
            return $this->content;
        }

        $chartmeta = $rec->chartmeta ? json_decode($rec->chartmeta, true) : [];
        $haschart  = !empty($chartmeta['type']) && $chartmeta['type'] !== 'none';
        $mode      = $this->config->displaymode ?? 'auto';

        if ($rows && ($mode === 'chart' || ($mode === 'auto' && $haschart))) {
            $this->content->text = $this->render_chart($rows, $chartmeta, $queryid);
        } else {
            $this->content->text = $this->render_table($rows);
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
    protected function render_table(array $rows): string {
        if (!$rows) {
            return html_writer::tag('p', get_string('norows', 'block_reportsources'), ['class' => 'text-muted']);
        }
        $table = new html_table();
        $table->head = array_map('s', array_keys($rows[0]));
        $table->attributes['class'] = 'table table-sm';
        // Cells may contain author-authored HTML (e.g. a CONCAT'd course link), exactly as the RB
        // report renders it. format_text() keeps anchors but strips scripts, so the block is no less
        // safe than the report. Filters are off so plain values pass through untouched.
        foreach ($rows as $row) {
            $table->data[] = array_map(
                static fn($v): string => self::open_links_in_new_tab(
                    format_text((string) $v, FORMAT_HTML, ['filter' => false])
                ),
                array_values($row)
            );
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
     * @return string
     */
    protected function render_chart(array $rows, array $chartmeta): string {
        global $OUTPUT;

        $xcol = (string) ($chartmeta['xcol'] ?? '');
        $ycol = (string) ($chartmeta['ycol'] ?? '');
        if ($xcol === '' || $ycol === '') {
            return $this->render_table($rows);
        }

        $labels = [];
        $values = [];
        foreach ($rows as $row) {
            $labels[] = (string) ($row[$xcol] ?? '');
            $values[] = (float) ($row[$ycol] ?? 0);
        }

        $chart = match ($chartmeta['type']) {
            'line'            => new \core\chart_line(),
            'pie', 'doughnut' => new \core\chart_pie(),
            default           => new \core\chart_bar(),
        };
        if ($chartmeta['type'] === 'doughnut') {
            $chart->set_doughnut(true);
        }
        $chart->add_series(new \core\chart_series($ycol, $values));
        $chart->set_labels($labels);

        $out = $OUTPUT->render_chart($chart, false);

        // A chart is cramped in a narrow block region. Offer an in-page "Expand" that opens a large
        // modal rendering the same chart client-side (core/chart_builder), so the full chart shows
        // without leaving the page. The chart definition is handed to JS as JSON on the trigger.
        $this->page->requires->js_call_amd('block_reportsources/expand', 'init');
        $out .= html_writer::tag(
            'button',
            get_string('expandchart', 'block_reportsources'),
            [
                'type'                => 'button',
                'class'               => 'btn btn-link btn-sm p-0 mt-1',
                'data-rsblock-expand' => '1',
                'data-chart'          => json_encode($chart),
                'data-title'          => $this->title,
            ]
        );

        return $out;
    }
}
