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
 * Beside it, and deliberately apart from it, is whether the site lets anybody bring a
 * key here at all. The two were one thing at first, and saying "no keys here" meant
 * claiming the provider took none - a statement about the provider, used to express a
 * decision of the site's. They are separate now: keyfield says where a key goes, which
 * is a fact, and byokmode says what this site allows, which is not.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class target_settings {
    /** @var string The table holding these settings. */
    public const TABLE = 'local_airouter_target';

    /** @var string Recorded when an administrator says a target takes no key. */
    public const NO_KEY = '';

    /** @var string People may bring a key here, and the site's own key works too. */
    public const MODE_ALLOWED = 'allowed';

    /** @var string This site does not let anybody bring a key here. */
    public const MODE_DISALLOWED = 'disallowed';

    /** @var string This provider may only be reached with a key somebody brought. */
    public const MODE_ONLY = 'only';

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

    /** @var \stdClass[]|null Every target's settings, once read for the path of a request. */
    protected ?array $rows = null;

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
     * Every target's settings, read once for this object and forgotten when it writes.
     *
     * The questions a request asks -- where only a brought key may go, where a brought
     * key may not, whether one can be brought to the target a rule named and which field
     * it goes in -- are answered from one read. An object lives for one page or one
     * request, so a setting saved elsewhere meanwhile is seen by the next one.
     *
     * @return \stdClass[] The rows.
     */
    protected function rows(): array {
        return $this->rows ??= $this->db->get_records(self::TABLE);
    }

    /**
     * One target's settings, from the rows read for this object.
     *
     * @param int $targetid The delegation target.
     * @return \stdClass|null The row, or null when nothing has been said about the target.
     */
    protected function row(int $targetid): ?\stdClass {
        foreach ($this->rows() as $record) {
            if ((int) $record->targetid === $targetid) {
                return $record;
            }
        }

        return null;
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
        $record = $this->row($targetid);

        return $record === null ? null : (string) $record->keyfield;
    }

    /**
     * Record which field a target's key goes in.
     *
     * @param int $targetid The delegation target.
     * @param string $field The field name, or the empty string for a target that takes none.
     */
    public function set_key_field(int $targetid, string $field): void {
        $this->write($targetid, ['keyfield' => $field]);
    }

    /**
     * Write some of a target's settings, leaving the rest as they were.
     *
     * @param int $targetid The delegation target.
     * @param array $values Column and value pairs to store.
     */
    protected function write(int $targetid, array $values): void {
        global $USER;

        $this->rows = null;
        $now = time();
        $record = $this->db->get_record(self::TABLE, ['targetid' => $targetid]);
        if ($record === false) {
            $this->db->insert_record(self::TABLE, (object) ($values + [
                'targetid' => $targetid,
                'keyfield' => self::NO_KEY,
                'byokmode' => self::MODE_ALLOWED,
                'usermodified' => (int) $USER->id,
                'timecreated' => $now,
                'timemodified' => $now,
            ]));

            return;
        }

        foreach ($values as $column => $value) {
            $record->$column = $value;
        }
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
     * What this site allows at a target.
     *
     * A target nobody has answered for is allowed, because the question that stops a
     * key being used there is the other one: nobody has said where the key would go.
     *
     * @param int $targetid The delegation target.
     * @return string One of the MODE_ constants.
     */
    public function get_mode(int $targetid): string {
        $record = $this->db->get_record(self::TABLE, ['targetid' => $targetid]);
        if ($record === false) {
            return self::MODE_ALLOWED;
        }

        return self::clean_mode((string) $record->byokmode);
    }

    /**
     * Record what this site allows at a target.
     *
     * @param int $targetid The delegation target.
     * @param string $mode One of the MODE_ constants.
     */
    public function set_mode(int $targetid, string $mode): void {
        $this->write($targetid, ['byokmode' => self::clean_mode($mode)]);
    }

    /**
     * What this site allows at every target it has been asked about.
     *
     * @return string[] Modes keyed by target id.
     */
    public function get_all_modes(): array {
        $modes = [];
        foreach ($this->db->get_records(self::TABLE) as $record) {
            $modes[(int) $record->targetid] = self::clean_mode((string) $record->byokmode);
        }

        return $modes;
    }

    /**
     * The targets a brought key would actually be used at.
     *
     * @return int[] The target ids.
     */
    public function get_byok_capable_ids(): array {
        $ids = [];
        foreach ($this->db->get_records(self::TABLE) as $record) {
            if ((string) $record->keyfield === self::NO_KEY) {
                continue;
            }
            if (self::clean_mode((string) $record->byokmode) === self::MODE_DISALLOWED) {
                continue;
            }
            $ids[] = (int) $record->targetid;
        }

        return $ids;
    }

    /**
     * The targets the site's own key may not be used at.
     *
     * Read in one query, because it is asked on the path of every request that has to
     * choose a target.
     *
     * @return int[] The target ids.
     */
    public function get_byok_only_ids(): array {
        $ids = [];
        foreach ($this->rows() as $record) {
            if (self::clean_mode((string) $record->byokmode) !== self::MODE_ONLY) {
                continue;
            }
            // A target set to "brought keys only" with nowhere to put a brought key
            // would be reachable by nobody at all. An unfinished setting narrows what
            // can be done, it does not take a provider away.
            if ((string) $record->keyfield !== self::NO_KEY) {
                $ids[] = (int) $record->targetid;
            }
        }

        return $ids;
    }

    /**
     * The targets a brought key may not be used at.
     *
     * The companion of get_byok_only_ids(), and read the same way and for the same
     * reason: the choice of target is made on the path of every request.
     *
     * @return int[] The target ids.
     */
    public function get_byok_disallowed_ids(): array {
        $ids = [];
        foreach ($this->rows() as $record) {
            if (self::clean_mode((string) $record->byokmode) === self::MODE_DISALLOWED) {
                $ids[] = (int) $record->targetid;
            }
        }

        return $ids;
    }

    /**
     * Whether this provider may only be reached with a key somebody brought.
     *
     * @param int $targetid The delegation target.
     * @return bool True when the site's own key may not be used here.
     */
    public function is_byok_only(int $targetid): bool {
        return $this->get_mode($targetid) === self::MODE_ONLY && $this->supports_byok($targetid);
    }

    /**
     * The modes an administrator can choose between.
     *
     * @return string[] The mode names.
     */
    public static function get_modes(): array {
        return [self::MODE_ALLOWED, self::MODE_DISALLOWED, self::MODE_ONLY];
    }

    /**
     * A stored mode, or the safe reading of one this version does not know.
     *
     * @param string $mode The stored value.
     * @return string One of the MODE_ constants.
     */
    protected static function clean_mode(string $mode): string {
        return in_array($mode, self::get_modes(), true) ? $mode : self::MODE_ALLOWED;
    }

    /**
     * Forget a target's answer, for a provider instance that has been deleted.
     *
     * @param int $targetid The delegation target.
     */
    public function forget(int $targetid): void {
        $this->rows = null;
        $this->db->delete_records(self::TABLE, ['targetid' => $targetid]);
    }

    /**
     * Whether a key can actually be brought to this target.
     *
     * Two questions at once, and both have to be answered yes: somebody has said where
     * a key would go, and the site has not said that keys are unwelcome here.
     *
     * @param int $targetid The delegation target.
     * @return bool True when a key brought here would be used.
     */
    public function supports_byok(int $targetid): bool {
        $record = $this->row($targetid);
        if ($record === null || (string) $record->keyfield === self::NO_KEY) {
            return false;
        }

        return self::clean_mode((string) $record->byokmode) !== self::MODE_DISALLOWED;
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
