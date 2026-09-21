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

namespace aiprovider_mock;

/**
 * A stand in AI provider whose behaviour is chosen per test.
 *
 * The router's job is to survive targets that misbehave, so the failure paths have to be
 * exercised against something that behaves like a real provider rather than against a
 * test double of the router's own delegator. This provider is reached through core's own
 * dispatch (\core_ai\manager::call_action_provider() builds "<first namespace segment>\
 * process_<action>"), which is why it lives in its own top level namespace instead of
 * under local_airouter: the router's namespace would resolve back to the router's own
 * processors.
 *
 * It is a test fixture, not a plugin. Nothing installs it and nothing ships it.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider extends \core_ai\provider {
    /** @var string Answers normally. */
    public const SUCCESS = 'success';

    /** @var string Reports a failure the way a provider reports an API error. */
    public const FAILURE = 'failure';

    /** @var string Throws, the way an unreachable endpoint does. */
    public const EXCEPTION = 'exception';

    /** @var string Succeeds but returns nothing to show. */
    public const EMPTY_CONTENT = 'empty';

    /** @var string Succeeds with no content because the token budget ran out. */
    public const TRUNCATED = 'truncated';

    /** @var string Refuses before the request is made, the way the rate limiter does. */
    public const RATELIMIT = 'ratelimit';

    /** @var string Succeeds but omits the keys the response class expects. */
    public const MALFORMED = 'malformed';

    #[\Override]
    public static function get_action_list(): array {
        return [
            \core_ai\aiactions\generate_text::class,
            \core_ai\aiactions\generate_image::class,
            \core_ai\aiactions\summarise_text::class,
            \core_ai\aiactions\explain_text::class,
        ];
    }

    #[\Override]
    public function is_provider_configured(): bool {
        return true;
    }

    #[\Override]
    public function get_name(): string {
        // Core resolves this from the class name and gets nothing for a fixture, which
        // leaves a not null column empty when core writes its own record of the action.
        // The name is only ever read back as a label.
        return 'aiprovider_mock';
    }

    /**
     * The behaviour this instance was built to exhibit.
     *
     * @return string One of the class constants.
     */
    public function get_scenario(): string {
        return $this->config['scenario'] ?? self::SUCCESS;
    }

    /**
     * A setting for the chosen scenario.
     *
     * @param string $name The setting name.
     * @param mixed $default Returned when the setting was not given.
     * @return mixed The value.
     */
    public function get_setting(string $name, mixed $default = null): mixed {
        return $this->config[$name] ?? $default;
    }

    #[\Override]
    public function is_request_allowed(\core_ai\aiactions\base $action): array|bool {
        // Core's own limiter is not used: it would need the fixture to be an installed
        // component. What matters to the router is how it behaves when a target says no.
        if ($this->get_scenario() !== self::RATELIMIT) {
            return true;
        }

        return [
            'success' => false,
            'errorcode' => 429,
            'error' => 'ratelimit',
            'errormessage' => 'Rate limit exceeded',
        ];
    }
}
