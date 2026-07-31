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
 * Strings for component 'local_busnippets', language 'en'.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['archiveurl'] = 'Archive URL';
$string['archiveurl_desc'] = 'The URL of a ZIP archive which contains your SCSS snippets. Any archive works as long as it contains the snippets as .scss files with valid Boost Union snippet metadata. Folder structures within the archive are ignored, only the file names matter. Examples: a GitHub archive URL like https://github.com/&lt;owner&gt;/&lt;repo&gt;/archive/refs/heads/main.zip, a GitLab archive URL or the URL of a release asset.<br><br>SCSS snippets carry no information about the Moodle version they were written for, and Boost Union applies every enabled snippet no matter which Moodle version a site runs. If you maintain snippets for more than one Moodle version, use the placeholder <code>{moodlebranch}</code>, which is replaced with the branch name of the running Moodle version (on this site: <code>{$a->branch}</code>), for example https://github.com/&lt;owner&gt;/&lt;repo&gt;/archive/refs/heads/{moodlebranch}.zip. The same setting value can then be used on all of your sites and each of them pulls the snippets which fit its Moodle version.';
$string['autoenable'] = 'Enable new snippets automatically';
$string['autoenable_desc'] = 'If enabled, snippets which arrive on this site for the first time are switched on right away. Snippets which are already known keep the state which they have on this site, so switching a snippet off in Boost Union is never overruled by the sync. If disabled, new snippets are added but stay switched off until an admin enables them.';
$string['enabled'] = 'Enable snippet sync';
$string['enabled_desc'] = 'If enabled, the scheduled task downloads the configured archive and adds the contained SCSS snippets to Boost Union. If disabled, the task does nothing and the already synced snippets stay untouched.';
$string['errordownload'] = 'The snippets archive could not be downloaded ({$a}).';
$string['errornoboostunion'] = 'The theme Boost Union is not installed. Without it, SCSS snippets can not be synced.';
$string['errornozip'] = 'The downloaded file could not be unpacked. Please check whether the archive URL really points to a ZIP archive.';
$string['lastrun'] = 'Last sync';
$string['lastrun_desc'] = 'The last sync ran on {$a->time}. Added: {$a->added}, updated: {$a->updated}, unchanged: {$a->unchanged}, removed: {$a->removed}.';
$string['pluginname'] = 'Boost Union snippet sync';
$string['privacy:metadata'] = 'The Boost Union snippet sync plugin does not store any personal data. It downloads an archive with SCSS snippets from the configured URL, using the configured access token if one is set.';
$string['removeorphans'] = 'Remove snippets which were deleted from the repository';
$string['removeorphans_desc'] = 'If enabled, snippets which this plugin added in an earlier run but which are not part of the archive anymore are removed from this site. Snippets which an admin uploaded by hand and snippets from other sources are never touched. If disabled, snippets stay on the site once they have been synced.';
$string['snippetsnoticeheading'] = 'Snippets from your own snippets repository';
$string['snippetsnoticeoff'] = 'The plugin "Boost Union snippet sync" is installed on this site. It can add SCSS snippets from your own snippets repository to the uploaded snippets above, automatically and on a schedule. The sync is currently switched off, so all snippets above were uploaded by hand. <a href="{$a}">Configure the snippet sync</a>';
$string['snippetsnoticeon'] = 'Some of the uploaded snippets above may be managed by the plugin "Boost Union snippet sync": It downloads SCSS snippets from your own snippets repository with a scheduled task and adds them to this file area. Such snippets are overwritten on the next sync run, so changes which you make to them here are lost. Switching a synced snippet off in the overview is never overruled: the sync only ever enables snippets which arrive on this site for the first time, and only if it is configured to do so. <a href="{$a}">Configure the snippet sync</a>';
$string['subdirectory'] = 'Subdirectory';
$string['subdirectory_desc'] = 'Optional. If your repository holds more than just snippets, or if you want to sync only a part of it, enter the path within the repository which should be synced, for example "snippets" or "eyecandy". Leave empty to sync all snippets in the archive.<br><br>The placeholders <code>{moodlebranch}</code> and <code>{moodleversion}</code> are replaced with the branch name and the version number of the running Moodle version (on this site: <code>{$a->branch}</code> and <code>{$a->version}</code>). If you keep the snippets for several Moodle versions in one repository, put them into folders which are named after the Moodle version and enter <code>{moodleversion}</code> here.';
$string['syncadded'] = 'Added snippet: {$a}';
$string['syncdisabled'] = 'The snippet sync is disabled, nothing to do.';
$string['syncdownloading'] = 'Downloading the snippets archive from {$a}';
$string['syncenabled'] = 'Enabled new snippet: {$a}';
$string['syncfound'] = 'Found {$a} valid snippets in the archive.';
$string['syncnochange'] = 'No snippet has changed, the theme caches are kept.';
$string['syncnourl'] = 'No archive URL is configured, nothing to do.';
$string['syncremoved'] = 'Removed snippet which is not in the repository anymore: {$a}';
$string['syncskippedduplicate'] = 'Skipped {$a} because a snippet with this file name was already found in the archive.';
$string['syncskippedinvalid'] = 'Skipped {$a} because it does not contain valid Boost Union snippet metadata.';
$string['syncsummary'] = 'Sync finished. Added: {$a->added}, updated: {$a->updated}, unchanged: {$a->unchanged}, removed: {$a->removed}.';
$string['syncupdated'] = 'Updated snippet: {$a}';
$string['syncuploadeddisabled'] = 'Note: The Boost Union setting "Enable uploaded snippets" is switched off. The snippets are synced but Boost Union will ignore them until this setting is switched on.';
$string['tasksyncsnippets'] = 'Sync SCSS snippets from the own snippets repository';
$string['token'] = 'Access token';
$string['token_desc'] = 'Optional. Only needed if the archive is not publicly readable. The token is sent as an "Authorization: Bearer" header with the download request. On GitHub, a fine-grained personal access token with the "Contents: read" permission for the snippets repository is enough. Leave empty for a public repository.';
