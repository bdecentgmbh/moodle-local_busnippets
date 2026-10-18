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
 * Version details.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version = 2026073101;              // The current plugin version (Date: YYYYMMDDXX).
$plugin->requires = 2024100700;             // Requires this Moodle version (4.5).
$plugin->supported = [405, 503];            // Supported Moodle versions (4.5 up to 5.3).
$plugin->component = 'local_busnippets';    // Full name of the plugin (used for diagnostics).
$plugin->maturity = MATURITY_ALPHA;
$plugin->release = 'v0.1.0-alpha';
$plugin->dependencies = [
    'theme_boost_union' => ANY_VERSION,
];
