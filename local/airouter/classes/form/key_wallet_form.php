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

namespace local_airouter\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Takes a key for a provider the person has had a key at before, and asks which account it is for.
 *
 * Two occasions, one question. Replacing a key that is held: is the new key for the
 * same account as the old one, and does the limit stay? Registering a key where one
 * was held and removed: is it for the account that was here before? The key itself
 * cannot answer, so the person is asked, and asked outright: nothing is chosen for
 * them, because a choice made by default would not be their answer.
 *
 * The one key nobody is asked about is the same key again. The form is told how to
 * recognise it, and drops the question for it.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_wallet_form extends \moodleform {
    /** @var string Replacing a key that is held. */
    public const MODE_REPLACE = 'replace';

    /** @var string Registering a key where one was held before. */
    public const MODE_REGISTER = 'register';

    /** @var string The answer that the key is for the same account. */
    public const SAME = 'same';

    /** @var string The answer that the key is for another account. */
    public const OTHER = 'other';

    /** @var string The answer that the limit stays. */
    public const KEEP = 'keep';

    /** @var string The answer that the limit goes. */
    public const DROP = 'drop';

    /** @var string The answer that a new wallet is opened rather than an earlier one gone on with. */
    public const NEW_WALLET = 'new';

    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;
        $mode = (string) ($this->_customdata['mode'] ?? self::MODE_REPLACE);

        $mform->addElement('hidden', 'action', $mode);
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('hidden', 'courseid', (int) ($this->_customdata['courseid'] ?? 0));
        $mform->setType('courseid', PARAM_INT);
        if ($mode === self::MODE_REPLACE) {
            $mform->addElement('hidden', 'keyid', (int) ($this->_customdata['keyid'] ?? 0));
            $mform->setType('keyid', PARAM_INT);
            // The wallet of the key the questions are about, so that a key moved to
            // another wallet before the answers arrive is not taken to be that key.
            $mform->addElement('hidden', 'walletid', (int) ($this->_customdata['walletid'] ?? 0));
            $mform->setType('walletid', PARAM_INT);
        } else {
            $mform->addElement('hidden', 'targetid', (int) ($this->_customdata['targetid'] ?? 0));
            $mform->setType('targetid', PARAM_INT);
        }

        $mform->addElement(
            'static',
            'target',
            get_string('keys:target', 'local_airouter'),
            s((string) ($this->_customdata['target'] ?? '')),
        );

        $mform->addElement('passwordunmask', 'secret', get_string('keys:secret', 'local_airouter'));
        $mform->setType('secret', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('secret', 'keys:secret', 'local_airouter');

        if ($mode === self::MODE_REPLACE) {
            $this->ask_about_replacing($mform);
        } else {
            $this->ask_about_registering($mform);
        }

        $this->add_action_buttons(true, get_string('keys:save', 'local_airouter'));
    }

    /**
     * The questions a replacement raises: same account, and whether the limit stays.
     *
     * @param \MoodleQuickForm $mform The form.
     */
    protected function ask_about_replacing(\MoodleQuickForm $mform): void {
        $same = get_string('keys:replace:sameaccount:same', 'local_airouter');
        $other = get_string('keys:replace:sameaccount:other', 'local_airouter');
        $mform->addGroup([
            $mform->createElement('radio', 'sameaccount', '', $same, self::SAME),
            $mform->createElement('radio', 'sameaccount', '', $other, self::OTHER),
        ], 'sameaccount', get_string('keys:replace:sameaccount', 'local_airouter'), '<br>', false);
        $mform->addHelpButton('sameaccount', 'keys:replace:sameaccount', 'local_airouter');

        $cap = $this->_customdata['cap'] ?? null;
        if ($cap === null) {
            return;
        }
        $mform->addGroup([
            $mform->createElement('radio', 'keepcap', '', get_string('keys:replace:keepcap:keep', 'local_airouter'), self::KEEP),
            $mform->createElement('radio', 'keepcap', '', get_string('keys:replace:keepcap:drop', 'local_airouter'), self::DROP),
        ], 'keepcap', get_string('keys:replace:keepcap', 'local_airouter'), '<br>', false);
        $mform->addHelpButton('keepcap', 'keys:replace:keepcap', 'local_airouter', '', false, (string) $cap);
    }

    /**
     * The question a registration raises where a key was held before: which record to go on with.
     *
     * @param \MoodleQuickForm $mform The form.
     */
    protected function ask_about_registering(\MoodleQuickForm $mform): void {
        $radios = [];
        foreach ($this->_customdata['previous'] ?? [] as $wallet) {
            $date = userdate((int) $wallet->timereleased, get_string('strftimedateshort', 'langconfig'));
            $label = (string) $wallet->hint === ''
                ? get_string('keys:register:previous:continue:nohint', 'local_airouter', ['date' => $date])
                : get_string('keys:register:previous:continue', 'local_airouter', ['hint' => s($wallet->hint), 'date' => $date]);
            $radios[] = $mform->createElement('radio', 'wallet', '', $label, (string) (int) $wallet->id);
        }
        $fresh = get_string('keys:register:previous:new', 'local_airouter');
        $radios[] = $mform->createElement('radio', 'wallet', '', $fresh, self::NEW_WALLET);
        $mform->addGroup($radios, 'wallet', get_string('keys:register:previous', 'local_airouter'), '<br>', false);
        $mform->addHelpButton('wallet', 'keys:register:previous', 'local_airouter');
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $secret = trim((string) ($data['secret'] ?? ''));
        if ($secret === '') {
            $errors['secret'] = get_string('key:error:empty', 'local_airouter');

            return $errors;
        }
        $issame = $this->_customdata['issame'] ?? null;
        if ($issame instanceof \Closure && $issame($secret)) {
            // The same key again is the same account, and no question arises.
            return $errors;
        }

        $mode = (string) ($this->_customdata['mode'] ?? self::MODE_REPLACE);
        if ($mode === self::MODE_REPLACE) {
            if (!in_array($data['sameaccount'] ?? null, [self::SAME, self::OTHER], true)) {
                $errors['sameaccount'] = get_string('keys:replace:choose', 'local_airouter');
            }
            $capped = ($this->_customdata['cap'] ?? null) !== null;
            if ($capped && !in_array($data['keepcap'] ?? null, [self::KEEP, self::DROP], true)) {
                $errors['keepcap'] = get_string('keys:replace:choose', 'local_airouter');
            }
        } else if (!isset($data['wallet']) || (string) $data['wallet'] === '') {
            $errors['wallet'] = get_string('keys:replace:choose', 'local_airouter');
        }

        return $errors;
    }

    /**
     * What the form said about the account, as the repository wants it.
     *
     * @param \stdClass $data The submitted data.
     * @return bool|null True for the same account, false for another, null when not asked.
     */
    public static function read_sameaccount(\stdClass $data): ?bool {
        return match ($data->sameaccount ?? null) {
            self::SAME => true,
            self::OTHER => false,
            default => null,
        };
    }

    /**
     * What the form said about the limit, as the repository wants it.
     *
     * @param \stdClass $data The submitted data.
     * @return bool|null True to keep it, false to drop it, null when not asked.
     */
    public static function read_keepcap(\stdClass $data): ?bool {
        return match ($data->keepcap ?? null) {
            self::KEEP => true,
            self::DROP => false,
            default => null,
        };
    }

    /**
     * The wallet the key was in when the questions were put.
     *
     * @param \stdClass $data The submitted data.
     * @return int The wallet id, or zero when the form did not carry one.
     */
    public static function read_shown_wallet(\stdClass $data): int {
        return max(0, (int) ($data->walletid ?? 0));
    }

    /**
     * Which earlier wallet the form said to go on with.
     *
     * @param \stdClass $data The submitted data.
     * @return int|null The wallet id, or null for a new wallet or when not asked.
     */
    public static function read_wallet(\stdClass $data): ?int {
        $wallet = (string) ($data->wallet ?? self::NEW_WALLET);

        return $wallet === self::NEW_WALLET || (int) $wallet <= 0 ? null : (int) $wallet;
    }
}
