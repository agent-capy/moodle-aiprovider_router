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

namespace local_airouter;

/**
 * One routing rule.
 *
 * Rules are evaluated in sortorder, then id, and the first one that matches decides
 * where the request is delegated. The id is what the monitor log in WP4 refers to, so
 * it is never reused: deleting a rule leaves a hole rather than renumbering.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule extends \core\persistent {
    /** @var string The table holding rules. */
    public const TABLE = 'local_airouter_rule';

    /** @var string Requests this rule claims are paid for with the site's own key. */
    public const KEYSOURCE_SITE = 'site';

    /** @var string They are paid for with a key the person who asked has brought. */
    public const KEYSOURCE_USER = 'user';

    /** @var string They are paid for with the key registered for the course. */
    public const KEYSOURCE_COURSE = 'course';

    #[\Override]
    protected static function define_properties(): array {
        return [
            'name' => [
                'type' => PARAM_TEXT,
            ],
            'enabled' => [
                'type' => PARAM_BOOL,
                'default' => true,
            ],
            'sortorder' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            // The provider instance to delegate to. Not a foreign key in the schema,
            // because core must stay free to delete a provider instance; a rule left
            // pointing at nothing falls through to the next rule instead.
            'targetid' => [
                'type' => PARAM_INT,
            ],
            // Who pays, rather than something asked about the request, which is why it
            // is a column and not a condition. Holding a key is still a requirement for
            // the rule to apply, and target_resolver is where that is decided: the
            // evaluator is left knowing only about the request itself.
            'keysource' => [
                'type' => PARAM_ALPHA,
                'default' => self::KEYSOURCE_SITE,
            ],
            'timestart' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'timeend' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
        ];
    }

    /**
     * A rule has to be named, so that the administrator can tell two of them apart.
     *
     * @param mixed $value The submitted name.
     * @return true|\core\lang_string True when valid, otherwise the error to show.
     */
    protected function validate_name($value): true|\core\lang_string {
        if (trim((string) $value) === '') {
            return new \core\lang_string('rule:error:noname', 'local_airouter');
        }

        return true;
    }

    /**
     * A rule with no target has nothing to do.
     *
     * Whether the target still exists is not checked here. A rule may outlive the
     * provider instance it names, and refusing to save the rule in that case would
     * leave the administrator unable to edit their way out of it.
     *
     * @param mixed $value The submitted target id.
     * @return true|\core\lang_string True when valid, otherwise the error to show.
     */
    protected function validate_targetid($value): true|\core\lang_string {
        if ((int) $value <= 0) {
            return new \core\lang_string('rule:error:notarget', 'local_airouter');
        }

        return true;
    }

    /**
     * A rule has to say whose key pays for what it claims.
     *
     * @param mixed $value The submitted key source.
     * @return true|\core\lang_string True when valid, otherwise the error to show.
     */
    protected function validate_keysource($value): true|\core\lang_string {
        if (!in_array((string) $value, self::get_keysources(), true)) {
            return new \core\lang_string('rule:error:keysource', 'local_airouter');
        }

        return true;
    }

    /**
     * Every key source a rule can name.
     *
     * The two brought-key values are deliberately the key scopes themselves, so that the
     * rule, the key it finds and the history row it writes all use one vocabulary and
     * nothing has to be translated between them.
     *
     * @return string[] The key sources.
     */
    public static function get_keysources(): array {
        return [self::KEYSOURCE_SITE, self::KEYSOURCE_USER, self::KEYSOURCE_COURSE];
    }

    /**
     * Whether this rule asks somebody to pay for what it claims.
     *
     * @return bool True when the rule names a brought key rather than the site's.
     */
    public function is_byok(): bool {
        return (string) $this->get('keysource') !== self::KEYSOURCE_SITE;
    }

    /**
     * An end before the start would make the rule permanently inactive without saying so.
     *
     * @param mixed $value The submitted end time.
     * @return true|\core\lang_string True when valid, otherwise the error to show.
     */
    protected function validate_timeend($value): true|\core\lang_string {
        $end = (int) $value;
        $start = (int) $this->raw_get('timestart');
        if ($end > 0 && $start > 0 && $end <= $start) {
            return new \core\lang_string('rule:error:endbeforestart', 'local_airouter');
        }

        return true;
    }

    /**
     * Whether this rule should be evaluated at the given moment.
     *
     * A rule outside its window is skipped rather than matched and then discarded, so
     * that no request ends up in a state of having matched an expired rule.
     *
     * @param int $now The time to test against.
     * @return bool True if the rule is enabled and within its window.
     */
    public function is_active(int $now): bool {
        if (!$this->get('enabled')) {
            return false;
        }
        $start = (int) $this->get('timestart');
        $end = (int) $this->get('timeend');

        return ($start === 0 || $now >= $start) && ($end === 0 || $now < $end);
    }
}
