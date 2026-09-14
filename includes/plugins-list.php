<?php
/**
 * プラグイン一覧（plugins.php / network/plugins.php）への表示.
 *
 * 表示の出し分けは、検出時に付けた `hidden` の印で決まる.
 *
 * - hidden = false: コアの更新行があるので、末尾へのメッセージ追記で足りる.
 * - hidden = true : コアが行を描画しないので、自前の更新行を丸ごと出す.
 *
 * @package L2D\CoreReqNotice
 */

namespace L2D\CoreReqNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'load-plugins.php', __NAMESPACE__ . '\\register_update_row_messages', 30 );

/**
 * 該当プラグインの更新行にフックを登録する.
 *
 * `wp_plugin_update_rows()` は admin_init:20 で登録されるため、
 * それより後に走る `load-plugins.php` で追加する.
 *
 * hidden = false: コアの更新行があるので、末尾へのメッセージ追記で足りる.
 * hidden = true : コアが行を描画しないので、after_plugin_row_{$file} で
 *                 自前の更新行を丸ごと出す.
 *
 * @return void
 */
function register_update_row_messages() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	foreach ( get_incompatible_plugin_updates() as $file => $entry ) {
		if ( $entry['hidden'] ) {
			add_action( "after_plugin_row_{$file}", __NAMESPACE__ . '\\render_own_update_row', 10, 2 );
		} else {
			add_action( "in_plugin_update_message-{$file}", __NAMESPACE__ . '\\render_update_row_message', 10, 2 );
		}
	}
}

/**
 * コアの更新行のメッセージ末尾に警告を追記する（hidden = false 用）.
 *
 * @param array  $plugin_data プラグインメタ情報.
 * @param object $response    更新情報オブジェクト.
 * @return void
 */
function render_update_row_message( $plugin_data, $response ) {
	$requires = isset( $response->requires ) ? (string) $response->requires : '';

	if ( '' === $requires || is_wp_version_compatible( $requires ) ) {
		return;
	}

	echo '<br>' . wp_kses_post( build_message( $requires ) );
}

/**
 * 自前の更新行を描画する（hidden = true 用）.
 *
 * マークアップは wp_plugin_update_row() の出力に合わせてある.
 * 更新は実行できないので「今すぐ更新」リンクは出さず、notice-error 固定にする.
 *
 * @param string $file        プラグインファイル.
 * @param array  $plugin_data プラグインメタ情報.
 * @return void
 */
function render_own_update_row( $file, $plugin_data ) {
	$incompatible = get_incompatible_plugin_updates();

	if ( ! isset( $incompatible[ $file ] ) ) {
		return;
	}

	$entry = $incompatible[ $file ];

	// コアの更新行と同じ許可タグでプラグイン名を通す.
	$plugin_name = wp_kses(
		isset( $plugin_data['Name'] ) ? $plugin_data['Name'] : $file,
		array(
			'abbr'    => array( 'title' => array() ),
			'acronym' => array( 'title' => array() ),
			'code'    => array(),
			'em'      => array(),
			'strong'  => array(),
		)
	);

	if ( is_network_admin() ) {
		$active_class = is_plugin_active_for_network( $file ) ? ' active' : '';
	} else {
		$active_class = is_plugin_active( $file ) ? ' active' : '';
	}

	// 型注釈のみの docblock. 説明文は不要なので短い説明の要求を無効化する.
	/** @var \WP_Plugins_List_Table $wp_list_table */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
	$wp_list_table = _get_list_table(
		'WP_Plugins_List_Table',
		array( 'screen' => get_current_screen() )
	);

	// コアの PHP 非互換メッセージと同じ組み立て（本文 → 詳細リンク → 補足）にする.
	$body = sprintf(
		/* translators: 1: プラグイン名, 2: バージョン番号 */
		esc_html__( '%1$s の新しいバージョン %2$s が公開されていますが、現在お使いの WordPress バージョンでは動作しません。', 'core-req-notice' ),
		$plugin_name,
		esc_html( $entry['new_version'] )
	);

	$parts = array_filter(
		array(
			$body . build_requirement_text( $entry['requires'] ),
			build_details_link( $entry, $plugin_name ),
			build_core_update_link(),
		)
	);

	printf(
		'<tr class="plugin-update-tr%1$s core-req-notice-tr" id="%2$s" data-slug="%3$s" data-plugin="%4$s">' .
		'<td colspan="%5$s" class="plugin-update colspanchange">' .
		'<div class="update-message notice inline notice-error notice-alt"><p>%6$s</p></div>' .
		'</td></tr>',
		esc_attr( $active_class ),
		esc_attr( $entry['slug'] . '-core-req-update' ),
		esc_attr( $entry['slug'] ),
		esc_attr( $file ),
		esc_attr( $wp_list_table->get_column_count() ),
		wp_kses_post( implode( ' ', $parts ) )
	);
}
