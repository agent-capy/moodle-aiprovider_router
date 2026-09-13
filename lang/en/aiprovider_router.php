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
$string['check:ruletargets'] = 'Rule delegation targets';
$string['check:ruletargets:found'] = '{$a} rules name a provider instance that no longer exists.';
$string['check:ruletargets:found_details'] = 'Requests matching these rules fall through to the next rule instead: {$a}. Edit each one to choose a target that exists, or delete it.';
$string['check:ruletargets:ok'] = 'Every rule names a provider instance that exists.';
$string['check:singleinstance'] = 'Number of AI Router instances';
$string['check:singleinstance:duplicates'] = 'This site has {$a} AI Router instances. Only one is used.';
$string['check:singleinstance:manage'] = 'Manage AI provider instances';
$string['check:singleinstance:ok'] = 'The site has a single AI Router instance.';
$string['check:staleentries'] = 'Leftover entries in the provider order';
$string['check:staleentries:found'] = 'The provider order has {$a} entries for provider instances that no longer exist.';
$string['check:staleentries:found_details'] = 'Leftover entries: {$a}. Moving providers up and down works on positions in this list, so leftovers can make reordering appear to do nothing.';
$string['check:staleentries:ok'] = 'Every entry in the provider order refers to a provider instance that exists.';
$string['condition:action'] = 'AI action';
$string['condition:action_help'] = 'Which kind of request this rule is about. Actions are the one thing a request always carries, so pairing this with a placement condition is more dependable than a placement condition alone.';
$string['condition:category'] = 'Course category';
$string['condition:category_help'] = 'Choosing a category also covers the categories and courses beneath it, so there is no separate option for subcategories. A request raised outside any category does not satisfy this condition.';
$string['condition:course'] = 'Course';
$string['condition:course_help'] = 'A request raised outside any course does not satisfy this condition, which includes requests from placements that are not tied to a course.';
$string['condition:describe:action'] = 'Action: {$a}';
$string['condition:describe:category'] = 'Category: {$a}';
$string['condition:describe:course'] = 'Course: {$a}';
$string['condition:describe:missing'] = 'a deleted item ({$a})';
$string['condition:describe:placement'] = 'Placement: {$a}';
$string['condition:describe:promptlength:gte'] = 'Estimated prompt length: at least {$a} tokens';
$string['condition:describe:promptlength:lte'] = 'Estimated prompt length: at most {$a} tokens';
$string['condition:describe:role'] = 'Role: {$a}';
$string['condition:placement'] = 'Placement';
$string['condition:placement_help'] = 'Which part of Moodle the request came from. Moodle does not record this on the request itself on any supported release, so it is worked out from the code that made the call; where that cannot be told, the condition is not satisfied. An action condition is the more dependable way to express most of what this is reached for.';
$string['condition:promptlength'] = 'Estimated prompt length';
$string['condition:promptlength:gte'] = 'is at least';
$string['condition:promptlength:lte'] = 'is at most';
$string['condition:promptlength_help'] = 'Compared against an estimate, not a measurement: nothing has been sent anywhere when a rule is evaluated. The estimate comes from the number of characters and the ratios set for the site, and differs from what a provider eventually charges. Use the rule tester to see the estimate for a prompt you have in mind.';
$string['condition:role'] = 'Role';
$string['condition:role_help'] = 'Roles the user holds where the request was raised, including roles assigned further up such as at the category or site level.';
$string['defaulttarget'] = 'Default delegation target';
$string['defaulttarget:none'] = 'No other AI provider instance is configured yet. Add one first, then come back and choose it here.';
$string['defaulttarget_help'] = 'The provider instance that handles a request when no rule picks one. Without it the router has nothing to delegate to and reports itself as not configured.';
$string['error:alltargetsfailed'] = 'The AI service could not be reached. Please try again shortly.';
$string['error:delegationunavailable'] = 'The AI service is unavailable. Please contact your site administrator.';
$string['error:emptyresponse'] = 'The AI did not return an answer. Try shortening your input, or try again.';
$string['error:nodefaulttarget'] = 'The AI request could not be handled. Please contact your site administrator.';
$string['error:norulematched'] = 'AI is not available for this request. Please contact your site administrator if you expected it to be.';
$string['error:onlyoneinstance'] = 'Only one AI Router instance can exist on a site. Edit the existing one instead.';
$string['mode'] = 'Operating mode';
$string['mode:coexist'] = 'Alongside other providers';
$string['mode:full'] = 'Router only';
$string['mode_help'] = 'In router only mode every AI request is expected to come through the router, which needs to be first in the provider order. Alongside other providers leaves requests the router declines to whichever provider comes next.';
$string['nomatch'] = 'When no rule matches';
$string['nomatch:decline'] = 'Decline the request (suits alongside other providers)';
$string['nomatch:delegate'] = 'Send it to the default delegation target (suits router only)';
$string['nomatch_help'] = 'Most requests take this path on a site whose rules cover only part of what it does, so it is worth choosing deliberately. Declining hands the request back to Moodle, which then tries the next AI provider in the site order; alongside other providers that means the site carries on as before, while in router only mode there is no next provider and the request stops. Declining is also how a site limits AI spending to the cases its rules describe.';
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
$string['rule:add'] = 'Add a rule';
$string['rule:conditions'] = 'Conditions';
$string['rule:conditions_intro'] = 'Every condition you fill in has to be satisfied for the rule to match, and each one is satisfied by any of the values you choose in it. Leave a condition empty to say the rule does not care about it. A rule with no conditions at all takes every request that reaches it, which is useful at the bottom of the list.';
$string['rule:edit'] = 'Edit rule';
$string['rule:enabled'] = 'Enabled';
$string['rule:error:endbeforestart'] = 'The end of the active period has to come after its start.';
$string['rule:error:noname'] = 'Give the rule a name, so that it can be told apart from the others.';
$string['rule:error:notarget'] = 'Choose the provider instance this rule delegates to.';
$string['rule:name'] = 'Rule name';
$string['rule:saved'] = 'Rule "{$a}" has been saved.';
$string['rule:target'] = 'Delegate to';
$string['rule:target_help'] = 'The provider instance that handles requests this rule claims. If that instance is later deleted or switched off, requests matching this rule move on to the next rule rather than failing here.';
$string['rule:timeend'] = 'Active until';
$string['rule:timestart'] = 'Active from';
$string['rule:window'] = 'Active period';
$string['rule:window_help'] = 'Outside this period the rule is skipped entirely, as though it were switched off. Leave both empty for a rule that always applies.';
$string['rules:add'] = 'Add a rule';
$string['rules:confirmdelete'] = 'Delete the rule "{$a}"? Requests it used to claim will be handled by the rules below it, or by the "When no rule matches" setting. This cannot be undone.';
$string['rules:copyof'] = 'Copy of {$a}';
$string['rules:deleted'] = 'Rule "{$a}" has been deleted.';
$string['rules:disable'] = 'Switch off';
$string['rules:disabled'] = 'Switched off';
$string['rules:duplicate'] = 'Copy';
$string['rules:enable'] = 'Switch on';
$string['rules:heading'] = 'AI Router rules';
$string['rules:intro'] = 'Rules are considered from the top down, and the first one that matches decides where the request goes. A rule matches when every condition on it is satisfied; a condition listing several values is satisfied by any one of them. A request no rule claims is handled according to the router\'s own "When no rule matches" setting.';
$string['rules:manage'] = 'Manage routing rules';
$string['rules:missingtarget'] = 'Instance {$a}, which no longer exists. Requests matching this rule fall through to the next rule.';
$string['rules:movedown'] = 'Move down';
$string['rules:moveup'] = 'Move up';
$string['rules:noconditions'] = 'No conditions, so this rule takes every request that reaches it.';
$string['rules:none'] = 'No rules yet. Until one is added, every request goes to the default delegation target.';
$string['rules:priority'] = 'Order';
$string['rules:unknowncondition'] = 'A condition of an unknown type ({$a}), which this version cannot evaluate, so the rule never matches.';
$string['rules:unreachable'] = 'Never reached: a rule above this one takes every request.';
$string['rules:window'] = 'Active from {$a->start} until {$a->end}';
$string['rules:window:open'] = 'further notice';
$string['ruletest:action'] = 'AI action';
$string['ruletest:course'] = 'Course';
$string['ruletest:course:none'] = 'None (outside any course)';
$string['ruletest:course_help'] = 'Where the request would be raised. Leave this empty for a request from outside any course, which is what a site wide placement produces.';
$string['ruletest:error:nosuchuser'] = 'No account was found with that username or email address.';
$string['ruletest:heading'] = 'Test the rules';
$string['ruletest:intro'] = 'Describe a request and see which rule would claim it. Nothing is sent to any AI provider, and nothing is recorded. The rules are evaluated by exactly the same code a real request uses.';
$string['ruletest:nomatch'] = 'No rule would claim this request. What happens to it is decided by the router\'s "When no rule matches" setting.';
$string['ruletest:outcome'] = 'Outcome';
$string['ruletest:outcome:chosen'] = 'Matched. The request would go to {$a}.';
$string['ruletest:outcome:inactive'] = 'Skipped: switched off, or outside its active period.';
$string['ruletest:outcome:notreached'] = 'Not reached, because a rule above it matched first.';
$string['ruletest:outcome:unmet'] = 'Did not match. Conditions not satisfied: {$a}.';
$string['ruletest:outcome:unusable'] = 'Matched, but instance {$a} cannot be used, so the search moved on to the next rule.';
$string['ruletest:placement'] = 'Placement';
$string['ruletest:placement:none'] = 'Not identified';
$string['ruletest:placement_help'] = 'Which part of Moodle the request would come from. Choosing "not identified" tests what happens when the placement cannot be told, which is how a real request behaves when nothing in the call identifies one.';
$string['ruletest:prompt'] = 'Prompt';
$string['ruletest:prompt_help'] = 'Only its length is used, to work out the estimated token count that prompt length conditions compare against. The text is not sent anywhere.';
$string['ruletest:request'] = 'The request being tested';
$string['ruletest:result'] = 'What each rule did';
$string['ruletest:run'] = 'Test';
$string['ruletest:tokens'] = 'Estimated prompt length';
$string['ruletest:tokens:value'] = 'About {$a->tokens} tokens ({$a->characters} characters: {$a->cjk} counted at {$a->cjkratio} characters per token, {$a->other} at {$a->otherratio}). This is an estimate, and what a provider charges will differ.';
$string['ruletest:user'] = 'User';
$string['ruletest:user:none'] = 'None';
$string['ruletest:user_help'] = 'A username or an email address. Their roles in the chosen course, including any inherited from above it, are what role conditions are tested against. Leave empty to test a request with no user attached.';
$string['ruletest:wouldgo'] = 'The rule "{$a->rule}" would claim this request, and it would go to {$a->target}.';
$string['warning:duplicateinstances'] = 'This site has {$a} AI Router instances, and only one of them is used. Delete the others from the AI provider list.';
