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
 * Turns the monitor's figures into the few shapes the dashboard is allowed to have.
 *
 * The shape is fixed on purpose: one time series, two breakdowns and one table. Reports
 * grow without limit if each new question is answered with a new chart, and the questions
 * a site owner actually has are how much is being used, where it goes, what it costs and
 * what is failing.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage_formatter {
    /**
     * The headline figures for the period.
     *
     * @param \stdClass $totals The totals.
     * @param string $currency The site currency.
     * @return string HTML.
     */
    public static function totals(\stdClass $totals, string $currency): string {
        $items = [
            'usage:total:requests' => number_format((int) $totals->requests),
            'usage:total:failures' => number_format((int) $totals->failures),
            'usage:total:tokens' => number_format((int) $totals->prompttokens + (int) $totals->completiontokens),
            'usage:total:cost' => self::cost($totals->cost, $currency),
        ];

        $cells = '';
        foreach ($items as $key => $value) {
            $cells .= \html_writer::div(
                \html_writer::div(get_string($key, 'aiprovider_router'), 'text-muted')
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
        if ($keysource === usage_report::KEYSOURCE_ALL) {
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
            get_string('usage:keysource:hidden', 'aiprovider_router', number_format($hidden)),
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
        $requests = (int) $totals->requests;
        $costed = (int) $totals->costedrequests;
        if ($requests === 0 || $costed === $requests) {
            return '';
        }

        $key = $costed === 0 ? 'usage:cost:none' : 'usage:cost:partial';

        return \html_writer::div(
            get_string($key, 'aiprovider_router', (object) ['costed' => $costed, 'requests' => $requests]),
            'text-muted',
        );
    }

    /**
     * A cost, with the currency it is counted in.
     *
     * @param int|float|null $cost The cost, or null when nothing priced it.
     * @param string $currency The site currency.
     * @return string The formatted cost.
     */
    public static function cost(int|float|null $cost, string $currency): string {
        if ($cost === null) {
            return get_string('usage:cost:unknown', 'aiprovider_router');
        }

        return format_float((float) $cost, 4, true, true) . ' ' . $currency;
    }

    /**
     * Requests and cost, day by day.
     *
     * Cost is on its own axis. The two are measured in different things, and a cost of a
     * few units drawn against a few hundred requests is a flat line along the bottom.
     *
     * @param array $series Rows keyed by the midnight of their day.
     * @param string $currency The site currency.
     * @return \core\chart_line The chart.
     */
    public static function daily_chart(array $series, string $currency): \core\chart_line {
        $labels = [];
        $requests = [];
        $costs = [];
        foreach ($series as $day => $row) {
            $labels[] = userdate($day, get_string('strftimedateshort', 'langconfig'));
            $requests[] = (int) $row->requests;
            $costs[] = $row->cost === null ? 0 : round((float) $row->cost, 4);
        }

        $chart = new \core\chart_line();
        $chart->set_labels($labels);
        $chart->add_series(new \core\chart_series(
            get_string('usage:total:requests', 'aiprovider_router'),
            $requests,
        ));

        $cost = new \core\chart_series(
            get_string('usage:total:cost', 'aiprovider_router') . ' (' . $currency . ')',
            $costs,
        );
        $cost->set_yaxis(1);
        $chart->add_series($cost);
        // The second axis cannot be created before the first one exists, so the axis the
        // request count already uses is asked for by name before the cost axis is added.
        $chart->get_yaxis(0, true);
        $chart->get_yaxis(1, true)->set_position(\core\chart_axis::POS_RIGHT);

        return $chart;
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
            get_string('usage:total:requests', 'aiprovider_router'),
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
            get_string('usage:total:requests', 'aiprovider_router'),
            $values,
        ));

        return $chart;
    }

    /**
     * What each model was asked for, what it used and what it cost.
     *
     * @param \stdClass[] $rows The rows, busiest first.
     * @param string $currency The site currency.
     * @return \html_table The table.
     */
    public static function model_table(array $rows, string $currency): \html_table {
        $table = new \html_table();
        $table->head = [
            get_string('usage:column:model', 'aiprovider_router'),
            get_string('usage:total:requests', 'aiprovider_router'),
            get_string('usage:column:prompttokens', 'aiprovider_router'),
            get_string('usage:column:completiontokens', 'aiprovider_router'),
            get_string('usage:total:cost', 'aiprovider_router'),
        ];
        $table->attributes['class'] = 'admintable generaltable';
        foreach ($rows as $row) {
            $table->data[] = [
                self::model_name($row),
                number_format((int) $row->requests),
                number_format((int) $row->prompttokens),
                number_format((int) $row->completiontokens),
                self::cost($row->cost, $currency),
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
            get_string('usage:column:reason', 'aiprovider_router'),
            get_string('usage:total:requests', 'aiprovider_router'),
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

        return $name === '' ? get_string('usage:notarget', 'aiprovider_router') : $name;
    }

    /**
     * The name of the action a row belongs to.
     *
     * Actions are named by core, so the name core uses is the one shown.
     *
     * @param \stdClass $row The row.
     * @return string The name.
     */
    public static function action_name(\stdClass $row): string {
        $action = (string) ($row->actionname ?? '');
        if ($action === '') {
            return get_string('usage:unknown', 'aiprovider_router');
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

        return $model === '' ? get_string('usage:nomodel', 'aiprovider_router') : $model;
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
            return get_string('usage:unknown', 'aiprovider_router');
        }
        $key = 'usage:reason:' . $reason;

        return get_string_manager()->string_exists($key, 'aiprovider_router')
            ? get_string($key, 'aiprovider_router')
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
            return \html_writer::div(get_string('usage:passthrough:none', 'aiprovider_router'), 'text-muted');
        }

        $share = round($passthrough->share * 100);
        $output = \html_writer::div(
            get_string('usage:passthrough:value', 'aiprovider_router', (object) [
                'routed' => number_format($passthrough->routed),
                'total' => number_format($passthrough->total),
                'share' => $share,
            ]),
        );

        if ($share < 100) {
            // The router can only apply rules to what reaches it, and what reaches it is
            // decided by the site's provider order rather than by anything here.
            $output .= \html_writer::div(
                get_string('usage:passthrough:notall', 'aiprovider_router')
                    . ' ' . \html_writer::link(
                        new \moodle_url('/ai/provider/router/order.php'),
                        get_string('order:heading', 'aiprovider_router'),
                    ),
                'text-muted',
            );
        }

        return $output;
    }
}
