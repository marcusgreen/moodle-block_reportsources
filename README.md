# Report sources block (block_reportsources)

A Moodle block that surfaces a **published `local_reportsources` report** — as a table or a
chart — inline on course pages, the site front page, or the user Dashboard.

It is the display companion to the [`local_reportsources`](../../local/reportsources) plugin.
That plugin is where reports are *authored* (write SQL, publish, get a Report Builder report);
this block is where a single report is *pinned* so people see it without navigating to the full
report viewer.

## Requirements

- Moodle 4.5 – 5.0 (`$plugin->supported = [405, 502]`)
- **`local_reportsources` must be installed first** — it is a hard dependency
  (`version.php` → `$plugin->dependencies`).

## Installation

1. Install `local_reportsources` into `<moodleroot>/local/reportsources`.
2. Copy this folder into `<moodleroot>/blocks/reportsources`.
3. Go to **Site admin → Notifications** to complete the install.

## What it does

- **Picks one published report.** The instance config form lists only reports that
  `local_reportsources` has published (`query::published_menu()`).
- **Renders table or chart.** Display mode is `auto` (chart if the report has chart metadata,
  else table), or forced to `table` / `chart`.
- **Respects per-viewer access.** The block calls the same access path as the Report Builder
  viewer (`current_user_can_view_report()`) and fetches rows through the shared
  `fetch_rows_for_viewer()`. So one shared block instance shows **each viewer only their own
  rows** (e.g. a teacher sees only their courses). No access → empty content → the block hides
  itself.
- **Expandable charts.** A narrow block region is cramped for a chart, so chart mode adds an
  **Expand** button. It opens a large modal that re-renders the same chart client-side
  (`core/chart_builder`), with a fullscreen toggle (`amd/src/expand.js`).
- **Links to the full report.** The footer links to `/reportbuilder/view.php` for the underlying
  report.

## Configuration (per block instance)

Set via the block's edit form (`edit_form.php`):

| Setting | String key | Notes |
|---|---|---|
| Report | `config_queryid` | Which published report to show |
| Display as | `config_displaymode` | `auto` / `table` / `chart` |
| Maximum rows | `config_rowlimit` | Clamped to 1–100, default 10 |
| Custom block title | `config_title` | Overrides the report name |

Multiple instances are allowed per page (`instance_allow_multiple()`), so you can pin several
reports side by side.

## Capabilities

Defined in `db/access.php`:

- `block/reportsources:addinstance` — add the block to a course/page. Granted to
  `editingteacher` and `manager` by default; carries `RISK_SPAM | RISK_XSS`.
- `block/reportsources:myaddinstance` — add the block to a personal Dashboard. Granted to all
  users so anyone can pin a report to their own `/my` page.

The block grants *placement*; the report's own Report Builder access still gates the *data*.

## Security notes

- Cell HTML is passed through `format_text(..., FORMAT_HTML, ['filter' => false])`, matching how
  the Report Builder report itself renders — anchors survive, scripts are stripped.
- A misconfigured filter column **fails closed**: rows are never shown raw; a neutral
  `errdata` message is displayed instead.
- Links in cells are rewritten to `target="_blank" rel="noopener noreferrer"` so opening a link
  does not lose the Dashboard and does not expose the reverse-tabnabbing hole.

## File map

```
block_reportsources.php   Block class: init, formats, get_content, table/chart rendering
edit_form.php             Per-instance config form
db/access.php             Capabilities
lang/en/...               English strings
amd/src/expand.js         Client-side chart expand/fullscreen modal (ES6 source)
amd/build/...             Built AMD (grunt output)
styles.css                Chart modal / fullscreen layout
tests/block_test.php      PHPUnit smoke tests
version.php               Version, requires, dependency on local_reportsources
```

## Tests

PHPUnit smoke tests in `tests/block_test.php` cover: class load + applicable formats, the
editing-teacher capability default, the published-report picker feed, table rendering + footer
link, chart mode emitting the Expand trigger, and empty content when unconfigured.

```bash
vendor/bin/phpunit blocks/reportsources/tests/block_test.php
```

## How this plugin was created

This block was built as a **companion to `local_reportsources`**, following the same workflow as
the parent plugin:

1. **Started from the dependency.** `local_reportsources` already owned report authoring,
   publishing, the published-report menu (`query::published_menu()`), and the per-viewer fetch
   path (`fetch_rows_for_viewer()`, `current_user_can_view_report()`). The block was designed to
   *reuse* that API rather than re-implement any SQL or access logic.
2. **Scaffolded a standard Moodle block** — `block_reportsources.php`, `edit_form.php`,
   `db/access.php`, `version.php`, lang strings — with correct frankenstyle component naming
   (`block_reportsources`), GPL headers, and a hard `dependencies` entry on
   `local_reportsources`.
3. **Added rendering paths** for table and chart, deferring to core (`html_table`,
   `\core\chart_*`, `$OUTPUT->render_chart()`) and to the report's saved chart metadata.
4. **Added the client-side Expand modal** as an AMD module (`amd/src/expand.js`), built with
   grunt into `amd/build/`, using core modules (`core/modal`, `core/chart_builder`,
   `core/chart_output_chartjs`, `core/str`, `core/notification`).
5. **Hardened the output** — fail-closed on filter errors, `format_text` cleaning, and safe
   new-tab link rewriting.
6. **Wrote PHPUnit smoke tests** against the real `local_reportsources` API (publish a view,
   render the block, assert table/chart output), with a `tearDown()` that drops the database
   VIEWs `publish()` leaves behind so the test reset does not error.

## License

GNU GPL v3 or later. © 2026 Marcus Green.
