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

use core_ai\aiactions\generate_text;
use core_ai\provider as ai_provider;
use local_airouter\record\ledger;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture_router.php');
require_once(__DIR__ . '/fixtures/fixture_text_provider.php');
require_once(__DIR__ . '/fixtures/fixture_unconfigured_provider.php');

/**
 * Tests for choosing where a request is delegated.
 *
 * @package    local_airouter
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
        $router = new \local_airouter\fixture_router(enabled: true, name: 'Router', config: json_encode($config), id: 1);

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
     * A rule that asks somebody other than the site to pay.
     *
     * @param string $name The rule name.
     * @param int $targetid Where it delegates.
     * @param string $keysource Whose key pays.
     * @return rule The saved rule.
     */
    protected function add_byok(string $name, int $targetid, string $keysource): rule {
        $rule = new rule();
        $rule->set('name', $name);
        $rule->set('targetid', $targetid);
        $rule->set('keysource', $keysource);

        return $this->repository->save($rule);
    }

    /**
     * The action every test routes.
     *
     * @param int $userid Who is asking.
     * @param int|null $contextid Where they are asking from, or null for the site.
     * @return generate_text The action.
     */
    protected function action(int $userid = 0, ?int $contextid = null): generate_text {
        return new generate_text(
            contextid: $contextid ?? \context_system::instance()->id,
            userid: $userid,
            prompttext: 'Hello',
        );
    }

    /**
     * The ids of the candidates a resolver returns, in order.
     *
     * @param target_resolver $resolver The resolver.
     * @param generate_text|null $action The request, or null for one from nobody in particular.
     * @return int[] The instance ids.
     */
    protected function candidates(target_resolver $resolver, ?generate_text $action = null): array {
        return array_map(
            static fn(candidate $candidate): int => (int) $candidate->target->id,
            $resolver->get_candidates($action ?? $this->action()),
        );
    }

    /**
     * Somebody who is allowed to bring a key, and has brought one.
     *
     * @param int $targetid The instance the key is for.
     * @param string $secret The key.
     * @return \stdClass The user.
     */
    protected function key_holder(int $targetid, string $secret = 'their-own-key'): \stdClass {
        global $DB;

        set_config(eligibility_policy::ACCESS_SETTING, eligibility_policy::ACCESS_EVERYBODY, 'local_airouter');
        eligibility_policy::purge();
        $user = $this->getDataGenerator()->create_user();
        (new target_settings($DB))->set_key_field($targetid, 'apikey');
        (new key_repository($DB))->save(key::SCOPE_USER, (int) $user->id, $targetid, $secret);

        return $user;
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
            new \local_airouter\fixture_router(enabled: true, name: 'Another router', config: '{"defaulttarget":7}', id: 2),
        ]);

        // A router delegating to a router is how a loop starts. None can be created any
        // more, but an instance list is not trusted to be free of one.
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

    public function test_what_no_rule_claimed_goes_to_the_default_target(): void {
        $resolver = $this->resolver([$this->instance(7)], ['defaulttarget' => 7]);

        $this->assertSame([7], $this->candidates($resolver));
        $this->assertFalse($resolver->was_declined());
    }

    public function test_a_site_can_decline_what_no_rule_claimed(): void {
        $resolver = $this->resolver(
            [$this->instance(7)],
            ['defaulttarget' => 7, 'nomatch' => provider::NOMATCH_DECLINE],
        );

        // Refusing everything no rule covers is a way of keeping AI spending to the
        // cases the rules describe.
        $this->assertSame([], $this->candidates($resolver));
        $this->assertTrue($resolver->was_declined());
    }

    public function test_an_answer_the_router_does_not_know_sends_the_request_on(): void {
        $resolver = $this->resolver([$this->instance(7)], ['defaulttarget' => 7, 'nomatch' => 'nonsense']);

        $this->assertSame([7], $this->candidates($resolver));
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
        $router = new \local_airouter\fixture_router(enabled: true, name: 'Router', config: '{}', id: 1);

        // A site that routes everything by rule and refuses the rest has no use for a
        // default target, and core would otherwise skip the router as unconfigured.
        $this->assertTrue($router->is_provider_configured());
    }

    public function test_a_rule_wanting_a_key_nobody_brought_hands_on_to_the_next_rule(): void {
        global $DB;
        (new target_settings($DB))->set_key_field(8, 'apikey');
        set_config(eligibility_policy::ACCESS_SETTING, eligibility_policy::ACCESS_EVERYBODY, 'local_airouter');
        eligibility_policy::purge();
        $user = $this->getDataGenerator()->create_user();
        $this->add_byok('their own key', 8, rule::KEYSOURCE_USER);
        $this->add('the site pays', 9);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8), $this->instance(9)]);

        // An ordinary state, not a failure. Two rules in this order are how a site says
        // "their own key if they have one, ours otherwise".
        $this->assertSame([9, 7], $this->candidates($resolver, $this->action((int) $user->id)));
        $this->assertSame('the site pays', $resolver->get_matched_rule()?->get('name'));
    }

    public function test_a_brought_key_is_put_into_the_instance_the_rule_named(): void {
        $user = $this->key_holder(8);
        $this->add_byok('their own key', 8, rule::KEYSOURCE_USER);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8)]);

        $candidates = $resolver->get_candidates($this->action((int) $user->id));

        $this->assertSame('their-own-key', $candidates[0]->target->config['apikey']);
        $this->assertSame(rule::KEYSOURCE_USER, $candidates[0]->keysource);
        $this->assertNotNull($candidates[0]->get_keyid());
    }

    public function test_a_key_that_cannot_be_read_stops_the_request_instead(): void {
        global $DB;
        $user = $this->key_holder(8);
        $DB->set_field(key::TABLE, 'secret', 'nonsense', ['scopeid' => $user->id]);
        $this->add_byok('their own key', 8, rule::KEYSOURCE_USER);
        $this->add('the site pays', 9);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8), $this->instance(9)]);

        $candidates = $resolver->get_candidates($this->action((int) $user->id));

        // The line between hole 1 and hole 4. Falling through to the rule below would
        // charge the site for a request somebody asked to pay for themselves, and the
        // only sign of it would be a bill.
        $this->assertSame([], $candidates);
        $this->assertNotNull($resolver->get_unreadable_key());
        $this->assertSame('their own key', $resolver->get_matched_rule()?->get('name'));
        $this->assertSame(rule::KEYSOURCE_USER, $resolver->get_keysource());
        $this->assertDebuggingCalled();
    }

    public function test_a_provider_nobody_has_said_the_key_field_of_is_treated_as_keyless(): void {
        global $DB;
        set_config(eligibility_policy::ACCESS_SETTING, eligibility_policy::ACCESS_EVERYBODY, 'local_airouter');
        eligibility_policy::purge();
        $user = $this->getDataGenerator()->create_user();
        (new key_repository($DB))->save(key::SCOPE_USER, (int) $user->id, 8, 'their-own-key');
        $this->add_byok('their own key', 8, rule::KEYSOURCE_USER);
        $this->add('the site pays', 9);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8), $this->instance(9)]);

        // Delegating without putting the key anywhere would send the request charged to
        // the site while the person who brought one believed they were paying.
        $this->assertSame([9, 7], $this->candidates($resolver, $this->action((int) $user->id)));
        $this->assertNull($resolver->get_unreadable_key());
    }

    public function test_somebody_who_may_no_longer_bring_a_key_stops_using_theirs(): void {
        $user = $this->key_holder(8);
        set_config(eligibility_policy::ACCESS_SETTING, eligibility_policy::ACCESS_NOBODY, 'local_airouter');
        eligibility_policy::purge();
        $this->add_byok('their own key', 8, rule::KEYSOURCE_USER);
        $this->add('the site pays', 9);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8), $this->instance(9)]);

        // Decided again here rather than only when the key was registered, so that an
        // administrator tightening the policy is obeyed on the next request. The key is
        // not deleted; it simply stops being used.
        $this->assertSame([9, 7], $this->candidates($resolver, $this->action((int) $user->id)));
    }

    public function test_only_instances_the_payer_has_a_key_for_stand_behind_a_brought_key(): void {
        global $DB;
        $user = $this->key_holder(8);
        (new target_settings($DB))->set_key_field(9, 'apikey');
        (new key_repository($DB))->save(key::SCOPE_USER, (int) $user->id, 9, 'their-other-key');
        $this->add_byok('their own key', 8, rule::KEYSOURCE_USER);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8), $this->instance(9)]);

        // The default target, 7, is not among them. A request somebody asked to pay for
        // themselves must not quietly become one the site pays for because the first
        // provider was busy.
        $this->assertSame([8, 9], $this->candidates($resolver, $this->action((int) $user->id)));
    }

    public function test_a_course_key_is_found_from_where_the_request_was_made(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        (new target_settings($DB))->set_key_field(8, 'apikey');
        (new key_repository($DB))->save(key::SCOPE_COURSE, (int) $course->id, 8, 'the-course-key');
        $this->add_byok('the course pays', 8, rule::KEYSOURCE_COURSE);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8)]);

        $candidates = $resolver->get_candidates(
            $this->action(0, \context_course::instance($course->id)->id),
        );

        // Nobody in the course needs a key of their own, which is the whole point of a
        // course key.
        $this->assertSame('the-course-key', $candidates[0]->target->config['apikey']);
        $this->assertSame(rule::KEYSOURCE_COURSE, $candidates[0]->keysource);
    }

    public function test_a_course_key_cannot_be_found_outside_a_course(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        (new target_settings($DB))->set_key_field(8, 'apikey');
        (new key_repository($DB))->save(key::SCOPE_COURSE, (int) $course->id, 8, 'the-course-key');
        $this->add_byok('the course pays', 8, rule::KEYSOURCE_COURSE);
        $this->add('the site pays', 9);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8), $this->instance(9)]);

        // There is no course to charge, which is an absence rather than a fault, so the
        // request carries on down the list.
        $this->assertSame([9, 7], $this->candidates($resolver));
    }

    public function test_a_provider_reserved_for_brought_keys_refuses_the_sites_own(): void {
        global $DB;
        $settings = new target_settings($DB);
        $settings->set_key_field(8, 'apikey');
        $settings->set_mode(8, target_settings::MODE_ONLY);
        $this->add('the site pays', 8);
        $this->add('and then this one', 9);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8), $this->instance(9)]);

        // Turned away here rather than at the provider. Letting the request go and be
        // refused would spend a round trip finding out what the site already knows.
        $this->assertSame([9, 7], $this->candidates($resolver, $this->action()));
        $this->assertSame('and then this one', $resolver->get_matched_rule()?->get('name'));
    }

    public function test_a_provider_reserved_for_brought_keys_still_takes_one(): void {
        global $DB;
        $user = $this->key_holder(8);
        (new target_settings($DB))->set_mode(8, target_settings::MODE_ONLY);
        $this->add_byok('their own key', 8, rule::KEYSOURCE_USER);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8)]);

        $candidates = $resolver->get_candidates($this->action((int) $user->id));

        $this->assertSame('their-own-key', $candidates[0]->target->config['apikey']);
        $this->assertSame(rule::KEYSOURCE_USER, $candidates[0]->keysource);
    }

    public function test_a_provider_reserved_for_brought_keys_is_not_a_default_target(): void {
        global $DB;
        $settings = new target_settings($DB);
        $settings->set_key_field(7, 'apikey');
        $settings->set_mode(7, target_settings::MODE_ONLY);
        $resolver = $this->resolver([$this->instance(7)]);

        // Nothing claimed the request and the only place left is one the site may not
        // pay at. Better nothing than a bill the administrator ruled out.
        $this->assertSame([], $resolver->get_candidates($this->action()));
    }

    public function test_a_provider_reserved_for_brought_keys_is_no_fallback_for_the_site(): void {
        global $DB;
        $settings = new target_settings($DB);
        $settings->set_key_field(7, 'apikey');
        $settings->set_mode(7, target_settings::MODE_ONLY);
        $this->add('the site pays', 9);
        $resolver = $this->resolver([$this->instance(7), $this->instance(9)]);

        // The default target stands behind every rule, so it has to be refused there too.
        $this->assertSame([9], $this->candidates($resolver, $this->action()));
    }

    /**
     * Record something spent against a brought key, as the request and attempt records hold it.
     *
     * @param int $userid Whose key paid.
     * @param int $targetid Where it went.
     * @param float|null $cost What it cost, or null when no rate covered it.
     */
    protected function spent(int $userid, int $targetid, ?float $cost): void {
        global $DB;

        if (!$DB->record_exists(price::TABLE, ['provider' => 'aiprovider_openai'])) {
            // The provider bills in dollars, so a limit on a key for it is in dollars.
            $rate = new price();
            $rate->set('provider', 'aiprovider_openai');
            $rate->set('currency', 'USD');
            $rate->set('promptrate', 1.0);
            $rate->create();
        }
        // Charged to the wallet of the key this person holds there, which is what
        // the limit on the key is measured by.
        $key = (new key_repository($DB))->find(key::SCOPE_USER, $userid, $targetid);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        $when = time() - HOURSECS;
        $request = $generator->create_request([
            'userid' => $userid,
            'keysource' => rule::KEYSOURCE_USER,
            'answeredby' => $targetid,
            'timestarted' => $when,
            'timeended' => $when,
        ]);
        $generator->create_attempt([
            'requestid' => $request->id,
            'targetid' => $targetid,
            'targetname' => 'Provider ' . $targetid,
            'targetprovider' => 'aiprovider_openai',
            'model' => 'gpt-4o',
            'keysource' => rule::KEYSOURCE_USER,
            'keyid' => $key === null ? null : (int) $key->get('id'),
            'walletid' => $key === null ? 0 : $key->get_wallet(),
            'cost' => $cost,
            'currency' => $cost === null ? null : 'USD',
            'timestarted' => $when,
            'timeended' => $when,
        ]);
        \core_cache\helper::purge_by_definition('local_airouter', ledger::CACHE_AREA);
    }

    public function test_a_key_that_has_spent_its_owners_limit_hands_on_to_the_next_rule(): void {
        global $DB;
        $user = $this->key_holder(8);
        $repository = new key_repository($DB);
        $repository->set_cap(
            $repository->find(key::SCOPE_USER, (int) $user->id, 8),
            10.0,
            ledger::PERIOD_MONTH,
            30,
        );
        $this->spent((int) $user->id, 8, 10.0);
        $this->add_byok('their own key', 8, rule::KEYSOURCE_USER);
        $this->add('the site pays', 9);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8), $this->instance(9)]);

        // Hole 1, not hole 4. The owner set themselves a limit and reached it, which is
        // not a fault: stopping the request would punish them for being careful.
        $this->assertSame([9, 7], $this->candidates($resolver, $this->action((int) $user->id)));
        $this->assertNull($resolver->get_unreadable_key());
    }

    public function test_a_key_under_its_limit_is_used_as_usual(): void {
        global $DB;
        $user = $this->key_holder(8);
        $repository = new key_repository($DB);
        $repository->set_cap(
            $repository->find(key::SCOPE_USER, (int) $user->id, 8),
            10.0,
            ledger::PERIOD_MONTH,
            30,
        );
        $this->spent((int) $user->id, 8, 4.0);
        $this->add_byok('their own key', 8, rule::KEYSOURCE_USER);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8)]);

        $candidates = $resolver->get_candidates($this->action((int) $user->id));

        $this->assertSame('their-own-key', $candidates[0]->target->config['apikey']);
    }

    public function test_a_limit_nobody_can_measure_leaves_the_key_in_play(): void {
        global $DB;
        $user = $this->key_holder(8);
        $repository = new key_repository($DB);
        $repository->set_cap(
            $repository->find(key::SCOPE_USER, (int) $user->id, 8),
            10.0,
            ledger::PERIOD_MONTH,
            30,
        );
        // A site with no rates entered. The opposite direction to a budget condition,
        // and deliberately so: this is somebody's own key, and a site that prices
        // nothing must not silently stop every key it holds.
        $this->spent((int) $user->id, 8, null);
        $this->add_byok('their own key', 8, rule::KEYSOURCE_USER);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8)]);

        $candidates = $resolver->get_candidates($this->action((int) $user->id));

        $this->assertSame('their-own-key', $candidates[0]->target->config['apikey']);
    }

    public function test_a_spent_key_is_left_out_of_the_fallback_chain_too(): void {
        global $DB;
        $user = $this->key_holder(8);
        $repository = new key_repository($DB);
        (new target_settings($DB))->set_key_field(9, 'apikey');
        $repository->save(key::SCOPE_USER, (int) $user->id, 9, 'their-second-key');
        $repository->set_cap(
            $repository->find(key::SCOPE_USER, (int) $user->id, 9),
            10.0,
            ledger::PERIOD_MONTH,
            30,
        );
        $this->spent((int) $user->id, 9, 20.0);
        $this->add_byok('their own key', 8, rule::KEYSOURCE_USER);
        $resolver = $this->resolver([$this->instance(7), $this->instance(8), $this->instance(9)]);

        // Instance 9 would otherwise stand behind 8, because the same person holds a
        // key for it. Their limit there has gone, so it is not a place to fall back to.
        $this->assertSame([8], $this->candidates($resolver, $this->action((int) $user->id)));
    }

    public function test_a_router_with_neither_rules_nor_a_target_is_not_configured(): void {
        $router = new \local_airouter\fixture_router(enabled: true, name: 'Router', config: '{}', id: 1);

        $this->assertFalse($router->is_provider_configured());
    }

    public function test_a_caller_that_knows_the_placement_is_believed(): void {
        $resolver = $this->resolver([$this->instance(3), $this->instance(7)]);
        $this->add('Editor only', 3, ['placement' => ['placements' => ['aiplacement_editor']]]);
        $action = $this->action();

        // Nothing in a test's call stack is a placement, which is the same position the
        // rule tester is in: it is a page, not an editor. The rule cannot match.
        $this->assertSame([7], $this->candidates($resolver, $action));

        // A caller that already knows which placement is being asked about says so, and
        // the rule that is limited to it claims the request. Working the placement out
        // here as well would answer about this call stack instead, and a screen built on
        // it would report the rule as matched while naming the default target.
        $candidates = $resolver->get_candidates(
            $action,
            new evaluation_context($action, placement: 'aiplacement_editor'),
        );

        // The rule's target first, with the default still behind it the way it is for
        // any matched rule. What the rule tester reports is the first of these.
        $this->assertSame(
            [3, 7],
            array_map(static fn(candidate $candidate): int => (int) $candidate->target->id, $candidates),
        );
        $this->assertSame('Editor only', $resolver->get_matched_rule()?->get('name'));
    }

    /**
     * A router carrying the instances a request read, as the routing manager hands it on.
     *
     * @param ai_provider[] $instances The instances, as read.
     * @param array $config The router instance configuration.
     * @return \local_airouter\fixture_router The router.
     */
    protected function carrying_router(array $instances, array $config = ['defaulttarget' => 7]): \local_airouter\fixture_router {
        $router = new \local_airouter\fixture_router(enabled: true, name: 'Router', config: json_encode($config), id: 1);
        $router->carry_request_instances($instances);

        return $router;
    }

    public function test_the_instances_the_request_began_with_are_the_ones_chosen_from(): void {
        // Nothing in ai_providers: the target exists only as the request read it. The
        // resolver that finds it has chosen from what the router was found among, not
        // from a second reading of the table.
        $resolver = new target_resolver($this->carrying_router([7 => $this->instance(7)]));

        $this->assertSame([7], $this->candidates($resolver));
    }

    public function test_a_brought_key_goes_into_a_copy_not_the_instances_the_request_read(): void {
        // The instances the request read are shared by everything that chooses a target
        // for it. A key somebody brought must reach the request it pays for and nothing
        // else, so the instance it goes into is a copy and the one read stays as it was.
        $user = $this->key_holder(8);
        $this->add_byok('their own key', 8, rule::KEYSOURCE_USER);
        $read = [7 => $this->instance(7), 8 => $this->instance(8)];
        $resolver = new target_resolver($this->carrying_router($read));

        $candidates = $resolver->get_candidates($this->action((int) $user->id));

        $this->assertSame('their-own-key', $candidates[0]->target->config['apikey']);
        $this->assertNotSame($read[8], $candidates[0]->target);
        $this->assertArrayNotHasKey('apikey', $read[8]->config);
    }

    public function test_the_context_the_start_was_recorded_with_is_the_one_the_rules_are_told(): void {
        // The start of a request is recorded with what the rules will be told, before
        // they run. Working it out again for the rules made two contexts for one
        // request, each with its own reading of the record.
        $resolver = new target_resolver($this->carrying_router([7 => $this->instance(7)]));
        $action = $this->action();
        $recorded = $resolver->get_evaluated_context($action);

        $resolver->get_candidates($action);

        $this->assertSame($recorded, $resolver->get_evaluated_context($action));
    }

    public function test_another_action_is_not_told_what_the_last_one_was(): void {
        $resolver = new target_resolver($this->carrying_router([7 => $this->instance(7)]));
        $first = $this->action();
        $recorded = $resolver->get_evaluated_context($first);
        $second = $this->action();

        $resolver->get_candidates($second);

        $told = $resolver->get_evaluated_context($second);
        $this->assertNotSame($recorded, $told);
        $this->assertTrue($told->is_for($second));
        $this->assertFalse($told->is_for($first));
    }
}
