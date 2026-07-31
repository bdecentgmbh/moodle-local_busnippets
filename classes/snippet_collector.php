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
 * Collecting the SCSS snippets from a downloaded snippets archive.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_busnippets;

use moodle_exception;
use theme_boost_union\snippets;

/**
 * Unpacks a snippets archive and collects the valid SCSS snippets which it contains.
 *
 * This is deliberately separate from the sync itself: everything in here only ever looks at
 * files on disk and never touches the Moodle file storage or the database.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class snippet_collector {
    /** @var callable|null A callback which receives progress messages. */
    protected $output;

    /**
     * Constructor.
     *
     * @param callable|null $output A callback which receives progress messages, or null to stay quiet.
     */
    public function __construct(?callable $output = null) {
        $this->output = $output;
    }

    /**
     * Unpack an archive and collect the valid SCSS snippets which it contains.
     *
     * @param string $archivefile The path of the downloaded archive.
     * @param string $subdirectory An optional path within the archive to restrict the collection to.
     * @return array An array of snippets, keyed by file name, each with the keys 'path' and 'preview'.
     */
    public function collect_from_archive(string $archivefile, string $subdirectory): array {
        $archivedir = $this->extract($archivefile);

        return $this->collect($archivedir, $subdirectory);
    }

    /**
     * Unpack the downloaded archive into a temporary directory.
     *
     * @param string $archivefile The path of the downloaded archive.
     * @return string The path of the directory with the unpacked archive.
     */
    protected function extract(string $archivefile): string {
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
     * @param string $subdirectory An optional path within the archive to restrict the collection to.
     * @return array An array of snippets, keyed by file name, each with the keys 'path' and 'preview'.
     */
    protected function collect(string $archivedir, string $subdirectory): array {
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
            if (!$this->is_wanted($relativepath, $subdirectory)) {
                continue;
            }

            $filename = $fileinfo->getFilename();
            if (!$this->is_snippet($fileinfo->getPathname(), $relativepath)) {
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
     * Check whether a file within the archive is wanted at all.
     *
     * @param string $relativepath The path of the file relative to the archive root.
     * @param string $subdirectory The configured subdirectory, or an empty string.
     * @return bool Whether the file should be looked at.
     */
    protected function is_wanted(string $relativepath, string $subdirectory): bool {
        // Ignore the boilerplate file of the snippets repository, just as Boost Union does.
        if (str_contains($relativepath, 'boilerplate/boilerplate.scss')) {
            return false;
        }

        // Honour an optional subdirectory restriction.
        if ($subdirectory !== '' && !str_starts_with($relativepath, $subdirectory . '/')) {
            return false;
        }

        return true;
    }

    /**
     * Check whether a file really is a Boost Union SCSS snippet.
     *
     * The check is done by Boost Union itself, so that this plugin accepts exactly the same files
     * which Boost Union would accept from a manual upload.
     *
     * @param string $fullpath The full path of the file.
     * @param string $relativepath The path of the file relative to the archive root, for the log.
     * @return bool Whether the file is a valid snippet.
     */
    protected function is_snippet(string $fullpath, string $relativepath): bool {
        $content = file_get_contents($fullpath);

        if ($content === false || !snippets::get_snippet_meta_from_file_content($content)) {
            $this->log(get_string('syncskippedinvalid', 'local_busnippets', $relativepath));
            return false;
        }

        return true;
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
