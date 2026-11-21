<?php
/**
 * Plugin Name: HB 不動産コア
 * Description: 不動産サイト共通の土台。物件・区画・周辺施設のデータ構造、URL設計、メディア配置、入力補助。見た目はテーマ側に置く。
 * Version:     1.0.0
 * Update URI:  https://github.com/hb149/hb-realestate
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: hb-realestate
 *
 * ここに何を置くか:
 *   不動産サイトなら案件が変わっても必要になるもの。
 *   投稿タイプ・タクソノミー・ACFの定義・URL設計・計算・入力補助。
 *   案件固有の事情（旧サイトからの移行、リダイレクト表、独自の文言）は
 *   案件側の mu-plugins に置くこと。
 *
 * 通常プラグインである理由:
 *   mu-plugins は WordPress の更新機構に乗らない。全案件へ更新を配るために
 *   通常プラグインにしている。ただし投稿タイプの登録が止まると
 *   「投稿はDBにあるのに管理画面から消える」事故になるので、
 *   プラグイン一覧から「停止」リンクを外してある（下の hb2103_re_lock_plugin）。
 *   開発中に止めたいときは wp-config.php に
 *     define('HB2103_RE_ALLOW_DEACTIVATE', true);
 *   を書くか、WP-CLI で `wp plugin deactivate hb-realestate` を使う。
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HB2103_RE_VERSION', '1.0.0');
define('HB2103_RE_DIR', __DIR__);
define('HB2103_RE_FILE', __FILE__);

foreach ([
    'post-types.php',    // property / facility / equipment / works / voice
    'taxonomies.php',    // area / facility_type / equipment_type / media_category
    'area.php',          // 住所から市区町村を読み取ってエリアに付ける
    'permalinks.php',    // URL周りの調整
    'slug.php',          // スラッグを英字必須にする（日本語URLを作らせない）
    'media.php',         // 物件単位のアップロード配置・WebP・メディア一覧の物件カラム
    'acf.php',           // ACFの定義をDBではなくファイルで管理する
    'admin.php',         // 管理画面CSS（ACFの設定で解決できないものだけ）
    'property-calc.php', // 価格・面積の範囲を区画から計算する
    'admin-assist.php',  // 編集画面の入力補助（面積換算・住所の自動提示）
    'content-sync.php',  // 説明文を本文へ同期（抜粋の自動生成と検索を本体の仕組みに乗せる）
    'figure.php',        // 区画図などの図版出力（SVGインライン展開に対応）
] as $file) {
    $path = __DIR__ . '/src/' . $file;
    if (is_readable($path)) {
        require_once $path;
    }
}

// 更新の仕組みは hb-base と共通。単体で入れても動くように、無ければ黙って諦める。
if (function_exists('hb_github_updater_register')) {
    hb_github_updater_register([
        'file'         => __FILE__,
        'slug'         => 'hb-realestate',
        'name'         => 'HB 不動産コア',
        'repo'         => defined('HB2103_RE_UPDATE_REPO') ? HB2103_RE_UPDATE_REPO : 'hb149/hb-realestate',
        'version'      => HB2103_RE_VERSION,
        'tested'       => '7.1',
        'requires_php' => '7.4',
    ]);
}

/**
 * うっかり停止されないように「停止」リンクを外す。
 * 投稿タイプの登録が止まると、投稿はDBに残ったまま管理画面から見えなくなる。
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'hb2103_re_lock_plugin');

function hb2103_re_lock_plugin(array $links): array
{
    if (defined('HB2103_RE_ALLOW_DEACTIVATE') && HB2103_RE_ALLOW_DEACTIVATE) {
        return $links;
    }

    unset($links['deactivate']);

    $links['hb-locked'] = '<span style="color:#646970">サイトの土台のため停止できません</span>';

    return $links;
}
