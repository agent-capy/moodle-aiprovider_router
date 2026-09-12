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

namespace aiprovider_router;

use core_ai\provider as ai_provider;

/**
 * Turns a provider_order value into something an administrator can act on.
 *
 * The stored value is a list of bare numbers with an empty first entry, which says nothing
 * about what is wrong or what a change would do. Every entry is named here, including the
 * ones that no longer refer to anything.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class order_formatter {
    /**
     * Describes one provider_order entry.
     *
     * @param string $entry The entry as stored.
     * @param ai_provider[] $instances Existing instances keyed by id.
     * @param int|null $routerid The id of the primary router, if there is one.
     * @return string Plain text, already escaped.
     */
    public static function describe_entry(string $entry, array $instances, ?int $routerid): string {
        if ($entry === '') {
            return get_string('order:entry:empty', 'aiprovider_router');
        }
        if (!isset($instances[$entry])) {
            return get_string('order:entry:stale', 'aiprovider_router', s($entry));
        }
        $placeholders = ['id' => s($entry), 'name' => s($instances[$entry]->name)];
        $key = ($routerid !== null && (int) $entry === $routerid) ? 'order:entry:router' : 'order:entry:instance';

        return get_string($key, 'aiprovider_router', $placeholders);
    }

    /**
     * Renders a provider_order value as a numbered list of what each entry means.
     *
     * @param string[] $entries The entries, in order.
     * @param ai_provider[] $instances Existing instances keyed by id.
     * @param int|null $routerid The id of the primary router, if there is one.
     * @return string HTML.
     */
    public static function render(array $entries, array $instances, ?int $routerid): string {
        $items = [];
        foreach ($entries as $entry) {
            $items[] = self::describe_entry($entry, $instances, $routerid);
        }

        return \html_writer::alist($items, [], 'ol');
    }
}
