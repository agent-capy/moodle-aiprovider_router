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
 * Tests for what the dashboard says about figures it is not showing.
 *
 * The dashboard answers what the site spent, so it shows one payer at a time. Nothing
 * about a filtered screen looks partial, which is why it has to say so itself.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(usage_formatter::class)]
final class usage_formatter_test extends \advanced_testcase {
    /**
     * The figures for one payer, as a breakdown row.
     *
     * @param string $keysource Who paid.
     * @param int $requests How many requests they covered.
     * @return \stdClass The row.
     */
    protected function row(string $keysource, int $requests): \stdClass {
        return (object) ['keysource' => $keysource, 'requests' => $requests];
    }

    public function test_requests_paid_for_another_way_are_owned_up_to(): void {
        $rows = [
            $this->row(rule::KEYSOURCE_SITE, 4),
            $this->row(rule::KEYSOURCE_USER, 96),
        ];

        $output = usage_formatter::elsewhere($rows, rule::KEYSOURCE_SITE);

        // A site reading four requests where ninety six more happened would conclude
        // that almost nobody uses its AI.
        $this->assertStringContainsString('96', $output);
    }

    public function test_nothing_is_said_when_nothing_is_left_out(): void {
        $rows = [$this->row(rule::KEYSOURCE_SITE, 4)];

        $this->assertSame('', usage_formatter::elsewhere($rows, rule::KEYSOURCE_SITE));
    }

    public function test_nothing_is_said_when_every_payer_is_being_shown(): void {
        $rows = [
            $this->row(rule::KEYSOURCE_SITE, 4),
            $this->row(rule::KEYSOURCE_USER, 96),
        ];

        $this->assertSame('', usage_formatter::elsewhere($rows, usage_report::KEYSOURCE_ALL));
    }

    public function test_an_empty_period_leaves_nothing_out(): void {
        $this->assertSame('', usage_formatter::elsewhere([], rule::KEYSOURCE_SITE));
    }

    /**
     * A set of totals with money on it.
     *
     * @param array[] $costs The money, by provider, as the reader gives it.
     * @return \stdClass The totals.
     */
    protected function totals(array $costs): \stdClass {
        $totals = (object) [
            'requests' => 5,
            'failures' => 0,
            'calls' => 5,
            'prompttokens' => 100,
            'completiontokens' => 50,
            'costs' => $costs,
            'costedcalls' => $costs ? 5 : 0,
        ];
        $entry = count($costs) === 1 ? reset($costs) : null;
        $totals->cost = $entry === null ? null : $entry['amount'];
        $totals->currency = $entry === null ? null : $entry['currency'];

        return $totals;
    }

    public function test_a_period_paid_to_one_provider_shows_the_figure_in_its_currency(): void {
        $this->resetAfterTest();

        $out = usage_formatter::totals($this->totals(reader::money('aiprovider_sakuraaiengine', 'JPY', 1010.0)));

        // The currency the provider bills in, and the provider named beside it.
        $this->assertStringContainsString('1010 JPY', $out);
        $this->assertStringNotContainsString('USD', $out);
    }

    public function test_a_period_paid_to_two_providers_shows_both_and_adds_neither(): void {
        $this->resetAfterTest();

        $out = usage_formatter::totals($this->totals(reader::add_costs(
            reader::money('aiprovider_sakuraaiengine', 'JPY', 1000.0),
            reader::money('aiprovider_openai', 'USD', 10.0),
        )));

        // 1000 JPY and 10 USD do not add up to 1010 of anything, and two providers
        // are not added even when they bill alike: the figures sit side by side and
        // the site adds them or not, as its budget is one figure or one per provider.
        $this->assertStringContainsString('1000 JPY', $out);
        $this->assertStringContainsString('10 USD', $out);
        $this->assertStringNotContainsString('1010', $out);
    }

    public function test_a_period_that_priced_nothing_says_so_rather_than_showing_zero(): void {
        $this->resetAfterTest();

        $out = usage_formatter::totals($this->totals([]));

        $this->assertStringContainsString(get_string('usage:cost:unknown', 'local_airouter'), $out);
        $this->assertStringNotContainsString('0.0000', $out);
    }

    public function test_the_table_by_provider_names_each_provider_with_its_currency(): void {
        $this->resetAfterTest();
        $rows = [
            (object) ['targetprovider' => 'aiprovider_openai', 'requests' => 3, 'calls' => 4, 'costedcalls' => 4,
                'costs' => reader::money('aiprovider_openai', 'USD', 1.5), 'cost' => 1.5, 'currency' => 'USD'],
            (object) ['targetprovider' => 'aiprovider_sakuraaiengine', 'requests' => 2, 'calls' => 2, 'costedcalls' => 0,
                'costs' => [], 'cost' => null, 'currency' => null],
        ];

        $table = usage_formatter::provider_table($rows);

        $this->assertSame('USD', $table->data[0][1]);
        $this->assertSame('1.5 USD', $table->data[0][5]);
        $this->assertSame('-', $table->data[1][1], 'Nothing priced: no currency to name.');
        $this->assertSame(get_string('usage:cost:unknown', 'local_airouter'), $table->data[1][5]);
    }

    public function test_a_core_action_is_named_the_way_core_names_it(): void {
        $this->resetAfterTest();

        $name = usage_formatter::action_name((object) ['actionname' => 'generate_text']);

        $this->assertSame(\core_ai\aiactions\generate_text::get_name(), $name);
        $this->assertStringNotContainsString('generate_text', $name);
    }

    public function test_an_action_defined_outside_core_is_named_by_its_own_plugin(): void {
        // A row records only the class basename, and core_ai has no string for an
        // action it did not define. Asking core alone left "transcribe a recording"
        // showing in a report as transcript_audio.
        $this->resetAfterTest();
        $class = 'local_aimedia\\aiactions\\transcript_audio';
        if (!class_exists($class)) {
            $this->markTestSkipped('local_aimedia is not installed on this site.');
        }

        $name = usage_formatter::action_name((object) ['actionname' => 'transcript_audio']);

        $this->assertSame($class::get_name(), $name);
        $this->assertNotSame('transcript_audio', $name);
    }

    public function test_an_action_nobody_can_name_any_more_still_shows_something(): void {
        // A row left behind by an action whose plugin has been uninstalled.
        $this->resetAfterTest();

        $this->assertSame(
            'an_action_that_is_gone',
            usage_formatter::action_name((object) ['actionname' => 'an_action_that_is_gone']),
        );
    }

    public function test_a_row_with_no_action_at_all_says_so(): void {
        $this->resetAfterTest();

        $this->assertSame(
            get_string('usage:unknown', 'local_airouter'),
            usage_formatter::action_name((object) ['actionname' => '']),
        );
    }
}
