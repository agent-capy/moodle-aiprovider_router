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
 * Tests for costing a request against the rates an administrator entered.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(price::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(price_book::class)]
final class price_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Record a rate.
     *
     * @param string $provider The provider component.
     * @param string $model The model, or empty for any.
     * @param float|null $prompt Per million prompt tokens.
     * @param float|null $completion Per million generated tokens.
     * @param int $timefrom When the rate took effect.
     * @param float|null $image Per image.
     * @return price The saved rate.
     */
    protected function add(
        string $provider,
        string $model,
        ?float $prompt,
        ?float $completion,
        int $timefrom = 0,
        ?float $image = null,
    ): price {
        $record = new price();
        $record->set('provider', $provider);
        $record->set('model', $model);
        $record->set('promptrate', $prompt);
        $record->set('completionrate', $completion);
        $record->set('imagerate', $image);
        $record->set('timefrom', $timefrom);

        return $record->create();
    }

    public function test_a_rate_is_read_per_million_tokens(): void {
        // Rates are entered the way providers publish them, so that a published price
        // list can be copied without doing arithmetic first.
        $record = $this->add('aiprovider_openai', 'gpt-x', 2.0, 8.0);

        $this->assertEqualsWithDelta(0.002 + 0.008, $record->cost(1000, 1000), 0.000001);
    }

    public function test_a_rate_covering_the_exact_model_wins(): void {
        global $DB;
        $this->add('aiprovider_openai', '', 1.0, 1.0);
        $this->add('aiprovider_openai', 'gpt-x', 5.0, 5.0);

        $found = (new price_book($DB))->find('aiprovider_openai', 'gpt-x', time());

        $this->assertSame('gpt-x', $found?->get('model'));
    }

    public function test_a_model_with_no_rate_of_its_own_falls_back_to_the_provider(): void {
        global $DB;
        $this->add('aiprovider_openai', '', 1.0, 1.0);

        $found = (new price_book($DB))->find('aiprovider_openai', 'something-new', time());

        $this->assertSame('', $found?->get('model'));
    }

    public function test_the_rate_in_force_at_the_time_is_the_one_used(): void {
        global $DB;
        $this->add('aiprovider_openai', 'gpt-x', 1.0, 1.0, 1000);
        $this->add('aiprovider_openai', 'gpt-x', 9.0, 9.0, 5000);
        $book = new price_book($DB);

        // A request from before the increase is costed at the old rate. Rates are
        // revised, and a report that restates last month in this month's prices is of
        // no use to anybody accounting for what was spent.
        $this->assertEquals(1.0, $book->find('aiprovider_openai', 'gpt-x', 4999)?->get('promptrate'));
        $this->assertEquals(9.0, $book->find('aiprovider_openai', 'gpt-x', 5000)?->get('promptrate'));
    }

    public function test_a_rate_that_has_not_started_yet_is_not_used(): void {
        global $DB;
        $this->add('aiprovider_openai', 'gpt-x', 9.0, 9.0, 5000);

        $this->assertNull((new price_book($DB))->find('aiprovider_openai', 'gpt-x', 4000));
    }

    public function test_an_unpriced_provider_has_no_cost_rather_than_a_cost_of_zero(): void {
        global $DB;

        // Zero would read as "this was free", which is a different and much more
        // comforting claim than "nobody has told us what this costs".
        $this->assertNull((new price_book($DB))->find('aiprovider_somewhere', 'model', time()));
    }

    public function test_a_rate_of_zero_is_free_rather_than_unknown(): void {
        // How a site says a provider costs nothing: a model somebody runs themselves,
        // where there is no bill to estimate. Leaving the rate out instead would record
        // every request as costing an unknown amount, which reads as a gap in the
        // records rather than as a fact about the provider.
        $record = $this->add('aiprovider_ollama', '', 0.0, 0.0);

        $this->assertSame(0.0, $record->cost(1000, 1000));
        $this->assertNotNull($record->cost(1000, 1000));
    }

    public function test_a_provider_wide_rate_of_zero_covers_every_model(): void {
        global $DB;
        $this->add('aiprovider_ollama', '', 0.0, 0.0);
        $book = new price_book($DB);

        // One row with the model left empty is all a free provider needs, whatever it
        // is asked to run.
        $this->assertSame(0.0, $book->find('aiprovider_ollama', 'gpt-oss:120b', 100)?->cost(500, 500));
        $this->assertSame(0.0, $book->find('aiprovider_ollama', 'llama3', 100)?->cost(500, 500));
    }

    public function test_a_rate_with_nothing_filled_in_costs_nothing_knowable(): void {
        $record = $this->add('aiprovider_openai', 'gpt-x', null, null);

        $this->assertNull($record->cost(1000, 1000));
    }

    public function test_a_half_filled_rate_costs_only_the_half_it_knows(): void {
        $record = $this->add('aiprovider_openai', 'gpt-x', 2.0, null);

        $this->assertEqualsWithDelta(0.002, $record->cost(1000, 1000), 0.000001);
    }

    public function test_images_are_costed_per_image(): void {
        // Image responses carry no token counts at all, so there is nothing else to
        // cost them by.
        $record = $this->add('aiprovider_openai', 'dall-x', null, null, 0, 0.04);

        $this->assertEqualsWithDelta(0.12, $record->cost(null, null, 3), 0.000001);
    }

    public function test_a_negative_rate_is_refused(): void {
        $record = new price();
        $record->set('provider', 'aiprovider_openai');
        $record->set('promptrate', -1.0);

        $errors = $record->validate();

        $this->assertIsArray($errors);
        $this->assertArrayHasKey('promptrate', $errors);
    }

    public function test_the_site_currency_defaults_and_can_be_set(): void {
        $this->assertSame(price_book::DEFAULT_CURRENCY, price_book::get_currency());

        set_config('currency', 'JPY', 'local_airouter');

        $this->assertSame('JPY', price_book::get_currency());
    }
}
