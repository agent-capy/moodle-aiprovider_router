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

namespace local_airouter\form;

use local_airouter\budget_notifier;
use local_airouter\rule_repository;
use local_airouter\spend_ledger;
use local_airouter\usage_aggregator;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Sets how long the detail behind the summaries is kept.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage_settings_form extends \moodleform {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement(
            'text',
            'logretentiondays',
            get_string('usage:retention', 'local_airouter'),
            ['size' => 8],
        );
        $mform->setType('logretentiondays', PARAM_INT);
        $mform->setDefault('logretentiondays', usage_aggregator::DEFAULT_RETENTION);
        $mform->addHelpButton('logretentiondays', 'usage:retention', 'local_airouter');

        $mform->addElement(
            'text',
            'summaryretentiondays',
            get_string('usage:summaryretention', 'local_airouter'),
            ['size' => 8],
        );
        $mform->setType('summaryretentiondays', PARAM_INT);
        $mform->setDefault('summaryretentiondays', usage_aggregator::DEFAULT_SUMMARY_RETENTION);
        $mform->addHelpButton('summaryretentiondays', 'usage:summaryretention', 'local_airouter');

        $mform->addElement('advcheckbox', 'budgetnotify', get_string('usage:notify', 'local_airouter'));
        $mform->setType('budgetnotify', PARAM_BOOL);
        $mform->setDefault('budgetnotify', 1);
        $mform->addHelpButton('budgetnotify', 'usage:notify', 'local_airouter');

        $mform->addElement(
            'text',
            'budgetnotifyshare',
            get_string('usage:notifyshare', 'local_airouter'),
            ['size' => 8],
        );
        $mform->setType('budgetnotifyshare', PARAM_INT);
        $mform->setDefault('budgetnotifyshare', budget_notifier::DEFAULT_SHARE);
        $mform->addHelpButton('budgetnotifyshare', 'usage:notifyshare', 'local_airouter');
        $mform->hideIf('budgetnotifyshare', 'budgetnotify');

        $this->add_action_buttons(false, get_string('savechanges'));
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        foreach (['logretentiondays', 'summaryretentiondays'] as $field) {
            if ((int) ($data[$field] ?? 0) < 0) {
                $errors[$field] = get_string('usage:error:retention', 'local_airouter');
            }
        }

        // Summaries are what the detail rows leave behind, so keeping them for less time
        // than the detail asks for the impossible: reports read the summaries for the
        // older half of any period, and those days would simply read as empty.
        $detail = (int) ($data['logretentiondays'] ?? 0);
        $summary = (int) ($data['summaryretentiondays'] ?? 0);
        if ($detail > 0 && $summary > 0 && $summary < $detail) {
            $errors['summaryretentiondays'] = get_string('usage:error:summaryretention', 'local_airouter');
        }

        // A warning at or past the budget is the budget, said twice.
        $share = (int) ($data['budgetnotifyshare'] ?? 0);
        if ($share < 0 || $share > 99) {
            $errors['budgetnotifyshare'] = get_string('usage:error:notifyshare', 'local_airouter');
        }

        // Budgets are worked out from what is still stored. Throwing away history the
        // budgets still reach back into does not make the figure unknown, which is the
        // one thing this plugin is careful about everywhere else -- it makes it a
        // smaller number, so a limit that has been reached is under the limit again
        // and the requests it was stopping start going through.
        $reach = $this->longest_budget_days();
        if ($reach > 0 && $summary > 0 && $summary < $reach) {
            $errors['summaryretentiondays'] = get_string(
                'usage:error:budgetretention',
                'local_airouter',
                $reach,
            );
        }

        return $errors;
    }

    /**
     * How far back the furthest limit on this site has to be able to see.
     *
     * Both kinds count: the budgets a rule sets, and the limits people put on keys
     * they brought. The second was missed at first, and a site with no rule budgets
     * and a monthly key limit could throw away the month the limit was about.
     *
     * @return int Days, or zero where nothing on the site sets a limit.
     */
    protected function longest_budget_days(): int {
        global $DB;

        return spend_ledger::longest_reach_days($DB);
    }
}
