# Changelog

このプラグインの主な変更を記録します。

書式は [Keep a Changelog](https://keepachangelog.com/ja/1.1.0/) に、バージョン番号は [Semantic Versioning](https://semver.org/lang/ja/) に従います。

> **履歴の出自について**
> このプラグインはもともと WordPress サイトの `wp-content/plugins` 配下で git 管理外のまま開発されていたものを、後から単体リポジトリとして切り出したものです。**1.0.0〜1.3.1** のコミットは、そのときのバージョンごとのスナップショットを時系列に並べ直したもので、実際の作業履歴ではありません。それらの日付はすべてリポジトリ初期化日（2026-09-14）であり、各版が実際に書かれた日ではありません。1.4.0 以降は通常の履歴です。
>
> 1.3.1 以前にはタグがありません（リポジトリ化した時点で既に過去の版だったため）。そのため下のバージョン見出しのうち、リンクになっているのは 1.4.0 だけです。

## [1.4.0] - 2026-09-14

配布物としての体裁を整えた版。**警告を出す機能そのものは 1.3.1 から変わっていない。**

### Added

- **GitHub Releases からの自動アップデート機構。** [l2d-wp-github-update-lib](https://github.com/lunaluna/l2d-wp-github-update-lib) を `git subtree` で `lib/l2d-updater/`（配布専用タグ `dist-1.2.0`）に取り込み、メインファイルから登録している。管理画面の通常の更新フロー（更新通知 → ワンクリック更新）でこのプラグイン自身を更新できる。

  既存の独自更新機構が無い新規導入のため、後方互換用の `cache_key` / `filter_prefix` は渡さず既定値に任せている。

- **リリース基盤。** `.github/workflows/release.yml`（`plugin-release.yml@1.2.0`）、`bin/build-zip.sh`、`.distignore` を追加した。`release.yml` の `version_files` にはバージョンを書いている 4 ファイル（プラグインヘッダー / `readme.txt` / `composer.json` / `README.md`）をすべて並べてあるので、1 箇所でもバンプ漏れがあればリリースが失敗する。
- `README.md` / `readme.txt` / `CHANGELOG.md` を追加した。
- `LICENSE`（GPL-2.0 全文）を同梱した。ヘッダーと `readme.txt` で `GPLv2 or later` を宣言しているのに全文が無かったため。
- プラグインヘッダーに `Plugin URI` / `Tested up to` / `Author` / `Author URI` / `Update URI` / `License URI` / `Text Domain` を追加し、他プラグインと表記を揃えた。`License` の表記も `GPL-2.0-or-later` から `GPLv2 or later` に変更した（ライセンス自体は変更なし）。
- PHPCS の設定（`phpcs.xml.dist`）と、開発用依存を管理する `composer.json` を追加した。構成は他リポジトリに合わせて `WordPress-Extra`（`WordPress.Files.FileName` は除外）+ `WordPress-Docs` + `PHPCompatibilityWP`。`composer lint` / `composer lint:fix` で実行する。ベンダーコピー（`lib/`）は検査対象から外している。

### Changed

- **プラグインを機能ごとのファイルへ分割した。** メインファイル `core-requirement-update-notice.php` はプラグインヘッダーと読み込みだけになり、実体は `includes/` 以下の 7 ファイル（`detection` / `messages` / `plugins-list` / `themes-list` / `update-core` / `assets` / `auto-update`）へ移した。関数 21 個・フック登録 7 個はすべて移動のみで、コード本体に変更はない（トークン列で照合済み。差分は各ファイルの `namespace` 宣言と `ABSPATH` ガード、メインファイルの `require_once` の追加だけ）。
- コメントの文末を句点「。」から半角ピリオド「.」に変更した。PHPCS のコメント系スニフはラテン文字の終端記号を要求するため、スニフを除外するのではなくコード側を規約に合わせる方針を採った（他リポジトリと同じ流儀）。docblock の長い説明が小文字の識別子で始まる箇所は、識別子をバッククォートで囲んで解消した。
- 区切りコメントを PHPCS の規約に合う形へ整えた。意図的な逸脱 2 箇所（型注釈のみの docblock、コアの訳語を引くためのテキストドメイン未指定）には理由付きの `phpcs:ignore` を入れた。
- プラグインヘッダーの `Description` も文末が半角ピリオドになった。プラグイン一覧に表示される文言だが、変更は句読点のみ。翻訳対象の文字列（画面に表示される文言）は句点のまま変更していない。

### 互換性についての注意

**「単一ファイルを `wp-content/plugins/` 直下に置くだけで動く」性質は失われた。** ディレクトリごと配置する必要がある。`core-requirement-update-notice.php` だけを手で置いていた場合は、ディレクトリごと入れ替えること。

## [1.3.1] - 2026-09-14

### Fixed

- 更新一覧の文言を分かりやすくした。「バージョン 29.1.6 がインストールされています。31.0.2 が公開されています。」だと 2 文が並列で、どちらが現状なのかが読み取りにくかったため、逆接でつないで「すでに新しい版が出ている」ことを明示するようにした。プラグイン・テーマの両セクションで同じ文言を使っている。

## [1.3.0] - 2026-09-14

テーマの更新にも同じ警告を出せるようにした。

テーマもプラグインとまったく同じで、WordPress 要件を満たさない更新は `update_themes` トランジェントの `no_update` に回されて表示されない。ただしテーマはプラグインと違い、コア側の表示ロジックが WordPress 非互換に対応済みで、足りないのはデータだけだった（`hasUpdate` が `isset( $updates[$slug] )`、つまり `response` のみで決まるせいで分岐の手前で落ちていた）。

実測（WordPress 6.8.8 / ローカル環境）: snow-monkey 29.1.6 インストール済み、`no_update` に `new_version=31.0.2` / `requires=7.1` のエントリが存在し、`response` 側は空だった。

### Added

- テーマ一覧（`themes.php`）に対応した。`wp_prepare_themes_for_js` で `hasUpdate = true` / `updateResponse.compatibleWP = false` / `hasPackage = false` を立て直し、コア自身の表示に乗せる。`hasUpdate` の分岐はすべて `updateResponse` で二重にガードされているため「今すぐ更新」ボタンは出ず、自前のマークアップも不要。`compatiblePHP` は触らない（両方 `false` にすると文言が「WordPress と PHP の両方」になってしまうため）。
- 更新一覧に「WordPress の更新が必要なテーマ」セクションを追加した。`get_theme_updates()` にフィルターが無いため、プラグインと同様に `core_upgrade_preamble` で出力する。
- `auto_update_theme` にも自動更新の抑止フィルターを追加した。テーマはトランジェント上は配列だがフィルターにはオブジェクトで渡るため、`(array)` キャストで両対応にしている。

### Changed

- セクション移動のマーカーを 2 組に一般化し、プラグイン節は「テーマ」見出しの直前、テーマ節は「翻訳」見出しの直前へ移動するようにした（プラグイン → 補完 → テーマ → 補完 → 翻訳 の順になる）。
- 関数名を `get_incompatible_plugin_updates()` / `build_plugin_entry()` に変更し、テーマ側と対になるようにした。

### 未対応

- マルチサイトのネットワーク管理テーマ一覧。`wp_prepare_themes_for_js()` は `! is_multisite()` のときしか更新情報を読まず、ネットワーク側は `WP_MS_Themes_List_Table` + `wp_theme_update_row()` という別経路になるため（未検証）。

## [1.2.0] - 2026-09-14

### Fixed

- 更新一覧のセクションがページ最下部（翻訳の後）に出ていたのを、本来の位置へ移動するようにした。1.1.0 で追加したセクションは `core_upgrade_preamble` で出力していたが、このフックは「コア・プラグイン・テーマ・翻訳」をすべて出し切った後に 1 回だけ発火するため、位置を選べなかった。

  「プラグイン」と「テーマ」の間には PHP のフックが一切ない（`list_plugin_updates()` の末尾にも `list_theme_updates()` の先頭にもアクション・フィルターが無い）ため、フックでは位置を作れない。そこで出力バッファ上で移動させる方式を採った。

  - セクションを HTML コメントのマーカーで囲んで出力する。
  - 一覧画面のときだけ `admin_head` で `ob_start()`。テーブル群が描画される前にバッファを開始し、`shutdown` 時のフラッシュでページ全体の HTML を受け取る。
  - コールバックでマーカー間を抜き出し、「テーマ」見出しの直前へ挿入し直す。見出しは更新の有無でマークアップが変わる（`<h2>テーマ</h2>` と `<h2>\n\tテーマ <span class="count">…`）ため、`<h2>` + 空白 + 訳語 の正規表現で両方を拾う。テーマ節が無ければ翻訳節の手前にフォールバックする。

  アンカーが見つからない場合は何もせず元の位置に残すので、表示自体が消えることはない。`do-plugin-upgrade` など進捗をストリーム出力するアクションではバッファリングしない（表示が固まるため）。

## [1.1.0] - 2026-09-14

### Fixed

- 検出対象を `no_update` にも広げた。**1.0.0 は `update_plugins` トランジェントの `response` だけを見ていたため、対象を 1 件も検出できていなかった。**

  wp.org の update-check API は、新バージョンが要求する WordPress バージョンをサイトが満たしていない場合、そのプラグインを `response` ではなく `no_update` に入れて返す（`new_version` にはインストール済みより新しい版が入ったまま）。PHP 要件を満たさない場合は `response` に入ったまま返るため、コアの PHP 警告は出るのに WordPress 要件の場合は更新行ごと消える、という差が生まれていた。

  実測（WordPress 6.8.8 / ローカル環境）: snow-monkey-blocks 24.1.12 インストール済み、`no_update` に `new_version=26.0.2` / `requires=7.1` のエントリが存在し、`response` 側には一切現れなかった。

### Added

- コアが更新行を描画しないケース（`hidden = true`）向けに、`after_plugin_row_{$file}` で `wp_plugin_update_row()` と同じマークアップの更新行を自前描画するようにした（`notice-error` 固定、「今すぐ更新」は出さない）。
- 更新一覧に独自セクションを追加した。`list_plugin_updates()` が `response` しか見ないため。

### Changed

- `response` と `no_update` の両方を走査するようにした。`no_update` 側はインストール済みより新しい版が提示されているものだけを拾う。
- JS は、自前描画したケースではプラグイン本体の行に `update` クラスを足して枠線を繋げるだけに変更した。

## [1.0.0] - 2026-09-14

### Added

- 初版。プラグインの新バージョンが要求する WordPress バージョンをサイトが満たしていない場合に、事前警告を表示するようにした。

  WordPress コアはこの警告を出さない。PHP 要件なら `wp_plugin_update_row()` が `notice-error` とメッセージを出し分けるのに、WordPress 要件（`$response->requires`）は `in_plugin_update_message-{$file}` の引数として渡されるだけで表示判定に使われない。`update-core.php` の `list_plugin_updates()` も `$compatible_php` しか見ておらず、`$compat` 文字列にフィルターも無い。一方 `Plugin_Upgrader::bulk_upgrade()` は `is_wp_version_compatible()` で事前に弾くため、「実行すると失敗するのに事前警告だけが無い」状態になっていた。

  - `update_plugins` トランジェントの `response` から `requires` を読み、`is_wp_version_compatible()` で自前に判定する。
  - プラグイン一覧: `in_plugin_update_message-{$file}` でメッセージを追記し、`notice-warning` を `notice-error` に差し替え「今すぐ更新」を JS で除去する。
  - 更新一覧: `list_plugin_updates()` にフィルターが無いため、JS で行を特定して注記を追加しチェックボックスを無効化する。
  - `auto_update_plugin` で自動更新の対象から外す（毎回失敗するため）。

[1.4.0]: https://github.com/lunaluna/core-requirement-update-notice/releases/tag/1.4.0
