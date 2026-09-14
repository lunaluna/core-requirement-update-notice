<?php
/**
 * 非互換な更新の検出.
 *
 * 更新トランジェントを走査して「新しい版は提示されているが、
 * サイトの WordPress バージョンが要件を満たしていない」ものを拾う.
 * 表示側（プラグイン一覧・テーマ一覧・更新一覧）はすべてここの結果を使う.
 *
 * @package L2D\CoreReqNotice
 */

namespace L2D\CoreReqNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 更新が提供されているが、コアバージョン要件を満たさないプラグインを返す.
 *
 * `update_plugins` トランジェントには 2 つの置き場所がある.
 *
 * - `response` : コアが「更新あり」として扱うもの. プラグイン一覧の更新行も
 *                更新一覧のテーブル行も、コアがここからしか作らない.
 * - `no_update`: コアが「更新なし」として扱うもの. 行は一切描画されない.
 *
 * ここが v1.0.0 の取りこぼしだった. wp.org の update-check API は、
 * 新バージョンが要求する WP バージョンをサイトが満たしていない場合、
 * そのプラグインを `response` ではなく `no_update` に入れて返す
 * （`new_version` にはインストール済みより新しい版が入ったまま）.
 * PHP 要件を満たさない場合は `response` に入ったまま返ってくるため、
 * コアの PHP 警告は表示されるのに WP 要件の場合は行ごと消える、という差が出る.
 *
 * 実測（2026-09-14 / WP 6.8.8 のローカル環境）:
 *   snow-monkey-blocks 24.1.12 インストール済み、
 *   no_update に new_version=26.0.2 / requires=7.1 のエントリが存在し、
 *   response 側には一切現れなかった.
 *
 * そのため両方を走査し、`no_update` 由来のものは「コアが行を描画しない」
 * 印（hidden = true）を付けて返す. 呼び出し側はこの印を見て、
 * メッセージの追記で済ませるか、行ごと自前で描画するかを切り替える.
 *
 * @return array<string, array{requires:string, new_version:string, slug:string, icons:array, hidden:bool}>
 */
function get_incompatible_plugin_updates() {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$cache   = array();
	$updates = get_site_transient( 'update_plugins' );

	if ( ! is_object( $updates ) ) {
		return $cache;
	}

	// no_update 側は「インストール済みより新しいか」の判定にインストール版が要る.
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$installed = get_plugins();

	// response（コアが行を描画する）→ hidden = false.
	foreach ( (array) ( $updates->response ?? array() ) as $file => $item ) {
		$entry = build_plugin_entry( $file, $item, false );

		if ( $entry ) {
			$cache[ $file ] = $entry;
		}
	}

	// no_update（コアが行を描画しない）→ hidden = true.
	foreach ( (array) ( $updates->no_update ?? array() ) as $file => $item ) {
		if ( isset( $cache[ $file ] ) ) {
			continue;
		}

		$new_version = isset( $item->new_version ) ? (string) $item->new_version : '';
		$current     = isset( $installed[ $file ]['Version'] ) ? (string) $installed[ $file ]['Version'] : '';

		// no_update には「本当に最新」のプラグインも全件入るので、
		// インストール済みより新しい版が提示されているものだけを拾う.
		if ( '' === $new_version || '' === $current || ! version_compare( $new_version, $current, '>' ) ) {
			continue;
		}

		$entry = build_plugin_entry( $file, $item, true );

		if ( $entry ) {
			$cache[ $file ] = $entry;
		}
	}

	return $cache;
}

/**
 * 更新情報オブジェクトから、非互換エントリの配列を組み立てる.
 *
 * WP バージョン要件を満たしている場合は null を返す（対象外）.
 *
 * @param string $file   プラグインファイル（plugins ディレクトリからの相対パス）.
 * @param object $item   更新情報オブジェクト.
 * @param bool   $hidden コアが更新行を描画しないエントリかどうか.
 * @return array|null
 */
function build_plugin_entry( $file, $item, $hidden ) {
	$requires = isset( $item->requires ) ? (string) $item->requires : '';

	if ( '' === $requires || is_wp_version_compatible( $requires ) ) {
		return null;
	}

	return array(
		'requires'    => $requires,
		'new_version' => isset( $item->new_version ) ? (string) $item->new_version : '',
		'slug'        => isset( $item->slug ) ? (string) $item->slug : dirname( $file ),
		'icons'       => isset( $item->icons ) ? (array) $item->icons : array(),
		'hidden'      => (bool) $hidden,
	);
}

/**
 * コアが握り潰しているテーマ更新（no_update 側）のうち、コア要件を満たさないものを返す.
 *
 * テーマもプラグインとまったく同じで、WP 要件を満たさない更新は
 * update_themes トランジェントの `no_update` に回されて表示されない.
 *
 * 実測（2026-09-14 / WP 6.8.8 のローカル環境）:
 *   snow-monkey 29.1.6 インストール済み、
 *   no_update に new_version=31.0.2 / requires=7.1 のエントリが存在し、
 *   response 側は空だった.
 *
 * プラグインと違い、コア側の表示ロジックは WP 非互換に対応済み
 * （themes.php の updateResponse.compatibleWP 分岐、list_theme_updates() の
 * $compatible_wp 判定）. 足りないのはデータだけなので、`response` 側は
 * コアに任せ、ここでは `no_update` に隠れているものだけを返す.
 *
 * なお update_themes のエントリはプラグインと違いオブジェクトではなく配列.
 *
 * @return array<string, array{requires:string, new_version:string}>
 */
function get_hidden_theme_updates() {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$cache   = array();
	$updates = get_site_transient( 'update_themes' );

	if ( ! is_object( $updates ) || empty( $updates->no_update ) ) {
		return $cache;
	}

	foreach ( (array) $updates->no_update as $slug => $item ) {
		$item     = (array) $item;
		$requires = isset( $item['requires'] ) ? (string) $item['requires'] : '';

		if ( '' === $requires || is_wp_version_compatible( $requires ) ) {
			continue;
		}

		$new_version = isset( $item['new_version'] ) ? (string) $item['new_version'] : '';
		$theme       = wp_get_theme( $slug );
		$current     = $theme->exists() ? (string) $theme->get( 'Version' ) : '';

		// no_update には「本当に最新」のテーマも全件入るので、
		// インストール済みより新しい版が提示されているものだけを拾う.
		if ( '' === $new_version || '' === $current || ! version_compare( $new_version, $current, '>' ) ) {
			continue;
		}

		$cache[ $slug ] = array(
			'requires'    => $requires,
			'new_version' => $new_version,
		);
	}

	return $cache;
}
