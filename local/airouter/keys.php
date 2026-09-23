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
 * Where somebody registers the key their AI requests are to be charged to.
 *
 * One screen for two subjects. Without a course it is the person's own keys, which they
 * pay for and the site's policy decides they may bring. With a course it is the key the
 * course pays with, which anybody who may edit the course can set, and which serves
 * everybody working in that course.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_airouter\eligibility_policy;
use local_airouter\form\key_cap_form;
use local_airouter\form\key_form;
use local_airouter\form\key_wallet_form;
use local_airouter\key;
use local_airouter\key_formatter;
use local_airouter\key_repository;
use local_airouter\key_tester;
use local_airouter\price_book;
use local_airouter\provider;
use local_airouter\record\ledger;
use local_airouter\retention_policy;
use local_airouter\target_settings;

$courseid = optional_param('courseid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$keyid = optional_param('keyid', 0, PARAM_INT);
$targetid = optional_param('targetid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

if ($courseid > 0) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($course->id);
    require_capability('local/airouter:managecoursekey', $context);
    $scope = key::SCOPE_COURSE;
    $scopeid = (int) $course->id;
    $url = new moodle_url('/local/airouter/keys.php', ['courseid' => $course->id]);
    $heading = get_string('keys:heading:course', 'local_airouter');
    $intro = get_string('keys:intro:course', 'local_airouter');
    // Before the heading, not after. set_heading() runs the text through
    // format_string(), which asks the page for its context and complains when there
    // is none. require_login($course) happens to leave one behind here and nothing
    // does in the branch below, which is how this went unnoticed on one of the two.
    $PAGE->set_context($context);
    $PAGE->set_pagelayout('incourse');
    $PAGE->set_heading(format_string($course->fullname));
} else {
    require_login();
    $context = context_user::instance($USER->id);
    $scope = key::SCOPE_USER;
    $scopeid = (int) $USER->id;
    $url = new moodle_url('/local/airouter/keys.php');
    $heading = get_string('keys:heading', 'local_airouter');
    $intro = get_string('keys:intro', 'local_airouter');
    $PAGE->set_context($context);
    $PAGE->set_pagelayout('standard');
    $PAGE->set_heading(fullname($USER));
}

$PAGE->set_url($url);
$PAGE->set_title($heading);

// A course key belongs to the course, so who may set one is a matter of who may act for
// the course. A key somebody brings for themselves is not, and the site's policy decides.
$allowed = $scope === key::SCOPE_COURSE || (new eligibility_policy())->is_eligible((int) $USER->id);

$repository = new key_repository($DB);
$settings = new target_settings($DB);

$targets = [];
foreach (\core\di::get(\core_ai\manager::class)->get_provider_instances() as $instance) {
    if ($instance instanceof provider || !$settings->supports_byok((int) $instance->id)) {
        continue;
    }
    $targets[(int) $instance->id] = $instance;
}
$names = array_map(fn($instance) => format_string($instance->name), $targets);
$keys = $repository->get_all($scope, $scopeid);

// Registering is for a provider that has no key yet. One that has is changed through
// its own row, where the questions a change raises can be asked.
$form = new key_form($url, ['targets' => array_diff_key($names, $keys)]);

// A change to a key waits for any other change to the same person's or course's keys,
// so that two of them never decide on the same rows at once. One that is still waiting
// when its time runs out is said, and nothing of it is stored.
$unlessbusy = function (\Closure $change) use ($url): mixed {
    try {
        return $change();
    } catch (\moodle_exception $e) {
        if ($e->errorcode !== 'keys:error:busy') {
            throw $e;
        }
        redirect($url, get_string('keys:error:busy', 'local_airouter'), null, \core\output\notification::NOTIFY_ERROR);
    }
};

// A key registered by somebody else while this one was being typed. The key just typed
// is not kept: whether it is for the account of the key now held is a question this
// form did not ask, and the replacement screen does.
$heldmeanwhile = function (int $targetid) use ($repository, $scope, $scopeid, $url): void {
    $held = $repository->find($scope, $scopeid, $targetid);
    redirect(
        new moodle_url($url, ['action' => 'replace', 'keyid' => $held === null ? 0 : (int) $held->get('id')]),
        get_string('keys:replace:held', 'local_airouter'),
        null,
        \core\output\notification::NOTIFY_INFO,
    );
};

// Removing a key is not gated on the policy. The key is a secret its owner handed over,
// and a site that tightens who may bring one must not thereby leave somebody holding a
// key they can no longer take back. The profile link is offered to anybody who has one
// for exactly this reason, and it would go nowhere if this screen refused them.
if ($action === 'delete' && $confirm) {
    require_sesskey();
    $key = $repository->get_for($keyid, $scope, $scopeid);
    if ($key !== null) {
        $unlessbusy(fn() => $repository->delete((int) $key->get('id')));
    }
    redirect($url, get_string('keys:deleted', 'local_airouter'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($allowed && $action === 'test') {
    require_sesskey();
    $key = $repository->get_for($keyid, $scope, $scopeid);
    if ($key === null || !isset($targets[(int) $key->get('targetid')])) {
        redirect($url);
    }
    $target = $targets[(int) $key->get('targetid')];
    $result = (new key_tester($DB))->test($target, $key, (int) $context->id, (int) $USER->id);
    redirect(
        $url,
        get_string('keys:tested:' . $result, 'local_airouter'),
        null,
        $result === key::VERIFY_OK
            ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_WARNING,
    );
}

// A limit on a key is a figure in the currency of the key's provider, which is the
// currency of that provider's rates; a provider with no rates yet has none.
$book = new price_book($DB);
$currencies = [];
foreach ($targets as $targetid => $instance) {
    $currencies[(int) $targetid] = $book->currency_of($instance->get_name());
}
// Not cached: somebody looking at their own limit is asking what it is now, and the
// figure is being shown rather than weighed on the path of a request.
$ledger = new ledger($DB, false);

$capform = null;
if ($allowed && $action === 'cap') {
    $capkey = $repository->get_for($keyid, $scope, $scopeid);
    if ($capkey === null) {
        redirect($url);
    }
    $capform = new key_cap_form(
        new moodle_url($url),
        ['currency' => $currencies[(int) $capkey->get('targetid')] ?? ''] + ($courseid > 0 ? ['courseid' => $courseid] : []),
    );
    if ($capform->is_cancelled()) {
        redirect($url);
    }
    if ($capdata = $capform->get_data()) {
        // Refused when the key was replaced by one for another account meanwhile:
        // the limit was decided about the key that was on the screen, whose wallet
        // the form carried.
        $capkey->set('walletid', max(0, (int) ($capdata->walletid ?? 0)));
        $capperiod = (string) ($capdata->capperiod ?? ledger::PERIOD_MONTH);
        $capdays = (int) ($capdata->capdays ?? 30);
        $applied = $repository->set_cap($capkey, key_cap_form::read_amount($capdata), $capperiod, $capdays);
        // A limit that looks back further than the site keeps its summaries is the
        // owner's to set all the same -- the retention is not theirs to change -- so
        // it is saved, and they are told that the figure against it will be a floor.
        $short = $applied && $capkey->has_cap()
            ? (new retention_policy($DB))->shortfall_of($capperiod, $capdays)
            : null;
        if (!$applied) {
            $message = get_string('keys:cap:changed', 'local_airouter');
        } else if ($short !== null) {
            $message = get_string('keys:cap:saved:short', 'local_airouter', $short);
        } else {
            $message = get_string('keys:cap:saved', 'local_airouter');
        }
        redirect(
            $url,
            $message,
            null,
            $applied && $short === null
                ? \core\output\notification::NOTIFY_SUCCESS
                : \core\output\notification::NOTIFY_WARNING,
        );
    }
    $capform->set_data([
        'keyid' => $keyid,
        'walletid' => $capkey->get_wallet(),
        'courseid' => $courseid,
        'capamount' => $capkey->has_cap() ? (string) $capkey->get_cap_amount() : '',
        'capperiod' => $capkey->get_cap_period(),
        'capdays' => $capkey->get_cap_days(),
    ]);
}

// Replacing a key that is held. The key does not say whether the new one is for the
// same account, so its owner is asked, and asked whether the limit stays; the same
// key entered again is recognised and not asked about.
$walletform = null;
$walletheading = '';
$walletintro = '';
if ($allowed && $action === 'replace') {
    $current = $repository->get_for($keyid, $scope, $scopeid);
    if ($current === null || !isset($targets[(int) $current->get('targetid')])) {
        redirect($url);
    }
    $currenttarget = (int) $current->get('targetid');
    $walletform = new key_wallet_form($url, [
        'mode' => key_wallet_form::MODE_REPLACE,
        'keyid' => (int) $current->get('id'),
        'walletid' => $current->get_wallet(),
        'courseid' => $courseid,
        'target' => $names[$currenttarget],
        'cap' => $current->has_cap() ? key_formatter::limit($current, $currencies[$currenttarget] ?? null) : null,
        // The same key again, or one this wallet or an earlier one held: known, and
        // not asked about.
        'issame' => fn(string $secret): bool => $repository->is_same_secret($current, $secret)
            || $repository->is_known_secret($scope, $scopeid, $currenttarget, $secret),
    ]);
    if ($walletform->is_cancelled()) {
        redirect($url);
    }
    if ($walletdata = $walletform->get_data()) {
        // The answers are about the key the owner was shown, which was in the wallet
        // the form carried. A key that has moved since is not the one they answered
        // about, and the repository stores nothing for it.
        $current->set('walletid', key_wallet_form::read_shown_wallet($walletdata));
        $replaced = $unlessbusy(fn() => $repository->replace(
            $current,
            (string) $walletdata->secret,
            key_wallet_form::read_sameaccount($walletdata),
            key_wallet_form::read_keepcap($walletdata),
        ));
        if ($replaced === null) {
            redirect($url, get_string('keys:replace:changed', 'local_airouter'), null, \core\output\notification::NOTIFY_WARNING);
        }
        redirect($url, get_string('keys:replaced', 'local_airouter'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $walletheading = get_string('keys:replace:heading', 'local_airouter', $names[$currenttarget]);
    $hint = (string) $current->get('hint');
    $walletintro = $hint === ''
        ? get_string('keys:replace:intro:nohint', 'local_airouter')
        : get_string('keys:replace:intro', 'local_airouter', s($hint));
}

// Registering a key where one was held before and removed. What the old key spent is
// still on record, and whether this key goes on with it is, again, the owner's to say.
if ($allowed && $action === 'register') {
    $previous = isset($targets[$targetid]) && !isset($keys[$targetid])
        ? $repository->get_previous_wallets($scope, $scopeid, $targetid)
        : [];
    if (!$previous) {
        // Nothing to ask: the ordinary form does this.
        redirect($url);
    }
    $walletform = new key_wallet_form($url, [
        'mode' => key_wallet_form::MODE_REGISTER,
        'targetid' => $targetid,
        'courseid' => $courseid,
        'target' => $names[$targetid],
        'previous' => $previous,
        'issame' => fn(string $secret): bool => $repository->is_known_secret($scope, $scopeid, $targetid, $secret),
    ]);
    if ($walletform->is_cancelled()) {
        redirect($url);
    }
    if ($walletdata = $walletform->get_data()) {
        $saved = $unlessbusy(fn() => $repository->save(
            $scope,
            $scopeid,
            $targetid,
            (string) $walletdata->secret,
            key_wallet_form::read_wallet($walletdata),
        ));
        if ($saved === null) {
            $heldmeanwhile($targetid);
        }
        redirect($url, get_string('keys:saved', 'local_airouter'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $walletheading = get_string('keys:register:heading', 'local_airouter', $names[$targetid]);
}

if ($allowed && $data = $form->get_data()) {
    $chosen = (int) $data->targetid;
    if (!isset($targets[$chosen])) {
        redirect($url);
    }
    if (isset($keys[$chosen])) {
        // Registered meanwhile, on another screen.
        $heldmeanwhile($chosen);
    }
    if (
        !$repository->is_known_secret($scope, $scopeid, $chosen, (string) $data->secret)
        && $repository->get_previous_wallets($scope, $scopeid, $chosen)
    ) {
        // A key was held here before and this is not it. Whether it is for the same
        // account is asked on a screen of its own, and the key is typed again there.
        redirect(
            new moodle_url($url, ['action' => 'register', 'targetid' => $chosen]),
            get_string('keys:register:previous:held', 'local_airouter'),
            null,
            \core\output\notification::NOTIFY_INFO,
        );
    }
    if ($unlessbusy(fn() => $repository->save($scope, $scopeid, $chosen, (string) $data->secret)) === null) {
        // Registered in the instant since the list above was read.
        $heldmeanwhile($chosen);
    }
    redirect($url, get_string('keys:saved', 'local_airouter'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
echo $OUTPUT->box($intro);

if (!$allowed) {
    // Not an error. The site has simply not said this person may bring one. If they
    // registered one before it said so, the key is still here and still theirs, so the
    // screen goes on to list it -- with nothing offered but removing it.
    echo $OUTPUT->notification(get_string('keys:notallowed', 'local_airouter'), 'info');
    if (!$keys) {
        echo $OUTPUT->footer();
        die;
    }
    echo $OUTPUT->notification(get_string('keys:notallowed:held', 'local_airouter'), 'warning');
}

if ($action === 'delete' && !$confirm) {
    $key = $repository->get_for($keyid, $scope, $scopeid);
    if ($key === null) {
        redirect($url);
    }
    echo $OUTPUT->confirm(
        get_string('keys:confirmdelete', 'local_airouter'),
        new moodle_url($url, ['action' => 'delete', 'keyid' => $keyid, 'confirm' => 1, 'sesskey' => sesskey()]),
        $url,
    );
    echo $OUTPUT->footer();
    die;
}

if ($walletform !== null) {
    echo $OUTPUT->heading($walletheading, 3);
    if ($walletintro !== '') {
        echo html_writer::div($walletintro, 'text-muted');
    }
    $walletform->display();
    echo $OUTPUT->footer();
    die;
}

if ($capform !== null) {
    echo $OUTPUT->heading(get_string('keys:cap:heading', 'local_airouter'), 3);
    // Said here as well as in the table, because this is where somebody decides the
    // number, and a limit read as a bill would be the wrong thing to decide against.
    echo html_writer::div(get_string('keys:cap:estimate', 'local_airouter'), 'text-muted');
    $capform->display();
    echo $OUTPUT->footer();
    die;
}

if ($keys) {
    echo html_writer::table(key_formatter::table($keys, $names, $url, $ledger, $currencies, $allowed));
    if ($allowed) {
        echo html_writer::div(get_string('keys:testcost', 'local_airouter'), 'text-muted');
    }
} else {
    echo $OUTPUT->notification(get_string('keys:none', 'local_airouter'), 'info');
}

if (!$allowed) {
    // Nothing further: registering one is what they may not do.
    echo $OUTPUT->footer();
    die;
}

if (!$targets) {
    // Nobody has said where a key goes for any provider on this site, so there is nothing
    // a key could be registered against.
    echo $OUTPUT->notification(get_string('keys:notargets', 'local_airouter'), 'warning');
    echo $OUTPUT->footer();
    die;
}

echo $OUTPUT->heading(get_string('keys:add', 'local_airouter'), 3);
if (array_diff_key($names, $keys)) {
    $form->display();
} else {
    echo html_writer::div(get_string('keys:add:allheld', 'local_airouter'), 'text-muted');
}

echo $OUTPUT->footer();
