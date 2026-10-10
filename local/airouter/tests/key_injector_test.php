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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock/provider.php');
require_once(__DIR__ . '/fixtures/modelled/model.php');
require_once(__DIR__ . '/fixtures/modelled/helper.php');
require_once(__DIR__ . '/fixtures/modelled/provider.php');

/**
 * Tests for putting somebody's key into the provider instance it is for.
 *
 * The four outcomes are the whole of what the routing side needs to tell apart, and two
 * of them look alike from the outside and must not be treated alike: no key registered
 * is a normal state, while a key that cannot be read is a fault that has to stop the
 * request rather than let it fall through to somebody else's money.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(key_injector::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(key_injection::class)]
final class key_injector_test extends \advanced_testcase {
    /** @var key_repository The key store. */
    protected key_repository $keys;

    /** @var target_settings Where the key field of each target is recorded. */
    protected target_settings $settings;

    /** @var key_injector The injector under test. */
    protected key_injector $injector;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->keys = new key_repository($DB);
        $this->settings = new target_settings($DB);
        $this->injector = new key_injector($DB);
    }

    /**
     * A provider instance with some configuration of its own.
     *
     * @param int $id The instance id.
     * @return \aiprovider_mock\provider The instance.
     */
    protected function target(int $id = 3): \aiprovider_mock\provider {
        return new \aiprovider_mock\provider(
            enabled: true,
            name: 'Mock',
            config: json_encode(['apikey' => 'the-site-key', 'endpoint' => 'https://example.invalid']),
            id: $id,
        );
    }

    public function test_the_key_replaces_the_one_the_site_was_using(): void {
        $this->settings->set_key_field(3, 'apikey');
        $key = $this->keys->save(key::SCOPE_USER, 7, 3, 'the-users-own-key');

        $injection = $this->injector->inject($this->target(), $key);

        $this->assertTrue($injection->is_usable());
        $this->assertSame('the-users-own-key', $injection->target->config['apikey']);
        // Everything else the administrator configured still applies.
        $this->assertSame('https://example.invalid', $injection->target->config['endpoint']);
        $this->assertSame(3, (int) $injection->target->id);
    }

    public function test_the_instance_the_site_configured_is_left_alone(): void {
        global $DB;
        $this->settings->set_key_field(3, 'apikey');
        $key = $this->keys->save(key::SCOPE_USER, 7, 3, 'the-users-own-key');
        $target = $this->target();

        $this->injector->inject($target, $key);

        // A copy for the length of one request, and nothing written anywhere.
        $this->assertSame('the-site-key', $target->config['apikey']);
        $this->assertFalse($DB->record_exists('ai_providers', ['id' => 3]));
    }

    public function test_a_provider_nobody_has_answered_for_cannot_take_a_key(): void {
        $key = $this->keys->save(key::SCOPE_USER, 7, 3, 'the-users-own-key');

        $injection = $this->injector->inject($this->target(), $key);

        // Putting the key nowhere would send the request charged to the site while the
        // person who brought it believed they were paying.
        $this->assertSame(key_status::NO_FIELD, $injection->status);
        $this->assertNull($injection->target);
    }

    public function test_a_provider_that_takes_no_key_cannot_take_one(): void {
        $this->settings->set_key_field(3, target_settings::NO_KEY);
        $key = $this->keys->save(key::SCOPE_USER, 7, 3, 'the-users-own-key');

        $injection = $this->injector->inject($this->target(), $key);

        $this->assertSame(key_status::NO_FIELD, $injection->status);
    }

    public function test_a_key_that_cannot_be_read_is_a_fault_not_an_absence(): void {
        global $DB;
        $this->settings->set_key_field(3, 'apikey');
        $key = $this->keys->save(key::SCOPE_USER, 7, 3, 'the-users-own-key');
        $DB->set_field(key::TABLE, 'secret', 'nonsense', ['id' => $key->get('id')]);

        $injection = $this->injector->inject($this->target(), $this->keys->get((int) $key->get('id')));

        $this->assertSame(key_status::UNREADABLE, $injection->status);
        $this->assertDebuggingCalled();
    }

    public function test_a_subject_with_no_key_is_simply_absent(): void {
        $this->settings->set_key_field(3, 'apikey');

        $injection = $this->injector->for_subject($this->target(), key::SCOPE_USER, 7);

        $this->assertSame(key_status::ABSENT, $injection->status);
    }

    public function test_no_subject_at_all_is_absent_rather_than_an_error(): void {
        $this->settings->set_key_field(3, 'apikey');

        // A course key asked for by a request made outside any course.
        $injection = $this->injector->for_subject($this->target(), key::SCOPE_COURSE, 0);

        $this->assertSame(key_status::ABSENT, $injection->status);
    }

    public function test_a_key_is_not_used_where_the_site_has_said_keys_are_not_welcome(): void {
        // The setting has to hold over keys that were registered before it was made.
        // Enforcing it only where a key is registered leaves every key already stored
        // going on being used, which is not a policy at all.
        $this->settings->set_key_field(3, 'apikey');
        $key = $this->keys->save(key::SCOPE_USER, 7, 3, 'the-users-own-key');
        $this->settings->set_mode(3, target_settings::MODE_DISALLOWED);

        $injection = $this->injector->inject($this->target(), $key);

        $this->assertFalse($injection->is_usable());
        // Told apart from a target nobody has finished setting up, because this one is
        // a decision somebody made.
        $this->assertSame(key_status::DISALLOWED, $injection->status);
    }

    public function test_a_target_that_is_merely_unfinished_still_reads_as_unfinished(): void {
        $key = $this->keys->save(key::SCOPE_USER, 7, 3, 'the-users-own-key');
        $this->settings->set_mode(3, target_settings::MODE_DISALLOWED);

        $injection = $this->injector->inject($this->target(), $key);

        $this->assertSame(key_status::NO_FIELD, $injection->status);
    }

    public function test_a_key_brought_to_a_subject_is_refused_there_too(): void {
        // The same test, reached the way a request reaches it.
        $this->settings->set_key_field(3, 'apikey');
        $this->keys->save(key::SCOPE_COURSE, 42, 3, 'the-course-key');
        $this->settings->set_mode(3, target_settings::MODE_DISALLOWED);

        $injection = $this->injector->for_subject($this->target(), key::SCOPE_COURSE, 42);

        $this->assertSame(key_status::DISALLOWED, $injection->status);
    }

    public function test_a_subjects_own_key_is_found_and_applied(): void {
        $this->settings->set_key_field(3, 'apikey');
        $this->keys->save(key::SCOPE_COURSE, 42, 3, 'the-course-key');
        $this->keys->save(key::SCOPE_USER, 42, 3, 'a-users-key');

        $injection = $this->injector->for_subject($this->target(), key::SCOPE_COURSE, 42);

        // The course and the user happen to share an id, which must not confuse them.
        $this->assertTrue($injection->is_usable());
        $this->assertSame('the-course-key', $injection->target->config['apikey']);
    }

    /**
     * The fixture provider that lists its models, using alpha for both of its text actions.
     *
     * @return \aiprovider_modelled\provider The instance.
     */
    protected function modelled(): \aiprovider_modelled\provider {
        return new \aiprovider_modelled\provider(
            enabled: true,
            name: 'Modelled',
            config: json_encode(['apikey' => 'the-site-key']),
            actionconfig: json_encode([
                \core_ai\aiactions\generate_text::class => ['enabled' => true, 'settings' => ['model' => 'alpha']],
                \core_ai\aiactions\summarise_text::class => ['enabled' => true, 'settings' => ['model' => 'alpha']],
            ]),
            id: 4,
        );
    }

    /**
     * A key at the fixture provider that asks for beta when generating text.
     *
     * @param string $model The model asked for.
     * @return key The key.
     */
    protected function key_asking_for(string $model): key {
        global $DB;
        $this->settings->set_key_field(4, 'apikey');
        $key = $this->keys->save(key::SCOPE_USER, 7, 4, 'the-users-own-key');
        (new key_repository($DB))->set_models($key, [\core_ai\aiactions\generate_text::class => $model]);

        return $key;
    }

    public function test_the_model_the_owner_chose_is_asked_for_where_the_site_allows_it(): void {
        $key = $this->key_asking_for('beta');
        $this->settings->set_model_choice(4, true);
        $target = $this->modelled();

        $generating = $this->injector->inject($target, $key, \core_ai\aiactions\generate_text::class);
        $summarising = $this->injector->inject($target, $key, \core_ai\aiactions\summarise_text::class);
        $untold = $this->injector->inject($target, $key);

        $this->assertSame('beta', $generating->target->actionconfig[\core_ai\aiactions\generate_text::class]['settings']['model']);
        // Chosen for one action only; the other keeps the site's.
        $summarised = $summarising->target->actionconfig[\core_ai\aiactions\summarise_text::class]['settings'];
        $this->assertSame('alpha', $summarised['model']);
        // Asked without an action, as the key test does, the site's model is used.
        $this->assertSame('alpha', $untold->target->actionconfig[\core_ai\aiactions\generate_text::class]['settings']['model']);
        // And the key goes in either way.
        $this->assertSame('the-users-own-key', $generating->target->config['apikey']);
    }

    public function test_a_chosen_model_is_not_used_where_the_site_does_not_allow_choosing(): void {
        $key = $this->key_asking_for('beta');

        $injection = $this->injector->inject($this->modelled(), $key, \core_ai\aiactions\generate_text::class);

        // Off unless an administrator turns it on, and the key is used all the same.
        $this->assertTrue($injection->is_usable());
        $this->assertSame('alpha', $injection->target->actionconfig[\core_ai\aiactions\generate_text::class]['settings']['model']);
    }

    public function test_a_chosen_name_that_cannot_be_a_model_name_is_not_sent(): void {
        global $DB;
        $key = $this->key_asking_for('beta');
        $this->settings->set_model_choice(4, true);
        // Written past the form, as a row might be by anything else.
        $DB->set_field(key::TABLE, 'models', json_encode([\core_ai\aiactions\generate_text::class => 'two words']), [
            'id' => (int) $key->get('id'),
        ]);
        $key = $this->keys->get((int) $key->get('id'));

        $injection = $this->injector->inject($this->modelled(), $key, \core_ai\aiactions\generate_text::class);

        $this->assertSame('alpha', $injection->target->actionconfig[\core_ai\aiactions\generate_text::class]['settings']['model']);
    }
}
