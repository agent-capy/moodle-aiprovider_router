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

/**
 * Tests for sizing a prompt in tokens.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(token_estimator::class)]
final class token_estimator_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_english_counts_against_the_wider_ratio(): void {
        $estimator = new token_estimator(cjkratio: 1.0, otherratio: 4.0);

        // Sixteen characters at four characters per token.
        $this->assertSame(4, $estimator->estimate('abcdefghijklmnop'));
    }

    public function test_japanese_counts_against_the_narrower_ratio(): void {
        $estimator = new token_estimator(cjkratio: 1.0, otherratio: 4.0);

        // Nine characters at one character per token.
        $this->assertSame(9, $estimator->estimate('日本語のプロンプト'));
    }

    public function test_a_mixed_prompt_is_counted_by_character_not_by_document(): void {
        $estimator = new token_estimator(cjkratio: 1.0, otherratio: 4.0);

        // Deciding the prompt is "Japanese" and applying one ratio to all of it would
        // overcount the English half by four to one.
        $mixed = '日本語' . 'abcdefgh';

        $this->assertSame(5, $estimator->estimate($mixed));
    }

    public function test_an_empty_prompt_is_worth_nothing(): void {
        $estimator = new token_estimator();

        $this->assertSame(0, $estimator->estimate(''));
        $this->assertSame(0, $estimator->estimate("  \n "));
    }

    public function test_the_ratios_come_from_the_site_settings(): void {
        set_config('tokenratiocjk', 2.0, 'local_airouter');
        set_config('tokenratioother', 8.0, 'local_airouter');
        $estimator = new token_estimator();

        $this->assertSame(2.0, $estimator->get_cjk_ratio());
        $this->assertSame(8.0, $estimator->get_other_ratio());
        $this->assertSame(2, $estimator->estimate('abcdefghijklmnop'));
    }

    public function test_a_nonsense_ratio_falls_back_to_the_shipped_one(): void {
        // Dividing by this would take the request down, and an estimate is never worth
        // that. The shipped value is used and the request goes on.
        set_config('tokenratiocjk', 0, 'local_airouter');
        set_config('tokenratioother', -3, 'local_airouter');
        $estimator = new token_estimator();

        $this->assertSame(token_estimator::DEFAULT_CJK_RATIO, $estimator->get_cjk_ratio());
        $this->assertSame(token_estimator::DEFAULT_OTHER_RATIO, $estimator->get_other_ratio());
    }

    public function test_the_counts_behind_an_estimate_can_be_shown(): void {
        $estimator = new token_estimator();

        // The administrator is shown where a number came from, because the number is a
        // guess and presenting it on its own would suggest otherwise.
        $this->assertSame(
            ['cjk' => 3, 'other' => 8, 'characters' => 11],
            $estimator->describe('日本語abcdefgh'),
        );
    }
}
