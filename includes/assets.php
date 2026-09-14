<?php
/**
 * 見た目の調整（インライン JS）.
 *
 * - hidden = false: コアが出した notice-warning を notice-error に差し替える.
 * - hidden = true : 自前の行の上（プラグイン本体の行）に update クラスを足し、
 *                   コアの更新行と同じ「枠が繋がった」見た目にする.
 * - update-core.php のテーブル: response 由来の行のチェックボックスを無効化する.
 *
 * @package L2D\CoreReqNotice
 */

namespace L2D\CoreReqNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_print_footer_scripts', __NAMESPACE__ . '\\print_footer_script' );

/**
 * 対象画面にインラインスクリプトを出力する.
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

	$incompatible = get_incompatible_plugin_updates();

	if ( empty( $incompatible ) ) {
		return;
	}

	$data = array();
	foreach ( $incompatible as $file => $entry ) {
		$data[] = array(
			'file'    => $file,
			'hidden'  => $entry['hidden'],
			'message' => build_message( $entry['requires'] ),
		);
	}
	?>
	<script>
	( function () {
		var items = <?php echo wp_json_encode( $data ); ?>;

		items.forEach( function ( item ) {
			if ( item.hidden ) {
				// 自前の更新行を出したケース: プラグイン本体の行に update クラスを付けて
				// 下の行と枠線を繋げる（コアは response に無いと付けてくれない）.
				document.querySelectorAll( 'tr[data-plugin]:not(.plugin-update-tr)' ).forEach( function ( row ) {
					if ( row.getAttribute( 'data-plugin' ) === item.file ) {
						row.classList.add( 'update' );
					}
				} );
				return;
			}

			// プラグイン一覧: コアの更新行を notice-error にし、「今すぐ更新」を外す.
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

			// 更新一覧: 注記を追記し、チェックボックスを外して無効化する.
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
