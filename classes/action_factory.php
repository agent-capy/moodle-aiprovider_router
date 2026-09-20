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

use core_ai\aiactions\base as action_base;
use core_ai\aiactions\generate_image;

/**
 * Builds a stand in action so that a rule set can be tried without calling anything.
 *
 * The rule tester has to evaluate exactly what a real request would evaluate, which
 * means handing the evaluator a real action object. Nothing is sent anywhere: the action
 * is built, read by the conditions, and thrown away.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_factory {
    /**
     * Build an action of the given class.
     *
     * @param string $class The action class, which must be one the router handles.
     * @param int $contextid Where the request would be raised.
     * @param int $userid Who would raise it.
     * @param string $prompt What they would ask.
     * @return action_base The action.
     */
    public static function make(string $class, int $contextid, int $userid, string $prompt): action_base {
        $class = ltrim($class, '\\');
        $known = array_map(
            static fn(string $action): string => ltrim($action, '\\'),
            provider::get_action_list(),
        );
        if (!in_array($class, $known, true)) {
            throw new \coding_exception('Unknown action class: ' . $class);
        }

        if ($class === 'local_aiaudio\\aiactions\\transcript_audio') {
            // This action carries a recording, and the rule tester has none: it is
            // asking which rule would claim the request, and no condition looks at
            // the audio. A silent placeholder stands in for it.
            return new $class(
                contextid: $contextid,
                userid: $userid,
                file: self::placeholder_recording($contextid),
            );
        }

        if ($class === generate_image::class) {
            // The image parameters play no part in routing, and asking an administrator
            // for them would suggest otherwise.
            return new generate_image(
                contextid: $contextid,
                userid: $userid,
                prompttext: $prompt,
                quality: 'standard',
                aspectratio: 'square',
                numimages: 1,
                style: 'natural',
            );
        }

        return new $class(contextid: $contextid, userid: $userid, prompttext: $prompt);
    }

    /**
     * An empty recording, for testing rules about actions that carry one.
     *
     * Nothing is sent anywhere by the rule tester, so the contents do not matter;
     * what matters is that the action can be built at all. The same empty file is
     * reused rather than written afresh each time the screen is used.
     *
     * @param int $contextid Where the test is being run.
     * @return \stored_file The placeholder.
     */
    protected static function placeholder_recording(int $contextid): \stored_file {
        $record = [
            'contextid' => $contextid,
            'component' => 'aiprovider_router',
            'filearea' => 'ruletest',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'silence.bin',
        ];

        $storage = get_file_storage();
        $existing = $storage->get_file(
            $record['contextid'],
            $record['component'],
            $record['filearea'],
            $record['itemid'],
            $record['filepath'],
            $record['filename'],
        );

        return $existing ?: $storage->create_file_from_string($record, '');
    }
}
