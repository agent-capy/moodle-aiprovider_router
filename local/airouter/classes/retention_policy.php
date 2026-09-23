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

use local_airouter\record\ledger;
use local_airouter\record\summariser;

/**
 * How long the site keeps its record, and what that has to be enough for.
 *
 * Every limit on the site -- the budgets rules set, the limits people put on keys they
 * brought -- is worked out from what is still stored. History thrown away from inside a
 * limit's period does not make the figure unknown, which is what this plugin does
 * everywhere else it cannot measure something: it makes the figure smaller, and a
 * limit that had been reached comes back under the line. So the record must be kept
 * at least as long as the furthest limit looks back, and this is where that rule
 * lives. Every way of changing the retention goes through save(), and every way of
 * setting a limit asks shortfall_of(), so that the rule holds whichever side moves.
 *
 * The one way round it is config.php, which fixes a setting beyond the reach of any
 * screen. That cannot be refused, so it is reported instead, by the status check, and
 * the figures a short retention produces are shown as floors rather than as totals.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class retention_policy {
    /** @var string The setting saying how many days of detail are kept. */
    public const DETAIL_SETTING = 'logretentiondays';

    /** @var string The setting saying how many days of summaries are kept. */
    public const SUMMARY_SETTING = 'summaryretentiondays';

    /** @var int How many days of detail are kept until the site says otherwise. */
    public const DEFAULT_DETAIL_DAYS = 90;

    /** @var int How many days of summaries are kept until the site says otherwise: all of them. */
    public const DEFAULT_SUMMARY_DAYS = 0;

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database to read limits from.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
    ) {
    }

    /**
     * How many days of detail the site keeps now, as the purge will read it.
     *
     * @return int Days, or zero to keep everything.
     */
    public function get_detail_days(): int {
        return summariser::get_retention_days();
    }

    /**
     * How many days of summaries the site keeps now, as the purge will read it.
     *
     * @return int Days, or zero to keep everything.
     */
    public function get_summary_days(): int {
        return summariser::get_summary_retention_days();
    }

    /**
     * What is wrong with a pair of retention figures, if anything.
     *
     * Three rules. Days are not negative. Summaries are kept at least as long as the
     * detail, because they are what the detail leaves behind and reports read them
     * for the older part of any period. And summaries are kept at least as long as
     * the furthest limit looks back, for the reason given on the class.
     *
     * @param int $detail Days of detail to keep, zero for ever.
     * @param int $summary Days of summaries to keep, zero for ever.
     * @return string[] Problems to show somebody, keyed by the setting each is about.
     */
    public function problems(int $detail, int $summary): array {
        $problems = [];
        foreach ([self::DETAIL_SETTING => $detail, self::SUMMARY_SETTING => $summary] as $setting => $days) {
            if ($days < 0) {
                $problems[$setting] = get_string('usage:error:retention', 'local_airouter');
            }
        }
        if ($problems) {
            return $problems;
        }
        if ($detail > 0 && $summary > 0 && $summary < $detail) {
            $problems[self::SUMMARY_SETTING] = get_string('usage:error:summaryretention', 'local_airouter');
        }
        $reach = $this->longest_reach_days();
        if ($reach > 0 && $summary > 0 && $summary < $reach) {
            $problems[self::SUMMARY_SETTING] = get_string('usage:error:budgetretention', 'local_airouter', $reach);
        }

        return $problems;
    }

    /**
     * Record how long the site keeps its record, or say why it cannot.
     *
     * The one way the retention is changed, whichever screen or script asks, so that
     * a figure the site's limits depend on cannot be shortened from somewhere the
     * check was not.
     *
     * @param int $detail Days of detail to keep, zero for ever.
     * @param int $summary Days of summaries to keep, zero for ever.
     * @return string[] Problems, keyed by setting, and nothing saved; or empty and both saved.
     */
    public function save(int $detail, int $summary): array {
        $problems = $this->problems($detail, $summary);
        if ($problems) {
            return $problems;
        }
        set_config(self::DETAIL_SETTING, $detail, 'local_airouter');
        set_config(self::SUMMARY_SETTING, $summary, 'local_airouter');

        return [];
    }

    /**
     * How far back the furthest limit on this site looks.
     *
     * @return int Days, or zero where nothing on the site sets a limit.
     */
    public function longest_reach_days(): int {
        return ledger::longest_reach_days($this->db);
    }

    /**
     * By how much the summaries kept fall short of a limit, or null when they cover it.
     *
     * Asked whenever a limit is set or switched on, and answered from the retention
     * as it stands, forced or not.
     *
     * @param string $period One of the ledger's periods.
     * @param int $days How many days a rolling period counts.
     * @return \stdClass|null The days the limit reaches and the days kept, or null.
     */
    public function shortfall_of(string $period, int $days): ?\stdClass {
        return $this->compare(ledger::reach_of($period, $days));
    }

    /**
     * By how much the summaries kept fall short of the furthest limit, or null when they cover it.
     *
     * The screen refuses this, so a shortfall here was set another way: fixed in
     * config.php, most likely. It is what the status check reports.
     *
     * @return \stdClass|null The days the furthest limit reaches and the days kept, or null.
     */
    public function shortfall(): ?\stdClass {
        return $this->compare($this->longest_reach_days());
    }

    /**
     * A reach against what is kept.
     *
     * @param int $reach How far back something looks, in days.
     * @return \stdClass|null The reach and the days kept, or null when kept covers it.
     */
    protected function compare(int $reach): ?\stdClass {
        $kept = $this->get_summary_days();
        if ($reach <= 0 || $kept <= 0 || $reach <= $kept) {
            return null;
        }

        return (object) ['reach' => $reach, 'kept' => $kept];
    }

    /**
     * Whether a setting is fixed in config.php, beyond the reach of any screen.
     *
     * The rule is core's own, from get_config(): a value that is null, an array or an
     * object counts as the setting not being forced at all.
     *
     * @param string $setting The setting name.
     * @return bool True when config.php decides it.
     */
    public static function is_forced(string $setting): bool {
        global $CFG;

        $forced = $CFG->forced_plugin_settings['local_airouter'][$setting] ?? null;

        return $forced !== null && !is_array($forced) && !is_object($forced);
    }
}
