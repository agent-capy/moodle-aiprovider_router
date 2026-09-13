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

/**
 * Strings for aiprovider_router.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['check:actionconflict'] = 'Providers ahead of the AI Router';
$string['check:actionconflict:found'] = '{$a} provider instances ahead of the AI Router handle the same actions.';
$string['check:actionconflict:found_details'] = 'These instances are asked first and answer, so the router is never reached: {$a}.';
$string['check:actionconflict:ok'] = 'No provider ahead of the AI Router handles the same actions.';
$string['check:norouter'] = 'No AI Router instance has been created yet, so there is nothing to check.';
$string['check:routerfirst'] = 'AI Router position in the provider order';
$string['check:routerfirst:coexist'] = 'The AI Router is not the first provider Moodle tries. In "Alongside other providers" mode this may be intended.';
$string['check:routerfirst:details'] = 'The router is number {$a->position} of {$a->total} provider instances.';
$string['check:routerfirst:notfirst'] = 'The AI Router is not the first provider Moodle tries, so requests are answered before they reach it.';
$string['check:routerfirst:ok'] = 'The AI Router is the first provider Moodle tries.';
$string['check:routerlisted'] = 'AI Router in the provider order';
$string['check:routerlisted:missing'] = 'The AI Router is missing from the provider order.';
$string['check:routerlisted:missing_details'] = 'Moodle tries providers in the order set for the site and returns the first answer it gets. A provider that is not listed is tried last, so the router only sees requests that every other provider has already refused.';
$string['check:routerlisted:ok'] = 'The AI Router is listed in the provider order.';
$string['check:singleinstance'] = 'Number of AI Router instances';
$string['check:singleinstance:duplicates'] = 'This site has {$a} AI Router instances. Only one is used.';
$string['check:singleinstance:manage'] = 'Manage AI provider instances';
$string['check:singleinstance:ok'] = 'The site has a single AI Router instance.';
$string['check:staleentries'] = 'Leftover entries in the provider order';
$string['check:staleentries:found'] = 'The provider order has {$a} entries for provider instances that no longer exist.';
$string['check:staleentries:found_details'] = 'Leftover entries: {$a}. Moving providers up and down works on positions in this list, so leftovers can make reordering appear to do nothing.';
$string['check:staleentries:ok'] = 'Every entry in the provider order refers to a provider instance that exists.';
$string['defaulttarget'] = 'Default delegation target';
$string['defaulttarget:none'] = 'No other AI provider instance is configured yet. Add one first, then come back and choose it here.';
$string['defaulttarget_help'] = 'The provider instance that handles a request when no rule picks one. Without it the router has nothing to delegate to and reports itself as not configured.';
$string['error:alltargetsfailed'] = 'The AI service could not be reached. Please try again shortly.';
$string['error:delegationunavailable'] = 'The AI service is unavailable. Please contact your site administrator.';
$string['error:emptyresponse'] = 'The AI did not return an answer. Try shortening your input, or try again.';
$string['error:nodefaulttarget'] = 'The AI request could not be handled. Please contact your site administrator.';
$string['error:onlyoneinstance'] = 'Only one AI Router instance can exist on a site. Edit the existing one instead.';
$string['mode'] = 'Operating mode';
$string['mode:coexist'] = 'Alongside other providers';
$string['mode:full'] = 'Router only';
$string['mode_help'] = 'In router only mode every AI request is expected to come through the router, which needs to be first in the provider order. Alongside other providers leaves requests the router declines to whichever provider comes next.';
$string['order:actions'] = 'Available changes';
$string['order:after'] = 'After the change';
$string['order:applied'] = 'The provider order has been updated.';
$string['order:apply'] = 'Apply the change';
$string['order:before'] = 'Now';
$string['order:cannotapply'] = 'That change cannot be applied. Please go back and try again.';
$string['order:checks'] = 'Checks';
$string['order:clean'] = 'Remove the leftover entries';
$string['order:clean_help'] = 'The provider order lists instances that no longer exist. Removing them does not change which providers are used, but it does make moving providers up and down behave as expected.';
$string['order:confirm:clean'] = 'Remove the leftover entries?';
$string['order:confirm:clean_help'] = 'This changes the provider order for the whole site, and the change is recorded in the configuration log. Only entries for instances that no longer exist are removed. The empty first entry stays, because Moodle does not enable or disable a provider in that position correctly.';
$string['order:confirm:promote'] = 'Move the AI Router to the front?';
$string['order:confirm:promote_help'] = 'This changes the provider order for the whole site, not just this provider, and the change is recorded in the configuration log. The other providers keep their order relative to each other. The first position is left empty on purpose, because Moodle does not enable or disable a provider in that position correctly.';
$string['order:current'] = 'Current order';
$string['order:entry:empty'] = 'Empty first entry, kept on purpose';
$string['order:entry:instance'] = '{$a->name} (instance {$a->id})';
$string['order:entry:router'] = 'This AI Router: {$a->name} (instance {$a->id})';
$string['order:entry:stale'] = 'Instance {$a}, which no longer exists';
$string['order:heading'] = 'AI provider order';
$string['order:instance:keep'] = 'Keep: {$a->name} (instance {$a->id})';
$string['order:instance:remove'] = 'Delete: {$a->name} (instance {$a->id})';
$string['order:nothingtodo'] = 'The provider order needs no changes.';
$string['order:notice:notfirst:coexist'] = 'This router is not the first AI provider Moodle tries. In "Alongside other providers" mode that may be what you intended.';
$string['order:notice:notfirst:full'] = 'This router is not the first AI provider Moodle tries, so requests are answered by another provider and never reach it.';
$string['order:promote'] = 'Move the AI Router to the front';
$string['order:promote_help'] = 'Moodle tries providers in this order and returns the first answer, so the router has to come first to see requests at all. The other providers keep their order relative to each other.';
$string['pluginname'] = 'AI Router';
$string['privacy:metadata'] = 'The AI Router plugin does not store any personal data. It delegates requests to other configured AI providers.';
$string['rule:error:endbeforestart'] = 'The end of the active period has to come after its start.';
$string['rule:error:noname'] = 'Give the rule a name, so that it can be told apart from the others.';
$string['rule:error:notarget'] = 'Choose the provider instance this rule delegates to.';
$string['warning:duplicateinstances'] = 'This site has {$a} AI Router instances, and only one of them is used. Delete the others from the AI provider list.';
