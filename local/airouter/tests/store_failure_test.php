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

use local_airouter\record\usage_recorder;
use local_airouter\record\request_state;
use core_ai\aiactions\generate_text;
use core_ai\manager;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock/provider.php');
require_once(__DIR__ . '/fixtures/mock/abstract_processor.php');
require_once(__DIR__ . '/fixtures/mock/process_generate_text.php');

/**
 * What happens when the AI has answered and the answer cannot be saved.
 *
 * Core saves the result in a transaction after the provider has returned, so a
 * failure there arrives when the request has already been made and paid for. Two
 * things must not happen: the request must not be made again to get a result that
 * saves, and the site must not be left unable to tell a call that never happened
 * from one whose record was lost.
 *
 * The failure is made by taking the table core writes to away, rather than by a
 * fixture that refuses: the real action, the real router and core's own saving are
 * then all in the picture, which is where the question is.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(single_router_dispatch::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(routing_manager::class)]
final class store_failure_test extends \advanced_testcase {
    /** @var manager The manager a placement would be given. */
    protected manager $manager;

    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        provider::get_instance_ids(true);
        \aiprovider_mock\provider::$ratechecks = [];
        $this->manager = \core\di::get(manager::class);
    }

    /**
     * A site that routes generate_text to one target, with no provider instance.
     *
     * @return \core_ai\provider The target the router will delegate to.
     */
    protected function routing_site(): \core_ai\provider {
        global $DB;

        $target = $this->manager->create_provider_instance(
            classname: \aiprovider_mock\provider::class,
            name: 'Routed',
            enabled: true,
            config: ['scenario' => \aiprovider_mock\provider::SUCCESS, 'content' => 'Answered'],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );

        $rule = new rule();
        $rule->set('name', 'Everything');
        $rule->set('targetid', (int) $target->id);
        (new rule_repository($DB))->save($rule);
        managed_policy::set_managed_actions([generate_text::class]);

        return $target;
    }

    /** @var bool Whether the register table has been taken away and needs putting back. */
    protected bool $registerdropped = false;

    /**
     * Take away the table core records the result in.
     *
     * Dropping it is schema surgery on a shared test database, so whichever test
     * does it says so and tearDown puts it back. Restoring in the test itself would
     * be left undone by the first failure.
     */
    protected function break_the_register(): void {
        global $DB;

        $DB->get_manager()->drop_table(new \xmldb_table('ai_action_register'));
        $this->registerdropped = true;
    }

    #[\Override]
    public function tearDown(): void {
        global $CFG, $DB;

        if ($this->registerdropped) {
            $DB->get_manager()->install_one_table_from_xmldb_file(
                $CFG->libdir . '/db/install.xml',
                'ai_action_register',
            );
            $this->registerdropped = false;
        }

        parent::tearDown();
    }

    /**
     * Ask for some text, the way a placement asks.
     *
     * @return \core_ai\aiactions\responses\response_base The result.
     */
    protected function ask(): \core_ai\aiactions\responses\response_base {
        return $this->manager->process_action(new generate_text(
            contextid: \context_system::instance()->id,
            userid: get_admin()->id,
            prompttext: 'Hello',
        ));
    }

    public function test_the_request_is_not_made_again_to_get_an_answer_that_saves(): void {
        $target = $this->routing_site();
        $this->break_the_register();

        try {
            $this->ask();
            $this->fail('A failure to save should not have been swallowed.');
        } catch (\dml_exception $e) {
            unset($e);
        }

        // One attempt. Asking again for a result that would save would spend the
        // money twice for one request somebody made once.
        $this->assertSame([(int) $target->id], \aiprovider_mock\provider::$ratechecks);
    }

    public function test_the_site_can_still_tell_that_the_ai_was_called(): void {
        global $DB;

        // The router writes its own record while it is delegating, before core tries
        // to save anything. A lost core record therefore does not also lose the
        // knowledge that a provider was called, and charged for it.
        $this->routing_site();
        $before = $DB->count_records(usage_recorder::REQUEST_TABLE);
        $this->break_the_register();

        try {
            $this->ask();
        } catch (\dml_exception $e) {
            unset($e);
        }

        $this->assertSame($before + 1, $DB->count_records(usage_recorder::REQUEST_TABLE));
    }

    public function test_the_attempt_is_recorded_as_having_reached_the_provider(): void {
        global $DB;

        // Not merely that something was written: what distinguishes this from a
        // request that never went anywhere is that the attempt is on the record.
        $this->routing_site();
        $this->break_the_register();

        try {
            $this->ask();
        } catch (\dml_exception $e) {
            unset($e);
        }

        $logged = $DB->get_records(usage_recorder::REQUEST_TABLE, null, 'id DESC', '*', 0, 1);
        $this->assertCount(1, $logged);
        $this->assertEquals(1, reset($logged)->attempts);
        $this->assertSame(request_state::SUCCEEDED, reset($logged)->state);
        $this->assertSame(1, $DB->count_records(usage_recorder::ATTEMPT_TABLE, ['requestid' => reset($logged)->id]));
    }
}
