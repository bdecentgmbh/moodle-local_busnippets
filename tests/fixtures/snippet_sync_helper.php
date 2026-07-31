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
 * Shared helpers for the snippet sync tests.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_busnippets;

use context_system;
use theme_boost_union\snippets;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/testable_sync.php');

/**
 * Shared helpers for the snippet sync tests.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait snippet_sync_helper {
    /**
     * Skip the test case if Boost Union is not installed, as there is nothing to sync into.
     *
     * @return void
     */
    public function setUp(): void {
        parent::setUp();

        if (!class_exists('\theme_boost_union\snippets')) {
            $this->markTestSkipped('The theme theme_boost_union is not installed.');
        }

        $this->resetAfterTest();
    }

    /**
     * Build the content of a valid SCSS snippet.
     *
     * @param string $title The snippet title, which is the only metadata field which is required.
     * @param string $css Additional SCSS code to put below the metadata header.
     * @return string The snippet content.
     */
    protected function snippet_content(string $title, string $css = ''): string {
        return "/**\n" .
            " * Snippet Title: {$title}\n" .
            " * Scope:         global\n" .
            " * Goal:          eyecandy\n" .
            " * Description:   A snippet for the unit tests.\n" .
            " * Creator:       bdecent gmbh\n" .
            " * Tested on:     Moodle 4.5\n" .
            " *\n" .
            " * @copyright     2026 bdecent gmbh\n" .
            " * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later\n" .
            " */\n\n" .
            $css;
    }

    /**
     * Build a ZIP archive from an array of paths and contents.
     *
     * @param array $files An array of paths within the archive mapped to their content.
     * @return string The path of the created archive.
     */
    protected function make_archive(array $files): string {
        $sourcedir = make_request_directory();

        $tozip = [];
        foreach ($files as $path => $content) {
            $fullpath = $sourcedir . '/' . $path;
            check_dir_exists(dirname($fullpath));
            file_put_contents($fullpath, $content);
            $tozip[$path] = $fullpath;
        }

        $archivepath = make_request_directory() . '/snippets.zip';
        $packer = get_file_packer('application/zip');
        $this->assertTrue((bool) $packer->archive_to_pathname($tozip, $archivepath));

        return $archivepath;
    }

    /**
     * Configure the plugin.
     *
     * @param array $config The config values to set, on top of the defaults used by the tests.
     * @return void
     */
    protected function set_plugin_config(array $config = []): void {
        $defaults = [
            'enabled' => 1,
            'archiveurl' => 'https://example.com/snippets.zip',
            'token' => '',
            'subdirectory' => '',
            'autoenable' => 0,
            'removeorphans' => 0,
        ];

        foreach (array_merge($defaults, $config) as $name => $value) {
            set_config($name, $value, 'local_busnippets');
        }
    }

    /**
     * Build a sync which serves the given archive instead of downloading one.
     *
     * @param string $archivepath The path of the archive to sync.
     * @return testable_sync The prepared sync.
     */
    protected function make_sync(string $archivepath): testable_sync {
        $sync = new testable_sync();
        $sync->set_archive($archivepath);

        return $sync;
    }

    /**
     * Run a sync against the given archive.
     *
     * @param string $archivepath The path of the archive to sync.
     * @return \stdClass|null The sync result.
     */
    protected function run_sync(string $archivepath): ?\stdClass {
        return $this->make_sync($archivepath)->run();
    }

    /**
     * Get the names of the files which are currently in the Boost Union snippets file area.
     *
     * @return string[] The file names, sorted.
     */
    protected function get_filearea_filenames(): array {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            context_system::instance()->id,
            'theme_boost_union',
            'uploadedsnippets',
            false,
            'filename',
            false
        );

        $names = array_map(fn($file) => $file->get_filename(), $files);
        sort($names);

        return array_values($names);
    }

    /**
     * Get the uploaded snippets which Boost Union knows, mapped to their enabled state.
     *
     * @return array The snippet names mapped to their enabled state.
     */
    protected function get_registered_snippets(): array {
        global $DB;

        $records = $DB->get_records('theme_boost_union_snippets', ['source' => snippets::SOURCE_UPLOADED]);

        $snippets = [];
        foreach ($records as $record) {
            $snippets[$record->name] = (int) $record->enabled;
        }
        ksort($snippets);

        return $snippets;
    }

    /**
     * Put a file into the snippets file area directly, simulating a manual upload by an admin.
     *
     * @param string $filename The file name.
     * @param string $content The file content.
     * @return void
     */
    protected function upload_manually(string $filename, string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => context_system::instance()->id,
            'component' => 'theme_boost_union',
            'filearea' => 'uploadedsnippets',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }
}
