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
use local_airouter\retention_policy;
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
        global $DB;
        $errors = parent::validation($data, $files);

        // The rules about how long the record is kept live with the policy, which is
        // what saves the setting whichever screen or script asks; the form only shows
        // what it says, against the fields of the same names.
        $errors += (new retention_policy($DB))->problems(
            (int) ($data['logretentiondays'] ?? 0),
            (int) ($data['summaryretentiondays'] ?? 0),
        );

        // A warning at or past the budget is the budget, said twice.
        $share = (int) ($data['budgetnotifyshare'] ?? 0);
        if ($share < 0 || $share > 99) {
            $errors['budgetnotifyshare'] = get_string('usage:error:notifyshare', 'local_airouter');
        }

        return $errors;
    }
}
