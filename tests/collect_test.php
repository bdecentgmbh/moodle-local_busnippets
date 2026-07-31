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
 * Unit tests for collecting the snippets from a snippets archive.
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
 * Unit tests for collecting the snippets from a snippets archive.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_busnippets\snippet_collector
 */
final class collect_test extends advanced_testcase {
    use snippet_sync_helper;

    /**
     * The sync does nothing while it is switched off.
     *
     * @return void
     */
    public function test_sync_does_not_run_when_disabled(): void {
        $this->set_plugin_config(['enabled' => 0]);
        $archive = $this->make_archive(['a.scss' => $this->snippet_content('A')]);

        $this->assertNull($this->run_sync($archive));
        $this->assertSame([], $this->get_filearea_filenames());
    }

    /**
     * The sync does nothing while no archive URL is configured.
     *
     * @return void
     */
    public function test_sync_does_not_run_without_url(): void {
        $this->set_plugin_config(['archiveurl' => '']);
        $archive = $this->make_archive(['a.scss' => $this->snippet_content('A')]);

        $this->assertNull($this->run_sync($archive));
        $this->assertSame([], $this->get_filearea_filenames());
    }

    /**
     * Files without valid Boost Union snippet metadata are skipped.
     *
     * @return void
     */
    public function test_files_without_metadata_are_skipped(): void {
        $this->set_plugin_config();
        $archive = $this->make_archive([
            'good.scss' => $this->snippet_content('Good'),
            'bad.scss' => "// Just some SCSS without any metadata.\n.foo { color: red; }\n",
        ]);

        $result = $this->run_sync($archive);

        $this->assertSame(1, $result->added);
        $this->assertSame(['good.scss'], $this->get_filearea_filenames());
    }

    /**
     * The boilerplate of the snippets repository is skipped.
     *
     * @return void
     */
    public function test_boilerplate_is_skipped(): void {
        $this->set_plugin_config();
        $archive = $this->make_archive([
            'good.scss' => $this->snippet_content('Good'),
            'boilerplate/boilerplate.scss' => $this->snippet_content('<snippet title>'),
        ]);

        $result = $this->run_sync($archive);

        $this->assertSame(1, $result->added);
        $this->assertSame(['good.scss'], $this->get_filearea_filenames());
    }

    /**
     * Only files with the .scss extension are considered.
     *
     * @return void
     */
    public function test_non_scss_files_are_ignored(): void {
        $this->set_plugin_config();
        $archive = $this->make_archive([
            'good.scss' => $this->snippet_content('Good'),
            'README.md' => '# Snippets',
            'notes.txt' => $this->snippet_content('Not a snippet'),
        ]);

        $result = $this->run_sync($archive);

        $this->assertSame(1, $result->added);
        $this->assertSame(['good.scss'], $this->get_filearea_filenames());
    }

    /**
     * If the same file name appears twice in the archive, only one of them is taken.
     *
     * @return void
     */
    public function test_duplicate_file_names_are_skipped(): void {
        $this->set_plugin_config();
        $archive = $this->make_archive([
            'eyecandy/dupe.scss' => $this->snippet_content('First one'),
            'easeofuse/dupe.scss' => $this->snippet_content('Second one'),
        ]);

        $result = $this->run_sync($archive);

        $this->assertSame(1, $result->added);
        $this->assertSame(['dupe.scss'], $this->get_filearea_filenames());
    }

    /**
     * The wrapping folder which GitHub and GitLab add to their archives is stripped.
     *
     * @return void
     */
    public function test_wrapper_directory_is_stripped(): void {
        $this->set_plugin_config(['subdirectory' => 'eyecandy']);
        $archive = $this->make_archive([
            'moodle-snippets-main/eyecandy/wanted.scss' => $this->snippet_content('Wanted'),
            'moodle-snippets-main/easeofuse/unwanted.scss' => $this->snippet_content('Unwanted'),
        ]);

        $result = $this->run_sync($archive);

        // Without stripping the wrapper folder, the subdirectory filter would match nothing.
        $this->assertSame(1, $result->added);
        $this->assertSame(['wanted.scss'], $this->get_filearea_filenames());
    }

    /**
     * The folder structure within the archive does not matter without a subdirectory filter.
     *
     * @return void
     */
    public function test_folder_structure_is_irrelevant(): void {
        $this->set_plugin_config();
        $archive = $this->make_archive([
            'repo-main/eyecandy/one.scss' => $this->snippet_content('One'),
            'repo-main/easeofuse/deeper/two.scss' => $this->snippet_content('Two'),
        ]);

        $result = $this->run_sync($archive);

        $this->assertSame(2, $result->added);
        $this->assertSame(['one.scss', 'two.scss'], $this->get_filearea_filenames());
    }

    /**
     * A preview image next to a snippet is synced along with it.
     *
     * @return void
     */
    public function test_preview_image_is_synced(): void {
        $this->set_plugin_config();
        $archive = $this->make_archive([
            'withpreview.scss' => $this->snippet_content('With preview'),
            'withpreview.png' => 'not really a png, but the extension is what counts',
            'stray.png' => 'an image without a snippet',
        ]);

        $this->run_sync($archive);

        // The preview of the snippet is there, the stray image is not.
        $this->assertSame(['withpreview.png', 'withpreview.scss'], $this->get_filearea_filenames());

        // And Boost Union resolves it as the preview of that snippet.
        $this->assertNotNull(snippets::get_snippet_preview_url('withpreview.scss', snippets::SOURCE_UPLOADED));
    }
}
