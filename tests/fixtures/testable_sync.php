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
 * Testable subclass of the sync class.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_busnippets;

/**
 * Testable subclass of the sync class.
 *
 * The only thing which can not be exercised in a unit test is the HTTP download itself.
 * This subclass replaces it with a local archive file, so that everything which happens
 * after the download can be tested without touching the network.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_sync extends sync {
    /** @var string The path of the archive which is served instead of a download. */
    protected $archivepath = '';

    /** @var string|null The URL which the sync asked to download, for assertions. */
    public $requestedurl = null;

    /** @var string|null The token which the sync would have sent, for assertions. */
    public $requestedtoken = null;

    /**
     * Set the archive which is served instead of a download.
     *
     * @param string $archivepath The path of the ZIP archive.
     * @return void
     */
    public function set_archive(string $archivepath): void {
        $this->archivepath = $archivepath;
    }

    /**
     * Serve the configured archive instead of downloading one.
     *
     * A copy is returned because the caller deletes the file after unpacking it, just as it
     * would delete a real download.
     *
     * @param string $url The URL which would have been downloaded.
     * @param string $token The token which would have been sent.
     * @return string The path of the archive copy.
     */
    protected function download_archive(string $url, string $token): string {
        $this->requestedurl = $url;
        $this->requestedtoken = $token;

        $copy = make_request_directory() . '/archive.zip';
        copy($this->archivepath, $copy);

        return $copy;
    }
}
