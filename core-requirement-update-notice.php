<?php
/**
 * Plugin Name:       Core Requirement Update Notice
 * Description:       更新は提供されているが、新バージョンが要求する WordPress コアバージョンを満たしていないプラグインについて、プラグイン一覧／更新一覧に PHP 非互換時と同等の警告を表示します。
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 *
 * @package L2D\CoreReqNotice
 */

namespace L2D\CoreReqNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 更新が提供されているが、コアバージョン要件を満たさないプラグインを返す。
 *
 * update_plugins トランジェントの response には wp.org の update-check API が返す
 * `requires`（新バージョンが必要とする WP バージョン）が含まれる。
 * コアはこれを表示判定に使っていないため、ここで自前に評価する。
 *
 * @return array<string, string> plugin_file => 必要な WP バージョン。
 */
function get_incompatible_updates() {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$cache   = array();
	$updates = get_site_transient( 'update_plugins' );

	if ( empty( $updates->response ) || ! is_array( $updates->response ) ) {
		return $cache;
	}

	foreach ( $updates->response as $file => $response ) {
		$requires = isset( $response->requires ) ? (string) $response->requires : '';

		if ( '' !== $requires && ! is_wp_version_compatible( $requires ) ) {
			$cache[ $file ] = $requires;
		}
	}

	return $cache;
}

/**
 * 警告メッセージ（HTML）を組み立てる。
 *
 * @param string $requires 必要な WP バージョン。
 * @return string
 */
function build_message( $requires ) {
	$message = sprintf(
		/* translators: 1: 必要な WordPress バージョン, 2: 現在の WordPress バージョン */
		__( 'この更新は現在お使いの WordPress バージョンでは動作しません。WordPress %1$s 以上が必要です (現在 %2$s)。', 'core-req-notice' ),
		$requires,
		get_bloginfo( 'version' )
	);

	if ( current_user_can( 'update_core' ) ) {
		$message .= ' ' . sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self_admin_url( 'update-core.php' ) ),
			esc_html__( 'WordPress の更新について', 'core-req-notice' )
		);
	}

	return $message;
}

/* -------------------------------------------------------------------------
 * プラグイン一覧（plugins.php / network/plugins.php）
 * ---------------------------------------------------------------------- */

add_action( 'load-plugins.php', __NAMESPACE__ . '\\register_update_row_messages', 30 );

/**
 * 該当プラグインの更新行にメッセージ出力フックを登録する。
 *
 * wp_plugin_update_rows() は admin_init:20 で登録されるため、
 * それより後に走る load-plugins.php で追加する。
 *
 * @return void
 */
function register_update_row_messages() {
	foreach ( array_keys( get_incompatible_updates() ) as $file ) {
		add_action( "in_plugin_update_message-{$file}", __NAMESPACE__ . '\\render_update_row_message', 10, 2 );
	}
}

/**
 * 更新行のメッセージ末尾に警告を追記する。
 *
 * @param array  $plugin_data プラグインメタ情報。
 * @param object $response    更新情報オブジェクト。
 * @return void
 */
function render_update_row_message( $plugin_data, $response ) {
	$requires = isset( $response->requires ) ? (string) $response->requires : '';

	if ( '' === $requires || is_wp_version_compatible( $requires ) ) {
		return;
	}

	echo '<br>' . wp_kses_post( build_message( $requires ) );
}

/* -------------------------------------------------------------------------
 * 見た目の調整と更新一覧（update-core.php）
 * update-core.php の list_plugin_updates() にはフィルターが無いため JS で補う。
 * ---------------------------------------------------------------------- */

add_action( 'admin_print_footer_scripts', __NAMESPACE__ . '\\print_footer_script' );

/**
 * 対象画面にインラインスクリプトを出力する。
 *
 * @return void
 */
function print_footer_script() {
	$screen = get_current_screen();

	if ( ! $screen ) {
		return;
	}

	$targets = array( 'plugins', 'plugins-network', 'update-core', 'update-core-network' );

	if ( ! in_array( $screen->id, $targets, true ) ) {
		return;
	}

	$incompatible = get_incompatible_updates();

	if ( empty( $incompatible ) ) {
		return;
	}

	$data = array();
	foreach ( $incompatible as $file => $requires ) {
		$data[] = array(
			'file'    => $file,
			'message' => build_message( $requires ),
		);
	}
	?>
	<script>
	( function () {
		var items = <?php echo wp_json_encode( $data ); ?>;

		items.forEach( function ( item ) {
			// プラグイン一覧: 更新行を notice-error にし、「今すぐ更新」を外す。
			document.querySelectorAll( 'tr.plugin-update-tr' ).forEach( function ( row ) {
				if ( row.getAttribute( 'data-plugin' ) !== item.file ) {
					return;
				}

				var notice = row.querySelector( '.update-message' );
				if ( notice ) {
					notice.classList.remove( 'notice-warning' );
					notice.classList.add( 'notice-error' );
				}

				var link = row.querySelector( 'a.update-link' );
				if ( link ) {
					link.remove();
				}
			} );

			// 更新一覧: 注記を追記し、チェックボックスを外して無効化する。
			document.querySelectorAll( '#update-plugins-table input[name="checked[]"]' ).forEach( function ( checkbox ) {
				if ( checkbox.value !== item.file ) {
					return;
				}

				checkbox.checked  = false;
				checkbox.disabled = true;

				var title = checkbox.closest( 'tr' ).querySelector( '.plugin-title p' );
				if ( ! title ) {
					return;
				}

				title.appendChild( document.createElement( 'br' ) );

				var span = document.createElement( 'span' );
				span.innerHTML = item.message;
				title.appendChild( span );
			} );
		} );
	} )();
	</script>
	<?php
}

/* -------------------------------------------------------------------------
 * 自動更新の抑止（任意）
 * 非互換のまま自動更新が走ると毎回失敗するため、対象外にする。
 * ---------------------------------------------------------------------- */

add_filter( 'auto_update_plugin', __NAMESPACE__ . '\\block_incompatible_auto_update', 10, 2 );

/**
 * コアバージョン非互換のプラグインを自動更新の対象から外す。
 *
 * @param bool|null $update 自動更新するかどうか。
 * @param object    $item   更新情報オブジェクト。
 * @return bool|null
 */
function block_incompatible_auto_update( $update, $item ) {
	$requires = isset( $item->requires ) ? (string) $item->requires : '';

	if ( '' !== $requires && ! is_wp_version_compatible( $requires ) ) {
		return false;
	}

	return $update;
}
