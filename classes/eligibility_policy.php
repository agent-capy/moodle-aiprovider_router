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

use aiprovider_router\eligibility\base as eligibility_base;
use aiprovider_router\eligibility\registry;

/**
 * Who the site allows to bring their own key.
 *
 * Asked twice: when somebody registers a key, and again on every request that would use
 * one. The second is what makes a tightened policy take effect on its own — a person who
 * stops teaching stops paying for the site's AI with their own key without anybody having
 * to go and find the key and remove it. Their key is not deleted; it simply stops being
 * used, and is theirs to remove whenever they like.
 *
 * Asking on every request means a role lookup on every request, which is why the answer
 * is cached. The cache is emptied outright whenever the policy changes, so an
 * administrator who tightens the rules sees it take effect at once; a change in who holds
 * which role is followed within the cache's lifetime instead.
 *
 * This applies to keys people bring for themselves. A course key belongs to the course
 * rather than to whoever entered it, so it is governed by who may edit the course, not by
 * this.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class eligibility_policy {
    /** @var string Nobody may bring a key. */
    public const ACCESS_NOBODY = 'nobody';

    /** @var string Anybody with an account may bring a key. */
    public const ACCESS_EVERYBODY = 'everybody';

    /** @var string Only people the conditions below admit. */
    public const ACCESS_CONDITIONS = 'conditions';

    /** @var string The setting holding which of the three applies. */
    public const ACCESS_SETTING = 'byokaccess';

    /** @var string The setting holding the conditions. */
    public const POLICY_SETTING = 'byokpolicy';

    /** @var string Somebody has to satisfy every condition. */
    public const MATCH_ALL = 'all';

    /** @var string Satisfying any one condition is enough. */
    public const MATCH_ANY = 'any';

    /** @var string The setting holding which of those two applies. */
    public const MATCH_SETTING = 'byokmatch';

    /** @var string The cache area holding what was decided about each person. */
    public const CACHE_AREA = 'eligibility';

    /**
     * Which of the three answers the site has chosen.
     *
     * @return string One of the access constants.
     */
    public function get_access(): string {
        $access = (string) get_config('aiprovider_router', self::ACCESS_SETTING);

        return in_array($access, self::get_access_options(), true) ? $access : self::ACCESS_NOBODY;
    }

    /**
     * The three answers, for a form.
     *
     * @return string[] The access constants.
     */
    public static function get_access_options(): array {
        return [self::ACCESS_NOBODY, self::ACCESS_EVERYBODY, self::ACCESS_CONDITIONS];
    }

    /**
     * Whether somebody has to satisfy every condition or just one of them.
     *
     * Spelled out rather than fixed, because a policy is a single statement with no list
     * behind it. Routing rules can express a choice by being several rules; this cannot,
     * so with only one of the two operators available half of the policies a site would
     * want to write could not be written at all. "Teachers, or anyone in the BYOK cohort"
     * and "teachers who are also in it" are both ordinary things to mean.
     *
     * @return string One of the match constants.
     */
    public function get_match(): string {
        $match = (string) get_config('aiprovider_router', self::MATCH_SETTING);

        return in_array($match, self::get_match_options(), true) ? $match : self::MATCH_ANY;
    }

    /**
     * The two ways conditions can be combined, for a form.
     *
     * @return string[] The match constants.
     */
    public static function get_match_options(): array {
        return [self::MATCH_ANY, self::MATCH_ALL];
    }

    /**
     * The conditions the site has set, as stored.
     *
     * @return array Configuration keyed by condition type.
     */
    public function get_stored_conditions(): array {
        $stored = json_decode((string) get_config('aiprovider_router', self::POLICY_SETTING), true);

        return is_array($stored) ? $stored : [];
    }

    /**
     * The conditions the site has set, as objects this version understands.
     *
     * A stored type this version does not know is dropped rather than guessed at, which
     * changes who is admitted in whichever direction the matching rule runs. That is why
     * an unknown type is reported on the policy screen rather than passed over in
     * silence: only a person can say whether the difference matters.
     *
     * @return eligibility_base[] Conditions keyed by type.
     */
    public function get_conditions(): array {
        $conditions = [];
        foreach ($this->get_stored_conditions() as $type => $config) {
            $condition = registry::make((string) $type, is_array($config) ? $config : []);
            if ($condition !== null) {
                $conditions[(string) $type] = $condition;
            }
        }

        return $conditions;
    }

    /**
     * Stored condition types this version does not understand.
     *
     * @return string[] The type names.
     */
    public function get_unknown_conditions(): array {
        return array_values(array_filter(
            array_keys($this->get_stored_conditions()),
            fn($type) => !registry::is_known((string) $type),
        ));
    }

    /**
     * Whether this person may bring their own key.
     *
     * @param int $userid The user.
     * @return bool True when they may.
     */
    public function is_eligible(int $userid): bool {
        if ($userid <= 0 || isguestuser($userid)) {
            return false;
        }

        $cache = $this->get_cache();
        $cached = $cache->get($userid);
        if ($cached !== false) {
            return (bool) $cached['eligible'];
        }

        $eligible = $this->evaluate($userid);
        // Wrapped in an array because a cached false is indistinguishable from a miss.
        $cache->set($userid, ['eligible' => $eligible]);

        return $eligible;
    }

    /**
     * Work out whether this person may bring their own key, asking nothing cached.
     *
     * @param int $userid The user.
     * @return bool True when they may.
     */
    public function evaluate(int $userid): bool {
        $access = $this->get_access();
        if ($access === self::ACCESS_NOBODY) {
            return false;
        }
        if ($access === self::ACCESS_EVERYBODY) {
            return true;
        }

        $conditions = $this->get_conditions();
        if (!$conditions) {
            // The site said "only those matching the conditions" and then named none.
            // Reading that as "everybody" would be the opposite of what was asked for.
            return false;
        }

        $all = $this->get_match() === self::MATCH_ALL;
        foreach ($conditions as $condition) {
            if ($condition->is_met($userid) !== $all) {
                // Under "any", the first condition met settles it; under "all", the first
                // one not met does.
                return !$all;
            }
        }

        return $all;
    }

    /**
     * Record a new policy, and forget every answer given under the old one.
     *
     * @param string $access One of the access constants.
     * @param array $conditions Configuration keyed by condition type.
     * @param string $match One of the match constants.
     */
    public function save(string $access, array $conditions, string $match = self::MATCH_ANY): void {
        if (!in_array($access, self::get_access_options(), true)) {
            throw new \coding_exception('Unknown access setting: ' . $access);
        }
        if (!in_array($match, self::get_match_options(), true)) {
            throw new \coding_exception('Unknown match setting: ' . $match);
        }
        set_config(self::ACCESS_SETTING, $access, 'aiprovider_router');
        set_config(self::MATCH_SETTING, $match, 'aiprovider_router');
        set_config(self::POLICY_SETTING, json_encode($conditions), 'aiprovider_router');
        self::purge();
    }

    /**
     * Throw away what was decided about everybody.
     *
     * Called whenever the policy changes, so that tightening it takes effect at once
     * rather than at the end of the cache's lifetime.
     */
    public static function purge(): void {
        \core_cache\helper::purge_by_definition('aiprovider_router', self::CACHE_AREA);
    }

    /**
     * What the policy says, in sentences an administrator can read.
     *
     * @return string[] One description per condition.
     */
    public function get_descriptions(): array {
        return array_map(fn($condition) => $condition->get_description(), array_values($this->get_conditions()));
    }

    /**
     * The cache holding what was decided about each person.
     *
     * @return \core_cache\cache The cache.
     */
    protected function get_cache(): \core_cache\cache {
        return \core_cache\cache::make('aiprovider_router', self::CACHE_AREA);
    }
}
