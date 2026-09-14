<?php
/**
 * Plugin Name:       Core Requirement Update Notice
 * Plugin URI:        https://github.com/lunaluna/core-requirement-update-notice
 * Description:       更新は提供されているが、新バージョンが要求する WordPress コアバージョンを満たしていないプラグイン・テーマについて、プラグイン一覧／テーマ一覧／更新一覧に PHP 非互換時と同等の警告を表示します.
 * Version:           1.4.0
 * Requires at least: 5.2
 * Tested up to:      6.8
 * Requires PHP:      7.4
 * Author:            lunaluna_dev
 * Author URI:        https://profiles.wordpress.org/lunaluna_dev/
 * Update URI:        false
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       core-req-notice
 *
 * @package L2D\CoreReqNotice
 */

namespace L2D\CoreReqNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 機能ごとのファイルを読み込む.
 *
 * クラスを持たない名前空間付き関数の集まりなので、オートローダーは使わず
 * 素の require_once で並べる. 各ファイルは読み込み時に自分の add_action /
 * add_filter を済ませるため、ここでの登録処理は不要.
 *
 * 読み込み順は依存の向きに合わせてある.
 *
 *   detection … 検出（他のどれにも依存しない）
 *   messages  … 文言の組み立て（detection の結果を受け取るだけ）
 *   以降       … 画面ごとの表示。上の 2 つを使う
 *
 * ただし実際の呼び出しはすべてフックの中で起きるので、
 * この順序は「読んで分かりやすいように」揃えているだけで、動作上の制約ではない.
 */
require_once __DIR__ . '/includes/detection.php';
require_once __DIR__ . '/includes/messages.php';
require_once __DIR__ . '/includes/plugins-list.php';
require_once __DIR__ . '/includes/themes-list.php';
require_once __DIR__ . '/includes/update-core.php';
require_once __DIR__ . '/includes/assets.php';
require_once __DIR__ . '/includes/auto-update.php';

/*
 * GitHub Releases からの自動更新.
 *
 * 同梱ライブラリ lib/l2d-updater のローダーを読み込んで自分を登録する.
 * `require_once` ではなく `require` なのは、戻り値のクロージャを受け取る必要が
 * あるため. 複数のプラグインが別バージョンのコピーを同梱していても、実行時に
 * 最も新しいコピーだけが起動する（バージョン交渉）仕組みになっている.
 *
 * ベンダーコピーは git subtree で配布専用タグ dist-X.Y.Z から取り込んでいる.
 * 直接編集せず、上流 https://github.com/lunaluna/l2d-wp-github-update-lib を
 * 更新して取り込み直すこと. .github/workflows/release.yml が参照するタグと
 * ベンダーコピーの版は必ず揃える（片方だけ上げると版が食い違う）.
 *
 * cache_key / filter_prefix は既存の独自更新機構から移行する場合の後方互換用の
 * 設定なので、新規導入のこのプラグインでは渡さず既定値に任せる.
 * wp.org の公式更新ルートは使わないため、ヘッダーに Update URI: false がある.
 */
$l2dwpghul_updater_register = require plugin_dir_path( __FILE__ ) . 'lib/l2d-updater/loader.php';
$l2dwpghul_updater_register(
	array(
		'plugin_file' => __FILE__,
		'github_repo' => 'lunaluna/core-requirement-update-notice',
	)
);
