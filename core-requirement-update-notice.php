<?php
/**
 * Plugin Name:       Core Requirement Update Notice
 * Description:       更新は提供されているが、新バージョンが要求する WordPress コアバージョンを満たしていないプラグイン・テーマについて、プラグイン一覧／テーマ一覧／更新一覧に PHP 非互換時と同等の警告を表示します。
 * Version:           1.3.1
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
 * update_plugins トランジェントには 2 つの置き場所がある。
 *
 * - `response` : コアが「更新あり」として扱うもの。プラグイン一覧の更新行も
 *                更新一覧のテーブル行も、コアがここからしか作らない。
 * - `no_update`: コアが「更新なし」として扱うもの。行は一切描画されない。
 *
 * ここが v1.0.0 の取りこぼしだった。wp.org の update-check API は、
 * 新バージョンが要求する WP バージョンをサイトが満たしていない場合、
 * そのプラグインを `response` ではなく `no_update` に入れて返す
 * （`new_version` にはインストール済みより新しい版が入ったまま）。
 * PHP 要件を満たさない場合は `response` に入ったまま返ってくるため、
 * コアの PHP 警告は表示されるのに WP 要件の場合は行ごと消える、という差が出る。
 *
 * 実測（2026-09-14 / WP 6.8.8 のローカル環境）:
 *   snow-monkey-blocks 24.1.12 インストール済み、
 *   no_update に new_version=26.0.2 / requires=7.1 のエントリが存在し、
 *   response 側には一切現れなかった。
 *
 * そのため両方を走査し、`no_update` 由来のものは「コアが行を描画しない」
 * 印（hidden = true）を付けて返す。呼び出し側はこの印を見て、
 * メッセージの追記で済ませるか、行ごと自前で描画するかを切り替える。
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

	// no_update 側は「インストール済みより新しいか」の判定にインストール版が要る。
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$installed = get_plugins();

	// response（コアが行を描画する）→ hidden = false。
	foreach ( (array) ( $updates->response ?? array() ) as $file => $item ) {
		$entry = build_plugin_entry( $file, $item, false );

		if ( $entry ) {
			$cache[ $file ] = $entry;
		}
	}

	// no_update（コアが行を描画しない）→ hidden = true。
	foreach ( (array) ( $updates->no_update ?? array() ) as $file => $item ) {
		if ( isset( $cache[ $file ] ) ) {
			continue;
		}

		$new_version = isset( $item->new_version ) ? (string) $item->new_version : '';
		$current     = isset( $installed[ $file ]['Version'] ) ? (string) $installed[ $file ]['Version'] : '';

		// no_update には「本当に最新」のプラグインも全件入るので、
		// インストール済みより新しい版が提示されているものだけを拾う。
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
 * 更新情報オブジェクトから、非互換エントリの配列を組み立てる。
 *
 * WP バージョン要件を満たしている場合は null を返す（対象外）。
 *
 * @param string $file   プラグインファイル（plugins ディレクトリからの相対パス）。
 * @param object $item   更新情報オブジェクト。
 * @param bool   $hidden コアが更新行を描画しないエントリかどうか。
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
 * コアが握り潰しているテーマ更新（no_update 側）のうち、コア要件を満たさないものを返す。
 *
 * テーマもプラグインとまったく同じで、WP 要件を満たさない更新は
 * update_themes トランジェントの `no_update` に回されて表示されない。
 *
 * 実測（2026-09-14 / WP 6.8.8 のローカル環境）:
 *   snow-monkey 29.1.6 インストール済み、
 *   no_update に new_version=31.0.2 / requires=7.1 のエントリが存在し、
 *   response 側は空だった。
 *
 * プラグインと違い、コア側の表示ロジックは WP 非互換に対応済み
 * （themes.php の updateResponse.compatibleWP 分岐、list_theme_updates() の
 * $compatible_wp 判定）。足りないのはデータだけなので、`response` 側は
 * コアに任せ、ここでは `no_update` に隠れているものだけを返す。
 *
 * なお update_themes のエントリはプラグインと違いオブジェクトではなく配列。
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
		// インストール済みより新しい版が提示されているものだけを拾う。
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

/**
 * バージョン要件だけを述べる文を組み立てる。
 *
 * 「動作しません」の一文は呼び出し側の文脈ごとに変わるため、ここには含めない。
 *
 * @param string $requires 必要な WP バージョン。
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
 * コア更新画面へのリンクを返す。権限が無ければ空文字。
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
 * 警告メッセージ（HTML）を組み立てる。
 *
 * コアが出した更新行やテーブル行に「追記」する用途向けの、単独で意味が通る文。
 *
 * @param string $requires 必要な WP バージョン。
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
 * 「バージョン X の詳細を表示」リンクを組み立てる。
 *
 * plugins.php / update-core.php はどちらも add_thickbox() 済みなので、
 * コアの更新行と同じ thickbox リンクをそのまま使える。
 *
 * @param array  $entry       get_incompatible_plugin_updates() のエントリ。
 * @param string $plugin_name プラグイン名（エスケープ済みであること）。
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

/*
 * -------------------------------------------------------------------------
 * プラグイン一覧（plugins.php / network/plugins.php）
 * ----------------------------------------------------------------------
 */

add_action( 'load-plugins.php', __NAMESPACE__ . '\\register_update_row_messages', 30 );

/**
 * 該当プラグインの更新行にフックを登録する。
 *
 * wp_plugin_update_rows() は admin_init:20 で登録されるため、
 * それより後に走る load-plugins.php で追加する。
 *
 * hidden = false: コアの更新行があるので、末尾へのメッセージ追記で足りる。
 * hidden = true : コアが行を描画しないので、after_plugin_row_{$file} で
 *                 自前の更新行を丸ごと出す。
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
 * コアの更新行のメッセージ末尾に警告を追記する（hidden = false 用）。
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

/**
 * 自前の更新行を描画する（hidden = true 用）。
 *
 * マークアップは wp_plugin_update_row() の出力に合わせてある。
 * 更新は実行できないので「今すぐ更新」リンクは出さず、notice-error 固定にする。
 *
 * @param string $file        プラグインファイル。
 * @param array  $plugin_data プラグインメタ情報。
 * @return void
 */
function render_own_update_row( $file, $plugin_data ) {
	$incompatible = get_incompatible_plugin_updates();

	if ( ! isset( $incompatible[ $file ] ) ) {
		return;
	}

	$entry = $incompatible[ $file ];

	// コアの更新行と同じ許可タグでプラグイン名を通す。
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

	// 型注釈のみの docblock。説明文は不要なので短い説明の要求を無効化する。
	/** @var \WP_Plugins_List_Table $wp_list_table */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
	$wp_list_table = _get_list_table(
		'WP_Plugins_List_Table',
		array( 'screen' => get_current_screen() )
	);

	// コアの PHP 非互換メッセージと同じ組み立て（本文 → 詳細リンク → 補足）にする。
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

/*
 * -------------------------------------------------------------------------
 * テーマ一覧（themes.php）
 *
 * テーマ側はコアがすでに「新しいバージョンがあるが WP 非互換」の文言を持っている
 * （themes.php の updateResponse.compatibleWP 分岐 / #tmpl-theme・#tmpl-theme-single）。
 * 分岐の手前の hasUpdate が false になっているだけなので、
 * wp_prepare_themes_for_js で以下を立て直せばコア自身の表示に乗る。
 *
 *   hasUpdate                    = true   … 更新ありの分岐に入れる
 *   updateResponse.compatibleWP  = false  … 「WP 非互換」の枝に落とす
 *   hasPackage                   = false  … 更新パッケージは提示しない
 *
 * hasUpdate の分岐はすべて updateResponse で二重にガードされているので、
 * 「今すぐ更新」ボタンが出ることはない。自前のマークアップも不要。
 * ----------------------------------------------------------------------
 */

add_filter( 'wp_prepare_themes_for_js', __NAMESPACE__ . '\\mark_incompatible_theme_updates' );

/**
 * テーマ一覧用のデータに「更新あり・ただし WP 非互換」の印を付ける。
 *
 * @param array $prepared_themes テーマ slug をキーにした表示用データ。
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

		// compatiblePHP まで false にすると文言が「WP と PHP の両方」になってしまうので触らない。
		$prepared_themes[ $slug ]['updateResponse']['compatibleWP'] = false;
	}

	return $prepared_themes;
}

/*
 * -------------------------------------------------------------------------
 * 更新一覧（update-core.php）: no_update 由来の分の一覧
 * list_plugin_updates() / list_theme_updates() はどちらも response しか見ないため
 * （get_plugin_updates() / get_theme_updates()）、隠れている分は 1 行も出ない。
 * どちらの関数にもフィルターが無いので、テーブル群の直後に発火する
 * core_upgrade_preamble で独自のセクションとして補う。
 *
 * ただし core_upgrade_preamble は「コア・プラグイン・テーマ・翻訳」を
 * すべて出し切った後に発火するため、そのままだと 2 つともページ最下部に出る。
 * 節と節の間には PHP のフックが一切無いので、それぞれをマーカーで囲んでおき、
 * ページの出力バッファ上で本来の位置へ移動させる。
 *
 *   プラグイン節 → 「テーマ」見出しの直前
 *   テーマ節     → 「翻訳」見出しの直前
 *
 * 結果として「プラグイン → 補完(プラグイン) → テーマ → 補完(テーマ) → 翻訳」になる。
 * ----------------------------------------------------------------------
 */

/**
 * 移動対象セクションのマーカー。HTML コメントなので残っても無害。
 *
 * 添字が種別（plugins / themes）、値が array( 開始マーカー, 終了マーカー, 挿入先候補の見出し )。
 * 見出しはコアの訳語をそのまま使うので、翻訳の有無に関わらず一致する。
 *
 * @return array<string, array{open:string, close:string, anchors:string[]}>
 */
function get_section_markers() {
	return array(
		'plugins' => array(
			'open'    => '<!--core-req-notice:plugins-->',
			'close'   => '<!--/core-req-notice:plugins-->',
			// テキストドメインを渡さないのは意図的。コア本体の訳語を引くためで、
			// 自前のドメインに切り替えると見出しと一致しなくなる。
			'anchors' => array( __( 'Themes' ), __( 'Translations' ) ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- コアの訳語をそのまま引く。
		),
		'themes'  => array(
			'open'    => '<!--core-req-notice:themes-->',
			'close'   => '<!--/core-req-notice:themes-->',
			// 同上。コア本体の訳語を引くためテキストドメインを渡さない。
			'anchors' => array( __( 'Translations' ) ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- コアの訳語をそのまま引く。
		),
	);
}

add_action( 'core_upgrade_preamble', __NAMESPACE__ . '\\render_update_core_sections' );

/**
 * 更新一覧に「WordPress の更新が必要なプラグイン／テーマ」セクションを出力する。
 *
 * @return void
 */
function render_update_core_sections() {
	render_update_core_plugin_section();
	render_update_core_theme_section();
}

/**
 * 更新一覧に「WordPress の更新が必要なプラグイン」セクションを出力する。
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

	echo $markers['plugins']['open']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 固定のマーカー文字列。
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

			// アイコンはコアの更新一覧と同じ優先順で選ぶ。
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
	echo $markers['plugins']['close']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 固定のマーカー文字列。
}

/**
 * 更新一覧に「WordPress の更新が必要なテーマ」セクションを出力する。
 *
 * 行のマークアップは list_theme_updates() に合わせてある
 * （スクリーンショットは 85x64 の updates-table-screenshot）。
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

	echo $markers['themes']['open']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 固定のマーカー文字列。
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
	echo $markers['themes']['close']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 固定のマーカー文字列。
}

add_action( 'admin_head', __NAMESPACE__ . '\\maybe_buffer_update_core', 0 );

/**
 * 更新一覧の本文出力をバッファリングする。
 *
 * admin-header.php の中で発火する admin_head は、テーブル群が描画される前。
 * ここでバッファを開始しておき、shutdown 時のフラッシュでコールバックが
 * ページ全体の HTML を受け取れるようにする。
 *
 * do-plugin-upgrade などの進捗をストリーム出力するアクションでは
 * バッファリングすると表示が固まるので、一覧画面のときだけ開始する。
 *
 * @return void
 */
function maybe_buffer_update_core() {
	$screen = get_current_screen();

	if ( ! $screen || ! in_array( $screen->id, array( 'update-core', 'update-core-network' ), true ) ) {
		return;
	}

	// update-core.php と同じ既定値で action を解決する。
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'upgrade-core'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示位置の判定のみ。

	if ( 'upgrade-core' !== $action ) {
		return;
	}

	// 出すものが無いならバッファリング自体を行わない。
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
 * マーカーで囲んだ各セクションを、本来あるべき見出しの直前へ移動する。
 *
 * 見出しが見つからない場合（該当する節がそもそも描画されていない等）は
 * そのセクションだけ移動を諦め、core_upgrade_preamble が出力した
 * 元の位置（ページ末尾）に残す。表示自体が消えることはない。
 *
 * @param string $html バッファリングされたページ HTML。
 * @return string
 */
function move_sections_into_place( $html ) {
	foreach ( get_section_markers() as $marker ) {
		$html = move_one_section( $html, $marker );
	}

	return $html;
}

/**
 * セクション 1 つ分を移動する。
 *
 * @param string $html   対象 HTML。
 * @param array  $marker get_section_markers() の 1 要素。
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

	// 先にセクションを抜き出しておく。自分自身の見出しにアンカーが当たるのを防ぐため。
	$rest   = substr( $html, 0, $start ) . substr( $html, $end );
	$offset = find_section_anchor( $rest, $marker['anchors'] );

	if ( null === $offset ) {
		return $html;
	}

	return substr( $rest, 0, $offset ) . $section . substr( $rest, $offset );
}

/**
 * 挿入位置（見出しの開始オフセット）を探す。
 *
 * list_theme_updates() 等の見出しは、更新が無ければ `<h2>テーマ</h2>`、
 * あれば `<h2>\n\tテーマ <span class="count">…` と形が変わるので、
 * `<h2>` + 空白 + 訳語 の正規表現で両方を拾う。
 * 候補は先頭から順に試し、最初に見つかったものを使う。
 *
 * @param string   $html   検索対象の HTML。
 * @param string[] $labels 見出しテキストの候補（コアの訳語）。
 * @return int|null 見つからなければ null。
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

/*
 * -------------------------------------------------------------------------
 * 見た目の調整（JS）
 * - hidden = false: コアが出した notice-warning を notice-error に差し替える。
 * - hidden = true : 自前の行の上（プラグイン本体の行）に update クラスを足し、
 *                   コアの更新行と同じ「枠が繋がった」見た目にする。
 * - update-core.php のテーブル: response 由来の行のチェックボックスを無効化する。
 * ----------------------------------------------------------------------
 */

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
				// 下の行と枠線を繋げる（コアは response に無いと付けてくれない）。
				document.querySelectorAll( 'tr[data-plugin]:not(.plugin-update-tr)' ).forEach( function ( row ) {
					if ( row.getAttribute( 'data-plugin' ) === item.file ) {
						row.classList.add( 'update' );
					}
				} );
				return;
			}

			// プラグイン一覧: コアの更新行を notice-error にし、「今すぐ更新」を外す。
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

/*
 * -------------------------------------------------------------------------
 * 自動更新の抑止（任意）
 * 非互換のまま自動更新が走ると毎回失敗するため、対象外にする。
 * ----------------------------------------------------------------------
 */

add_filter( 'auto_update_plugin', __NAMESPACE__ . '\\block_incompatible_auto_update', 10, 2 );
add_filter( 'auto_update_theme', __NAMESPACE__ . '\\block_incompatible_auto_update', 10, 2 );

/**
 * コアバージョン非互換のプラグイン・テーマを自動更新の対象から外す。
 *
 * 管理画面以外（wp-cron）でも走るため、get_incompatible_plugin_updates() 等には依存せず
 * 渡された更新情報だけで判定する。
 * テーマ側はトランジェント上は配列だが、このフィルターにはオブジェクトで渡ってくる
 * （WP_Automatic_Updater::update( 'theme', (object) $theme )）。
 * 呼び出し元によっては配列のまま渡る可能性もあるため、どちらも受け付ける。
 *
 * @param bool|null    $update 自動更新するかどうか。
 * @param object|array $item   更新情報。
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
