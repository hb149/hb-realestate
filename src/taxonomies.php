<?php
/**
 * タクソノミーの登録
 *
 * 現行サイトからの対応:
 *   マルチサイト3サイト ＋ area_category(314ターム) → area          エリア（階層）
 *   location_category(28ターム・重複あり)           → facility_type 施設種別
 *   equipment_category                              → equipment     設備・仕様
 *   （なし）                                        → media_category メディア分類（新規）
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'hb2103_register_taxonomies');

function hb2103_register_taxonomies(): void
{
    // ── エリア ───────────────────────────────────────────────
    // マルチサイトの /kitakyushu/ /fukuoka/ を置き換えるもの。
    // 現行の area_category は市・区・町名が同一階層に混在して314タームあったため、
    // hierarchical = true にして「北九州 > 小倉南区 > 上吉田」のように整理できるようにする。
    register_taxonomy('area', ['property', 'facility', 'works', 'post'], [
        'labels' => hb2103_tax_labels('エリア', true),
        'description'       => 'エリア。北九州・福岡を頂点に市区町村を階層で持つ。',
        'public'            => true,
        'hierarchical'      => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'rewrite'           => ['slug' => 'area', 'with_front' => false, 'hierarchical' => true],
    ]);

    // ── 施設種別 ─────────────────────────────────────────────
    // 現行は「病院224 / 医療221」「公園・公共施設179 / 公園153」のように
    // 同義タームが並走していた。原因はカテゴリ名がACFフィールド名
    // （周辺環境_医療 など）にハードコードされ、タクソノミーと二重管理だったこと。
    // 移行時に10前後へ統合する。
    register_taxonomy('facility_type', ['facility'], [
        'labels' => hb2103_tax_labels('施設種別', true),
        'description'       => '周辺施設の種別。移行時に重複タームを統合する。',
        'public'            => false,
        'publicly_queryable' => false,
        'show_ui'           => true,
        'hierarchical'      => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'rewrite'           => false,
    ]);

    // ── 設備・仕様の分類 ─────────────────────────────────────
    // 設備・仕様そのものは CPT（equipment）。これはその並びをまとめるための分類で、
    // 旧サイトのターム側メタ「情報管理_分類」にあたる。
    // 旧データは「キッチン」のような区分名と「食器洗い乾燥機」のような品目が
    // 同じ階層に混ざっていたので、区分だけをここに持たせる。
    register_taxonomy('equipment_type', ['equipment'], [
        'labels' => hb2103_tax_labels('分類', false),
        'public'             => false,
        'publicly_queryable' => false,
        'show_ui'            => true,
        'show_admin_column'  => true,
        'hierarchical'       => true,
        'show_in_rest'       => true,
        'rewrite'            => false,
    ]);

    // ── メディア分類 ─────────────────────────────────────────
    // FileBird のようなフォルダプラグインを使わず、本体のタクソノミーで分類する。
    // 保存先が wp_terms / wp_term_relationships なので、
    // 将来どのプラグインを入れても外しても分類が消えない（ロックインしない）。
    register_taxonomy('media_category', ['attachment'], [
        'labels' => hb2103_tax_labels('メディア分類', true),
        'description'       => 'メディアの用途分類。本体のタクソノミーなのでプラグインに依存しない。',
        'public'            => false,
        'publicly_queryable' => false,
        'show_ui'           => true,
        'show_in_menu'      => true,
        'hierarchical'      => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'rewrite'           => false,
    ]);
}

/**
 * タクソノミーのラベルを日本語でまとめて作る。
 */
function hb2103_tax_labels(string $name, bool $hierarchical = true): array
{
    $labels = [
        'name'          => $name,
        'singular_name' => $name,
        'menu_name'     => $name,
        'all_items'     => 'すべての' . $name,
        'edit_item'     => $name . 'を編集',
        'view_item'     => $name . 'を表示',
        'update_item'   => $name . 'を更新',
        'add_new_item'  => $name . 'を追加',
        'new_item_name' => '新しい' . $name,
        'search_items'  => $name . 'を検索',
        'not_found'     => $name . 'が見つかりません',
        'back_to_items' => $name . '一覧へ戻る',
    ];

    if ($hierarchical) {
        $labels['parent_item']       = '親' . $name;
        $labels['parent_item_colon'] = '親' . $name . ':';
    } else {
        $labels['popular_items']              = 'よく使う' . $name;
        $labels['separate_items_with_commas'] = $name . 'をカンマで区切ってください';
        $labels['add_or_remove_items']        = $name . 'の追加・削除';
        $labels['choose_from_most_used']      = 'よく使う' . $name . 'から選択';
    }

    return $labels;
}

/**
 * 既定の「カテゴリー」「タグ」はお知らせ用に残すが、ラベルを実態に合わせる。
 */
add_filter('taxonomy_labels_category', function (object $labels): object {
    $labels->name      = 'お知らせカテゴリー';
    $labels->menu_name = 'カテゴリー';
    return $labels;
});

/**
 * 施設カテゴリの初期値。
 *
 * 公開側の周辺環境は「学校」「買い物」のようにカテゴリごとにまとめて出すため、
 * 空のままだと分類できない。よく使うものを最初だけ入れておく。
 *
 * 一度きり（オプションで記録）にしているのは、不要なカテゴリを消したときに
 * 次の読み込みで復活しないようにするため。
 */
add_action('init', 'hb2103_seed_facility_types', 20);

function hb2103_seed_facility_types(): void
{
    if (get_option('hb2103_facility_types_seeded')) {
        return;
    }
    update_option('hb2103_facility_types_seeded', 1, false);

    foreach (hb2103_facility_type_defaults() as $slug => $def) {
        if (!term_exists($slug, 'facility_type')) {
            wp_insert_term($def['name'], 'facility_type', ['slug' => $slug]);
        }
    }


}

/**
 * 施設カテゴリの初期値と、Googleマップの施設種別との対応。
 *
 * google は Places が返す types。地図で施設を選んだときに種別を自動で選ぶのに使う。
 */
function hb2103_facility_type_defaults(): array
{
    return [
        'station'  => ['name' => '駅・バス停', 'google' => ['train_station', 'subway_station', 'light_rail_station', 'transit_station', 'bus_station', 'bus_stop']],
        'school'   => ['name' => '学校',       'google' => ['primary_school', 'secondary_school', 'school', 'university']],
        'nursery'  => ['name' => '保育園・幼稚園', 'google' => ['preschool', 'child_care_agency']],
        'shopping' => ['name' => '買い物',     'google' => ['supermarket', 'convenience_store', 'shopping_mall', 'department_store', 'grocery_or_supermarket', 'drugstore', 'home_goods_store', 'store']],
        'medical'  => ['name' => '医療',       'google' => ['hospital', 'doctor', 'dentist', 'pharmacy', 'health']],
        'bank'     => ['name' => '金融・郵便', 'google' => ['bank', 'atm', 'post_office']],
        'public'   => ['name' => '公共施設',   'google' => ['city_hall', 'local_government_office', 'library', 'police', 'fire_station', 'community_center']],
        'park'     => ['name' => '公園',       'google' => ['park', 'campground', 'stadium', 'gym']],
        'dining'   => ['name' => '飲食',       'google' => ['restaurant', 'cafe', 'bakery', 'meal_takeaway', 'food']],
    ];
}

/**
 * 施設カテゴリの一覧を、Googleの施設種別との対応つきで返す。
 */
function hb2103_facility_type_choices(): array
{
    $defaults = hb2103_facility_type_defaults();
    $terms = get_terms(['taxonomy' => 'facility_type', 'hide_empty' => false]);
    if (is_wp_error($terms)) {
        return [];
    }

    $out = [];
    foreach ($terms as $t) {
        $out[] = [
            'id'     => (int) $t->term_id,
            'name'   => $t->name,
            'google' => $defaults[$t->slug]['google'] ?? [],
        ];
    }
    return $out;
}

/**
 * 設備・仕様の分類の初期値。
 *
 * 旧サイトで区分として使われていた語をそのまま入れる
 * （タームのメタ「情報管理_分類」にあたる区分）。
 * 施設カテゴリとは別のフラグで管理する。片方を消しても、
 * もう片方の初期登録が道連れにならないようにするため。
 */
add_action('init', 'hb2103_seed_equipment_types', 20);

function hb2103_seed_equipment_types(): void
{
    if (get_option('hb2103_equipment_types_seeded')) {
        return;
    }
    update_option('hb2103_equipment_types_seeded', 1, false);

    $types = ['キッチン', 'バス', 'トイレ', '洗面', '収納', '給湯システム', '断熱', '防犯', '構造', 'その他設備'];
    foreach ($types as $name) {
        if (!term_exists($name, 'equipment_type')) {
            wp_insert_term($name, 'equipment_type');
        }
    }
}
