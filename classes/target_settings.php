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
 * Which field of a delegation target's configuration a brought key belongs in.
 *
 * There is no common name for it. Moodle's own providers call it apikey, Sakura AI
 * Engine calls it account_token, another calls it systemtoken, and ollama has no key at
 * all. Substituting "the apikey field" would therefore work for some providers and
 * silently do nothing for others, which is worse than not supporting them: the request
 * would go out charged to the site while the user believed they were paying.
 *
 * So the field is guessed from the instance's own configuration and then confirmed by an
 * administrator. A guess is a starting point offered on a form, never a decision.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class target_settings {
    /** @var string The table holding these settings. */
    public const TABLE = 'aiprovider_router_target';

    /** @var string Recorded when an administrator says a target takes no key. */
    public const NO_KEY = '';

    /** @var string[] Configuration names that are a key, in the order they are preferred. */
    protected const KNOWN = [
        'apikey',
        'api_key',
        'account_token',
        'systemtoken',
        'accesstoken',
        'apitoken',
        'secretkey',
        'token',
        'secret',
    ];

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database to work on.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
    ) {
    }

    /**
     * The field an administrator has confirmed for a target.
     *
     * Null and the empty string mean different things. Null is "nobody has said yet",
     * and a rule that wants to bring a key to this target cannot be honoured because the
     * plugin does not know where to put it. The empty string is "an administrator has
     * looked and this provider takes no key", which is a settled answer.
     *
     * @param int $targetid The delegation target.
     * @return string|null The field name, the empty string for none, or null if unanswered.
     */
    public function get_key_field(int $targetid): ?string {
        $record = $this->db->get_record(self::TABLE, ['targetid' => $targetid]);

        return $record === false ? null : (string) $record->keyfield;
    }

    /**
     * Record which field a target's key goes in.
     *
     * @param int $targetid The delegation target.
     * @param string $field The field name, or the empty string for a target that takes none.
     */
    public function set_key_field(int $targetid, string $field): void {
        global $USER;

        $now = time();
        $record = $this->db->get_record(self::TABLE, ['targetid' => $targetid]);
        if ($record === false) {
            $this->db->insert_record(self::TABLE, (object) [
                'targetid' => $targetid,
                'keyfield' => $field,
                'usermodified' => (int) $USER->id,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);

            return;
        }

        $record->keyfield = $field;
        $record->usermodified = (int) $USER->id;
        $record->timemodified = $now;
        $this->db->update_record(self::TABLE, $record);
    }

    /**
     * Every answer the site has recorded.
     *
     * @return string[] Field names keyed by target id.
     */
    public function get_all(): array {
        $fields = [];
        foreach ($this->db->get_records(self::TABLE) as $record) {
            $fields[(int) $record->targetid] = (string) $record->keyfield;
        }

        return $fields;
    }

    /**
     * Forget a target's answer, for a provider instance that has been deleted.
     *
     * @param int $targetid The delegation target.
     */
    public function forget(int $targetid): void {
        $this->db->delete_records(self::TABLE, ['targetid' => $targetid]);
    }

    /**
     * Whether a key can actually be brought to this target.
     *
     * @param int $targetid The delegation target.
     * @return bool True when a field has been confirmed and it is not "no key".
     */
    public function supports_byok(int $targetid): bool {
        $field = $this->get_key_field($targetid);

        return $field !== null && $field !== self::NO_KEY;
    }

    /**
     * The field that looks most like a key in an instance's configuration.
     *
     * Offered as the default on the form an administrator confirms. Names known to be
     * keys are preferred over anything merely key-shaped, because a pattern loose enough
     * to catch account_token is also loose enough to catch something that only has the
     * word token in it.
     *
     * @param ai_provider $target The instance to look at.
     * @return string The best guess, or the empty string when nothing looks like a key.
     */
    public static function guess(ai_provider $target): string {
        $names = array_keys($target->config ?? []);
        foreach (self::KNOWN as $known) {
            foreach ($names as $name) {
                if (strtolower((string) $name) === $known) {
                    return (string) $name;
                }
            }
        }
        foreach ($names as $name) {
            if (preg_match('/(api.?key|token|secret)/i', (string) $name)) {
                return (string) $name;
            }
        }

        return self::NO_KEY;
    }
}
