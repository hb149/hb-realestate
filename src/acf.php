<?php
/**
 * ACF の保存先をファイルに切り替える
 *
 * ■ なぜ必要か
 *   ACFのフィールド定義は既定でDB（acf-field-group 投稿）にしか保存されない。
 *   そのままだと「ローカルで作ったフィールドが本番に無い」「本番で直した定義が
 *   次のデプロイで消える」という事故が起きる。
 *   現行サイトがまさにその状態で、site2 と site3 のフィールド定義が
 *   いつの間にか乖離していた（販売管理 2022-04 / 2026-09）。
 *
 * ■ やること
 *   フィールドを保存すると JSON ファイルが acf-json/ に書き出され、
 *   読み込み時はそのファイルが使われる。定義がGitに乗り、差分が見えるようになる。
 *
 * ■ なぜテーマではなく mu-plugins に置くか
 *   ACFの慣習ではテーマ内の acf-json/ を使うが、フィールド定義は「見た目」ではなく
 *   「どんなデータを持つか」＝データ構造。テーマを差し替えても失われてはいけない。
 *   投稿タイプと同じ場所に置く。
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HB2103_ACF_JSON_DIR', HB2103_RE_DIR . '/acf-json');

/**
 * フィールドを保存したら JSON をここに書き出す。
 */
add_filter('acf/settings/save_json', function (): string {
    if (!is_dir(HB2103_ACF_JSON_DIR)) {
        wp_mkdir_p(HB2103_ACF_JSON_DIR);
    }
    return HB2103_ACF_JSON_DIR;
});

/**
 * 読み込み元。既定のテーマ内パスは外し、ここだけを見るようにする。
 */
add_filter('acf/settings/load_json', function (array $paths): array {
    unset($paths[0]);
    $paths[] = HB2103_ACF_JSON_DIR;
    return $paths;
});

/**
 * ファイル名をグループキーにする。
 *
 * 既定は ACF の内部キー（group_6523ab...）でランダム、
 * フィールドグループ名を sanitize_title に通すと日本語が16進エンコードされて
 * e58fb7e59cb0e68385e5a0b1.json のように読めなくなる。
 * こちらで付けた group_hb2103_lot のようなキーをそのまま使うのが一番読みやすい。
 */
add_filter('acf/json/save_file_name', function (string $filename, array $post): string {
    $key = $post['key'] ?? '';
    return (is_string($key) && preg_match('/^[A-Za-z0-9_-]+$/', $key)) ? $key . '.json' : $filename;
}, 10, 2);

/**
 * 本番ではフィールドの編集UIを隠す。
 *
 * 定義はファイルで管理するので、本番の管理画面から直接いじられると
 * ファイルとDBが食い違う。開発環境でだけ編集できるようにする。
 */
add_filter('acf/settings/show_admin', function (bool $show): bool {
    return wp_get_environment_type() !== 'production';
});

/**
 * サイト共通設定のオプションページ。
 * 会社情報や共通の問い合わせ先など、どの投稿にも属さない値の置き場。
 */
add_action('acf/init', function (): void {
    if (!function_exists('acf_add_options_page')) {
        return;
    }

    acf_add_options_page([
        'page_title' => 'サイト共通設定',
        'menu_title' => 'サイト共通設定',
        'menu_slug'  => 'hb2103-site-settings',
        'capability' => 'manage_options',
        'icon_url'   => 'dashicons-admin-settings',
        'position'   => 59,
        'redirect'   => false,
        'update_button' => '保存',
        'updated_message' => '共通設定を保存しました',
    ]);
});

/**
 * ACFのGoogleマップで使うAPIキー。
 *
 * このキーは Maps JavaScript API 用で、ブラウザに出る前提のもの。
 * 秘匿して守るものではなく、Google Cloud側の「HTTPリファラー制限」で守る。
 * したがって管理画面（サイト共通設定）から入力してDBに置いて問題ない。
 *
 * 読む順番:
 *   1. wp-config.php の定数 HB2103_GOOGLE_MAPS_API_KEY（環境ごとに固定したいとき）
 *   2. サイト共通設定の入力欄
 * 定数があればそちらが勝つ。本番だけ別キーにしたい場合に使える。
 */
add_action('acf/init', function (): void {
    $key = '';

    if (defined('HB2103_GOOGLE_MAPS_API_KEY') && HB2103_GOOGLE_MAPS_API_KEY !== '') {
        $key = (string) HB2103_GOOGLE_MAPS_API_KEY;
    } elseif (function_exists('get_field')) {
        $key = (string) get_field('google_maps_api_key', 'option');
    }

    if ($key !== '') {
        acf_update_setting('google_api_key', $key);
    }
}, 20);

/**
 * Googleマップの読み込みに region=JP を足す。
 *
 * ACFは language=ja までは付けるが region を付けないため、検索や逆引きの候補が
 * 日本に寄らないことがある。日本の住所表記・候補の優先はこの2つで決まる。
 */
add_filter('acf/fields/google_map/api', 'hb2103_google_map_api_args');

function hb2103_google_map_api_args(array $api): array
{
    $api['language'] = 'ja';
    $api['region']   = 'JP';
    return $api;
}
