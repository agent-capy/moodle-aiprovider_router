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

use core_ai\aiactions\generate_text;
use core_ai\provider as ai_provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture_text_provider.php');
require_once(__DIR__ . '/fixtures/fixture_unconfigured_provider.php');

/**
 * Tests for choosing where a request is delegated.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(target_resolver::class)]
final class target_resolver_test extends \advanced_testcase {
    /** @var rule_repository Where the rules live. */
    protected rule_repository $repository;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        provider::get_instance_ids(true);
        $this->repository = new rule_repository($DB);
    }

    /**
     * A provider instance that can take a generate_text request.
     *
     * @param int $id The instance id.
     * @param string $class The fixture class to build.
     * @param bool $enabled Whether the instance is switched on.
     * @return ai_provider The instance.
     */
    protected function instance(
        int $id,
        string $class = fixture_text_provider::class,
        bool $enabled = true,
    ): ai_provider {
        return new $class(enabled: $enabled, name: "Provider {$id}", config: '{}', id: $id);
    }

    /**
     * A resolver over a made up site.
     *
     * @param ai_provider[] $instances The provider instances the site has.
     * @param array $config The router instance configuration.
     * @return target_resolver The resolver under test.
     */
    protected function resolver(array $instances, array $config = ['defaulttarget' => 7]): target_resolver {
        $router = new provider(enabled: true, name: 'Router', config: json_encode($config), id: 1);

        return new class ($router, $instances) extends target_resolver {
            /**
             * Constructor.
             *
             * @param provider $router The router instance.
             * @param ai_provider[] $testinstances The instances the site has.
             */
            public function __construct(
                provider $router,
                /** @var ai_provider[] The injected instances. */
                public array $testinstances,
            ) {
                parent::__construct($router);
            }

            #[\Override]
            protected function get_instances(): array {
                return $this->testinstances;
            }
        };
    }

    /**
     * Save a rule.
     *
     * @param string $name The rule name.
     * @param int $targetid Where it delegates.
     * @param array[] $conditions Configuration keyed by condition type.
     * @return rule The saved rule.
     */
    protected function add(string $name, int $targetid, array $conditions = []): rule {
        $rule = new rule();
        $rule->set('name', $name);
        $rule->set('targetid', $targetid);

        return $this->repository->save($rule, $conditions);
    }

    /**
     * The action every test routes.
     *
     * @return generate_text The action.
     */
    protected function action(): generate_text {
        return new generate_text(
            contextid: \context_system::instance()->id,
            userid: 0,
            prompttext: 'Hello',
        );
    }

    /**
     * The ids of the candidates a resolver returns, in order.
     *
     * @param target_resolver $resolver The resolver.
     * @return int[] The instance ids.
     */
    protected function candidates(target_resolver $resolver): array {
        return array_map(
            static fn(ai_provider $instance): int => (int) $instance->id,
            $resolver->get_candidates($this->action()),
        );
    }

    public function test_without_rules_the_default_target_is_used(): void {
        $resolver = $this->resolver([$this->instance(7), $this->instance(8)]);

        $this->assertSame([7], $this->candidates($resolver));
        $this->assertNull($resolver->get_matched_rule());
    }

    public function test_a_matching_rule_decides_where_the_request_goes(): void {
        $this->add('to eight', 8);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8)]);

        // The rule's target first, the default target behind it.
        $this->assertSame([8, 7], $this->candidates($resolver));
        $this->assertSame('to eight', $resolver->get_matched_rule()?->get('name'));
    }

    public function test_the_first_matching_rule_wins(): void {
        $this->add('first', 8);
        $this->add('second', 9);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8), $this->instance(9)]);

        $this->assertSame([8, 7], $this->candidates($resolver));
        $this->assertSame('first', $resolver->get_matched_rule()?->get('name'));
    }

    public function test_a_rule_whose_target_is_gone_hands_on_to_the_next_rule(): void {
        $this->add('points at nothing', 404);
        $this->add('points at eight', 8);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8)]);

        // The rule matched, but there is nothing to honour it with. Stopping here would
        // strand the request on the strength of a rule that cannot be carried out.
        $this->assertSame([8, 7], $this->candidates($resolver));
        $this->assertSame('points at eight', $resolver->get_matched_rule()?->get('name'));
    }

    public function test_a_rule_whose_target_is_switched_off_hands_on_to_the_next_rule(): void {
        $this->add('points at a disabled instance', 8);
        $this->add('points at nine', 9);
        $resolver = $this->resolver([
            $this->instance(7),
            $this->instance(8, enabled: false),
            $this->instance(9),
        ]);

        $this->assertSame([9, 7], $this->candidates($resolver));
    }

    public function test_a_rule_whose_target_is_unconfigured_hands_on_to_the_next_rule(): void {
        $this->add('points at an unconfigured instance', 8);
        $this->add('points at nine', 9);
        $resolver = $this->resolver([
            $this->instance(7),
            $this->instance(8, class: fixture_unconfigured_provider::class),
            $this->instance(9),
        ]);

        $this->assertSame([9, 7], $this->candidates($resolver));
    }

    public function test_a_rule_pointing_at_a_router_is_not_honoured(): void {
        $this->add('points at a router', 2);
        $resolver = $this->resolver([
            $this->instance(7),
            new provider(enabled: true, name: 'Another router', config: '{"defaulttarget":7}', id: 2),
        ]);

        // A router delegating to a router is how a loop starts, and the single instance
        // restriction only covers instances created through the form.
        $this->assertSame([7], $this->candidates($resolver));
        $this->assertNull($resolver->get_matched_rule());
    }

    public function test_conditions_decide_which_rule_matches(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->add('only in that course', 8, ['course' => ['courseids' => [(int) $course->id]]]);
        $this->add('anywhere', 9);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8), $this->instance(9)]);

        // The request is raised in the system context, so the course rule is passed over.
        $this->assertSame([9, 7], $this->candidates($resolver));
        $this->assertSame('anywhere', $resolver->get_matched_rule()?->get('name'));
    }

    public function test_router_only_mode_delegates_what_no_rule_claimed(): void {
        $resolver = $this->resolver(
            [$this->instance(7)],
            ['defaulttarget' => 7, 'mode' => provider::MODE_FULL],
        );

        // There is nobody behind the router to take a refused request.
        $this->assertSame([7], $this->candidates($resolver));
        $this->assertFalse($resolver->was_declined());
    }

    public function test_alongside_other_providers_declines_what_no_rule_claimed(): void {
        $resolver = $this->resolver(
            [$this->instance(7)],
            ['defaulttarget' => 7, 'mode' => provider::MODE_COEXIST],
        );

        // Declining hands the request back to core, which asks the next provider.
        $this->assertSame([], $this->candidates($resolver));
        $this->assertTrue($resolver->was_declined());
    }

    public function test_the_administrator_can_choose_the_other_answer(): void {
        $coexisting = $this->resolver(
            [$this->instance(7)],
            ['defaulttarget' => 7, 'mode' => provider::MODE_COEXIST, 'nomatch' => provider::NOMATCH_DELEGATE],
        );
        $routeronly = $this->resolver(
            [$this->instance(7)],
            ['defaulttarget' => 7, 'mode' => provider::MODE_FULL, 'nomatch' => provider::NOMATCH_DECLINE],
        );

        $this->assertSame([7], $this->candidates($coexisting));
        // Refusing everything no rule covers is a way of keeping AI spending to the
        // cases the rules describe, so router only mode has to be able to do it.
        $this->assertSame([], $this->candidates($routeronly));
        $this->assertTrue($routeronly->was_declined());
    }

    public function test_declining_also_means_no_silent_fallback_behind_a_rule(): void {
        $this->add('to eight', 8);
        $resolver = $this->resolver(
            [$this->instance(7), $this->instance(8)],
            ['defaulttarget' => 7, 'nomatch' => provider::NOMATCH_DECLINE],
        );

        // An administrator who refuses unclaimed requests has said the default target is
        // not a catch all. Sending a failed request there anyway would spend money at a
        // provider they deliberately kept out of the picture.
        $this->assertSame([8], $this->candidates($resolver));
    }

    public function test_the_default_target_is_not_offered_twice(): void {
        $this->add('to the default target', 7);
        $resolver = $this->resolver([$this->instance(7)]);

        $this->assertSame([7], $this->candidates($resolver));
    }

    public function test_an_unusable_default_target_leaves_nothing(): void {
        $resolver = $this->resolver([$this->instance(7, enabled: false)]);

        $this->assertSame([], $this->candidates($resolver));
        // Not a refusal. The site is misconfigured, and the failure has to say so.
        $this->assertFalse($resolver->was_declined());
    }

    public function test_a_router_with_rules_and_no_default_target_is_still_configured(): void {
        $this->add('to eight', 8);
        $router = new provider(enabled: true, name: 'Router', config: '{}', id: 1);

        // A site that routes everything by rule and refuses the rest has no use for a
        // default target, and core would otherwise skip the router as unconfigured.
        $this->assertTrue($router->is_provider_configured());
    }

    public function test_a_router_with_neither_rules_nor_a_target_is_not_configured(): void {
        $router = new provider(enabled: true, name: 'Router', config: '{}', id: 1);

        $this->assertFalse($router->is_provider_configured());
    }
}
