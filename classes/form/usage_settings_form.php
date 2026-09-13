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

namespace aiprovider_router\form;

use aiprovider_router\usage_aggregator;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Sets how long the detail behind the summaries is kept.
 *
 * @package    aiprovider_router
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
            get_string('usage:retention', 'aiprovider_router'),
            ['size' => 8],
        );
        $mform->setType('logretentiondays', PARAM_INT);
        $mform->setDefault('logretentiondays', usage_aggregator::DEFAULT_RETENTION);
        $mform->addHelpButton('logretentiondays', 'usage:retention', 'aiprovider_router');

        $this->add_action_buttons(false, get_string('savechanges'));
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if ((int) ($data['logretentiondays'] ?? 0) < 0) {
            $errors['logretentiondays'] = get_string('usage:error:retention', 'aiprovider_router');
        }

        return $errors;
    }
}
