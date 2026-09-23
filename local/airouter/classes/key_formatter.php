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

/**
 * Shows somebody which keys are held for them, without showing any of them.
 *
 * Every cell here is built from what is stored beside the key rather than from the key,
 * so this screen still works on a site that has lost the file its keys were encrypted
 * with. That is the case where somebody most needs to see what they registered.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_formatter {
    /**
     * The keys held, one per row.
     *
     * @param key[] $keys The keys, keyed by target id.
     * @param string[] $targets Target names keyed by id.
     * @param \moodle_url $url The page the actions return to.
     * @param ledger|null $ledger The ledger, for what each key has spent.
     * @param array $currencies The currency each target's provider bills in, keyed by
     *                          target id; null where the provider has no rates yet.
     * @param bool $mayuse Whether the owner may still use these keys, as opposed to
     *                     only remove them.
     * @return \html_table The table.
     */
    public static function table(
        array $keys,
        array $targets,
        \moodle_url $url,
        ?ledger $ledger = null,
        array $currencies = [],
        bool $mayuse = true,
    ): \html_table {
        $table = new \html_table();
        $table->head = [
            get_string('keys:target', 'local_airouter'),
            get_string('keys:hint', 'local_airouter'),
            get_string('keys:tested', 'local_airouter'),
            get_string('keys:cap', 'local_airouter'),
            get_string('actions'),
        ];
        $table->attributes['class'] = 'admintable generaltable';

        foreach ($keys as $targetid => $key) {
            $table->data[] = [
                $targets[$targetid] ?? get_string('keys:target:gone', 'local_airouter', $targetid),
                self::hint($key),
                self::tested($key),
                self::cap($key, $ledger, $currencies[$targetid] ?? null),
                self::actions($url, $key, $mayuse),
            ];
        }

        return $table;
    }

    /**
     * The limit the owner set, and how much of it is gone.
     *
     * A key that has reached its limit stops being used and nothing else happens: no
     * error, no message at the time. So it is said here, plainly, because the owner's
     * other reading of a key that stopped working is that the key is broken.
     *
     * @param key $key The key.
     * @param ledger|null $ledger The ledger, for what the key has spent.
     * @param string|null $currency The currency the limit is in, which is what the
     *                              key's provider bills in; null where the provider
     *                              has no rates yet and so no currency.
     * @return string HTML.
     */
    public static function cap(key $key, ?ledger $ledger, ?string $currency): string {
        global $DB;

        if (!$key->has_cap()) {
            return \html_writer::span(get_string('keys:cap:none', 'local_airouter'), 'text-muted');
        }

        $output = \html_writer::div(self::limit($key, $currency));
        $short = (new retention_policy($DB))->shortfall_of($key->get_cap_period(), $key->get_cap_days());
        if ($short !== null) {
            // The site keeps less than this limit looks back over. The owner cannot
            // change that, so it is said rather than refused: the figure below is a
            // floor.
            $output .= \html_writer::div(get_string('keys:cap:short', 'local_airouter', $short), 'text-warning small');
        }

        if ($ledger === null) {
            return $output;
        }
        $spend = $key->get_cap_spend($ledger, time());
        $provider = $spend->sole_provider();
        $entry = $spend->get_provider($provider);
        if ($entry !== null && $entry->amount !== null && !$entry->comparable) {
            // Recorded in a currency the limit is not written in: the provider's
            // rates say another currency now, or say nothing. The figure is real and
            // is shown as recorded, but it cannot be put next to the limit: nothing
            // here converts between currencies, and a share worked out from two of
            // them would be a number about nothing.
            return $output . \html_writer::div(
                get_string('keys:cap:othercurrency', 'local_airouter', [
                    'amount' => format_float($entry->amount, 2, true) . ' ' . $entry->currency,
                ]),
                'text-muted small',
            );
        }
        if (!$spend->is_known(ledger::METRIC_COST, $provider)) {
            // Nothing prices what this key was used for, so nothing can be measured
            // against the limit. The key keeps working, which is the direction that
            // does not punish somebody for setting themselves one.
            return $output . \html_writer::div(
                get_string('keys:cap:unmeasured', 'local_airouter'),
                'text-muted small',
            );
        }

        // The owner's own money, so the bar carries the figures with it, in the
        // currency the costs were recorded in, which is the provider's.
        $spent = $spend->get_amount($provider);
        $spentin = $spend->get_currency($provider) ?? $currency;
        $amount = format_float($spent, 2, true) . ($spentin === null ? '' : ' ' . $spentin);
        // A period the site cannot account for the start of gives a floor, and a
        // floor is said to be one.
        $note = $spend->is_partial()
            ? get_string('keys:cap:spent:atleast', 'local_airouter', [
                'amount' => $amount,
                'from' => userdate($spend->get_covered_from(), get_string('strftimedateshort', 'langconfig')),
            ])
            : get_string('keys:cap:spent', 'local_airouter', ['amount' => $amount]);
        $output .= usage_formatter::progress($spent / $key->get_cap_amount(), get_string('keys:cap', 'local_airouter'), $note);
        if ($spend->has_reached($key->get_cap_amount(), ledger::METRIC_COST, $provider)) {
            $output .= \html_writer::div(
                get_string('keys:cap:reached', 'local_airouter'),
                'text-warning',
            );
        }

        return $output;
    }

    /**
     * The limit the owner set, as a sentence.
     *
     * @param key $key The key, which has a limit.
     * @param string|null $currency The currency the limit is in, or null where the
     *                              provider has no rates yet and so no currency.
     * @return string Plain text.
     */
    public static function limit(key $key, ?string $currency): string {
        $limit = format_float($key->get_cap_amount(), 2, true) . ($currency === null ? '' : ' ' . $currency);
        $period = $key->get_cap_period() === ledger::PERIOD_MONTH
            ? get_string('keys:cap:month', 'local_airouter')
            : get_string('keys:cap:rolling:days', 'local_airouter', $key->get_cap_days());

        return get_string('keys:cap:limit', 'local_airouter', ['amount' => $limit, 'period' => $period]);
    }

    /**
     * The only part of a key anybody is ever shown again.
     *
     * @param key $key The key.
     * @return string HTML.
     */
    public static function hint(key $key): string {
        $hint = (string) $key->get('hint');

        return $hint === ''
            ? get_string('keys:hint:none', 'local_airouter')
            : \html_writer::tag('code', '…' . s($hint));
    }

    /**
     * What the provider said the last time the key was put to it.
     *
     * @param key $key The key.
     * @return string HTML.
     */
    public static function tested(key $key): string {
        $when = (int) $key->get('timeverified');
        if ($when === 0) {
            return \html_writer::span(get_string('keys:tested:never', 'local_airouter'), 'text-muted');
        }

        $status = (string) $key->get('verifystatus');
        $classes = [
            key::VERIFY_OK => 'text-success',
            key::VERIFY_REJECTED => 'text-danger',
            key::VERIFY_FAILED => 'text-warning',
        ];

        return \html_writer::span(
            get_string('keys:tested:' . ($status ?: key::VERIFY_FAILED), 'local_airouter'),
            $classes[$status] ?? 'text-muted',
        ) . \html_writer::div(userdate($when), 'text-muted small');
    }

    /**
     * What can be done with a key once it is stored.
     *
     * Replacing it, testing it, setting a limit on it, and removing it.
     *
     * @param \moodle_url $url The page the actions return to.
     * @param key $key The key.
     * @param bool $mayuse Whether the owner may still use the key. Removing it is
     *                     offered either way: the key is theirs, and a policy tightened
     *                     after they registered it must not leave them unable to take
     *                     back a secret the site is holding for them.
     * @return string HTML.
     */
    public static function actions(\moodle_url $url, key $key, bool $mayuse = true): string {
        $id = (int) $key->get('id');
        $links = [];
        if ($mayuse) {
            // Replacing a key, testing it and capping it all mean going on using it.
            // None makes sense for somebody the site no longer allows to bring one.
            $links[] = \html_writer::link(
                new \moodle_url($url, ['action' => 'replace', 'keyid' => $id]),
                get_string('keys:replace', 'local_airouter'),
            );
            $links[] = \html_writer::link(
                new \moodle_url($url, ['action' => 'test', 'keyid' => $id, 'sesskey' => sesskey()]),
                get_string('keys:test', 'local_airouter'),
            );
            $links[] = \html_writer::link(
                new \moodle_url($url, ['action' => 'cap', 'keyid' => $id]),
                get_string('keys:cap:set', 'local_airouter'),
            );
        }
        // Always offered. A key is a secret its owner handed over, and taking it back
        // cannot depend on a policy somebody else changed afterwards.
        $links[] = \html_writer::link(
            new \moodle_url($url, ['action' => 'delete', 'keyid' => $id]),
            get_string('delete'),
        );

        return implode(' ', $links);
    }
}
