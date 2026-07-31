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
 * Scheduled task which syncs the SCSS snippets from the own snippets repository.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_busnippets\task;

use core\task\scheduled_task;
use local_busnippets\sync;

/**
 * Scheduled task which syncs the SCSS snippets from the own snippets repository.
 *
 * @package    local_busnippets
 * @copyright  2026 bdecent GmbH <info@bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_snippets extends scheduled_task {
    /**
     * Get the name of the task, shown in the scheduled tasks overview.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('tasksyncsnippets', 'local_busnippets');
    }

    /**
     * Run the task.
     *
     * @return void
     */
    public function execute(): void {
        $sync = new sync(true);
        $result = $sync->run();

        if ($result === null) {
            return;
        }

        mtrace(get_string('syncsummary', 'local_busnippets', $result));
    }
}
