<?php
/**
 * 警告メッセージとリンクの組み立て.
 *
 * プラグイン一覧・テーマ一覧・更新一覧のいずれからも使う共通部品.
 * 出力はしないで HTML 文字列を返すだけにしてあるので、
 * 呼び出し側が「行に追記する」「自前の行に埋める」を選べる.
 *
 * @package L2D\CoreReqNotice
 */

namespace L2D\CoreReqNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * バージョン要件だけを述べる文を組み立てる.
 *
 * 「動作しません」の一文は呼び出し側の文脈ごとに変わるため、ここには含めない.
 *
 * @param string $requires 必要な WP バージョン.
 * @return string
 */
function build_requirement_text( $requires ) {
	return sprintf(
		/* translators: 1: 必要な WordPress バージョン, 2: 現在の WordPress バージョン */
		esc_html__( 'WordPress %1$s 以上が必要です (現在 %2$s)。', 'core-req-notice' ),
		esc_html( $requires ),
		esc_html( get_bloginfo( 'version' ) )
	);
}

/**
 * コア更新画面へのリンクを返す. 権限が無ければ空文字.
 *
 * @return string
 */
function build_core_update_link() {
	if ( ! current_user_can( 'update_core' ) ) {
		return '';
	}

	return sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( self_admin_url( 'update-core.php' ) ),
		esc_html__( 'WordPress の更新について', 'core-req-notice' )
	);
}

/**
 * 警告メッセージ（HTML）を組み立てる.
 *
 * コアが出した更新行やテーブル行に「追記」する用途向けの、単独で意味が通る文.
 *
 * @param string $requires 必要な WP バージョン.
 * @return string
 */
function build_message( $requires ) {
	$message = esc_html__( 'この更新は現在お使いの WordPress バージョンでは動作しません。', 'core-req-notice' )
		. build_requirement_text( $requires );

	$link = build_core_update_link();

	if ( '' !== $link ) {
		$message .= ' ' . $link;
	}

	return $message;
}

/**
 * 「バージョン X の詳細を表示」リンクを組み立てる.
 *
 * `plugins.php` / `update-core.php` はどちらも `add_thickbox()` 済みなので、
 * コアの更新行と同じ thickbox リンクをそのまま使える.
 *
 * @param array  $entry       get_incompatible_plugin_updates() のエントリ.
 * @param string $plugin_name プラグイン名（エスケープ済みであること）.
 * @return string
 */
function build_details_link( $entry, $plugin_name ) {
	if ( '' === $entry['new_version'] ) {
		return '';
	}

	$details_url = add_query_arg(
		array(
			'tab'       => 'plugin-information',
			'plugin'    => $entry['slug'],
			'section'   => 'changelog',
			'TB_iframe' => 'true',
			'width'     => 600,
			'height'    => 800,
		),
		self_admin_url( 'plugin-install.php' )
	);

	return sprintf(
		'<a href="%1$s" class="thickbox open-plugin-details-modal" aria-label="%2$s">%3$s</a>',
		esc_url( $details_url ),
		/* translators: 1: プラグイン名, 2: バージョン番号 */
		esc_attr( sprintf( __( '%1$s バージョン %2$s の詳細を表示', 'core-req-notice' ), $plugin_name, $entry['new_version'] ) ),
		/* translators: %s: バージョン番号 */
		esc_html( sprintf( __( 'バージョン %s の詳細を表示', 'core-req-notice' ), $entry['new_version'] ) )
	);
}
