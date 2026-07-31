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
 * Admin settings.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_busnippets',
        get_string('pluginname', 'local_busnippets')
    );

    // Show the outcome of the last sync run, if there was one.
    $lastrun = get_config('local_busnippets', \local_busnippets\sync::CONFIG_LASTRUN);
    if (!empty($lastrun)) {
        $lastrundata = json_decode($lastrun);
        if (is_object($lastrundata) && isset($lastrundata->time, $lastrundata->result)) {
            $info = (object) [
                'time' => userdate($lastrundata->time),
                'added' => $lastrundata->result->added ?? 0,
                'updated' => $lastrundata->result->updated ?? 0,
                'unchanged' => $lastrundata->result->unchanged ?? 0,
                'removed' => $lastrundata->result->removed ?? 0,
            ];
            $settings->add(new admin_setting_heading(
                'local_busnippets/lastrun',
                get_string('lastrun', 'local_busnippets'),
                get_string('lastrun_desc', 'local_busnippets', $info)
            ));
        }
    }

    // Setting: Enable the sync.
    $settings->add(new admin_setting_configcheckbox(
        'local_busnippets/enabled',
        get_string('enabled', 'local_busnippets'),
        get_string('enabled_desc', 'local_busnippets'),
        0
    ));

    // Setting: The URL of the snippets archive.
    $settings->add(new admin_setting_configtext(
        'local_busnippets/archiveurl',
        get_string('archiveurl', 'local_busnippets'),
        get_string('archiveurl_desc', 'local_busnippets'),
        '',
        PARAM_URL,
        60
    ));

    // Setting: The access token for a private repository.
    $settings->add(new admin_setting_configpasswordunmask(
        'local_busnippets/token',
        get_string('token', 'local_busnippets'),
        get_string('token_desc', 'local_busnippets'),
        ''
    ));

    // Setting: An optional subdirectory within the archive.
    $settings->add(new admin_setting_configtext(
        'local_busnippets/subdirectory',
        get_string('subdirectory', 'local_busnippets'),
        get_string('subdirectory_desc', 'local_busnippets'),
        '',
        PARAM_PATH,
        60
    ));

    // Setting: Enable newly synced snippets automatically.
    $settings->add(new admin_setting_configcheckbox(
        'local_busnippets/autoenable',
        get_string('autoenable', 'local_busnippets'),
        get_string('autoenable_desc', 'local_busnippets'),
        0
    ));

    // Setting: Remove snippets which vanished from the repository.
    $settings->add(new admin_setting_configcheckbox(
        'local_busnippets/removeorphans',
        get_string('removeorphans', 'local_busnippets'),
        get_string('removeorphans_desc', 'local_busnippets'),
        0
    ));

    $ADMIN->add('localplugins', $settings);

    // Add a notice about this plugin to the "Uploaded snippets" section of the Boost Union
    // SCSS snippets settings page, so that admins understand where the additional snippets
    // in that file area come from.
    //
    // Boost Union does not offer an extension point for its settings, but it does not have to:
    // Moodle builds the admin tree with the theme settings first and with the plugin settings
    // last, explicitly so that "plugins may insert pages anywhere" (see admin_get_root()). The
    // Boost Union snippets page is a tabbed settings page whose tabs are publicly accessible,
    // so the notice can simply be appended to the settings tab. Everything is guarded, so a
    // renamed page or tab in a future Boost Union version just means no notice, never an error.
    if (class_exists('\theme_boost_admin_settingspage_tabs')) {
        $snippetspage = $ADMIN->locate('theme_boost_union_snippets');
        if ($snippetspage instanceof \theme_boost_admin_settingspage_tabs) {
            foreach ($snippetspage->get_tabs() as $snippetstab) {
                if (
                    !($snippetstab instanceof admin_settingpage)
                    || $snippetstab->name !== 'theme_boost_union_snippets_settings'
                ) {
                    continue;
                }

                // Tell the admin whether the sync is actually running and where to configure it.
                $syncenabled = (bool) get_config('local_busnippets', 'enabled');
                $settingsurl = (new \core\url('/admin/settings.php', ['section' => 'local_busnippets']))->out();
                $notification = new \core\output\notification(
                    get_string($syncenabled ? 'snippetsnoticeon' : 'snippetsnoticeoff', 'local_busnippets', $settingsurl),
                    $syncenabled ? \core\output\notification::NOTIFY_INFO : \core\output\notification::NOTIFY_WARNING,
                    false
                );

                $snippetstab->add(new admin_setting_heading(
                    'local_busnippets/snippetsnotice',
                    get_string('snippetsnoticeheading', 'local_busnippets'),
                    $OUTPUT->render($notification)
                ));

                break;
            }
        }
    }
}
