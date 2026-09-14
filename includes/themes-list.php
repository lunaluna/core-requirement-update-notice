<?php
/**
 * テーマ一覧（themes.php）への表示.
 *
 * テーマ側はコアがすでに「新しいバージョンがあるが WP 非互換」の文言を持っている
 * （themes.php の updateResponse.compatibleWP 分岐 / #tmpl-theme・#tmpl-theme-single）.
 * 分岐の手前の hasUpdate が false になっているだけなので、
 * wp_prepare_themes_for_js で以下を立て直せばコア自身の表示に乗る.
 *
 *   hasUpdate                    = true   … 更新ありの分岐に入れる
 *   updateResponse.compatibleWP  = false  … 「WP 非互換」の枝に落とす
 *   hasPackage                   = false  … 更新パッケージは提示しない
 *
 * hasUpdate の分岐はすべて updateResponse で二重にガードされているので、
 * 「今すぐ更新」ボタンが出ることはない. 自前のマークアップも不要.
 *
 * @package L2D\CoreReqNotice
 */

namespace L2D\CoreReqNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wp_prepare_themes_for_js', __NAMESPACE__ . '\\mark_incompatible_theme_updates' );

/**
 * テーマ一覧用のデータに「更新あり・ただし WP 非互換」の印を付ける.
 *
 * @param array $prepared_themes テーマ slug をキーにした表示用データ.
 * @return array
 */
function mark_incompatible_theme_updates( $prepared_themes ) {
	if ( ! is_array( $prepared_themes ) ) {
		return $prepared_themes;
	}

	foreach ( get_hidden_theme_updates() as $slug => $entry ) {
		if ( ! isset( $prepared_themes[ $slug ] ) ) {
			continue;
		}

		$prepared_themes[ $slug ]['hasUpdate']  = true;
		$prepared_themes[ $slug ]['hasPackage'] = false;

		// compatiblePHP まで false にすると文言が「WP と PHP の両方」になってしまうので触らない.
		$prepared_themes[ $slug ]['updateResponse']['compatibleWP'] = false;
	}

	return $prepared_themes;
}
