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
 * Tests for what the dashboard says about figures it is not showing.
 *
 * The dashboard answers what the site spent, so it shows one payer at a time. Nothing
 * about a filtered screen looks partial, which is why it has to say so itself.
 *
 * @package    aiprovider_router
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
}
