<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Unit tests for the configuration of the snippet sync.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_busnippets;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/snippet_sync_helper.php');

/**
 * Unit tests for the configuration of the snippet sync.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_busnippets\sync
 */
final class config_test extends advanced_testcase {
    use snippet_sync_helper;

    /**
     * The configured URL and token are the ones which are used for the download.
     *
     * @return void
     */
    public function test_configured_url_and_token_are_used(): void {
        $this->set_plugin_config(['archiveurl' => 'https://example.com/private.zip', 'token' => 'secret-token']);

        $sync = $this->make_sync($this->make_archive(['a.scss' => $this->snippet_content('A')]));
        $sync->run();

        $this->assertSame('https://example.com/private.zip', $sync->requestedurl);
        $this->assertSame('secret-token', $sync->requestedtoken);
    }

    /**
     * The Moodle branch placeholder in the archive URL is resolved.
     *
     * This is what lets one and the same setting value serve sites which run different Moodle
     * versions, by pointing each of them at the branch of the repository which matches.
     *
     * @return void
     */
    public function test_moodle_branch_placeholder_is_resolved_in_url(): void {
        global $CFG;

        $this->set_plugin_config(['archiveurl' => 'https://example.com/{moodlebranch}.zip']);

        $sync = $this->make_sync($this->make_archive(['a.scss' => $this->snippet_content('A')]));
        $sync->run();

        $this->assertSame('https://example.com/MOODLE_' . $CFG->branch . '_STABLE.zip', $sync->requestedurl);
        $this->assertStringNotContainsString('{moodlebranch}', $sync->requestedurl);
    }

    /**
     * The Moodle version placeholder in the subdirectory is resolved.
     *
     * @return void
     */
    public function test_moodle_version_placeholder_is_resolved_in_subdirectory(): void {
        global $CFG;

        $this->set_plugin_config(['subdirectory' => '{moodleversion}']);

        // Only the snippet in the folder of the running Moodle version must be picked up.
        $archive = $this->make_archive([
            $CFG->branch . '/matching.scss' => $this->snippet_content('Matching'),
            '999/other.scss' => $this->snippet_content('Other'),
        ]);

        $result = $this->run_sync($archive);

        $this->assertSame(1, $result->added);
        $this->assertSame(['matching.scss'], $this->get_filearea_filenames());
    }

    /**
     * With autoenable on, snippets which are new to the site are switched on.
     *
     * @return void
     */
    public function test_autoenable_enables_new_snippets(): void {
        $this->set_plugin_config(['autoenable' => 1]);
        $archive = $this->make_archive(['a.scss' => $this->snippet_content('A')]);

        $result = $this->run_sync($archive);

        $this->assertSame(['a.scss'], $result->enabled);
        $this->assertSame(['a.scss' => 1], $this->get_registered_snippets());
    }

    /**
     * A snippet which an admin switched off is not switched on again by a later sync.
     *
     * @return void
     */
    public function test_autoenable_does_not_reenable_disabled_snippets(): void {
        global $DB;

        $this->set_plugin_config(['autoenable' => 1]);
        $archive = $this->make_archive(['a.scss' => $this->snippet_content('A')]);

        $this->run_sync($archive);
        $this->assertSame(['a.scss' => 1], $this->get_registered_snippets());

        // The admin switches the snippet off on this site.
        $DB->set_field('theme_boost_union_snippets', 'enabled', 0, ['name' => 'a.scss']);

        // A changed snippet forces the sync to do its work again.
        $changed = $this->make_archive(['a.scss' => $this->snippet_content('A', '.changed { color: red; }')]);
        $result = $this->run_sync($changed);

        $this->assertSame(1, $result->updated);
        $this->assertSame([], $result->enabled);
        $this->assertSame(['a.scss' => 0], $this->get_registered_snippets());
    }

    /**
     * Without autoenable, new snippets stay switched off.
     *
     * @return void
     */
    public function test_new_snippets_stay_disabled_without_autoenable(): void {
        $this->set_plugin_config(['autoenable' => 0]);
        $archive = $this->make_archive(['a.scss' => $this->snippet_content('A')]);

        $result = $this->run_sync($archive);

        $this->assertSame([], $result->enabled);
        $this->assertSame(['a.scss' => 0], $this->get_registered_snippets());
    }

    /**
     * The scheduled task is registered and runs the sync.
     *
     * @return void
     */
    public function test_scheduled_task_is_registered(): void {
        $task = \core\task\manager::get_scheduled_task('\local_busnippets\task\sync_snippets');

        $this->assertInstanceOf(\local_busnippets\task\sync_snippets::class, $task);
        $this->assertNotEmpty($task->get_name());
    }
}
