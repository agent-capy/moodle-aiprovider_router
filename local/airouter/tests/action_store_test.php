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

use core_ai\aiactions\explain_text;
use core_ai\aiactions\generate_image;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;
use core_ai\manager;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock/provider.php');
require_once(__DIR__ . '/fixtures/mock/abstract_processor.php');
require_once(__DIR__ . '/fixtures/mock/process_generate_text.php');
require_once(__DIR__ . '/fixtures/mock/process_summarise_text.php');
require_once(__DIR__ . '/fixtures/mock/process_explain_text.php');
require_once(__DIR__ . '/fixtures/mock/process_generate_image.php');
require_once(__DIR__ . '/fixtures/mock/process_describe_image.php');
require_once(__DIR__ . '/fixtures/mock/process_transcript_audio.php');

/**
 * Every action, through the router, all the way into the database.
 *
 * Answering in the right type is not the same as the answer being storable. Each
 * action writes a table of its own, with its own columns, and core writes its
 * register row in the same transaction: a mismatch anywhere loses both. The
 * arrangement where the router hands core an object core has never seen is exactly
 * the kind that could satisfy every interface and still fail here.
 *
 * These run wherever the suite runs, so the continuous integration matrix answers
 * the version question for the actions core defines. What a release changed about
 * response types is checked separately and much more cheaply by
 * dev/deploy/response-contract.sh, which does not reach a database.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(single_router_dispatch::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(routing_manager::class)]
final class action_store_test extends \advanced_testcase {
    /** @var manager The manager a placement would be given. */
    protected manager $manager;

    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // The actions that work on a file need somewhere to put one, and a draft
        // area belongs to whoever is asking.
        $this->setAdminUser();
        provider::get_instance_ids(true);
        $this->manager = \core\di::get(manager::class);
    }

    /**
     * Every action core defines, with a way to build one.
     *
     * @return array<string, array{class-string, string}> Class and its table, by name.
     */
    public static function core_actions(): array {
        return [
            'generate text' => [generate_text::class, 'ai_action_generate_text'],
            'summarise text' => [summarise_text::class, 'ai_action_summarise_text'],
            'explain text' => [explain_text::class, 'ai_action_explain_text'],
            'generate image' => [generate_image::class, 'ai_action_generate_image'],
        ];
    }

    /**
     * The actions another plugin defines, where that plugin is installed.
     *
     * @return array<string, array{class-string, string}> Class and its table, by name.
     */
    public static function extra_actions(): array {
        return [
            'describe image' => ['local_aimedia\\aiactions\\describe_image', 'local_aimedia_describe'],
            'transcribe audio' => ['local_aimedia\\aiactions\\transcript_audio', 'local_aimedia_transcript'],
        ];
    }

    /**
     * A file for an action that works on one.
     *
     * @param string $filename What it is called.
     * @return \stored_file The file.
     */
    protected function file(string $filename): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance((int) get_admin()->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => file_get_unused_draft_itemid(),
            'filepath' => '/',
            'filename' => $filename,
        ], 'some bytes');
    }

    /**
     * Build one of them, with whatever that action needs.
     *
     * @param string $classname The action class.
     * @return \core_ai\aiactions\base The action.
     */
    protected function build(string $classname): \core_ai\aiactions\base {
        $common = [
            'contextid' => \context_system::instance()->id,
            'userid' => (int) get_admin()->id,
            'prompttext' => 'Hello',
        ];
        if ($classname === generate_image::class) {
            return new $classname(
                ...$common,
                quality: 'hd',
                aspectratio: 'square',
                numimages: 1,
                style: 'natural',
            );
        }
        if (str_ends_with($classname, 'describe_image')) {
            return new $classname(
                contextid: $common['contextid'],
                userid: $common['userid'],
                file: $this->file('picture.png'),
                prompttext: 'What is this?',
            );
        }
        if (str_ends_with($classname, 'transcript_audio')) {
            return new $classname(
                contextid: $common['contextid'],
                userid: $common['userid'],
                file: $this->file('recording.ogg'),
            );
        }

        return new $classname(...$common);
    }

    /**
     * A site that routes the given action to one target.
     *
     * @param string $classname The action to place under the router.
     * @param string $scenario How the target behaves.
     * @return \core_ai\provider The target.
     */
    protected function routing_site(string $classname, string $scenario): \core_ai\provider {
        global $DB;

        $target = $this->manager->create_provider_instance(
            classname: \aiprovider_mock\provider::class,
            name: 'Routed',
            enabled: true,
            config: ['scenario' => $scenario, 'content' => 'Answered'],
            actionconfig: [$classname => ['enabled' => true]],
        );

        $rule = new rule();
        $rule->set('name', 'Everything');
        $rule->set('targetid', (int) $target->id);
        (new rule_repository($DB))->save($rule);
        managed_policy::set_managed_actions([$classname]);

        return $target;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('core_actions')]
    public function test_a_routed_answer_is_stored(string $classname, string $table): void {
        global $DB;

        $this->routing_site($classname, \aiprovider_mock\provider::SUCCESS);

        $response = $this->manager->process_action($this->build($classname));

        $this->assertTrue($response->get_success(), $classname);
        $this->assertSame(1, $DB->count_records($table), $table);

        $register = $DB->get_records('ai_action_register');
        $this->assertCount(1, $register);
        $this->assertSame('local_airouter', reset($register)->provider);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('extra_actions')]
    public function test_an_action_from_another_plugin_is_stored(string $classname, string $table): void {
        global $DB;

        // The release that broke these broke them here: the response could not even
        // be built, so nothing reached the table. Running this wherever the suite
        // runs is what puts that question in front of every supported release.
        if (!class_exists($classname)) {
            $this->markTestSkipped('local_aimedia is not installed');
        }

        $this->routing_site($classname, \aiprovider_mock\provider::SUCCESS);

        $response = $this->manager->process_action($this->build($classname));

        $this->assertTrue($response->get_success(), $classname);
        $this->assertSame(1, $DB->count_records($table), $table);

        $register = $DB->get_records('ai_action_register');
        $this->assertCount(1, $register);
        $this->assertSame('local_airouter', reset($register)->provider);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('extra_actions')]
    public function test_an_action_from_another_plugin_stores_its_failures(string $classname, string $table): void {
        global $DB;

        if (!class_exists($classname)) {
            $this->markTestSkipped('local_aimedia is not installed');
        }

        $this->routing_site($classname, \aiprovider_mock\provider::FAILURE);

        $response = $this->manager->process_action($this->build($classname));

        $this->assertFalse($response->get_success(), $classname);
        $this->assertSame(1, $DB->count_records($table), $table);
        $this->assertSame(1, $DB->count_records('ai_action_register'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('core_actions')]
    public function test_a_routed_failure_is_stored_too(string $classname, string $table): void {
        global $DB;

        // A failed request is one the site made, and core keeps the asking. The row
        // in the action's own table is what holds the prompt, so losing it would
        // leave the register saying something happened with no record of what.
        $this->routing_site($classname, \aiprovider_mock\provider::FAILURE);

        $response = $this->manager->process_action($this->build($classname));

        $this->assertFalse($response->get_success(), $classname);
        $this->assertSame(1, $DB->count_records($table), $table);
        $this->assertSame(1, $DB->count_records('ai_action_register'));
    }
}
