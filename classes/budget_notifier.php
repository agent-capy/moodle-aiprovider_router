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

use aiprovider_router\condition\budget;

/**
 * Says once, afterwards, that a budget or a key's limit has been reached.
 *
 * Nothing here happens while a request is being routed. A budget already decides where
 * requests go on its own; this is the part that tells a person about it, and telling
 * people is slow and fails in ways an AI request must not inherit. A mail server having
 * a bad afternoon should not become an AI having a bad afternoon.
 *
 * Saying it once is the whole difficulty. A daily task looking at a figure that is still
 * over the limit would say the same thing every morning until the month turned, and the
 * obvious fix - remembering the period a notice was about - does not work for a rolling
 * period, whose start moves every day.
 *
 * So a notice is remembered while the threshold is over, and forgotten when the
 * spending falls back under it. A calendar month resets by itself, because the spending
 * drops to nothing on the first; a rolling period resets when it genuinely eases off.
 * Neither case needs this to know anything about periods.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class budget_notifier {
    /** @var string The table remembering what has been said. */
    public const TABLE = 'aiprovider_router_notice';

    /** @var string A budget over the whole site. */
    public const KIND_SITE = 'site';

    /** @var string A budget over one course. */
    public const KIND_COURSE = 'course';

    /** @var string A budget over one person. */
    public const KIND_USER = 'user';

    /** @var string A limit somebody put on the key they brought. */
    public const KIND_KEY = 'key';

    /**
     * @var array<string, string> What somebody must be allowed before a notice reaches them.
     *
     * A notice carries figures, and the same figures sit behind a screen. The two
     * have to agree, or the notice becomes a way around the screen: a notice about
     * one person names them and says what they spent, which belongs to the named
     * report rather than the course monitor, and a notice about the site's money
     * belongs to the site's own report. A course notice carries a course's figures,
     * which is exactly what the course monitor shows, so that one is unchanged.
     */
    protected const WATCHER_CAPABILITY = [
        self::KIND_SITE => 'moodle/site:config',
        self::KIND_COURSE => 'aiprovider/router:viewusage',
        self::KIND_USER => 'aiprovider/router:viewuserusage',
    ];

    /** @var string Config saying whether to send anything at all. */
    public const ENABLED_SETTING = 'budgetnotify';

    /** @var string Config holding the share of a limit that earns an early warning. */
    public const SHARE_SETTING = 'budgetnotifyshare';

    /** @var int The early warning share used when the site has not chosen. */
    public const DEFAULT_SHARE = 80;

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database to work on.
     * @param spend_ledger|null $ledger The ledger. Uncached by default: a figure acted
     *                                  on once a day is worth a query.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
        /** @var spend_ledger|null The ledger. */
        protected ?spend_ledger $ledger = null,
    ) {
        $this->ledger ??= new spend_ledger($db, null, false);
    }

    /**
     * Look at every budget and every key limit, and say what has not been said.
     *
     * @param int $now The current time.
     * @return array Counts of notices sent and of thresholds that have eased off.
     */
    public function run(int $now): array {
        if (!self::is_enabled()) {
            return ['sent' => 0, 'cleared' => 0, 'checked' => 0];
        }

        $result = ['sent' => 0, 'cleared' => 0, 'checked' => 0];
        foreach ($this->get_budgets() as $budget) {
            [$from, $to] = $this->ledger->get_window($budget->period, $budget->days, $now);
            if ($budget->scope === spend_ledger::SCOPE_SITE) {
                $spending = [0 => $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to)];
            } else {
                $spending = $this->ledger->measure_each($budget->scope, $from, $to);
            }
            foreach ($spending as $subjectid => $spend) {
                $this->weigh(
                    $result,
                    $budget->scope,
                    (int) $subjectid,
                    $spend,
                    $budget->amount,
                    $budget->metric,
                    $now,
                );
            }
        }

        foreach ($this->get_capped_keys() as $key) {
            // A limit somebody puts on their own key is an amount of money. What they
            // are protecting is a bill, and the provider sends it in money.
            $spend = $key->get_cap_spend($this->ledger, $now);
            $this->weigh(
                $result,
                self::KIND_KEY,
                (int) $key->get('id'),
                $spend,
                $key->get_cap_amount(),
                spend_ledger::METRIC_COST,
                $now,
            );
        }

        return $result;
    }

    /**
     * Whether this site sends these at all.
     *
     * @return bool True when notices are wanted.
     */
    public static function is_enabled(): bool {
        $configured = get_config('aiprovider_router', self::ENABLED_SETTING);

        return $configured === false || (bool) $configured;
    }

    /**
     * The share of a limit that earns a warning before the limit itself.
     *
     * @return int A percentage, or zero when only the limit itself is worth saying.
     */
    public static function get_share(): int {
        $configured = get_config('aiprovider_router', self::SHARE_SETTING);
        if ($configured === false || $configured === '') {
            return self::DEFAULT_SHARE;
        }

        return min(99, max(0, (int) $configured));
    }

    /**
     * The thresholds a limit is weighed against, as percentages.
     *
     * @return int[] The percentages, lowest first.
     */
    public static function get_thresholds(): array {
        $share = self::get_share();

        return $share > 0 ? [$share, 100] : [100];
    }

    /**
     * Compare one subject's spending against one limit, and act on the difference.
     *
     * @param array $result Counts so far. Modified in place.
     * @param string $kind What the limit is about.
     * @param int $subjectid The course, person or key.
     * @param spend $spend What has been spent.
     * @param float $limit The limit.
     * @param string $metric What the limit counts.
     * @param int $now The current time.
     */
    protected function weigh(
        array &$result,
        string $kind,
        int $subjectid,
        spend $spend,
        float $limit,
        string $metric,
        int $now,
    ): void {
        if ($limit <= 0 || !$spend->is_known($metric)) {
            // Nothing can be said about spending nobody can work out. The status report
            // is where a site is told that its rates are missing; saying it again here,
            // by mail, every day, would be a worse way to make the same point.
            return;
        }

        foreach (self::get_thresholds() as $threshold) {
            $result['checked']++;
            $reached = $spend->get_measure($metric) >= $limit * $threshold / 100;
            // The metric is part of what is being remembered. A course held to 3000
            // JPY and to 3000 requests has two limits, reached at different moments;
            // without this, one of them would silence the other.
            $remembered = $this->db->get_record(self::TABLE, [
                'kind' => $kind,
                'subjectid' => $subjectid,
                'metric' => $metric,
                'limitamount' => $limit,
                'threshold' => $threshold,
            ]);

            if ($reached && $remembered === false) {
                if (!$this->announce($kind, $subjectid, $spend, $limit, $metric, $threshold)) {
                    // Nobody to tell. ⚠ Not remembered as said, because it was not:
                    // recording it would mean that giving somebody the role tomorrow
                    // would still leave them hearing nothing.
                    continue;
                }
                $this->db->insert_record(self::TABLE, (object) [
                    'kind' => $kind,
                    'subjectid' => $subjectid,
                    'metric' => $metric,
                    'limitamount' => $limit,
                    'threshold' => $threshold,
                    'timenotified' => $now,
                ]);
                $result['sent']++;
            } else if (!$reached && $remembered !== false) {
                // Eased off, so the next time it is reached is worth saying again.
                $this->db->delete_records(self::TABLE, ['id' => $remembered->id]);
                $result['cleared']++;
            }
        }
    }

    /**
     * Tell whoever should hear about it.
     *
     * Who that is depends on what the limit is about, and so does how much of it they
     * are told. People who can already see what the site spends are told the figures.
     * The course or the person a budget is about is told that it has been reached and
     * nothing more: the figure is the site's expenditure, shown on a screen they have
     * no access to, and a number nobody can check is worse than no number.
     *
     * @param string $kind What the limit is about.
     * @param int $subjectid The course, person or key.
     * @param spend $spend What has been spent.
     * @param float $limit The limit.
     * @param string $metric What the limit counts.
     * @param int $threshold The share of it that has been crossed.
     * @return bool True when somebody was told.
     */
    protected function announce(
        string $kind,
        int $subjectid,
        spend $spend,
        float $limit,
        string $metric,
        int $threshold,
    ): bool {
        // Both figures carry the unit that says what they count, so the sentences
        // around them do not have to be written twice.
        $figures = (object) [
            'amount' => budget::label_amount($spend->get_measure($metric), $metric),
            'limit' => budget::label_amount($limit, $metric),
            'subject' => $this->describe($kind, $subjectid),
        ];
        $figures->share = min(999, (int) round($spend->get_measure($metric) / $limit * 100));
        $suffix = $threshold >= 100 ? 'reached' : 'nearly';

        if ($kind === self::KIND_KEY) {
            // Their own key, their own money, so their own figures.
            $sent = 0;
            foreach ($this->get_key_owners($subjectid) as $user) {
                $this->post('keycap', $user, 'notify:keycap:' . $suffix, $figures);
                $sent++;
            }

            return $sent > 0;
        }

        $told = [];
        foreach ($this->get_watchers($kind) as $user) {
            $told[(int) $user->id] = true;
            $this->post('budget', $user, 'notify:budget:' . $kind . ':' . $suffix, $figures);
        }
        foreach ($this->get_affected($kind, $subjectid) as $user) {
            if (isset($told[(int) $user->id])) {
                // Somebody who watches the site's spending and also teaches the course.
                // One message, the one with the figures in it.
                continue;
            }
            $told[(int) $user->id] = true;
            $this->post('budget', $user, 'notify:budget:affected:' . $suffix, $figures);
        }

        return $told !== [];
    }

    /**
     * Send one message.
     *
     * @param string $provider The message provider name.
     * @param \stdClass $user The recipient.
     * @param string $key The language string holding the body.
     * @param \stdClass $figures What to put in it.
     */
    protected function post(string $provider, \stdClass $user, string $key, \stdClass $figures): void {
        $message = new \core\message\message();
        $message->component = 'aiprovider_router';
        $message->name = $provider;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = get_string('notify:' . $provider . ':subject', 'aiprovider_router');
        $message->fullmessage = get_string($key, 'aiprovider_router', $figures);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        $message->contexturl = (new \moodle_url('/ai/provider/router/usage.php'))->out(false);
        $message->contexturlname = get_string('usage:heading', 'aiprovider_router');

        message_send($message);
    }

    /**
     * What the limit was about, in words that carry no figures.
     *
     * @param string $kind What the limit is about.
     * @param int $subjectid The course, person or key.
     * @return string The description.
     */
    protected function describe(string $kind, int $subjectid): string {
        if ($kind === self::KIND_COURSE) {
            $name = $this->db->get_field('course', 'fullname', ['id' => $subjectid], IGNORE_MISSING);

            return $name === false
                ? get_string('report:gonecourse', 'aiprovider_router', $subjectid)
                : format_string($name);
        }
        if ($kind === self::KIND_USER) {
            $names = user_report::get_names([$subjectid]);

            return $names[$subjectid] ?? get_string('report:goneuser', 'aiprovider_router', $subjectid);
        }

        return get_string('notify:subject:site', 'aiprovider_router');
    }

    /**
     * The people who watch what the site spends.
     *
     * ⚠ The administrators are included by hand. get_users_by_capability() does not
     * return them - they pass every capability without holding any - so on a site
     * that has given the role to nobody in particular, which is most sites, a notice
     * built from that list alone would go nowhere at all.
     *
     * @return \stdClass[] The users, keyed by id.
     */
    protected function get_watchers(string $kind): array {
        // Whole records, deliberately: message_send() wants auth, suspended, deleted
        // and emailstop as well as the name fields, and quietly fetches them one at a
        // time when they are missing. Admin records come back whole already.
        $fields = 'u.*';
        $watchers = [];
        foreach (get_admins() as $admin) {
            $watchers[(int) $admin->id] = $admin;
        }
        $capability = self::WATCHER_CAPABILITY[$kind] ?? self::WATCHER_CAPABILITY[self::KIND_SITE];
        foreach (get_users_by_capability(\context_system::instance(), $capability, $fields) as $user) {
            $watchers[(int) $user->id] = $user;
        }

        return $watchers;
    }

    /**
     * The people a budget is about, who are told without any figures.
     *
     * @param string $kind What the limit is about.
     * @param int $subjectid The course or the person.
     * @return \stdClass[] The users.
     */
    protected function get_affected(string $kind, int $subjectid): array {
        if ($kind === self::KIND_COURSE) {
            $context = \context_course::instance($subjectid, IGNORE_MISSING);

            return $context === false ? [] : get_users_by_capability(
                $context,
                'aiprovider/router:viewusage',
                'u.*',
            );
        }
        if ($kind === self::KIND_USER) {
            $user = \core_user::get_user($subjectid, '*', IGNORE_MISSING);

            return $user && !$user->deleted && !$user->suspended ? [$user] : [];
        }

        return [];
    }

    /**
     * Whoever brought a key, or whoever may act for the course that did.
     *
     * @param int $keyid The key.
     * @return \stdClass[] The users.
     */
    protected function get_key_owners(int $keyid): array {
        $record = $this->db->get_record(key::TABLE, ['id' => $keyid]);
        if ($record === false) {
            return [];
        }
        if ((string) $record->scope === key::SCOPE_COURSE) {
            $context = \context_course::instance((int) $record->scopeid, IGNORE_MISSING);

            return $context === false ? [] : get_users_by_capability(
                $context,
                'aiprovider/router:managecoursekey',
                'u.*',
            );
        }
        $user = \core_user::get_user((int) $record->scopeid, '*', IGNORE_MISSING);

        return $user && !$user->deleted && !$user->suspended ? [$user] : [];
    }

    /**
     * Every budget the enabled rules set.
     *
     * @return \stdClass[] Rows of scope, metric, amount, period and days.
     */
    protected function get_budgets(): array {
        return (new rule_repository($this->db))->get_budgets();
    }

    /**
     * Every key whose owner has put a limit on it.
     *
     * @return key[] The keys.
     */
    protected function get_capped_keys(): array {
        $keys = [];
        foreach ($this->db->get_records_select(key::TABLE, 'capamount IS NOT NULL') as $record) {
            $keys[] = new key(0, $record);
        }

        return $keys;
    }
}
