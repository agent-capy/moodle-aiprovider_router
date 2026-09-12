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
 * Japanese strings for aiprovider_router.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['defaulttarget'] = '既定の委譲先';
$string['defaulttarget:none'] = '委譲できるAIプロバイダインスタンスがまだありません。先に追加してから、ここで選択してください。';
$string['defaulttarget_help'] = 'どのルールにも当てはまらないリクエストを処理するプロバイダインスタンスです。設定しない場合、ルータは委譲先を持たないため「未設定」として扱われます。';
$string['error:alltargetsfailed'] = 'AIサービスに接続できませんでした。しばらく待ってからもう一度お試しください。';
$string['error:delegationunavailable'] = 'AIサービスを利用できません。サイト管理者に連絡してください。';
$string['error:emptyresponse'] = 'AIが応答を返しませんでした。入力を短くするか、もう一度お試しください。';
$string['error:nodefaulttarget'] = 'AI機能を利用できませんでした。サイト管理者に連絡してください。';
$string['error:onlyoneinstance'] = 'AIルータのインスタンスはサイトに1つだけです。既存のインスタンスを編集してください。';
$string['mode'] = '運用モード';
$string['mode:coexist'] = '他のプロバイダと併用';
$string['mode:full'] = 'ルータのみ';
$string['mode_help'] = '「ルータのみ」は、すべてのAIリクエストがルータを経由する前提のモードです。プロバイダの優先順位でルータが先頭にある必要があります。「他のプロバイダと併用」では、ルータが処理しなかったリクエストは次のプロバイダに渡されます。';
$string['pluginname'] = 'AIルータ';
$string['privacy:metadata'] = 'AIルータプラグインは個人データを保存しません。リクエストは設定済みの他のAIプロバイダに委譲されます。';
