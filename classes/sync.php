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
 * Synchronisation of SCSS snippets from an own snippets repository into Boost Union.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_busnippets;

use context_system;
use file_storage;
use moodle_exception;
use stdClass;
use theme_boost_union\snippets;

/**
 * Downloads an archive with SCSS snippets and adds the contained snippets to Boost Union.
 *
 * Boost Union does not offer an API to register an additional snippet source. It does however
 * read all snippets from the 'uploadedsnippets' file area and it offers a public API to register
 * the files which are stored there. This class therefore writes the snippets from the configured
 * archive into exactly this file area, just as if an admin had uploaded them by hand, and then
 * lets Boost Union pick them up.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync {
    /** @var string The component which owns the snippets file area. */
    const SNIPPETS_COMPONENT = 'theme_boost_union';

    /** @var string The file area which holds the uploaded snippets. */
    const SNIPPETS_FILEAREA = 'uploadedsnippets';

    /** @var string The config setting which remembers the files which were written by this plugin. */
    const CONFIG_MANAGEDFILES = 'managedfiles';

    /** @var string The config setting which remembers the outcome of the last run. */
    const CONFIG_LASTRUN = 'lastrunsummary';

    /** @var int The maximum time in seconds which the download of the archive may take. */
    const DOWNLOAD_TIMEOUT = 300;

    /** @var callable|null A callback which receives progress messages. */
    protected $output;

    /** @var stdClass|null The plugin configuration of the current run. */
    protected $config = null;

    /**
     * Constructor.
     *
     * @param callable|null $output A callback which receives progress messages, for example 'mtrace'.
     *                              Pass null to stay quiet, which is what the unit tests do.
     */
    public function __construct(?callable $output = null) {
        $this->output = $output;
    }

    /**
     * Run the synchronisation.
     *
     * The synchronisation is written in a way that it does as little as possible: The theme caches
     * are only reset if a snippet was really added, updated or removed. Running this repeatedly
     * without a change in the snippets repository is therefore cheap.
     *
     * @return stdClass|null An object with the counters 'added', 'updated', 'unchanged', 'removed',
     *                       the boolean 'changed' and the array 'enabled', or null if the sync did not run.
     */
    public function run(): ?stdClass {
        global $CFG;

        // The curl class, the file packer and the file storage all live in filelib.php,
        // which is not loaded automatically in every context this can be called from.
        require_once($CFG->libdir . '/filelib.php');

        $this->config = get_config('local_busnippets');

        if (!$this->is_runnable()) {
            return null;
        }

        // Fetch the archive and collect the snippets which it contains.
        $archivefile = $this->download_archive(
            $this->resolve_placeholders($this->config->archiveurl),
            $this->config->token ?? ''
        );
        $collector = new snippet_collector(fn(string $message) => $this->log($message));
        $snippetfiles = $collector->collect_from_archive(
            $archivefile,
            $this->resolve_placeholders($this->config->subdirectory ?? '')
        );
        $this->log(get_string('syncfound', 'local_busnippets', count($snippetfiles)));

        // Write them into the Boost Union file area.
        $result = $this->store_snippets($snippetfiles);

        // Let Boost Union register the snippets and rebuild the CSS, but only if needed.
        if ($result->changed) {
            $result->enabled = $this->register_snippets();
        } else {
            $result->enabled = [];
            $this->log(get_string('syncnochange', 'local_busnippets'));
        }

        // Remember the outcome so that it can be shown on the settings page.
        set_config(
            self::CONFIG_LASTRUN,
            json_encode(['time' => time(), 'result' => $result]),
            'local_busnippets'
        );

        return $result;
    }

    /**
     * Check whether the sync is configured well enough to run at all.
     *
     * @return bool Whether the sync should run.
     */
    protected function is_runnable(): bool {
        global $CFG;

        // Check whether the sync is switched on at all.
        if (empty($this->config->enabled)) {
            $this->log(get_string('syncdisabled', 'local_busnippets'));
            return false;
        }

        // Without an archive URL there is nothing to fetch.
        if (empty($this->config->archiveurl)) {
            $this->log(get_string('syncnourl', 'local_busnippets'));
            return false;
        }

        // The whole plugin is useless without Boost Union. This is covered by the plugin
        // dependency already, but the theme could have been removed in the meantime.
        if (!class_exists('\theme_boost_union\snippets')) {
            throw new moodle_exception('errornoboostunion', 'local_busnippets');
        }

        // Warn if Boost Union would ignore the snippets anyway. This is not an error, the
        // snippets are still synced and become effective as soon as the setting is switched on.
        // Boost Union stores its yes/no settings as the strings defined in its lib.php.
        require_once($CFG->dirroot . '/theme/boost_union/lib.php');
        if (get_config('theme_boost_union', 'enableuploadedsnippets') != THEME_BOOST_UNION_SETTING_SELECT_YES) {
            $this->log(get_string('syncuploadeddisabled', 'local_busnippets'));
        }

        return true;
    }

    /**
     * Replace the Moodle version placeholders in a setting value.
     *
     * SCSS snippets carry no information about the Moodle version they were written for, and
     * Boost Union applies every enabled snippet regardless of the Moodle version. A snippet which
     * targets the markup of one Moodle version can therefore do nothing or even the wrong thing on
     * another one. These placeholders let a single setting value serve sites on different Moodle
     * versions, by pointing each of them at the matching branch or folder of the repository.
     *
     * @param string $value The configured value.
     * @return string The value with the placeholders replaced.
     */
    protected function resolve_placeholders(string $value): string {
        global $CFG;

        return str_replace(
            ['{moodlebranch}', '{moodleversion}'],
            ['MOODLE_' . $CFG->branch . '_STABLE', $CFG->branch],
            $value
        );
    }

    /**
     * Download the snippets archive into a temporary file.
     *
     * @param string $url The URL of the ZIP archive.
     * @param string $token An optional bearer token for private repositories.
     * @return string The path of the downloaded file.
     */
    protected function download_archive(string $url, string $token): string {
        $this->log(get_string('syncdownloading', 'local_busnippets', $url));

        $headers = ['User-Agent: Moodle local_busnippets'];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $tempdir = make_temp_directory('local_busnippets');
        $tempfile = $tempdir . '/' . 'archive-' . random_string(10) . '.zip';

        $curl = new \curl();
        $curl->setHeader($headers);
        $result = $curl->download_one($url, null, [
            'filepath' => $tempfile,
            'timeout' => self::DOWNLOAD_TIMEOUT,
            'followlocation' => true,
            'maxredirs' => 5,
        ]);

        $httpcode = isset($curl->info['http_code']) ? (int) $curl->info['http_code'] : 0;
        if ($result !== true || $httpcode < 200 || $httpcode >= 300) {
            @unlink($tempfile);
            $detail = ($result === true) ? 'HTTP ' . $httpcode : (string) $result;
            throw new moodle_exception('errordownload', 'local_busnippets', '', $detail);
        }

        return $tempfile;
    }

    /**
     * Write the collected snippets into the Boost Union file area.
     *
     * @param array $snippetfiles The collected snippets as returned by the snippet collector.
     * @return stdClass The counters of this run.
     */
    protected function store_snippets(array $snippetfiles): stdClass {
        $fs = get_file_storage();
        $contextid = context_system::instance()->id;

        $result = (object) [
            'added' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'removed' => 0,
            'changed' => false,
        ];

        $managed = [];

        foreach ($snippetfiles as $filename => $snippet) {
            $stored = $this->store_snippet($fs, $contextid, $filename, $snippet, $managed);

            $result->{$stored['status']}++;
            if ($stored['status'] !== 'unchanged' || $stored['previewchanged']) {
                $result->changed = true;
            }
        }

        // Remove the files which this plugin wrote in an earlier run but which are not part of
        // the archive anymore. Files which were uploaded by an admin by hand are never touched.
        if (!empty($this->config->removeorphans)) {
            $result->removed = $this->remove_orphans($fs, $contextid, $managed);
            if ($result->removed > 0) {
                $result->changed = true;
            }
        }

        // Remember which files this plugin is responsible for.
        set_config(self::CONFIG_MANAGEDFILES, json_encode(array_values(array_unique($managed))), 'local_busnippets');

        return $result;
    }

    /**
     * Write a single snippet and its preview image into the Boost Union file area.
     *
     * @param file_storage $fs The file storage.
     * @param int $contextid The id of the system context.
     * @param string $filename The file name of the snippet.
     * @param array $snippet The snippet with the keys 'path' and 'preview'.
     * @param array $managed The list of managed file names, extended by this method.
     * @return array An array with the keys 'status' and 'previewchanged'.
     */
    protected function store_snippet(
        file_storage $fs,
        int $contextid,
        string $filename,
        array $snippet,
        array &$managed
    ): array {
        $managed[] = $filename;

        $status = $this->store_file($fs, $contextid, $filename, $snippet['path']);
        if ($status === 'added') {
            $this->log(get_string('syncadded', 'local_busnippets', $filename));
        } else if ($status === 'updated') {
            $this->log(get_string('syncupdated', 'local_busnippets', $filename));
        }

        $previewchanged = false;
        if ($snippet['preview'] !== null) {
            $previewname = basename($snippet['preview']);
            $managed[] = $previewname;
            $previewchanged = $this->store_file($fs, $contextid, $previewname, $snippet['preview']) !== 'unchanged';
        }

        return ['status' => $status, 'previewchanged' => $previewchanged];
    }

    /**
     * Store a single file in the Boost Union file area, if its content has changed.
     *
     * @param file_storage $fs The file storage.
     * @param int $contextid The id of the system context.
     * @param string $filename The name of the file.
     * @param string $sourcepath The full path of the source file.
     * @return string One of 'added', 'updated' or 'unchanged'.
     */
    protected function store_file(file_storage $fs, int $contextid, string $filename, string $sourcepath): string {
        $existing = $fs->get_file(
            $contextid,
            self::SNIPPETS_COMPONENT,
            self::SNIPPETS_FILEAREA,
            0,
            '/',
            $filename
        );

        // Compare the content so that unchanged files do not trigger a theme cache reset.
        if ($existing && $existing->get_contenthash() === file_storage::hash_from_path($sourcepath)) {
            return 'unchanged';
        }

        $status = $existing ? 'updated' : 'added';
        if ($existing) {
            $existing->delete();
        }

        $fs->create_file_from_pathname([
            'contextid' => $contextid,
            'component' => self::SNIPPETS_COMPONENT,
            'filearea' => self::SNIPPETS_FILEAREA,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
            'timecreated' => time(),
            'timemodified' => time(),
        ], $sourcepath);

        return $status;
    }

    /**
     * Remove the files which this plugin wrote earlier but which are not in the archive anymore.
     *
     * @param file_storage $fs The file storage.
     * @param int $contextid The id of the system context.
     * @param array $current The file names which were written in this run.
     * @return int The number of removed files.
     */
    protected function remove_orphans(file_storage $fs, int $contextid, array $current): int {
        $previous = json_decode(get_config('local_busnippets', self::CONFIG_MANAGEDFILES) ?: '[]');
        if (!is_array($previous)) {
            return 0;
        }

        $removed = 0;
        foreach (array_diff($previous, $current) as $filename) {
            $file = $fs->get_file(
                $contextid,
                self::SNIPPETS_COMPONENT,
                self::SNIPPETS_FILEAREA,
                0,
                '/',
                $filename
            );
            if ($file) {
                $file->delete();
                $removed++;
                $this->log(get_string('syncremoved', 'local_busnippets', $filename));
            }
        }

        return $removed;
    }

    /**
     * Let Boost Union register the synced snippets and rebuild the theme CSS.
     *
     * @return string[] The names of the snippets which were enabled by this run.
     */
    protected function register_snippets(): array {
        global $DB;

        // Remember which snippets Boost Union knew before, to be able to spot the new ones.
        $before = $DB->get_fieldset_select(
            'theme_boost_union_snippets',
            'name',
            'source = ?',
            [snippets::SOURCE_UPLOADED]
        );

        // These are the very same calls which Boost Union runs when an admin saves the
        // 'Upload snippets' setting.
        snippets::parse_uploaded_snippets();
        snippets::cleanup_snippets();

        $enabled = $this->enable_new_snippets($before);

        theme_reset_all_caches();

        return $enabled;
    }

    /**
     * Switch on the snippets which arrived on this site for the first time.
     *
     * Snippets which this site already knew keep the state which an admin gave them, so that
     * switching a snippet off on a site is never overruled by a later sync.
     *
     * @param array $before The snippet names which were known before the snippets were registered.
     * @return string[] The names of the snippets which were enabled.
     */
    protected function enable_new_snippets(array $before): array {
        global $DB;

        if (empty($this->config->autoenable)) {
            return [];
        }

        $after = $DB->get_fieldset_select(
            'theme_boost_union_snippets',
            'name',
            'source = ?',
            [snippets::SOURCE_UPLOADED]
        );

        $enabled = [];
        foreach (array_diff($after, $before) as $name) {
            $DB->set_field(
                'theme_boost_union_snippets',
                'enabled',
                1,
                ['name' => $name, 'source' => snippets::SOURCE_UPLOADED]
            );
            $enabled[] = $name;
            $this->log(get_string('syncenabled', 'local_busnippets', $name));
        }

        return $enabled;
    }

    /**
     * Pass a message on to the configured output callback.
     *
     * @param string $message The message.
     * @return void
     */
    protected function log(string $message): void {
        if ($this->output !== null) {
            ($this->output)($message);
        }
    }
}
