<?php
/**
 * 投稿タイプの登録
 *
 * 現行サイトからの対応:
 *   page（親）   → property  分譲地
 *   page（子）   → 分譲地の lots リピーター（号地は独立投稿にしない）
 *   location     → facility  周辺施設  ※物件ではなく施設マスタだった。非公開CPTにする
 *   works        → works     施工事例
 *   testimonial  → voice     お客様の声
 *   post         → post      お知らせ（本体の投稿をラベル変更して使う）
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'hb2103_register_post_types');

function hb2103_register_post_types(): void
{
    // ── 分譲地 ───────────────────────────────────────────────
    // アイキャッチのラベルだけ分譲地用に差し替える。
    // 号地の「外観」（1棟ごとの写真）と区別がつかないという指摘があったため、
    // どの階層のどういう用途の画像かを名前で示す。
    $property_labels = hb2103_pt_labels('分譲地', '分譲地');
    $property_labels['featured_image']        = '分譲地のメイン画像';
    $property_labels['set_featured_image']    = '分譲地のメイン画像を設定';
    $property_labels['remove_featured_image'] = '分譲地のメイン画像を削除';
    $property_labels['use_featured_image']    = '分譲地のメイン画像として使用';

    register_post_type('property', [
        'labels' => $property_labels,
        'description'  => '分譲地。号地は lots リピーターで持つ。',
        'public'       => true,
        'has_archive'  => true,
        'hierarchical' => false,
        'menu_icon'    => 'dashicons-building',
        'menu_position' => 5,
        // editor を外している理由:
        //   分譲地の中身は構造化データ（所在地・区画数・建ぺい率…）が主役で、自由記述は補足。
        //   ブロックエディタを有効にすると ACF の50フィールドが下部のメタボックス引き出しに
        //   押し込まれて実質入力できない。自由記述は ACF の wysiwyg フィールドで受ける。
        'supports'     => ['title', 'thumbnail', 'excerpt', 'revisions', 'custom-fields', 'page-attributes'],
        'rewrite'      => ['slug' => 'property', 'with_front' => false],
        'show_in_rest' => true,
        'rest_base'    => 'properties',
    ]);

    // ── 周辺施設 ─────────────────────────────────────────────
    // public = false：施設そのもののページは作らない。
    //   現行は1施設1投稿で2,684件が公開され、うち99.7%が日本語URLエンコードのスラッグだった。
    //   施設は物件ページ内で参照されるだけなので、単独URLも検索対象も不要。
    register_post_type('facility', [
        'labels' => hb2103_pt_labels('周辺施設', '周辺施設'),
        'description'  => '周辺施設マスタ。物件から参照される。距離は物件側が持つ。',
        'public'             => false,
        'publicly_queryable' => false,
        'exclude_from_search' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_nav_menus'  => false,
        'hierarchical'       => false,
        'menu_icon'          => 'dashicons-location',
        'menu_position'      => 6,
        'supports'           => ['title', 'thumbnail', 'custom-fields'],
        'rewrite'            => false,
        'show_in_rest'       => true,
        'rest_base'          => 'facilities',
    ]);

    // ── 設備・仕様 ───────────────────────────────────────────
    // 旧サイトは equipment_category というタクソノミーだったが、タームに
    // 画像（41件）・分類・説明文・ギャラリーをACFで持たせており、実質カスタム投稿だった。
    // タームはメタを持てても編集画面が貧弱で並べ替えもできないため、CPTにする。
    // 分譲地からは関連フィールドで参照し、分類は equipment_type でまとめる。
    register_post_type('equipment', [
        'labels' => hb2103_pt_labels('設備・仕様', '設備・仕様'),
        'description'  => '設備・仕様マスタ。分譲地・区画から参照される。',
        'public'             => false,
        'publicly_queryable' => false,
        'exclude_from_search' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_nav_menus'  => false,
        'hierarchical'       => false,
        'menu_icon'          => 'dashicons-admin-tools',
        'menu_position'      => 7,
        // 一覧の並べ替えに page-attributes（順序）を使う
        'supports'           => ['title', 'thumbnail', 'custom-fields', 'page-attributes'],
        'rewrite'            => false,
        'show_in_rest'       => true,
        'rest_base'          => 'equipments',
    ]);

    // ── 施工事例 ─────────────────────────────────────────────
    register_post_type('works', [
        'labels' => hb2103_pt_labels('施工事例', '施工事例'),
        'public'       => true,
        'has_archive'  => true,
        'hierarchical' => false,
        'menu_icon'    => 'dashicons-hammer',
        'menu_position' => 7,
        'supports'     => ['title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'custom-fields'],
        'rewrite'      => ['slug' => 'works', 'with_front' => false],
        'show_in_rest' => true,
    ]);

    // ── お客様の声 ───────────────────────────────────────────
    register_post_type('voice', [
        'labels' => hb2103_pt_labels('お客様の声', 'お客様の声'),
        'public'       => true,
        'has_archive'  => true,
        'hierarchical' => false,
        'menu_icon'    => 'dashicons-format-quote',
        'menu_position' => 8,
        'supports'     => ['title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'custom-fields'],
        'rewrite'      => ['slug' => 'voice', 'with_front' => false],
        'show_in_rest' => true,
    ]);
}

/**
 * 投稿タイプのラベルを日本語でまとめて作る。
 * 日本語では単数・複数を区別しないので、1語から全ラベルを組み立てられる。
 */
function hb2103_pt_labels(string $name, string $singular): array
{
    return [
        'name'                  => $name,
        'singular_name'         => $singular,
        'menu_name'             => $name,
        'all_items'             => $name . '一覧',
        'add_new'               => '新規追加',
        'add_new_item'          => $singular . 'を追加',
        'edit_item'             => $singular . 'を編集',
        'new_item'              => '新しい' . $singular,
        'view_item'             => $singular . 'を表示',
        'view_items'            => $name . 'を表示',
        'search_items'          => $name . 'を検索',
        'not_found'             => $name . 'が見つかりません',
        'not_found_in_trash'    => 'ゴミ箱に' . $name . 'はありません',
        'featured_image'        => 'メイン画像',
        'set_featured_image'    => 'メイン画像を設定',
        'remove_featured_image' => 'メイン画像を削除',
        'use_featured_image'    => 'メイン画像として使用',
        'item_published'        => $singular . 'を公開しました',
        'item_updated'          => $singular . 'を更新しました',
        'item_scheduled'        => $singular . 'を予約投稿しました',
        'item_reverted_to_draft' => $singular . 'を下書きに戻しました',
    ];
}

/**
 * 本体の「投稿」を「お知らせ」として使う。
 * 新しい投稿タイプを作らない理由: 現行の post（270件）がそのまま移行でき、
 * 本体やプラグインが投稿に対して持っている機能（フィード・日付アーカイブ等）を素直に使えるため。
 */
add_filter('post_type_labels_post', function (object $labels): object {
    $labels->name          = 'お知らせ';
    $labels->singular_name = 'お知らせ';
    $labels->menu_name     = 'お知らせ';
    $labels->all_items     = 'お知らせ一覧';
    $labels->add_new_item  = 'お知らせを追加';
    $labels->edit_item     = 'お知らせを編集';
    $labels->view_item     = 'お知らせを表示';
    $labels->search_items  = 'お知らせを検索';
    $labels->not_found     = 'お知らせが見つかりません';
    return $labels;
});
