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

use local_airouter\record\reader;

/**
 * Turns the monitor's figures into the few shapes the dashboard is allowed to have.
 *
 * The shape is fixed on purpose: one time series, two breakdowns and one table. Reports
 * grow without limit if each new question is answered with a new chart, and the questions
 * a site owner actually has are how much is being used, where it goes, what it costs and
 * what is failing.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage_formatter {
    /**
     * The headline figures for the period.
     *
     * @param \stdClass $totals The totals.
     * @return string HTML.
     */
    public static function totals(\stdClass $totals): string {
        $items = [
            'usage:total:requests' => number_format((int) $totals->requests),
            'usage:total:failures' => number_format((int) $totals->failures),
            'usage:total:tokens' => number_format((int) $totals->prompttokens + (int) $totals->completiontokens),
            'usage:total:cost' => self::costs($totals->costs),
        ];

        $cells = '';
        foreach ($items as $key => $value) {
            $cells .= \html_writer::div(
                \html_writer::div(get_string($key, 'local_airouter'), 'text-muted')
                    . \html_writer::tag('strong', $value, ['class' => 'h4']),
                'col-sm-3 mb-2',
            );
        }

        return \html_writer::div($cells, 'row') . self::coverage($totals);
    }

    /**
     * What the payer being looked at leaves out.
     *
     * A screen showing one payer's figures says nothing about the rest, and nothing on
     * it looks incomplete. A site whose teachers pay for most of the AI would otherwise
     * read its own usage as a fraction of what is happening.
     *
     * @param \stdClass[] $bykeysource Every payer's figures for the period, unfiltered.
     * @param string $keysource The payer being shown.
     * @return string HTML, empty when nothing is being left out.
     */
    public static function elsewhere(array $bykeysource, string $keysource): string {
        if ($keysource === reader::KEYSOURCE_ALL) {
            return '';
        }

        $hidden = 0;
        foreach ($bykeysource as $row) {
            if ((string) $row->keysource !== $keysource) {
                $hidden += (int) $row->requests;
            }
        }
        if ($hidden === 0) {
            return '';
        }

        return \html_writer::div(
            get_string('usage:keysource:hidden', 'local_airouter', number_format($hidden)),
            'text-muted',
        );
    }

    /**
     * What a cost total is worth saying about itself.
     *
     * A total drawn from rates that covered only some of the requests is not the cost of
     * the period, and saying so is the difference between a figure and a guess.
     *
     * @param \stdClass $totals The totals.
     * @return string HTML.
     */
    public static function coverage(\stdClass $totals): string {
        // Against the calls rather than the requests. The cost is the cost of the
        // calls, and a request that went through two providers has two of them.
        $calls = (int) ($totals->calls ?? $totals->requests);
        $costed = (int) $totals->costedcalls;
        if ($calls === 0 || $costed === $calls) {
            return '';
        }

        $key = $costed === 0 ? 'usage:cost:none' : 'usage:cost:partial';

        return \html_writer::div(
            get_string($key, 'local_airouter', (object) ['costed' => $costed, 'calls' => $calls]),
            'text-muted',
        );
    }

    /**
     * Money by provider, each provider's amount in its own currency, side by side.
     *
     * Costs are worked out when a request happens and nothing here converts between
     * currencies, so a period that used a provider billed in dollars and one billed
     * in yen is shown as an amount for each. They are not added even when two
     * providers bill alike: whether the site's budget is one figure or one per
     * provider is the site's to decide, and the figures are laid out for it to add.
     *
     * @param array[] $costs Entries of provider, currency and amount, empty when nothing was priced.
     * @return string The formatted amounts.
     */
    public static function costs(array $costs): string {
        if (!$costs) {
            return get_string('usage:cost:unknown', 'local_airouter');
        }
        $parts = [];
        foreach ($costs as $entry) {
            $parts[] = get_string('usage:cost:byprovider', 'local_airouter', (object) [
                'provider' => self::provider_name((string) $entry['provider']),
                'amount' => self::cost((float) $entry['amount'], (string) $entry['currency']),
            ]);
        }

        return implode(' / ', $parts);
    }

    /**
     * The name of a provider plugin, for a row's money or a row grouped by provider.
     *
     * @param string $component The provider component, or a dash for none.
     * @return string The name.
     */
    public static function provider_name(string $component): string {
        if ($component === '' || $component === '-') {
            return get_string('usage:notarget', 'local_airouter');
        }

        return get_string_manager()->string_exists('pluginname', $component)
            ? get_string('pluginname', $component)
            : $component;
    }

    /**
     * What each provider was asked, and what it cost, for the period.
     *
     * The table a site adds up by hand, or does not: one row per provider, each in
     * the currency that provider bills in, and no total across them.
     *
     * @param \stdClass[] $rows The rows by provider, busiest first.
     * @return \html_table The table.
     */
    public static function provider_table(array $rows): \html_table {
        $table = new \html_table();
        $table->head = [
            get_string('usage:column:provider', 'local_airouter'),
            get_string('usage:column:currency', 'local_airouter'),
            get_string('usage:total:requests', 'local_airouter'),
            get_string('usage:column:calls', 'local_airouter'),
            get_string('usage:column:costedcalls', 'local_airouter'),
            get_string('usage:total:cost', 'local_airouter'),
        ];
        $table->attributes['class'] = 'admintable generaltable';
        foreach ($rows as $row) {
            $table->data[] = [
                self::provider_name((string) ($row->targetprovider ?? '-')),
                $row->currency ?? '-',
                number_format((int) $row->requests),
                number_format((int) $row->calls),
                number_format((int) $row->costedcalls),
                $row->cost === null ? self::costs($row->costs) : self::cost($row->cost, $row->currency),
            ];
        }

        return $table;
    }

    /**
     * A cost, with the currency it is counted in.
     *
     * @param int|float|null $cost The cost, or null when nothing priced it.
     * @param string $currency What it is in.
     * @return string The formatted cost.
     */
    public static function cost(int|float|null $cost, string $currency): string {
        if ($cost === null) {
            return get_string('usage:cost:unknown', 'local_airouter');
        }

        return format_float((float) $cost, 4, true, true) . ' ' . $currency;
    }

    /**
     * How much of a limit has gone, as a bar.
     *
     * There are places where the share is the whole of what somebody may be told. A
     * teacher whose course has a budget set on it has no business seeing what the site
     * spends, but does need to know how close the course is to the point where its AI
     * changes behaviour, and a proportion carries that without carrying a figure.
     *
     * Over the limit the bar stops at full and the text goes on, because a bar that
     * could not be read past the end would say "finished" for every degree of over.
     *
     * @param float $share What has gone, where 1.0 is the whole limit.
     * @param string $label What the bar is about, read out to screen readers.
     * @param string|null $note A line to put under it, such as the figures, where the
     *                          reader is allowed them.
     * @return string HTML.
     */
    public static function progress(float $share, string $label, ?string $note = null): string {
        $percent = max(0, (int) round($share * 100));
        $width = min(100, $percent);
        $level = match (true) {
            $percent >= 100 => 'bg-danger',
            $percent >= 80 => 'bg-warning',
            default => '',
        };

        $bar = \html_writer::div('', trim('progress-bar ' . $level), [
            'style' => 'width: ' . $width . '%',
            'role' => 'progressbar',
            'aria-valuenow' => $percent,
            'aria-valuemin' => 0,
            'aria-valuemax' => 100,
            'aria-label' => $label,
        ]);
        $output = \html_writer::div($bar, 'progress', ['style' => 'max-width: 20rem;']);
        $output .= \html_writer::div(
            get_string('usage:budget:share', 'local_airouter', $percent),
            $percent >= 100 ? 'text-danger small' : 'text-muted small',
        );
        if ($note !== null && $note !== '') {
            $output .= \html_writer::div($note, 'text-muted small');
        }

        return $output;
    }

    /**
     * Requests and cost, day by day.
     *
     * Cost is on its own axis. The two are measured in different things, and a cost of a
     * few units drawn against a few hundred requests is a flat line along the bottom.
     * The money is drawn as a line per provider, each named for the provider and its
     * currency; they share the cost axis, since core's charts offer one axis on each
     * side, and each line is read against its own name.
     *
     * @param array $series Rows keyed by the midnight of their day.
     * @return \core\chart_line The chart.
     */
    public static function daily_chart(array $series): \core\chart_line {
        $labels = [];
        $requests = [];
        $entries = self::total_costs($series);
        $costs = array_fill_keys(array_keys($entries), []);
        foreach ($series as $day => $row) {
            $labels[] = userdate($day, get_string('strftimedateshort', 'langconfig'));
            $requests[] = (int) $row->requests;
            foreach ($entries as $key => $entry) {
                $costs[$key][] = round((float) ($row->costs[$key]['amount'] ?? 0), 4);
            }
        }

        $chart = new \core\chart_line();
        $chart->set_labels($labels);
        $chart->add_series(new \core\chart_series(
            get_string('usage:total:requests', 'local_airouter'),
            $requests,
        ));

        if (!$entries) {
            // Nothing in the period was priced, so there is no cost line to draw. The
            // request count is still worth drawing.
            return $chart;
        }

        foreach ($entries as $key => $entry) {
            $cost = new \core\chart_series(
                get_string('usage:total:cost', 'local_airouter')
                    . ' (' . self::provider_name((string) $entry['provider']) . ', ' . $entry['currency'] . ')',
                $costs[$key],
            );
            $cost->set_yaxis(1);
            $chart->add_series($cost);
        }
        // The second axis cannot be created before the first one exists, so the axis the
        // request count already uses is asked for by name before the cost axis is added.
        $chart->get_yaxis(0, true);
        $chart->get_yaxis(1, true)->set_position(\core\chart_axis::POS_RIGHT);

        return $chart;
    }

    /**
     * The money in a set of rows, by provider.
     *
     * @param \stdClass[] $rows Rows carrying costs.
     * @return array[] Entries of provider, currency and amount, by key.
     */
    protected static function total_costs(array $rows): array {
        $total = [];
        foreach ($rows as $row) {
            $total = record\reader::add_costs($total, $row->costs ?? []);
        }

        return $total;
    }

    /**
     * Requests day by day, without the cost.
     *
     * What a course used is a teacher's business; what it cost the site is not.
     *
     * @param array $series Rows keyed by the midnight of their day.
     * @return \core\chart_line The chart.
     */
    public static function course_chart(array $series): \core\chart_line {
        $labels = [];
        $requests = [];
        foreach ($series as $day => $row) {
            $labels[] = userdate($day, get_string('strftimedateshort', 'langconfig'));
            $requests[] = (int) $row->requests;
        }

        $chart = new \core\chart_line();
        $chart->set_labels($labels);
        $chart->add_series(new \core\chart_series(
            get_string('usage:total:requests', 'local_airouter'),
            $requests,
        ));

        return $chart;
    }

    /**
     * One of the two breakdowns, as a share of the period.
     *
     * @param \stdClass[] $rows The rows, busiest first.
     * @param callable $label How to name each row.
     * @return \core\chart_pie The chart.
     */
    public static function breakdown_chart(array $rows, callable $label): \core\chart_pie {
        $labels = [];
        $values = [];
        foreach ($rows as $row) {
            $labels[] = $label($row);
            $values[] = (int) $row->requests;
        }

        $chart = new \core\chart_pie();
        $chart->set_labels($labels);
        $chart->add_series(new \core\chart_series(
            get_string('usage:total:requests', 'local_airouter'),
            $values,
        ));

        return $chart;
    }

    /**
     * What each model was asked for, what it used and what it cost.
     *
     * @param \stdClass[] $rows The rows, busiest first.
     * @return \html_table The table.
     */
    public static function model_table(array $rows): \html_table {
        $table = new \html_table();
        $table->head = [
            get_string('usage:column:model', 'local_airouter'),
            get_string('usage:total:requests', 'local_airouter'),
            get_string('usage:column:prompttokens', 'local_airouter'),
            get_string('usage:column:completiontokens', 'local_airouter'),
            get_string('usage:total:cost', 'local_airouter'),
        ];
        $table->attributes['class'] = 'admintable generaltable';
        foreach ($rows as $row) {
            $table->data[] = [
                self::model_name($row),
                number_format((int) $row->requests),
                number_format((int) $row->prompttokens),
                number_format((int) $row->completiontokens),
                self::costs($row->costs),
            ];
        }

        return $table;
    }

    /**
     * How many requests failed, and for what.
     *
     * @param \stdClass[] $rows The rows, commonest first.
     * @return \html_table The table.
     */
    public static function reason_table(array $rows): \html_table {
        $table = new \html_table();
        $table->head = [
            get_string('usage:column:reason', 'local_airouter'),
            get_string('usage:total:requests', 'local_airouter'),
        ];
        $table->attributes['class'] = 'admintable generaltable';
        foreach ($rows as $row) {
            $table->data[] = [self::reason_name($row->reason), number_format((int) $row->requests)];
        }

        return $table;
    }

    /**
     * The name of the instance a row belongs to.
     *
     * @param \stdClass $row The row.
     * @return string The name.
     */
    public static function target_name(\stdClass $row): string {
        $name = (string) ($row->targetname ?? '');

        return $name === '' ? get_string('usage:notarget', 'local_airouter') : $name;
    }

    /**
     * The name of the action a row belongs to.
     *
     * A row records the action by its class basename, which is all core stores. The
     * name belongs to the action, so the action is asked for it: one defined outside
     * core names itself from its own plugin's language strings, and core_ai has
     * nothing for it. Looking the name up in core_ai alone, which is what this did at
     * first, left "transcribe a recording" showing as transcript_audio.
     *
     * Falling back to core_ai still matters for a row left behind by an action that
     * has since been uninstalled, and falling back to the basename after that means a
     * report never goes blank over a name.
     *
     * @param \stdClass $row The row.
     * @return string The name.
     */
    public static function action_name(\stdClass $row): string {
        $action = (string) ($row->actionname ?? '');
        if ($action === '') {
            return get_string('usage:unknown', 'local_airouter');
        }

        foreach (provider::get_action_list() as $class) {
            if ($class::get_basename() === $action) {
                return $class::get_name();
            }
        }

        $key = 'action_' . $action;

        return get_string_manager()->string_exists($key, 'core_ai')
            ? get_string($key, 'core_ai')
            : $action;
    }

    /**
     * The name of the model a row belongs to.
     *
     * @param \stdClass $row The row.
     * @return string The name.
     */
    public static function model_name(\stdClass $row): string {
        $model = (string) ($row->model ?? '');

        return $model === '' ? get_string('usage:nomodel', 'local_airouter') : $model;
    }

    /**
     * A failure reason, in words rather than as the code it is stored as.
     *
     * Reasons are stable short names that also travel to the placement, so an unknown one
     * is shown as it is rather than hidden.
     *
     * @param string|null $reason The reason code.
     * @return string The name.
     */
    public static function reason_name(?string $reason): string {
        $reason = (string) $reason;
        if ($reason === '') {
            return get_string('usage:unknown', 'local_airouter');
        }
        $key = 'usage:reason:' . $reason;

        return get_string_manager()->string_exists($key, 'local_airouter')
            ? get_string($key, 'local_airouter')
            : $reason;
    }

    /**
     * How much of the site's AI use reached the router at all.
     *
     * @param \stdClass|null $passthrough What core's register says.
     * @return string HTML.
     */
    public static function passthrough(?\stdClass $passthrough): string {
        if ($passthrough === null || $passthrough->share === null) {
            return \html_writer::div(get_string('usage:passthrough:none', 'local_airouter'), 'text-muted');
        }

        $share = round($passthrough->share * 100);
        $output = \html_writer::div(
            get_string('usage:passthrough:value', 'local_airouter', (object) [
                'routed' => number_format($passthrough->routed),
                'total' => number_format($passthrough->total),
                'share' => $share,
            ]),
        );

        if ($share < 100) {
            // The router can only apply rules to what reaches it, and what reaches it is
            // the actions the site has placed under it.
            $output .= \html_writer::div(
                get_string('usage:passthrough:notall', 'local_airouter')
                    . ' ' . \html_writer::link(
                        new \moodle_url('/local/airouter/managed.php'),
                        get_string('managed:heading', 'local_airouter'),
                    ),
                'text-muted',
            );
        }

        return $output;
    }
}
