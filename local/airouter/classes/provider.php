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
 * The router as core's AI manager sees it while it carries out an action.
 *
 * Core runs an action against a provider object: it asks the object which actions it
 * offers and hands it to the processor that does the work. The router is not a provider
 * a site creates, lists or orders, so this object is not stored anywhere; it is built for
 * one request from the router's own settings (adapter_provider) and thrown away after it.
 *
 * The declared actions are the four core actions common to Moodle 5.0 through 5.2, and
 * the actions other plugins define where they are installed.
 *
 * This class holds what the site has decided; target_resolver decides where an
 * individual request goes.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class provider extends \core_ai\provider {
    #[\Override]
    public static function get_action_list(): array {
        $actions = [
            \core_ai\aiactions\generate_text::class,
            \core_ai\aiactions\generate_image::class,
            \core_ai\aiactions\summarise_text::class,
            \core_ai\aiactions\explain_text::class,
        ];

        // Actions that are not core's are routed too, where something defines them.
        // Offered only while the class is installed: an action named in this list and
        // absent from the site would be asked for its own settings, and cannot answer.
        foreach (self::EXTRA_ACTIONS as $class) {
            if (class_exists($class)) {
                $actions[] = $class;
            }
        }

        return $actions;
    }

    /**
     * @var string[] Actions defined outside core that this router can carry.
     *
     * Kept as strings rather than as ::class references, so that naming one here
     * does not require the plugin defining it to be installed.
     */
    protected const EXTRA_ACTIONS = [
        'local_aimedia\\aiactions\\transcript_audio',
        'local_aimedia\\aiactions\\describe_image',
    ];

    /** @var string Send a request no rule claimed to the default target. */
    public const NOMATCH_DELEGATE = 'delegate';

    /** @var string Turn down a request no rule claimed. */
    public const NOMATCH_DECLINE = 'decline';

    /** @var \core_ai\provider[]|null Every provider instance, as read for the request this router answers. */
    private ?array $requestinstances = null;

    /**
     * Carry the provider instances read when the request this router answers began.
     *
     * The router object is built for one request. What it carries goes with it and
     * nowhere else, so a change saved meanwhile reaches the next request, which reads
     * the instances again. The instances themselves are not changed on the way: a key
     * somebody brought is put into a copy.
     *
     * @param \core_ai\provider[] $instances The instances, as read.
     */
    public function carry_request_instances(array $instances): void {
        $this->requestinstances = $instances;
    }

    /**
     * The provider instances read when the request this router answers began.
     *
     * @return \core_ai\provider[]|null The instances, or null when none were carried.
     */
    public function get_request_instances(): ?array {
        return $this->requestinstances;
    }

    /**
     * What to do with a request that no rule claimed.
     *
     * This is not an edge case. It is the most travelled path on a site that has just
     * installed the plugin, on one whose rules cover part of what it does, and on one
     * whose rules have expired. Unless the site says otherwise, such a request goes to
     * the default target.
     *
     * Declining refuses the request. An action placed under the router is offered to
     * nobody else, so declining stops it; that is still worth offering, because "only
     * these courses may use AI, and nothing else may spend money" is a reasonable way
     * to run a site.
     *
     * @return string One of the NOMATCH_ constants.
     */
    public function get_nomatch_behaviour(): string {
        $behaviour = $this->config['nomatch'] ?? '';

        return $behaviour === self::NOMATCH_DECLINE ? self::NOMATCH_DECLINE : self::NOMATCH_DELEGATE;
    }

    /**
     * The instance the router falls back to when no rule picks a target.
     *
     * @return int|null The provider instance id, or null when none is set.
     */
    public function get_default_target_id(): ?int {
        $targetid = (int) ($this->config['defaulttarget'] ?? 0);

        return $targetid > 0 ? $targetid : null;
    }

    /**
     * Whether the site has any routing rules at all.
     *
     * @return bool True when at least one rule exists.
     */
    public static function has_rules(): bool {
        return (int) get_config('local_airouter', 'rulecount') > 0;
    }
}
