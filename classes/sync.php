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
use core\exception\moodle_exception;
use file_storage;
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

    /** @var bool Whether progress information is written with mtrace(). */
    protected $verbose;

    /** @var string[] Collected log lines of the current run. */
    protected $log = [];

    /**
     * Constructor.
     *
     * @param bool $verbose Whether progress information should be written with mtrace().
     */
    public function __construct(bool $verbose = false) {
        $this->verbose = $verbose;
    }

    /**
     * Run the synchronisation.
     *
     * The synchronisation is written in a way that it does as little as possible: The theme caches
     * are only reset if a snippet was really added, updated or removed. Running this repeatedly
     * without a change in the snippets repository is therefore cheap.
     *
     * @return \stdClass An object with the counters 'added', 'updated', 'unchanged', 'removed',
     *                   'skipped' and the boolean 'changed', or null if the sync did not run.
     */
    public function run(): ?\stdClass {
        global $CFG;

        // The curl class, the file packer and the file storage all live in filelib.php,
        // which is not loaded automatically in every context this can be called from.
        require_once($CFG->libdir . '/filelib.php');

        $config = get_config('local_busnippets');

        // Check whether the sync is switched on at all.
        if (empty($config->enabled)) {
            $this->log(get_string('syncdisabled', 'local_busnippets'));
            return null;
        }

        // Without an archive URL there is nothing to fetch.
        if (empty($config->archiveurl)) {
            $this->log(get_string('syncnourl', 'local_busnippets'));
            return null;
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

        // Fetch and unpack the archive.
        $archivefile = $this->download_archive($config->archiveurl, $config->token ?? '');
        $archivedir = $this->extract_archive($archivefile);

        // Collect the snippets from the unpacked archive.
        $snippetfiles = $this->collect_snippets($archivedir, $config->subdirectory ?? '');
        $this->log(get_string('syncfound', 'local_busnippets', count($snippetfiles)));

        // Write them into the Boost Union file area.
        $result = $this->store_snippets($snippetfiles, !empty($config->removeorphans));

        // Let Boost Union register the snippets and rebuild the CSS, but only if needed.
        if ($result->changed) {
            $newnames = $this->register_snippets(!empty($config->autoenable));
            $result->enabled = $newnames;
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
     * Unpack the downloaded archive into a temporary directory.
     *
     * @param string $archivefile The path of the downloaded archive.
     * @return string The path of the directory with the unpacked archive.
     */
    protected function extract_archive(string $archivefile): string {
        $packer = get_file_packer('application/zip');
        $extractdir = make_request_directory(false);
        $extracted = $packer->extract_to_pathname($archivefile, $extractdir);

        // The temporary download is not needed anymore in any case.
        @unlink($archivefile);

        if (!$extracted) {
            throw new moodle_exception('errornozip', 'local_busnippets');
        }

        return $extractdir;
    }

    /**
     * Collect all valid SCSS snippets from the unpacked archive.
     *
     * The folder structure within the archive is not relevant, just as it is not relevant when an
     * admin uploads a snippets ZIP archive by hand. Only the file name identifies a snippet.
     *
     * @param string $archivedir The path of the directory with the unpacked archive.
     * @param string $subdirectory An optional path within the archive to restrict the sync to.
     * @return array An array of snippets, keyed by file name, each with the keys 'path' and 'preview'.
     */
    protected function collect_snippets(string $archivedir, string $subdirectory): array {
        $subdirectory = trim($subdirectory, '/');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($archivedir, \FilesystemIterator::SKIP_DOTS)
        );

        $snippets = [];
        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isFile() || strtolower($fileinfo->getExtension()) !== 'scss') {
                continue;
            }

            // The path within the archive, without the wrapping folder which GitHub and GitLab
            // add to their archives.
            $relativepath = $this->get_archive_path($archivedir, $fileinfo->getPathname());

            // Ignore the boilerplate file of the snippets repository, just as Boost Union does.
            if (str_contains($relativepath, 'boilerplate/boilerplate.scss')) {
                continue;
            }

            // Honour an optional subdirectory restriction.
            if ($subdirectory !== '' && !str_starts_with($relativepath, $subdirectory . '/')) {
                continue;
            }

            $filename = $fileinfo->getFilename();
            $content = file_get_contents($fileinfo->getPathname());

            // Let Boost Union decide whether this is a valid snippet. This is the very same
            // check which Boost Union runs on a manually uploaded file.
            if ($content === false || !snippets::get_snippet_meta_from_file_content($content)) {
                $this->log(get_string('syncskippedinvalid', 'local_busnippets', $relativepath));
                continue;
            }

            // Snippets are identified by their file name alone, so a name must not appear twice.
            if (isset($snippets[$filename])) {
                $this->log(get_string('syncskippedduplicate', 'local_busnippets', $relativepath));
                continue;
            }

            $snippets[$filename] = [
                'path' => $fileinfo->getPathname(),
                'preview' => $this->find_preview_file($fileinfo->getPathname()),
            ];
        }

        ksort($snippets);

        return $snippets;
    }

    /**
     * Get the path of a file relative to the archive root.
     *
     * Archives which are downloaded from GitHub or GitLab wrap the whole repository into a single
     * folder which is named after the repository and the ref. This folder is stripped here so that
     * the subdirectory setting can be written as it looks in the repository.
     *
     * @param string $archivedir The path of the directory with the unpacked archive.
     * @param string $fullpath The full path of the file.
     * @return string The path of the file relative to the archive root.
     */
    protected function get_archive_path(string $archivedir, string $fullpath): string {
        $relativepath = ltrim(substr($fullpath, strlen($archivedir)), '/');

        // If the archive consists of exactly one top level folder, treat this folder as the root.
        $toplevel = glob($archivedir . '/*');
        if (is_array($toplevel) && count($toplevel) === 1 && is_dir($toplevel[0])) {
            $wrapper = basename($toplevel[0]) . '/';
            if (str_starts_with($relativepath, $wrapper)) {
                $relativepath = substr($relativepath, strlen($wrapper));
            }
        }

        return $relativepath;
    }

    /**
     * Search for the preview image which belongs to a snippet.
     *
     * Boost Union expects the preview image to sit next to the snippet and to carry the same
     * base name, just with an image file extension.
     *
     * @param string $snippetpath The full path of the snippet file.
     * @return string|null The full path of the preview image, or null if there is none.
     */
    protected function find_preview_file(string $snippetpath): ?string {
        $basepath = substr($snippetpath, 0, -strlen('.scss'));

        foreach (snippets::ALLOWED_PREVIEW_FILE_EXTENSIONS as $extension) {
            $candidate = $basepath . '.' . $extension;
            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Write the collected snippets into the Boost Union file area.
     *
     * @param array $snippetfiles The collected snippets as returned by collect_snippets().
     * @param bool $removeorphans Whether snippets which vanished from the archive should be removed.
     * @return \stdClass The counters of this run.
     */
    protected function store_snippets(array $snippetfiles, bool $removeorphans): \stdClass {
        $fs = get_file_storage();
        $systemcontext = context_system::instance();

        $result = (object) [
            'added' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'removed' => 0,
            'changed' => false,
        ];

        $managed = [];

        foreach ($snippetfiles as $filename => $snippet) {
            $managed[] = $filename;

            // Store the snippet itself.
            $status = $this->store_file($fs, $systemcontext->id, $filename, $snippet['path']);
            if ($status === 'added') {
                $result->added++;
                $this->log(get_string('syncadded', 'local_busnippets', $filename));
            } else if ($status === 'updated') {
                $result->updated++;
                $this->log(get_string('syncupdated', 'local_busnippets', $filename));
            } else {
                $result->unchanged++;
            }

            // Store the preview image, if there is one.
            if ($snippet['preview'] !== null) {
                $previewname = basename($snippet['preview']);
                $managed[] = $previewname;
                $previewstatus = $this->store_file($fs, $systemcontext->id, $previewname, $snippet['preview']);
                if ($previewstatus !== 'unchanged') {
                    $result->changed = true;
                }
            }
        }

        if ($result->added > 0 || $result->updated > 0) {
            $result->changed = true;
        }

        // Remove the files which this plugin wrote in an earlier run but which are not part of
        // the archive anymore. Files which were uploaded by an admin by hand are never touched.
        if ($removeorphans) {
            $result->removed = $this->remove_orphans($fs, $systemcontext->id, $managed);
            if ($result->removed > 0) {
                $result->changed = true;
            }
        }

        // Remember which files this plugin is responsible for.
        set_config(self::CONFIG_MANAGEDFILES, json_encode(array_values(array_unique($managed))), 'local_busnippets');

        return $result;
    }

    /**
     * Store a single file in the Boost Union file area, if its content has changed.
     *
     * @param \file_storage $fs The file storage.
     * @param int $contextid The id of the system context.
     * @param string $filename The name of the file.
     * @param string $sourcepath The full path of the source file.
     * @return string One of 'added', 'updated' or 'unchanged'.
     */
    protected function store_file(\file_storage $fs, int $contextid, string $filename, string $sourcepath): string {
        $existing = $fs->get_file(
            $contextid,
            self::SNIPPETS_COMPONENT,
            self::SNIPPETS_FILEAREA,
            0,
            '/',
            $filename
        );

        // Compare the content so that unchanged files do not trigger a theme cache reset.
        $sourcehash = file_storage::hash_from_path($sourcepath);
        if ($existing && $existing->get_contenthash() === $sourcehash) {
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
     * @param \file_storage $fs The file storage.
     * @param int $contextid The id of the system context.
     * @param array $current The file names which were written in this run.
     * @return int The number of removed files.
     */
    protected function remove_orphans(\file_storage $fs, int $contextid, array $current): int {
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
     * @param bool $autoenable Whether snippets which are new to this site should be enabled.
     * @return string[] The names of the snippets which were enabled by this run.
     */
    protected function register_snippets(bool $autoenable): array {
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

        $enabled = [];
        if ($autoenable) {
            $after = $DB->get_fieldset_select(
                'theme_boost_union_snippets',
                'name',
                'source = ?',
                [snippets::SOURCE_UPLOADED]
            );
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
        }

        theme_reset_all_caches();

        return $enabled;
    }

    /**
     * Add a line to the log of this run.
     *
     * @param string $message The message.
     * @return void
     */
    protected function log(string $message): void {
        $this->log[] = $message;
        if ($this->verbose) {
            mtrace($message);
        }
    }

    /**
     * Get the collected log lines of this run.
     *
     * @return string[]
     */
    public function get_log(): array {
        return $this->log;
    }
}
