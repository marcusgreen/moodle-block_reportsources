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
        // The chart is a server-rendered SVG data URI; the Expand trigger carries a larger copy.
        $this->assertStringContainsString('data:image/svg+xml;base64,', $content->text);
        $this->assertStringContainsString('data-rsblock-expand', $content->text);
        $this->assertStringContainsString('data-chart-src', $content->text);
    }

    public function test_get_content_chart_honours_saved_labelsize(): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/blocks/moodleblock.class.php');
        require_once($CFG->dirroot . '/blocks/reportsources/block_reportsources.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        // Publish a bar chart with a non-default category-label size.
        $id = query::save($this->formdata([
            'querysql'        => 'SELECT username AS label, id AS val FROM {user}',
            'chart_type'      => 'bar',
            'chart_xcol'      => 'label',
            'chart_ycol'      => 'val',
            'chart_rowlimit'  => 50,
            'chart_labelsize' => 42,
        ]));
        query::get($id)->publish();

        $block = new \block_reportsources();
        $block->init();
        $block->config = (object) ['queryid' => $id, 'displaymode' => 'chart', 'rowlimit' => 5];
        $PAGE->set_url('/');
        $block->page = $PAGE;

        $content = $block->get_content();

        // Decode every base64 SVG data URI the block emitted (inline + Expand copy) and confirm the
        // category labels are drawn at the saved 42px (the top increment) — not the hardcoded 16px
        // default the block used before it forwarded chartmeta['labelsize'] to chart_svg::render(),
        // and not the old 32px clamp ceiling.
        preg_match_all('#data:image/svg\+xml;base64,([A-Za-z0-9+/=]+)#', $content->text, $m);
        $this->assertNotEmpty($m[1], 'expected at least one SVG data URI');
        foreach ($m[1] as $b64) {
            $this->assertStringContainsString('font-size="42"', base64_decode($b64));
        }
    }

    public function test_get_content_chart_labels_apply_textcase(): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/blocks/moodleblock.class.php');
        require_once($CFG->dirroot . '/blocks/reportsources/block_reportsources.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        // The x-column carries a %%CASE(..., title)%% transform. It is display-only (the stored value
        // stays raw), applied by the RB entity / chart.php via query::column_textcase(). The block
        // must apply it too, or its chart labels differ from every other surface.
        $user = $this->getDataGenerator()->create_user(['username' => 'zeta_user']);

        $id = query::save($this->formdata([
            'querysql'       => 'SELECT %%CASE(username, title)%% AS label, id AS val '
                . "FROM {user} WHERE username = 'zeta_user'",
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

        // Decode the SVG and confirm the label was title-cased ("Zeta_User"), not left raw ("zeta_user").
        preg_match_all('#data:image/svg\+xml;base64,([A-Za-z0-9+/=]+)#', $content->text, $m);
        $this->assertNotEmpty($m[1], 'expected at least one SVG data URI');
        $svg = base64_decode($m[1][0]);
        $this->assertStringContainsString('Zeta_User', $svg);
        $this->assertStringNotContainsString('zeta_user', $svg);
    }

    public function test_get_content_table_applies_column_transforms(): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/blocks/moodleblock.class.php');
        require_once($CFG->dirroot . '/blocks/reportsources/block_reportsources.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        // A table (no chart) with an %%CASE()%% column and a %%TIMESTAMP()%% column. Both transforms
        // are display-only, applied by the RB data report via column callbacks — the block table must
        // apply them too or it shows raw upper/lower text and a bare epoch instead of a date.
        $epoch = 1700000000; // 2023-11-14.
        $user = $this->getDataGenerator()->create_user([
            'username' => 'zeta_user',
            'timecreated' => $epoch,
        ]);

        $id = query::save($this->formdata([
            'querysql'   => 'SELECT id, %%CASE(username, upper)%% AS uname, '
                . '%%TIMESTAMP(timecreated, dd/mm/yyyy)%% AS created '
                . "FROM {user} WHERE username = 'zeta_user'",
            'chart_type' => 'none',
        ]));
        query::get($id)->publish();

        $block = new \block_reportsources();
        $block->init();
        $block->config = (object) ['queryid' => $id, 'displaymode' => 'table', 'rowlimit' => 5];
        $PAGE->set_url('/');
        $block->page = $PAGE;

        $html = $block->get_content()->text;

        // %%CASE(upper)%%: raw "zeta_user" rendered upper-cased, not raw.
        $this->assertStringContainsString('ZETA_USER', $html);
        $this->assertStringNotContainsString('zeta_user', $html);
        // %%TIMESTAMP(..., dd/mm/yyyy)%%: epoch formatted as a date, not the bare integer.
        $this->assertStringContainsString(userdate($epoch, '%d/%m/%Y', 99, false), $html);
        $this->assertStringNotContainsString((string) $epoch, $html);
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
