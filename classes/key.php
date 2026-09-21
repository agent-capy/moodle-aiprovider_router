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

/**
 * One key somebody has brought for one delegation target.
 *
 * The key itself is held encrypted and is never read back except at the moment it is
 * used or tested. What can be read freely is the hint: the last few characters, kept
 * separately so that the owner can tell their keys apart without anything being
 * decrypted, and so that a site which has lost its encryption key can still show people
 * what they registered.
 *
 * Keys belong to a provider instance rather than to a provider plugin. Two instances of
 * one plugin can point at different endpoints, and a key that works at one of them is no
 * evidence about the other.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key extends \core\persistent {
    /** @var string The table holding keys. */
    public const TABLE = 'aiprovider_router_key';

    /** @var string A key belonging to one person, who pays for their own requests. */
    public const SCOPE_USER = 'user';

    /** @var string A key belonging to a course, which pays for the requests made in it. */
    public const SCOPE_COURSE = 'course';

    /** @var string Recorded when a test call was accepted. */
    public const VERIFY_OK = 'ok';

    /** @var string Recorded when the provider refused the key. */
    public const VERIFY_REJECTED = 'rejected';

    /** @var string Recorded when the test could not reach a conclusion. */
    public const VERIFY_FAILED = 'failed';

    /** @var int How many characters of the key the hint keeps. */
    public const HINT_LENGTH = 4;

    #[\Override]
    protected static function define_properties(): array {
        return [
            'scope' => [
                'type' => PARAM_ALPHA,
            ],
            'scopeid' => [
                'type' => PARAM_INT,
            ],
            // Not a foreign key in the schema, for the same reason a rule's target is
            // not: core must stay free to delete a provider instance.
            'targetid' => [
                'type' => PARAM_INT,
            ],
            'secret' => [
                'type' => PARAM_RAW,
            ],
            'hint' => [
                'type' => PARAM_TEXT,
                'default' => '',
            ],
            'timeverified' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'verifystatus' => [
                'type' => PARAM_ALPHA,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            // What the owner has decided to spend here at most. Theirs to set, and
            // nobody else's: an administrator limiting this would be limiting somebody
            // else's money.
            'capamount' => [
                'type' => PARAM_FLOAT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'capperiod' => [
                'type' => PARAM_ALPHA,
                'default' => spend_ledger::PERIOD_MONTH,
            ],
            'capdays' => [
                'type' => PARAM_INT,
                'default' => 30,
            ],
        ];
    }

    /**
     * Whether the owner has put a limit on this key.
     *
     * @return bool True when there is one.
     */
    public function has_cap(): bool {
        return $this->get_cap_amount() > 0;
    }

    /**
     * What the owner is willing to spend here.
     *
     * @return float The limit, or zero when there is none.
     */
    public function get_cap_amount(): float {
        return (float) ($this->get('capamount') ?? 0);
    }

    /**
     * How the limit is counted.
     *
     * @return string One of the ledger's periods.
     */
    public function get_cap_period(): string {
        return (string) $this->get('capperiod') === spend_ledger::PERIOD_ROLLING
            ? spend_ledger::PERIOD_ROLLING
            : spend_ledger::PERIOD_MONTH;
    }

    /**
     * How many days a rolling limit counts.
     *
     * @return int The number of days.
     */
    public function get_cap_days(): int {
        return max(1, (int) $this->get('capdays'));
    }

    /**
     * What has been spent against this key's limit, and whether it has been reached.
     *
     * The figure is what the site's rate table says the requests would have cost,
     * not what the owner's provider actually billed them. Anywhere it is shown has to
     * say so.
     *
     * @param spend_ledger $ledger The ledger to measure with.
     * @param int $now The moment the period ends at.
     * @return spend The spending over the period the limit is counted in.
     */
    public function get_cap_spend(spend_ledger $ledger, int $now): spend {
        return $ledger->get_key_spend($this, $this->get_cap_period(), $this->get_cap_days(), $now);
    }

    /**
     * Whether this key has spent what its owner allowed it to.
     *
     * Unknown spending does not reach a limit. The direction is the opposite of a
     * budget condition's on purpose: this is somebody's own key, and a site that has
     * entered no rates would otherwise silently stop every brought key it holds. A
     * period recorded in a currency the limit is not written in is unknown in the same
     * way, because nothing here converts between currencies.
     *
     * @param spend_ledger $ledger The ledger to measure with.
     * @param int $now The moment the period ends at.
     * @return bool True when the key should be left out of routing.
     */
    public function is_spent(spend_ledger $ledger, int $now): bool {
        if (!$this->has_cap()) {
            return false;
        }

        return $this->get_cap_spend($ledger, $now)->has_reached($this->get_cap_amount()) === true;
    }

    /**
     * Every scope this plugin understands.
     *
     * @return string[] The scopes.
     */
    public static function get_scopes(): array {
        return [self::SCOPE_USER, self::SCOPE_COURSE];
    }

    /**
     * The last few characters of a key, which is all that is ever shown of it.
     *
     * Deliberately the end. Provider keys commonly begin with a fixed prefix, which
     * would identify nothing, and the end is both more useful for telling two keys apart
     * and no help at all in reconstructing one.
     *
     * @param string $secret The key as the owner typed it.
     * @return string The hint.
     */
    public static function hint_of(string $secret): string {
        $secret = trim($secret);
        if ($secret === '') {
            return '';
        }

        // A hint is the tail of something longer. Where the key is no longer than the
        // hint, the tail is the key, and the promise that the plaintext is never kept
        // or shown again would be broken by the very thing meant to keep it.
        if (\core_text::strlen($secret) <= self::HINT_LENGTH) {
            return '';
        }

        return \core_text::substr($secret, -self::HINT_LENGTH);
    }

    /**
     * A key has to say whose it is.
     *
     * @param mixed $value The submitted scope.
     * @return true|\core\lang_string True when valid, otherwise the error to show.
     */
    protected function validate_scope($value): true|\core\lang_string {
        if (!in_array((string) $value, self::get_scopes(), true)) {
            return new \core\lang_string('key:error:scope', 'aiprovider_router');
        }

        return true;
    }

    /**
     * A key with no owner belongs to nobody and would never be found again.
     *
     * @param mixed $value The submitted scope id.
     * @return true|\core\lang_string True when valid, otherwise the error to show.
     */
    protected function validate_scopeid($value): true|\core\lang_string {
        if ((int) $value <= 0) {
            return new \core\lang_string('key:error:scopeid', 'aiprovider_router');
        }

        return true;
    }

    /**
     * A key is for one delegation target.
     *
     * @param mixed $value The submitted target id.
     * @return true|\core\lang_string True when valid, otherwise the error to show.
     */
    protected function validate_targetid($value): true|\core\lang_string {
        if ((int) $value <= 0) {
            return new \core\lang_string('key:error:notarget', 'aiprovider_router');
        }

        return true;
    }

    /**
     * An empty secret would be stored as an empty string and read back as no key at all.
     *
     * @param mixed $value The encrypted key.
     * @return true|\core\lang_string True when valid, otherwise the error to show.
     */
    protected function validate_secret($value): true|\core\lang_string {
        if (trim((string) $value) === '') {
            return new \core\lang_string('key:error:empty', 'aiprovider_router');
        }

        return true;
    }
}
