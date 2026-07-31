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
 * Unit tests for storing the synced snippets.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_busnippets;

use advanced_testcase;
use theme_boost_union\snippets;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/snippet_sync_helper.php');

/**
 * Unit tests for storing the synced snippets.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_busnippets\sync
 */
final class sync_test extends advanced_testcase {
    use snippet_sync_helper;

    /**
     * Valid snippets are added and registered with Boost Union.
     *
     * @return void
     */
    public function test_valid_snippets_are_added_and_registered(): void {
        $this->set_plugin_config();
        $archive = $this->make_archive([
            'first.scss' => $this->snippet_content('First'),
            'second.scss' => $this->snippet_content('Second'),
        ]);

        $result = $this->run_sync($archive);

        $this->assertSame(2, $result->added);
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->unchanged);
        $this->assertTrue($result->changed);
        $this->assertSame(['first.scss', 'second.scss'], $this->get_filearea_filenames());

        // Boost Union knows them now, and they are switched off, as autoenable is off.
        $this->assertSame(['first.scss' => 0, 'second.scss' => 0], $this->get_registered_snippets());
    }

    /**
     * A second run with an unchanged archive does not change anything.
     *
     * @return void
     */
    public function test_unchanged_snippets_are_not_rewritten(): void {
        $this->set_plugin_config();
        $archive = $this->make_archive(['a.scss' => $this->snippet_content('A')]);

        $first = $this->run_sync($archive);
        $this->assertSame(1, $first->added);
        $this->assertTrue($first->changed);

        $second = $this->run_sync($archive);
        $this->assertSame(0, $second->added);
        $this->assertSame(0, $second->updated);
        $this->assertSame(1, $second->unchanged);
        $this->assertFalse($second->changed);
    }

    /**
     * A snippet whose content changed in the repository is updated.
     *
     * @return void
     */
    public function test_changed_snippets_are_updated(): void {
        $this->set_plugin_config();

        $this->run_sync($this->make_archive(['a.scss' => $this->snippet_content('A')]));

        $changed = $this->make_archive(['a.scss' => $this->snippet_content('A', '.changed { color: red; }')]);
        $result = $this->run_sync($changed);

        $this->assertSame(0, $result->added);
        $this->assertSame(1, $result->updated);
        $this->assertTrue($result->changed);

        // The new content really arrived.
        $scss = snippets::get_snippet_scss('a.scss', snippets::SOURCE_UPLOADED);
        $this->assertStringContainsString('.changed', $scss);
    }

    /**
     * With removeorphans on, a snippet which vanished from the repository is removed.
     *
     * @return void
     */
    public function test_removeorphans_removes_vanished_snippets(): void {
        $this->set_plugin_config(['removeorphans' => 1]);

        $this->run_sync($this->make_archive([
            'stays.scss' => $this->snippet_content('Stays'),
            'goes.scss' => $this->snippet_content('Goes'),
        ]));
        $this->assertSame(['goes.scss', 'stays.scss'], $this->get_filearea_filenames());

        $result = $this->run_sync($this->make_archive(['stays.scss' => $this->snippet_content('Stays')]));

        $this->assertSame(1, $result->removed);
        $this->assertTrue($result->changed);
        $this->assertSame(['stays.scss'], $this->get_filearea_filenames());

        // Boost Union does not know the removed snippet anymore either.
        $this->assertSame(['stays.scss' => 0], $this->get_registered_snippets());
    }

    /**
     * The preview image of a removed snippet is removed as well.
     *
     * @return void
     */
    public function test_removeorphans_removes_preview_images(): void {
        $this->set_plugin_config(['removeorphans' => 1]);

        $this->run_sync($this->make_archive([
            'goes.scss' => $this->snippet_content('Goes'),
            'goes.png' => 'preview',
        ]));
        $this->assertSame(['goes.png', 'goes.scss'], $this->get_filearea_filenames());

        $result = $this->run_sync($this->make_archive(['other.scss' => $this->snippet_content('Other')]));

        $this->assertSame(2, $result->removed);
        $this->assertSame(['other.scss'], $this->get_filearea_filenames());
    }

    /**
     * Without removeorphans, snippets stay on the site once they were synced.
     *
     * @return void
     */
    public function test_removeorphans_off_keeps_vanished_snippets(): void {
        $this->set_plugin_config(['removeorphans' => 0]);

        $this->run_sync($this->make_archive([
            'stays.scss' => $this->snippet_content('Stays'),
            'goes.scss' => $this->snippet_content('Goes'),
        ]));

        $result = $this->run_sync($this->make_archive(['stays.scss' => $this->snippet_content('Stays')]));

        $this->assertSame(0, $result->removed);
        $this->assertSame(['goes.scss', 'stays.scss'], $this->get_filearea_filenames());
    }

    /**
     * Snippets which an admin uploaded by hand are never removed by the sync.
     *
     * @return void
     */
    public function test_removeorphans_leaves_manual_uploads_alone(): void {
        $this->set_plugin_config(['removeorphans' => 1]);

        $this->upload_manually('manual.scss', $this->snippet_content('Uploaded by hand'));

        $this->run_sync($this->make_archive(['synced.scss' => $this->snippet_content('Synced')]));
        $this->assertSame(['manual.scss', 'synced.scss'], $this->get_filearea_filenames());

        // The synced snippet vanishes from the repository, the manual one must survive.
        $result = $this->run_sync($this->make_archive(['other.scss' => $this->snippet_content('Other')]));

        $this->assertSame(1, $result->removed);
        $this->assertSame(['manual.scss', 'other.scss'], $this->get_filearea_filenames());
    }

    /**
     * The files which the sync wrote are remembered, so that orphans can be spotted later.
     *
     * @return void
     */
    public function test_managed_files_are_remembered(): void {
        $this->set_plugin_config();
        $this->run_sync($this->make_archive([
            'a.scss' => $this->snippet_content('A'),
            'a.png' => 'preview',
        ]));

        $managed = json_decode(get_config('local_busnippets', sync::CONFIG_MANAGEDFILES));
        sort($managed);

        $this->assertSame(['a.png', 'a.scss'], $managed);
    }

    /**
     * The outcome of the last run is remembered for the settings page.
     *
     * @return void
     */
    public function test_last_run_summary_is_stored(): void {
        $this->set_plugin_config();
        $this->run_sync($this->make_archive(['a.scss' => $this->snippet_content('A')]));

        $lastrun = json_decode(get_config('local_busnippets', sync::CONFIG_LASTRUN));

        $this->assertNotEmpty($lastrun->time);
        $this->assertSame(1, $lastrun->result->added);
    }
}
