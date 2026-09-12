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
$string['pluginname'] = 'AI Router';
$string['privacy:metadata'] = 'The AI Router plugin does not store any personal data. It delegates requests to other configured AI providers.';
