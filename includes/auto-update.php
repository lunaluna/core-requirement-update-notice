<?php
/**
 * 自動更新の抑止（任意）.
 *
 * 非互換のまま自動更新が走ると毎回失敗するため、対象外にする.
 *
 * @package L2D\CoreReqNotice
 */

namespace L2D\CoreReqNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'auto_update_plugin', __NAMESPACE__ . '\\block_incompatible_auto_update', 10, 2 );
add_filter( 'auto_update_theme', __NAMESPACE__ . '\\block_incompatible_auto_update', 10, 2 );

/**
 * コアバージョン非互換のプラグイン・テーマを自動更新の対象から外す.
 *
 * 管理画面以外（wp-cron）でも走るため、get_incompatible_plugin_updates() 等には依存せず
 * 渡された更新情報だけで判定する.
 * テーマ側はトランジェント上は配列だが、このフィルターにはオブジェクトで渡ってくる
 * （WP_Automatic_Updater::update( 'theme', (object) $theme )）.
 * 呼び出し元によっては配列のまま渡る可能性もあるため、どちらも受け付ける.
 *
 * @param bool|null    $update 自動更新するかどうか.
 * @param object|array $item   更新情報.
 * @return bool|null
 */
function block_incompatible_auto_update( $update, $item ) {
	$data     = (array) $item;
	$requires = isset( $data['requires'] ) ? (string) $data['requires'] : '';

	if ( '' !== $requires && ! is_wp_version_compatible( $requires ) ) {
		return false;
	}

	return $update;
}
