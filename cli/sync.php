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
 * CLI script to sync the SCSS snippets from the own snippets repository right away.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    echo "Sync the SCSS snippets from the own snippets repository into Boost Union.

This runs exactly the same sync as the scheduled task, but right away and with its output on
the console. It is meant to be used after a deployment or while setting the plugin up.

Options:
  -h, --help  Print out this help.

Example:
  \$ php local/busnippets/cli/sync.php
";
    exit(0);
}

$sync = new \local_busnippets\sync('mtrace');
$result = $sync->run();

if ($result === null) {
    // The sync did not run. The reason has already been written by the sync itself.
    exit(0);
}

cli_writeln('');
cli_writeln(get_string('syncsummary', 'local_busnippets', $result));

exit(0);
