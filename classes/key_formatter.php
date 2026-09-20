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
 * Shows somebody which keys are held for them, without showing any of them.
 *
 * Every cell here is built from what is stored beside the key rather than from the key,
 * so this screen still works on a site that has lost the file its keys were encrypted
 * with. That is the case where somebody most needs to see what they registered.
 *
 * @package    aiprovider_router
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
     * @param spend_ledger|null $ledger The ledger, for what each key has spent.
     * @param string $currency The site currency.
     * @return \html_table The table.
     */
    public static function table(
        array $keys,
        array $targets,
        \moodle_url $url,
        ?spend_ledger $ledger = null,
        string $currency = '',
    ): \html_table {
        $table = new \html_table();
        $table->head = [
            get_string('keys:target', 'aiprovider_router'),
            get_string('keys:hint', 'aiprovider_router'),
            get_string('keys:tested', 'aiprovider_router'),
            get_string('keys:cap', 'aiprovider_router'),
            get_string('actions'),
        ];
        $table->attributes['class'] = 'admintable generaltable';

        foreach ($keys as $targetid => $key) {
            $table->data[] = [
                $targets[$targetid] ?? get_string('keys:target:gone', 'aiprovider_router', $targetid),
                self::hint($key),
                self::tested($key),
                self::cap($key, $ledger, $currency),
                self::actions($url, $key),
            ];
        }

        return $table;
    }

    /**
     * The limit the owner set, and how much of it is gone.
     *
     * ⚠ A key that has reached its limit stops being used and nothing else happens: no
     * error, no message at the time. So it is said here, plainly, because the owner's
     * other reading of a key that stopped working is that the key is broken.
     *
     * @param key $key The key.
     * @param spend_ledger|null $ledger The ledger, for what the key has spent.
     * @param string $currency The site currency.
     * @return string HTML.
     */
    public static function cap(key $key, ?spend_ledger $ledger, string $currency): string {
        if (!$key->has_cap()) {
            return \html_writer::span(get_string('keys:cap:none', 'aiprovider_router'), 'text-muted');
        }

        $limit = format_float($key->get_cap_amount(), 2, true) . ' ' . $currency;
        $period = $key->get_cap_period() === spend_ledger::PERIOD_MONTH
            ? get_string('keys:cap:month', 'aiprovider_router')
            : get_string('keys:cap:rolling:days', 'aiprovider_router', $key->get_cap_days());
        $output = \html_writer::div(get_string(
            'keys:cap:limit',
            'aiprovider_router',
            ['amount' => $limit, 'period' => $period],
        ));

        if ($ledger === null) {
            return $output;
        }
        $spend = $key->get_cap_spend($ledger, time());
        if (!$spend->is_known()) {
            // This site prices nothing, so nothing can be measured against the limit.
            // The key keeps working, which is the direction that does not punish
            // somebody for setting themselves one.
            return $output . \html_writer::div(
                get_string('keys:cap:unmeasured', 'aiprovider_router'),
                'text-muted small',
            );
        }

        // The owner's own money, so the bar carries the figures with it.
        $output .= usage_formatter::progress(
            $spend->get_amount() / $key->get_cap_amount(),
            get_string('keys:cap', 'aiprovider_router'),
            get_string('keys:cap:spent', 'aiprovider_router', [
                'amount' => format_float($spend->get_amount(), 2, true) . ' ' . $currency,
            ]),
        );
        if ($spend->has_reached($key->get_cap_amount())) {
            $output .= \html_writer::div(
                get_string('keys:cap:reached', 'aiprovider_router'),
                'text-warning',
            );
        }

        return $output;
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
            ? get_string('keys:hint:none', 'aiprovider_router')
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
            return \html_writer::span(get_string('keys:tested:never', 'aiprovider_router'), 'text-muted');
        }

        $status = (string) $key->get('verifystatus');
        $classes = [
            key::VERIFY_OK => 'text-success',
            key::VERIFY_REJECTED => 'text-danger',
            key::VERIFY_FAILED => 'text-warning',
        ];

        return \html_writer::span(
            get_string('keys:tested:' . ($status ?: key::VERIFY_FAILED), 'aiprovider_router'),
            $classes[$status] ?? 'text-muted',
        ) . \html_writer::div(userdate($when), 'text-muted small');
    }

    /**
     * What can be done with a key once it is stored.
     *
     * Replacing one is done by registering it again for the same provider, so what is
     * left is testing it, setting a limit on it, and removing it.
     *
     * @param \moodle_url $url The page the actions return to.
     * @param key $key The key.
     * @return string HTML.
     */
    public static function actions(\moodle_url $url, key $key): string {
        $id = (int) $key->get('id');
        $links = [
            \html_writer::link(
                new \moodle_url($url, ['action' => 'test', 'keyid' => $id, 'sesskey' => sesskey()]),
                get_string('keys:test', 'aiprovider_router'),
            ),
            \html_writer::link(
                new \moodle_url($url, ['action' => 'cap', 'keyid' => $id]),
                get_string('keys:cap:set', 'aiprovider_router'),
            ),
            \html_writer::link(
                new \moodle_url($url, ['action' => 'delete', 'keyid' => $id]),
                get_string('delete'),
            ),
        ];

        return implode(' ', $links);
    }
}
