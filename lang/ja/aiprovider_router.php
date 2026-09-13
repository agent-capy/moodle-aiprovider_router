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

$string['check:actionconflict'] = 'AIルータより前にいるプロバイダ';
$string['check:actionconflict:found'] = 'AIルータより前に、同じアクションを処理できるプロバイダインスタンスが {$a} 件あります。';
$string['check:actionconflict:found_details'] = '次のインスタンスが先に処理して応答するため、ルータまで要求が届きません: {$a}';
$string['check:actionconflict:ok'] = 'AIルータより前に、同じアクションを処理するプロバイダはありません。';
$string['check:norouter'] = 'AIルータのインスタンスがまだ作成されていないため、検査する対象がありません。';
$string['check:routerfirst'] = 'プロバイダ順序におけるAIルータの位置';
$string['check:routerfirst:coexist'] = 'AIルータは最初に試行されるプロバイダではありません。「他のプロバイダと併用」モードでは意図した構成である可能性があります。';
$string['check:routerfirst:details'] = 'ルータは {$a->total} 件のプロバイダインスタンスのうち {$a->position} 番目です。';
$string['check:routerfirst:notfirst'] = 'AIルータは最初に試行されるプロバイダではないため、ルータに届く前に要求が処理されています。';
$string['check:routerfirst:ok'] = 'AIルータは最初に試行されるプロバイダです。';
$string['check:routerlisted'] = 'AIルータのプロバイダ順序への登録';
$string['check:routerlisted:missing'] = 'AIルータがプロバイダの順序に含まれていません。';
$string['check:routerlisted:missing_details'] = 'Moodleはサイトに設定された順序でプロバイダを試し、最初に得られた応答を返します。順序に含まれないプロバイダは最後に試されるため、ルータには他のすべてのプロバイダが処理できなかった要求しか届きません。';
$string['check:routerlisted:ok'] = 'AIルータはプロバイダの順序に含まれています。';
$string['check:singleinstance'] = 'AIルータのインスタンス数';
$string['check:singleinstance:duplicates'] = 'このサイトには AIルータのインスタンスが {$a} 件あります。実際に使われるのは1件だけです。';
$string['check:singleinstance:manage'] = 'AIプロバイダインスタンスを管理する';
$string['check:singleinstance:ok'] = 'このサイトの AIルータのインスタンスは1件です。';
$string['check:staleentries'] = 'プロバイダ順序に残った項目';
$string['check:staleentries:found'] = 'プロバイダの順序に、すでに存在しないインスタンスの項目が {$a} 件含まれています。';
$string['check:staleentries:found_details'] = '残っている項目: {$a}。プロバイダの上下移動はこの一覧内の位置を基準に動作するため、残骸があると並べ替えが効かないように見えることがあります。';
$string['check:staleentries:ok'] = 'プロバイダの順序の各項目は、いずれも実在するプロバイダインスタンスを指しています。';
$string['defaulttarget'] = '既定の委譲先';
$string['defaulttarget:none'] = '委譲できるAIプロバイダインスタンスがまだありません。先に追加してから、ここで選択してください。';
$string['defaulttarget_help'] = 'どのルールにも当てはまらないリクエストを処理するプロバイダインスタンスです。設定しない場合、ルータは委譲先を持たないため「未設定」として扱われます。';
$string['error:alltargetsfailed'] = 'AIサービスに接続できませんでした。しばらく待ってからもう一度お試しください。';
$string['error:delegationunavailable'] = 'AIサービスを利用できません。サイト管理者に連絡してください。';
$string['error:emptyresponse'] = 'AIが応答を返しませんでした。入力を短くするか、もう一度お試しください。';
$string['error:nodefaulttarget'] = 'AI機能を利用できませんでした。サイト管理者に連絡してください。';
$string['error:norulematched'] = 'この要求にはAIを利用できません。利用できるはずの場合はサイト管理者に連絡してください。';
$string['error:onlyoneinstance'] = 'AIルータのインスタンスはサイトに1つだけです。既存のインスタンスを編集してください。';
$string['mode'] = '運用モード';
$string['mode:coexist'] = '他のプロバイダと併用';
$string['mode:full'] = 'ルータのみ';
$string['mode_help'] = '「ルータのみ」は、すべてのAIリクエストがルータを経由する前提のモードです。プロバイダの優先順位でルータが先頭にある必要があります。「他のプロバイダと併用」では、ルータが処理しなかったリクエストは次のプロバイダに渡されます。';
$string['nomatch'] = 'どのルールにも一致しないとき';
$string['nomatch:decline'] = '要求を拒否する（「他のプロバイダと併用」向き）';
$string['nomatch:delegate'] = '既定の委譲先に送る（「ルータのみ」向き）';
$string['nomatch_help'] = 'ルールが一部しか用意されていないサイトでは、多くの要求がこの経路を通ります。そのため既定に任せず選んでおく価値があります。拒否するとMoodleに要求が返り、サイトの順序で次のAIプロバイダが試されます。「他のプロバイダと併用」ではこれまでどおりサイトが動き続け、「ルータのみ」では次のプロバイダがいないため要求はそこで止まります。拒否は、ルールに書いた範囲にAIの支出を限定する手段でもあります。';
$string['order:actions'] = '実行できる変更';
$string['order:after'] = '変更後';
$string['order:applied'] = 'プロバイダの順序を更新しました。';
$string['order:apply'] = 'この変更を適用する';
$string['order:before'] = '現在';
$string['order:cannotapply'] = 'この変更は適用できません。前の画面に戻ってやり直してください。';
$string['order:checks'] = '検査結果';
$string['order:clean'] = '残骸の項目を削除する';
$string['order:clean_help'] = 'プロバイダの順序に、すでに存在しないインスタンスが含まれています。削除しても使用されるプロバイダは変わりませんが、プロバイダの上下移動が期待どおりに動作するようになります。';
$string['order:confirm:clean'] = '残骸の項目を削除しますか?';
$string['order:confirm:clean_help'] = 'これはサイト全体のプロバイダの順序を変更します。変更内容は設定ログに記録されます。削除するのは、すでに存在しないインスタンスの項目だけです。先頭の空の項目は残します。その位置にあるプロバイダはMoodleが有効化・無効化を正しく処理できないためです。';
$string['order:confirm:promote'] = 'AIルータを先頭に移動しますか?';
$string['order:confirm:promote_help'] = 'これはこのプロバイダだけでなく、サイト全体のプロバイダの順序を変更します。変更内容は設定ログに記録されます。他のプロバイダどうしの順序は維持されます。先頭は意図的に空のままにします。その位置にあるプロバイダはMoodleが有効化・無効化を正しく処理できないためです。';
$string['order:current'] = '現在の順序';
$string['order:entry:empty'] = '先頭の空項目 (意図的に維持しています)';
$string['order:entry:instance'] = '{$a->name} (インスタンス {$a->id})';
$string['order:entry:router'] = 'このAIルータ: {$a->name} (インスタンス {$a->id})';
$string['order:entry:stale'] = 'インスタンス {$a} (すでに存在しません)';
$string['order:heading'] = 'AIプロバイダの順序';
$string['order:instance:keep'] = '残す: {$a->name} (インスタンス {$a->id})';
$string['order:instance:remove'] = '削除する: {$a->name} (インスタンス {$a->id})';
$string['order:nothingtodo'] = 'プロバイダの順序に変更の必要はありません。';
$string['order:notice:notfirst:coexist'] = 'このルータは最初に試行されるAIプロバイダではありません。「他のプロバイダと併用」モードでは意図した構成である可能性があります。';
$string['order:notice:notfirst:full'] = 'このルータは最初に試行されるAIプロバイダではないため、要求は別のプロバイダが処理してしまい、ルータまで届きません。';
$string['order:promote'] = 'AIルータを先頭に移動する';
$string['order:promote_help'] = 'Moodleはこの順序でプロバイダを試し、最初に得られた応答を返します。そのためルータが要求を受け取るには先頭にある必要があります。他のプロバイダどうしの順序は維持されます。';
$string['pluginname'] = 'AIルータ';
$string['privacy:metadata'] = 'AIルータプラグインは個人データを保存しません。リクエストは設定済みの他のAIプロバイダに委譲されます。';
$string['rule:error:endbeforestart'] = '有効期間の終了は開始より後にしてください。';
$string['rule:error:noname'] = '他のルールと区別できるよう、ルールに名前を付けてください。';
$string['rule:error:notarget'] = 'このルールの委譲先となるプロバイダインスタンスを選んでください。';
$string['warning:duplicateinstances'] = 'このサイトには AIルータのインスタンスが {$a} 件ありますが、実際に使われるのは1件だけです。残りは AIプロバイダの一覧から削除してください。';
