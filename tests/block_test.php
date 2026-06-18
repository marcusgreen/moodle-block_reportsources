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

declare(strict_types=1);

namespace block_reportsources;

use local_reportsources\local\query;

/**
 * Smoke tests for block_reportsources.
 *
 * @package   block_reportsources
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_reportsources
 */
final class block_test extends \advanced_testcase {

    /**
     * Drop any VIEWs left by publish() before the framework reset (which DROP TABLEs a VIEW errors on).
     */
    protected function tearDown(): void {
        global $DB;
        $prefix = $DB->get_prefix() . 'local_reportsources_v_';
        $views = $DB->get_records_sql(
            "SELECT table_name FROM information_schema.views WHERE table_schema = DATABASE() AND table_name LIKE ?",
            [$prefix . '%']
        );
        foreach ($views as $view) {
            $name = $view->table_name ?? reset($view);
            $DB->execute('DROP VIEW IF EXISTS ' . $name);
        }
        parent::tearDown();
    }

    /**
     * Build a minimal valid form-data object for query::save().
     *
     * @param array $extra
     * @return \stdClass
     */
    private function formdata(array $extra = []): \stdClass {
        return (object) array_merge([
            'name'     => 'Block test view',
            'querysql' => 'SELECT id FROM {user}',
            'courseid' => 0,
            'visible'  => 1,
        ], $extra);
    }

    public function test_block_class_loads_and_declares_formats(): void {
        global $CFG;
        require_once($CFG->dirroot . '/blocks/moodleblock.class.php');
        require_once($CFG->dirroot . '/blocks/reportsources/block_reportsources.php');

        $block = new \block_reportsources();
        $block->init();

        $this->assertSame(get_string('pluginname', 'block_reportsources'), $block->title);
        $this->assertTrue($block->instance_allow_multiple());

        $formats = $block->applicable_formats();
        $this->assertArrayHasKey('course-view', $formats);
        $this->assertArrayHasKey('my', $formats);
    }

    public function test_capabilities_allow_editingteacher_to_add(): void {
        // db/access.php grants addinstance to the editingteacher archetype.
        $caps = get_default_capabilities('editingteacher');
        $this->assertArrayHasKey('block/reportsources:addinstance', $caps);
        $this->assertSame((string) CAP_ALLOW, (string) $caps['block/reportsources:addinstance']);
    }

    public function test_published_menu_feeds_the_picker(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = query::save($this->formdata(['name' => 'Pickable']));
        query::get($id)->publish();

        $this->assertArrayHasKey($id, query::published_menu());
    }

    public function test_get_content_renders_table_and_footer_link(): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/blocks/moodleblock.class.php');
        require_once($CFG->dirroot . '/blocks/reportsources/block_reportsources.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $id = query::save($this->formdata(['querysql' => 'SELECT id, username FROM {user}']));
        query::get($id)->publish();
        $reportid = (int) query::get($id)->reportid();

        $block = new \block_reportsources();
        $block->init();
        $block->config = (object) ['queryid' => $id, 'displaymode' => 'table', 'rowlimit' => 5];
        $PAGE->set_url('/');
        $block->page = $PAGE;

        $content = $block->get_content();
        $this->assertNotNull($content);
        $this->assertStringContainsString('<table', $content->text);
        $this->assertStringContainsString('/reportbuilder/view.php', $content->footer);
        $this->assertStringContainsString('id=' . $reportid, $content->footer);
    }

    public function test_get_content_chart_mode_includes_popout(): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/blocks/moodleblock.class.php');
        require_once($CFG->dirroot . '/blocks/reportsources/block_reportsources.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $id = query::save($this->formdata([
            'querysql'       => 'SELECT username AS label, id AS val FROM {user}',
            'chart_type'     => 'bar',
            'chart_xcol'     => 'label',
            'chart_ycol'     => 'val',
            'chart_rowlimit' => 50,
        ]));
        query::get($id)->publish();

        $block = new \block_reportsources();
        $block->init();
        $block->config = (object) ['queryid' => $id, 'displaymode' => 'chart', 'rowlimit' => 5];
        $PAGE->set_url('/');
        $block->page = $PAGE;

        $content = $block->get_content();
        $this->assertStringContainsString(get_string('expandchart', 'block_reportsources'), $content->text);
        // The Expand trigger carries the chart JSON for the client-side modal.
        $this->assertStringContainsString('data-rsblock-expand', $content->text);
        $this->assertStringContainsString('data-chart', $content->text);
    }

    public function test_get_content_empty_when_unconfigured_and_not_editing(): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/blocks/moodleblock.class.php');
        require_once($CFG->dirroot . '/blocks/reportsources/block_reportsources.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $block = new \block_reportsources();
        $block->init();
        $block->config = new \stdClass();
        $PAGE->set_url('/');
        $block->page = $PAGE;

        $content = $block->get_content();
        $this->assertSame('', $content->text);
    }
}
