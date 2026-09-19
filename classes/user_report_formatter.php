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
 * Turns the person level figures into tables, and into rows for a spreadsheet.
 *
 * Charts are deliberately absent. This report is read to answer a question about named
 * people, usually so that it can be quoted somewhere else, and a table is what survives
 * being quoted. The dashboard is where the shape of the site's usage is looked at.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_report_formatter {
    /**
     * Everybody who used the AI in the period.
     *
     * @param \stdClass[] $people Rows from the report.
     * @param string[] $names User names keyed by id.
     * @param string $currency The site currency.
     * @param \moodle_url $url The page each person's name links back into.
     * @return \html_table The table.
     */
    public static function people(
        array $people,
        array $names,
        string $currency,
        \moodle_url $url,
    ): \html_table {
        $table = new \html_table();
        $table->head = [
            get_string('report:person', 'aiprovider_router'),
            get_string('usage:total:requests', 'aiprovider_router'),
            get_string('usage:total:tokens', 'aiprovider_router'),
            get_string('report:sitecost', 'aiprovider_router'),
            get_string('report:broughtcost', 'aiprovider_router'),
        ];
        $table->attributes['class'] = 'admintable generaltable';

        foreach ($people as $person) {
            $userid = (int) $person->userid;
            $table->data[] = [
                self::person($userid, $names, $url),
                number_format((int) $person->requests),
                number_format((int) $person->prompttokens + (int) $person->completiontokens),
                usage_formatter::cost($person->sitecost, $currency),
                self::brought($person, $currency),
            ];
        }

        return $table;
    }

    /**
     * One person's name, or an honest answer when there is not one.
     *
     * @param int $userid The person.
     * @param string[] $names User names keyed by id.
     * @param \moodle_url $url The page their name links into.
     * @return string HTML.
     */
    public static function person(int $userid, array $names, \moodle_url $url): string {
        if ($userid <= 0) {
            // Summaries written before this plugin recorded who made the requests. They
            // are not user zero and they are not nobody's usage; they are usage whose
            // owner was never kept, and saying so is the only truthful thing available.
            return \html_writer::span(get_string('report:unattributed', 'aiprovider_router'), 'text-muted');
        }
        if (!isset($names[$userid])) {
            // The account has been deleted. The figures outlive it, which is the point
            // of keeping them, but there is no name left to put on them.
            return \html_writer::span(
                get_string('report:goneuser', 'aiprovider_router', $userid),
                'text-muted',
            );
        }

        return \html_writer::link(new \moodle_url($url, ['userid' => $userid]), s($names[$userid]));
    }

    /**
     * What somebody spent on their own key, with the warning that belongs beside it.
     *
     * @param \stdClass $person The person's figures.
     * @param string $currency The site currency.
     * @return string HTML.
     */
    protected static function brought(\stdClass $person, string $currency): string {
        if ((int) $person->broughtrequests === 0) {
            return \html_writer::span('-', 'text-muted');
        }

        return usage_formatter::cost($person->broughtcost, $currency)
            . \html_writer::div(
                get_string('report:broughtrequests', 'aiprovider_router', number_format($person->broughtrequests)),
                'text-muted small',
            );
    }

    /**
     * One person's usage, day by day.
     *
     * @param \stdClass[] $days Rows from the report.
     * @param string $currency The site currency.
     * @return \html_table The table.
     */
    public static function days(array $days, string $currency): \html_table {
        $table = new \html_table();
        $table->head = [
            get_string('report:day', 'aiprovider_router'),
            get_string('report:column:action', 'aiprovider_router'),
            get_string('report:column:target', 'aiprovider_router'),
            get_string('usage:column:model', 'aiprovider_router'),
            get_string('usage:keysource', 'aiprovider_router'),
            get_string('usage:total:requests', 'aiprovider_router'),
            get_string('usage:total:tokens', 'aiprovider_router'),
            get_string('usage:total:cost', 'aiprovider_router'),
        ];
        $table->attributes['class'] = 'admintable generaltable';

        foreach ($days as $day) {
            $table->data[] = [
                userdate((int) $day->daystart, get_string('strftimedateshort', 'langconfig')),
                usage_formatter::action_name($day),
                usage_formatter::target_name($day),
                usage_formatter::model_name($day),
                self::keysource((string) $day->keysource),
                number_format((int) $day->requests),
                number_format((int) $day->prompttokens + (int) $day->completiontokens),
                usage_formatter::cost($day->cost, $currency),
            ];
        }

        return $table;
    }

    /**
     * One person's individual requests.
     *
     * @param \stdClass[] $requests Rows from the report.
     * @param string $currency The site currency.
     * @return \html_table The table.
     */
    public static function requests(array $requests, string $currency): \html_table {
        $table = new \html_table();
        $table->head = [
            get_string('report:when', 'aiprovider_router'),
            get_string('report:column:action', 'aiprovider_router'),
            get_string('report:placement', 'aiprovider_router'),
            get_string('report:column:target', 'aiprovider_router'),
            get_string('usage:column:model', 'aiprovider_router'),
            get_string('usage:keysource', 'aiprovider_router'),
            get_string('usage:total:tokens', 'aiprovider_router'),
            get_string('usage:total:cost', 'aiprovider_router'),
        ];
        $table->attributes['class'] = 'admintable generaltable';

        foreach ($requests as $request) {
            $row = new \html_table_row();
            $row->cells = [
                userdate((int) $request->timecreated),
                usage_formatter::action_name($request),
                self::placement($request),
                usage_formatter::target_name($request),
                usage_formatter::model_name($request),
                self::keysource((string) $request->keysource),
                number_format((int) $request->prompttokens + (int) $request->completiontokens),
                usage_formatter::cost($request->cost, $currency),
            ];
            if (!$request->success) {
                $row->attributes['class'] = 'dimmed_text';
            }
            $table->data[] = $row;
        }

        return $table;
    }

    /**
     * Who holds a brought key, and what it has been used for.
     *
     * @param \stdClass[] $holders Rows from the report.
     * @param \stdClass[] $usage Usage keyed by key id.
     * @param string[] $names User names keyed by id.
     * @param string[] $courses Course names keyed by id.
     * @param string[] $targets Target names keyed by id.
     * @param string $currency The site currency.
     * @return \html_table The table.
     */
    public static function holders(
        array $holders,
        array $usage,
        array $names,
        array $courses,
        array $targets,
        string $currency,
    ): \html_table {
        $table = new \html_table();
        $table->head = [
            get_string('report:holder', 'aiprovider_router'),
            get_string('keys:target', 'aiprovider_router'),
            get_string('report:registered', 'aiprovider_router'),
            get_string('keys:tested', 'aiprovider_router'),
            get_string('usage:total:requests', 'aiprovider_router'),
            get_string('report:broughtcost', 'aiprovider_router'),
        ];
        $table->attributes['class'] = 'admintable generaltable';

        foreach ($holders as $holder) {
            $used = $usage[(int) $holder->id] ?? null;
            $table->data[] = [
                self::holder($holder, $names, $courses),
                $targets[(int) $holder->targetid]
                    ?? get_string('keys:target:gone', 'aiprovider_router', $holder->targetid),
                userdate((int) $holder->timecreated, get_string('strftimedateshort', 'langconfig')),
                self::verified($holder),
                $used === null ? '0' : number_format((int) $used->requests),
                $used === null ? '-' : usage_formatter::cost($used->cost, $currency),
            ];
        }

        return $table;
    }

    /**
     * Whose key it is.
     *
     * @param \stdClass $holder The key record.
     * @param string[] $names User names keyed by id.
     * @param string[] $courses Course names keyed by id.
     * @return string HTML.
     */
    protected static function holder(\stdClass $holder, array $names, array $courses): string {
        $id = (int) $holder->scopeid;
        if ((string) $holder->scope === key::SCOPE_COURSE) {
            return get_string(
                'report:coursekey',
                'aiprovider_router',
                s($courses[$id] ?? get_string('report:gonecourse', 'aiprovider_router', $id)),
            );
        }

        return s($names[$id] ?? get_string('report:goneuser', 'aiprovider_router', $id));
    }

    /**
     * What the provider said the last time a key was put to it.
     *
     * @param \stdClass $holder The key record.
     * @return string Plain text.
     */
    protected static function verified(\stdClass $holder): string {
        if (!$holder->timeverified) {
            return get_string('keys:tested:never', 'aiprovider_router');
        }

        return get_string(
            'keys:tested:' . ($holder->verifystatus ?: key::VERIFY_FAILED),
            'aiprovider_router',
        );
    }

    /**
     * Who paid, in words.
     *
     * @param string $keysource One of the key sources.
     * @return string Plain text.
     */
    public static function keysource(string $keysource): string {
        $names = rule::get_keysources();

        return in_array($keysource, $names, true)
            ? get_string('keysource:' . $keysource, 'aiprovider_router')
            : get_string('usage:unknown', 'aiprovider_router');
    }

    /**
     * Which part of Moodle raised a request.
     *
     * @param \stdClass $request The request row.
     * @return string Plain text.
     */
    protected static function placement(\stdClass $request): string {
        $placement = (string) ($request->placement ?? '');
        if ($placement === '') {
            return get_string('usage:unknown', 'aiprovider_router');
        }

        $manager = \core_plugin_manager::instance();
        $plugin = $manager->get_plugin_info($placement);

        return $plugin === null ? $placement : $plugin->displayname;
    }

    /**
     * The people report as rows for a spreadsheet.
     *
     * Exported rather than linked, because this is the form the figures leave in: a
     * report somebody has been asked to produce, which will be read outside Moodle.
     *
     * @param \stdClass[] $people Rows from the report.
     * @param string[] $names User names keyed by id.
     * @param string $currency The site currency.
     * @return array[] Rows of plain values.
     */
    public static function people_rows(array $people, array $names, string $currency): array {
        $rows = [];
        foreach ($people as $person) {
            $userid = (int) $person->userid;
            $rows[] = [
                $userid > 0 ? $userid : '',
                $names[$userid] ?? get_string('report:unattributed', 'aiprovider_router'),
                (int) $person->requests,
                (int) $person->prompttokens,
                (int) $person->completiontokens,
                $person->sitecost === null ? '' : (float) $person->sitecost,
                $person->broughtcost === null ? '' : (float) $person->broughtcost,
                (int) $person->broughtrequests,
                $currency,
            ];
        }

        return $rows;
    }

    /**
     * The column headings of the exported file.
     *
     * @return string[] The headings.
     */
    public static function export_columns(): array {
        return [
            get_string('report:column:userid', 'aiprovider_router'),
            get_string('report:person', 'aiprovider_router'),
            get_string('usage:total:requests', 'aiprovider_router'),
            get_string('usage:column:prompttokens', 'aiprovider_router'),
            get_string('usage:column:completiontokens', 'aiprovider_router'),
            get_string('report:sitecost', 'aiprovider_router'),
            get_string('report:broughtcost', 'aiprovider_router'),
            get_string('report:column:broughtrequests', 'aiprovider_router'),
            get_string('report:column:currency', 'aiprovider_router'),
        ];
    }
}
