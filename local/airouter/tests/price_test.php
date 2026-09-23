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

use local_airouter\record\generation;
use local_airouter\record\usage_recorder;

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
        string $currency = 'USD',
    ): price {
        $record = new price();
        $record->set('provider', $provider);
        $record->set('model', $model);
        $record->set('currency', $currency);
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

    public function test_a_rate_without_a_currency_is_refused(): void {
        $record = new price();
        $record->set('provider', 'aiprovider_openai');
        $record->set('promptrate', 1.0);

        $errors = $record->validate();

        // A cost in no currency cannot be added to anything or weighed against anything.
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('currency', $errors);
    }

    public function test_a_providers_currency_is_the_one_on_its_rates(): void {
        global $DB;
        $book = new price_book($DB);
        $this->assertNull($book->currency_of('aiprovider_openai'), 'No rates, so no currency yet.');

        $this->add('aiprovider_openai', '', 1.0, 2.0);
        $this->add('aiprovider_openai', 'gpt-4o', 3.0, 4.0);
        $this->add('aiprovider_sakuraaiengine', '', 100.0, 200.0, currency: 'JPY');

        // One provider bills in dollars and another in yen, and neither is the site's.
        $this->assertSame('USD', $book->currency_of('aiprovider_openai'));
        $this->assertSame('JPY', $book->currency_of('aiprovider_sakuraaiengine'));
        $this->assertSame(
            ['aiprovider_openai' => 'USD', 'aiprovider_sakuraaiengine' => 'JPY'],
            $book->get_provider_currencies(),
        );
        $this->assertSame(['JPY', 'USD'], $book->get_currencies());
    }

    public function test_the_rate_found_says_what_currency_it_is_in(): void {
        global $DB;
        $this->add('aiprovider_sakuraaiengine', '', 100.0, 200.0, currency: 'JPY');

        $found = (new price_book($DB))->find('aiprovider_sakuraaiengine', 'some-model', time());

        $this->assertNotNull($found);
        $this->assertSame('JPY', $found->get('currency'));
    }

    public function test_correcting_a_providers_currency_changes_all_of_its_rates_and_no_others(): void {
        global $DB;
        $book = new price_book($DB);
        $this->add('aiprovider_openai', '', 1.0, 2.0);
        $this->add('aiprovider_openai', 'gpt-4o', 3.0, 4.0);
        $this->add('aiprovider_sakuraaiengine', '', 100.0, 200.0, currency: 'JPY');

        // A provider bills in one currency, so the currency is changed for the provider.
        $changed = $book->recost_provider('aiprovider_openai', 'EUR');

        $this->assertSame(['rates' => 2, 'attempts' => 0, 'summaries' => 0], $changed);
        $this->assertSame('EUR', $book->currency_of('aiprovider_openai'));
        $this->assertSame(2, $DB->count_records(price::TABLE, ['provider' => 'aiprovider_openai', 'currency' => 'EUR']));
        $this->assertSame('JPY', $book->currency_of('aiprovider_sakuraaiengine'), 'The other provider is untouched.');
    }

    public function test_a_currency_is_stored_the_way_codes_are_written(): void {
        $this->assertSame('JPY', price::normalise_currency(' jpy '));
    }

    public function test_correcting_a_providers_currency_works_its_recorded_costs_out_again(): void {
        global $DB;
        $book = new price_book($DB);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        $now = time();

        // A provisional entry: the yen provider's rates typed in as dollars, at a
        // number that was a guess, and a request already costed under it. Another
        // provider, entered right, with a request of its own.
        $provisional = $this->add('aiprovider_sakuraaiengine', '', 1.0, 2.0);
        $this->add('aiprovider_openai', '', 5.0, 10.0);
        $request = $generator->create_request(['timestarted' => $now - 100, 'timeended' => $now - 90]);
        $generator->create_attempt([
            'requestid' => $request->id, 'targetprovider' => 'aiprovider_sakuraaiengine', 'model' => 'm',
            'prompttokens' => 1000000, 'completiontokens' => 1000000, 'cost' => 3.0, 'currency' => 'USD',
            'timestarted' => $now - 99, 'timeended' => $now - 90,
        ]);
        $generator->create_attempt([
            'requestid' => $request->id, 'seq' => 2, 'targetprovider' => 'aiprovider_openai', 'model' => 'm',
            'prompttokens' => 1000000, 'completiontokens' => 0, 'cost' => 5.0, 'currency' => 'USD',
            'timestarted' => $now - 89, 'timeended' => $now - 80,
        ]);
        // A day already summarised under the provisional entry, and a day of the same
        // key that was somehow already in yen, which the correction has to fold together.
        $day = \local_airouter\record\summariser::day_of($now - 5 * DAYSECS);
        $summary = ['daystart' => $day, 'userid' => 5, 'courseid' => 0, 'actionname' => 'generate_text',
            'targetprovider' => 'aiprovider_sakuraaiengine', 'targetid' => 1, 'model' => '-', 'keysource' => 'site',
            'calls' => 1, 'knowncalls' => 1, 'prompttokens' => 1000000, 'completiontokens' => 0, 'images' => 0,
            'costedcalls' => 1];
        $DB->insert_record(\local_airouter\record\summariser::TABLE, (object) ($summary + ['currency' => 'USD', 'cost' => 1.0]));
        $DB->insert_record(\local_airouter\record\summariser::TABLE, (object) ($summary + ['currency' => 'JPY', 'cost' => 100.0]));
        $DB->insert_record(\local_airouter\record\summariser::TABLE, (object) ([
            'targetprovider' => 'aiprovider_openai', 'currency' => 'USD', 'cost' => 5.0,
        ] + $summary));

        // The correction: the rates are really yen, and a hundred times the guess.
        $provisional->set('currency', 'JPY');
        $provisional->set('promptrate', 100.0);
        $provisional->set('completionrate', 200.0);
        $provisional->update();
        $changed = $book->recost_provider('aiprovider_sakuraaiengine', 'JPY');

        $this->assertSame(1, $changed['rates']);
        $this->assertSame(1, $changed['attempts']);
        $this->assertSame(2, $changed['summaries']);
        // The attempt: worked out again from what it used, at the corrected rate, in yen.
        $attempts = array_values($DB->get_records(\local_airouter\record\usage_recorder::ATTEMPT_TABLE, null, 'seq ASC'));
        $this->assertEqualsWithDelta(300.0, (float) $attempts[0]->cost, 0.000001);
        $this->assertSame('JPY', $attempts[0]->currency);
        // The other provider's attempt: exactly as it was.
        $this->assertEqualsWithDelta(5.0, (float) $attempts[1]->cost, 0.000001);
        $this->assertSame('USD', $attempts[1]->currency);
        // The summary: one yen row for the day, the two folded together and priced
        // from the day's tokens; the other provider's row untouched.
        $rows = $DB->get_records(\local_airouter\record\summariser::TABLE, ['targetprovider' => 'aiprovider_sakuraaiengine']);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('JPY', $row->currency);
        $this->assertSame(2, (int) $row->calls);
        $this->assertSame(2, (int) $row->costedcalls);
        $this->assertEqualsWithDelta(200.0, (float) $row->cost, 0.000001, 'Two million prompt tokens at 100 per million.');
        $other = $DB->get_record(\local_airouter\record\summariser::TABLE, ['targetprovider' => 'aiprovider_openai']);
        $this->assertEqualsWithDelta(5.0, (float) $other->cost, 0.000001);
        $this->assertSame('USD', $other->currency);
    }

    public function test_a_provider_whose_rates_do_not_cover_a_call_leaves_it_unpriced_when_corrected(): void {
        global $DB;
        $book = new price_book($DB);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        $now = time();
        // The rate covers one model only; the call was to another.
        $this->add('aiprovider_sakuraaiengine', 'covered', 1.0, 2.0);
        $request = $generator->create_request(['timestarted' => $now - 100, 'timeended' => $now - 90]);
        $generator->create_attempt([
            'requestid' => $request->id, 'targetprovider' => 'aiprovider_sakuraaiengine', 'model' => 'other',
            'prompttokens' => 10, 'completiontokens' => 10, 'cost' => 3.0, 'currency' => 'USD',
            'timestarted' => $now - 99, 'timeended' => $now - 90,
        ]);

        $book->recost_provider('aiprovider_sakuraaiengine', 'JPY');

        // Not zero, and not the old figure either: nothing prices it now, so it is not priced.
        $attempt = $DB->get_record(\local_airouter\record\usage_recorder::ATTEMPT_TABLE, ['model' => 'other']);
        $this->assertNull($attempt->cost);
        $this->assertNull($attempt->currency);
    }


    public function test_a_correction_that_fails_leaves_the_rate_and_the_record_as_they_were(): void {
        global $DB;
        // A real rollback is what is being tested, and inside the transaction PHPUnit
        // wraps a test in on PostgreSQL a nested rollback undoes nothing until the end.
        $this->preventResetByRollback();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        $now = time();
        $rate = $this->add('aiprovider_sakuraaiengine', '', 1.0, 2.0);
        $request = $generator->create_request(['timestarted' => $now - 100, 'timeended' => $now - 90]);
        $generator->create_attempt([
            'requestid' => $request->id, 'targetprovider' => 'aiprovider_sakuraaiengine', 'model' => 'm',
            'prompttokens' => 1000000, 'completiontokens' => 0, 'cost' => 1.0, 'currency' => 'USD',
            'timestarted' => $now - 99, 'timeended' => $now - 90,
        ]);
        // A book whose recalculation breaks half way, once.
        $book = new class ($DB) extends price_book {
            /** @var bool Whether to break the next recalculation. */
            public bool $break = true;

            #[\Override]
            protected function recost_summaries(string $provider): int {
                if ($this->break) {
                    $this->break = false;
                    throw new \RuntimeException('the summary could not be read');
                }

                return parent::recost_summaries($provider);
            }
        };

        // The rate is being corrected to yen, in one operation with the recalculation.
        $rate->set('currency', 'JPY');
        $rate->set('promptrate', 100.0);
        try {
            $book->save_rate($rate, false);
            $this->fail('The failure should have surfaced.');
        } catch (\RuntimeException $e) {
            $this->assertSame('the summary could not be read', $e->getMessage());
        }

        // Nothing of it was saved: not the rate, not the relabelling, not the costs.
        $stored = price::get_record(['id' => $rate->get('id')]);
        $this->assertSame('USD', $stored->get('currency'));
        $this->assertEquals(1.0, $stored->get('promptrate'));
        $attempt = $DB->get_record(\local_airouter\record\usage_recorder::ATTEMPT_TABLE, ['model' => 'm']);
        $this->assertSame('USD', $attempt->currency);
        $this->assertEqualsWithDelta(1.0, (float) $attempt->cost, 0.000001);

        // Sent again once the trouble has passed, the same operation corrects everything.
        $changed = $book->save_rate($rate, false);
        $this->assertTrue($changed['recosted']);
        $this->assertSame('JPY', price::get_record(['id' => $rate->get('id')])->get('currency'));
        $attempt = $DB->get_record(\local_airouter\record\usage_recorder::ATTEMPT_TABLE, ['model' => 'm']);
        $this->assertSame('JPY', $attempt->currency);
        $this->assertEqualsWithDelta(100.0, (float) $attempt->cost, 0.000001);
    }

    public function test_saving_a_rate_corrects_a_record_that_disagrees_with_the_rates(): void {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        $now = time();
        // The rates say yen; a call was somehow recorded in dollars, as one that ended
        // during a correction can be. Saving the rate again, unchanged, puts it right.
        $rate = $this->add('aiprovider_sakuraaiengine', '', 100.0, 200.0, currency: 'JPY');
        $request = $generator->create_request(['timestarted' => $now - 100, 'timeended' => $now - 90]);
        $generator->create_attempt([
            'requestid' => $request->id, 'targetprovider' => 'aiprovider_sakuraaiengine', 'model' => 'm',
            'prompttokens' => 1000000, 'completiontokens' => 0, 'cost' => 1.0, 'currency' => 'USD',
            'timestarted' => $now - 99, 'timeended' => $now - 90,
        ]);
        $book = new price_book($DB);
        $this->assertTrue($book->has_costs_in_another_currency('aiprovider_sakuraaiengine', 'JPY'));

        $changed = $book->save_rate($rate, false);

        $this->assertTrue($changed['recosted']);
        $attempt = $DB->get_record(\local_airouter\record\usage_recorder::ATTEMPT_TABLE, ['model' => 'm']);
        $this->assertSame('JPY', $attempt->currency);
        $this->assertEqualsWithDelta(100.0, (float) $attempt->cost, 0.000001);
        $this->assertFalse($book->has_costs_in_another_currency('aiprovider_sakuraaiengine', 'JPY'));
        // And a rate saved into a record that agrees with it recalculates nothing.
        $this->assertFalse($book->save_rate($rate, false)['recosted']);
    }

    public function test_a_correction_does_not_make_an_unpriced_call_free(): void {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        $now = time();
        // A rate for images alone, and a call that used tokens and produced no image:
        // the rate exists and cannot price the call, in the detail and in the summary.
        $this->add('aiprovider_sakuraaiengine', '', null, null, 0, 2.0);
        foreach ([$now - 3 * DAYSECS, $now - 100] as $when) {
            $request = $generator->create_request(['timestarted' => $when - 10, 'timeended' => $when]);
            $generator->create_attempt([
                'requestid' => $request->id, 'targetprovider' => 'aiprovider_sakuraaiengine', 'model' => 'm',
                'prompttokens' => 1000, 'completiontokens' => 0, 'images' => 0, 'cost' => null, 'currency' => null,
                'timestarted' => $when - 8, 'timeended' => $when,
            ]);
        }
        (new \local_airouter\record\summariser($DB))->run($now);
        $table = \local_airouter\record\summariser::TABLE;
        $this->assertGreaterThan(0, $DB->count_records($table, ['targetprovider' => 'aiprovider_sakuraaiengine']));

        (new price_book($DB))->recost_provider('aiprovider_sakuraaiengine', 'JPY');

        $spend = (new \local_airouter\record\ledger($DB, false))
            ->measure(\local_airouter\record\ledger::SCOPE_SITE, 0, $now - 7 * DAYSECS, $now);
        $this->assertSame(2, $spend->get_calls_to('aiprovider_sakuraaiengine'));
        $this->assertSame(0, $spend->get_costed_calls('aiprovider_sakuraaiengine'), 'What cannot be priced is not free.');
        $this->assertFalse($spend->is_known(\local_airouter\record\ledger::METRIC_COST, 'aiprovider_sakuraaiengine'));
        foreach ($DB->get_records($table, ['targetprovider' => 'aiprovider_sakuraaiengine']) as $summary) {
            $this->assertSame('-', $summary->currency);
            $this->assertSame(0, (int) $summary->costedcalls);
        }
    }
    /**
     * Run something on a second connection, as another process would.
     *
     * @param \Closure $operation Given the other connection.
     * @return mixed What it returned.
     */
    private function separately(\Closure $operation): mixed {
        global $DB, $CFG;
        $original = $DB;
        $other = \moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary);
        $other->connect($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->prefix, $CFG->dboptions);
        try {
            $DB = $other;

            return $operation($other);
        } finally {
            $DB = $original;
            $other->dispose();
        }
    }

    public function test_a_correction_moves_the_record_generation(): void {
        // R8-06. A reader in the middle of reading must be able to tell that the
        // record it is reading has been priced again underneath it.
        global $DB;
        $this->add('aiprovider_openai', '', 1.0, 2.0);
        $before = generation::get($DB);

        (new price_book($DB))->recost_provider('aiprovider_openai', 'JPY');

        $this->assertGreaterThan($before, generation::get($DB));
    }

    public function test_an_ending_that_arrives_unpriced_during_a_correction_is_priced_by_it(): void {
        // R8-05. The correction holds the record lock for its run; an ending that
        // cannot take it is written unpriced, and the correction, still holding the
        // lock once it has committed, prices it at the rates as they now are.
        global $DB;
        $this->preventResetByRollback();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        $now = time();
        $this->add('aiprovider_openai', '', 1.0, 0.0);
        $request = $generator->create_request(['timestarted' => $now - 100, 'timeended' => null, 'state' => 'open']);
        $attempt = $generator->create_attempt([
            'requestid' => $request->id, 'targetprovider' => 'aiprovider_openai', 'model' => 'm',
            'state' => \local_airouter\record\attempt_state::STARTED, 'usageknown' => 0,
            'prompttokens' => null, 'completiontokens' => null, 'cost' => null, 'currency' => null, 'timeended' => null,
        ]);
        // A book that, once it has priced the attempts again and before it commits,
        // lets another connection end the attempt. That ending waits for the lock
        // this book holds, gives up, and writes without a price.
        $book = new class ($DB) extends price_book {
            /** @var \Closure|null What to do before pricing the summaries. */
            public ?\Closure $pause = null;

            #[\Override]
            protected function recost_summaries(string $provider): int {
                if ($this->pause !== null) {
                    $pause = $this->pause;
                    $this->pause = null;
                    $pause();
                }

                return parent::recost_summaries($provider);
            }
        };
        $book->pause = fn() => $this->separately(fn($db) => (new usage_recorder($db))->end_attempt(
            (int) $attempt->id,
            \local_airouter\record\attempt_state::SUCCEEDED,
            new \local_airouter\record\usage(1000000, 0),
            'm',
            null,
            'aiprovider_openai',
        ));

        $book->recost_provider('aiprovider_openai', 'JPY');

        $this->assertDebuggingCalled(null, DEBUG_NORMAL);
        $row = $DB->get_record(usage_recorder::ATTEMPT_TABLE, ['id' => $attempt->id], '*', MUST_EXIST);
        $this->assertSame(\local_airouter\record\attempt_state::SUCCEEDED, $row->state);
        $this->assertSame(0, (int) $row->unpriced);
        $this->assertSame('JPY', $row->currency, 'Priced by the correction, in the corrected currency.');
        $this->assertEqualsWithDelta(1.0, (float) $row->cost, 0.000001);
    }

    public function test_a_rate_save_decides_and_saves_under_the_lock_a_correction_needs(): void {
        // R9-02. A rate saved as it was, because no correction was due, was decided
        // on and saved outside the lock. A correction that finished in between was
        // then half undone: this rate back in the old currency, the provider's other
        // rates and its recorded costs in the new one. Now the decision and the save
        // hold the lock a correction has to take, so no correction lands in between.
        global $DB;
        $this->preventResetByRollback();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        $now = time();
        $rate = $this->add('aiprovider_openai', '', 1.0, 0.0);
        $this->add('aiprovider_openai', 'other-model', 1.0, 0.0);
        $request = $generator->create_request(['timestarted' => $now - 100, 'timeended' => $now - 90]);
        $attempt = $generator->create_attempt([
            'requestid' => $request->id, 'targetprovider' => 'aiprovider_openai', 'model' => '',
            'prompttokens' => 9000000, 'completiontokens' => 0, 'cost' => 9.0, 'currency' => 'USD',
            'timestarted' => $now - 99, 'timeended' => $now - 90,
        ]);
        $book = new class ($DB) extends price_book {
            /** @var \Closure|null What to do once, while deciding. */
            public ?\Closure $pause = null;

            #[\Override]
            public function has_costs_in_another_currency(string $provider, string $currency): bool {
                $other = parent::has_costs_in_another_currency($provider, $currency);
                if ($this->pause !== null) {
                    $pause = $this->pause;
                    $this->pause = null;
                    $pause();
                }

                return $other;
            }
        };
        $free = null;
        $book->pause = function () use (&$free): void {
            $free = $this->separately(function (): bool {
                $lock = usage_recorder::lock_factory()->get_lock(usage_recorder::LOCK, 0);
                if ($lock === false) {
                    return false;
                }
                $lock->release();

                return true;
            });
        };
        $rate->set('promptrate', 2.0);

        $this->assertFalse($book->save_rate($rate, false)['recosted']);
        $this->assertFalse($free, 'A correction cannot begin while the save is deciding.');

        // The correction then runs, and a screen that still shows dollars saves after
        // it. That save is a correction back, and everything follows it.
        $this->separately(fn($db) => (new price_book($db))->recost_provider('aiprovider_openai', 'JPY'));
        $this->assertTrue($book->save_rate($rate, false)['recosted']);

        $currencies = $DB->get_fieldset_select(price::TABLE, 'DISTINCT currency', 'provider = ?', ['aiprovider_openai']);
        $this->assertSame(['USD'], array_values($currencies));
        $row = $DB->get_record(usage_recorder::ATTEMPT_TABLE, ['id' => $attempt->id], '*', MUST_EXIST);
        $this->assertSame('USD', $row->currency);
        $this->assertEqualsWithDelta(18.0, (float) $row->cost, 0.000001);
    }

    public function test_a_rate_save_that_cannot_take_the_lock_saves_nothing(): void {
        // The other side of R9-02: a save that finds the record busy is told so, as a
        // correction is, and leaves the rate as it was for the administrator to send
        // again.
        global $DB, $CFG;
        $this->preventResetByRollback();
        $rate = $this->add('aiprovider_openai', '', 1.0, 0.0);
        $original = $DB;
        $other = \moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary);
        $other->connect($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->prefix, $CFG->dboptions);
        $held = false;
        try {
            // The lock factory binds to the connection that is $DB when it is made.
            $DB = $other;
            $held = usage_recorder::lock_factory()->get_lock(usage_recorder::LOCK, 0);
            $DB = $original;
            $this->assertNotFalse($held);
            $rate->set('promptrate', 2.0);
            try {
                (new price_book($DB))->save_rate($rate, false);
                $this->fail('A busy record should have been said.');
            } catch (\moodle_exception $e) {
                $this->assertSame('rates:error:busy', $e->errorcode);
            }
        } finally {
            // Released on its own connection, before that connection goes.
            if ($held !== false) {
                $held->release();
            }
            $DB = $original;
            $other->dispose();
        }

        $this->assertEqualsWithDelta(1.0, (float) $DB->get_field(price::TABLE, 'promptrate', ['id' => $rate->get('id')]), 0.000001);
    }
}
