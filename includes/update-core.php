<?php
/**
 * 更新一覧（update-core.php）への表示: no_update 由来の分の一覧.
 *
 * `list_plugin_updates()` / `list_theme_updates()` はどちらも response しか見ないため
 * （get_plugin_updates() / get_theme_updates()）、隠れている分は 1 行も出ない.
 * どちらの関数にもフィルターが無いので、テーブル群の直後に発火する
 * core_upgrade_preamble で独自のセクションとして補う.
 *
 * ただし core_upgrade_preamble は「コア・プラグイン・テーマ・翻訳」を
 * すべて出し切った後に発火するため、そのままだと 2 つともページ最下部に出る.
 * 節と節の間には PHP のフックが一切無いので、それぞれをマーカーで囲んでおき、
 * ページの出力バッファ上で本来の位置へ移動させる.
 *
 *   プラグイン節 → 「テーマ」見出しの直前
 *   テーマ節     → 「翻訳」見出しの直前
 *
 * 結果として「プラグイン → 補完(プラグイン) → テーマ → 補完(テーマ) → 翻訳」になる.
 *
 * @package L2D\CoreReqNotice
 */

namespace L2D\CoreReqNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 移動対象セクションのマーカー. HTML コメントなので残っても無害.
 *
 * 添字が種別（plugins / themes）、値が array( 開始マーカー, 終了マーカー, 挿入先候補の見出し ).
 * 見出しはコアの訳語をそのまま使うので、翻訳の有無に関わらず一致する.
 *
 * @return array<string, array{open:string, close:string, anchors:string[]}>
 */
function get_section_markers() {
	return array(
		'plugins' => array(
			'open'    => '<!--core-req-notice:plugins-->',
			'close'   => '<!--/core-req-notice:plugins-->',
			// テキストドメインを渡さないのは意図的. コア本体の訳語を引くためで、
			// 自前のドメインに切り替えると見出しと一致しなくなる.
			'anchors' => array( __( 'Themes' ), __( 'Translations' ) ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- コアの訳語をそのまま引く.
		),
		'themes'  => array(
			'open'    => '<!--core-req-notice:themes-->',
			'close'   => '<!--/core-req-notice:themes-->',
			// 同上. コア本体の訳語を引くためテキストドメインを渡さない.
			'anchors' => array( __( 'Translations' ) ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- コアの訳語をそのまま引く.
		),
	);
}

add_action( 'core_upgrade_preamble', __NAMESPACE__ . '\\render_update_core_sections' );

/**
 * 更新一覧に「WordPress の更新が必要なプラグイン／テーマ」セクションを出力する.
 *
 * @return void
 */
function render_update_core_sections() {
	render_update_core_plugin_section();
	render_update_core_theme_section();
}

/**
 * 更新一覧に「WordPress の更新が必要なプラグイン」セクションを出力する.
 *
 * @return void
 */
function render_update_core_plugin_section() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$hidden = array_filter(
		get_incompatible_plugin_updates(),
		static function ( $entry ) {
			return $entry['hidden'];
		}
	);

	if ( empty( $hidden ) ) {
		return;
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$installed = get_plugins();
	$markers   = get_section_markers();

	echo $markers['plugins']['open']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 固定のマーカー文字列.
	?>
	<h2>
		<?php
		printf(
			'%s <span class="count">(%d)</span>',
			esc_html__( 'WordPress の更新が必要なプラグイン', 'core-req-notice' ),
			count( $hidden )
		);
		?>
	</h2>
	<p><?php esc_html_e( '以下のプラグインには新しいバージョンがありますが、現在の WordPress バージョンでは動作しないため更新できません。WordPress 本体を更新すると更新できるようになります。', 'core-req-notice' ); ?></p>
	<table class="widefat updates-table" id="core-req-notice-plugins-table">
		<tbody class="plugins">
		<?php foreach ( $hidden as $file => $entry ) : ?>
			<?php
			$plugin_name = isset( $installed[ $file ]['Name'] ) ? $installed[ $file ]['Name'] : $file;
			$current     = isset( $installed[ $file ]['Version'] ) ? $installed[ $file ]['Version'] : '';

			// アイコンはコアの更新一覧と同じ優先順で選ぶ.
			$icon = '<span class="dashicons dashicons-admin-plugins"></span>';
			foreach ( array( 'svg', '2x', '1x', 'default' ) as $size ) {
				if ( ! empty( $entry['icons'][ $size ] ) ) {
					$icon = '<img src="' . esc_url( $entry['icons'][ $size ] ) . '" alt="" />';
					break;
				}
			}
			?>
			<tr>
				<td class="check-column"></td>
				<td class="plugin-title"><p>
					<?php echo wp_kses_post( $icon ); ?>
					<strong><?php echo esc_html( $plugin_name ); ?></strong>
					<?php
					printf(
						/* translators: 1: インストール済みバージョン, 2: 新しいバージョン */
						esc_html__( 'バージョン %1$s がインストールされていますが、すでに %2$s が公開されています。', 'core-req-notice' ),
						esc_html( $current ),
						esc_html( $entry['new_version'] )
					);

					echo ' ' . wp_kses_post( build_details_link( $entry, $plugin_name ) );
					echo '<br>' . wp_kses_post( build_message( $entry['requires'] ) );
					?>
				</p></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	echo $markers['plugins']['close']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 固定のマーカー文字列.
}

/**
 * 更新一覧に「WordPress の更新が必要なテーマ」セクションを出力する.
 *
 * 行のマークアップは list_theme_updates() に合わせてある
 * （スクリーンショットは 85x64 の updates-table-screenshot）.
 *
 * @return void
 */
function render_update_core_theme_section() {
	if ( ! current_user_can( 'update_themes' ) ) {
		return;
	}

	$hidden = get_hidden_theme_updates();

	if ( empty( $hidden ) ) {
		return;
	}

	$markers = get_section_markers();

	echo $markers['themes']['open']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 固定のマーカー文字列.
	?>
	<h2>
		<?php
		printf(
			'%s <span class="count">(%d)</span>',
			esc_html__( 'WordPress の更新が必要なテーマ', 'core-req-notice' ),
			count( $hidden )
		);
		?>
	</h2>
	<p><?php esc_html_e( '以下のテーマには新しいバージョンがありますが、現在の WordPress バージョンでは動作しないため更新できません。WordPress 本体を更新すると更新できるようになります。', 'core-req-notice' ); ?></p>
	<table class="widefat updates-table" id="core-req-notice-themes-table">
		<tbody class="plugins">
		<?php foreach ( $hidden as $slug => $entry ) : ?>
			<?php
			$theme = wp_get_theme( $slug );

			if ( ! $theme->exists() ) {
				continue;
			}

			$screenshot = $theme->get_screenshot();
			?>
			<tr>
				<td class="check-column"></td>
				<td class="plugin-title"><p>
					<?php if ( $screenshot ) : ?>
						<img src="<?php echo esc_url( $screenshot . '?ver=' . $theme->get( 'Version' ) ); ?>" width="85" height="64" class="updates-table-screenshot" alt="" />
					<?php endif; ?>
					<strong><?php echo esc_html( $theme->display( 'Name', false ) ); ?></strong>
					<?php
					printf(
						/* translators: 1: インストール済みバージョン, 2: 新しいバージョン */
						esc_html__( 'バージョン %1$s がインストールされていますが、すでに %2$s が公開されています。', 'core-req-notice' ),
						esc_html( $theme->get( 'Version' ) ),
						esc_html( $entry['new_version'] )
					);

					echo '<br>' . wp_kses_post( build_message( $entry['requires'] ) );
					?>
				</p></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	echo $markers['themes']['close']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 固定のマーカー文字列.
}

add_action( 'admin_head', __NAMESPACE__ . '\\maybe_buffer_update_core', 0 );

/**
 * 更新一覧の本文出力をバッファリングする.
 *
 * `admin-header.php` の中で発火する admin_head は、テーブル群が描画される前.
 * ここでバッファを開始しておき、shutdown 時のフラッシュでコールバックが
 * ページ全体の HTML を受け取れるようにする.
 *
 * do-plugin-upgrade などの進捗をストリーム出力するアクションでは
 * バッファリングすると表示が固まるので、一覧画面のときだけ開始する.
 *
 * @return void
 */
function maybe_buffer_update_core() {
	$screen = get_current_screen();

	if ( ! $screen || ! in_array( $screen->id, array( 'update-core', 'update-core-network' ), true ) ) {
		return;
	}

	// update-core.php と同じ既定値で action を解決する.
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'upgrade-core'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示位置の判定のみ.

	if ( 'upgrade-core' !== $action ) {
		return;
	}

	// 出すものが無いならバッファリング自体を行わない.
	$has_plugins = (bool) array_filter(
		get_incompatible_plugin_updates(),
		static function ( $entry ) {
			return $entry['hidden'];
		}
	);

	if ( ! $has_plugins && ! get_hidden_theme_updates() ) {
		return;
	}

	ob_start( __NAMESPACE__ . '\\move_sections_into_place' );
}

/**
 * マーカーで囲んだ各セクションを、本来あるべき見出しの直前へ移動する.
 *
 * 見出しが見つからない場合（該当する節がそもそも描画されていない等）は
 * そのセクションだけ移動を諦め、core_upgrade_preamble が出力した
 * 元の位置（ページ末尾）に残す. 表示自体が消えることはない.
 *
 * @param string $html バッファリングされたページ HTML.
 * @return string
 */
function move_sections_into_place( $html ) {
	foreach ( get_section_markers() as $marker ) {
		$html = move_one_section( $html, $marker );
	}

	return $html;
}

/**
 * セクション 1 つ分を移動する.
 *
 * @param string $html   対象 HTML.
 * @param array  $marker get_section_markers() の 1 要素.
 * @return string
 */
function move_one_section( $html, $marker ) {
	$start = strpos( $html, $marker['open'] );

	if ( false === $start ) {
		return $html;
	}

	$end = strpos( $html, $marker['close'], $start );

	if ( false === $end ) {
		return $html;
	}

	$end += strlen( $marker['close'] );

	$section = substr( $html, $start, $end - $start );

	// 先にセクションを抜き出しておく. 自分自身の見出しにアンカーが当たるのを防ぐため.
	$rest   = substr( $html, 0, $start ) . substr( $html, $end );
	$offset = find_section_anchor( $rest, $marker['anchors'] );

	if ( null === $offset ) {
		return $html;
	}

	return substr( $rest, 0, $offset ) . $section . substr( $rest, $offset );
}

/**
 * 挿入位置（見出しの開始オフセット）を探す.
 *
 * `list_theme_updates()` 等の見出しは、更新が無ければ `<h2>テーマ</h2>`、
 * あれば `<h2>\n\tテーマ <span class="count">…` と形が変わるので、
 * `<h2>` + 空白 + 訳語 の正規表現で両方を拾う.
 * 候補は先頭から順に試し、最初に見つかったものを使う.
 *
 * @param string   $html   検索対象の HTML.
 * @param string[] $labels 見出しテキストの候補（コアの訳語）.
 * @return int|null 見つからなければ null.
 */
function find_section_anchor( $html, $labels ) {
	foreach ( $labels as $label ) {
		$pattern = '#<h2>\s*' . preg_quote( $label, '#' ) . '#u';

		if ( preg_match( $pattern, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $matches[0][1];
		}
	}

	return null;
}
