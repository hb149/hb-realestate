<?php
/**
 * 分譲地の価格・面積の範囲を区画から計算する
 *
 * 現行サイトは「1,066万円 〜 1,129万円」を人が手で入力していた。
 * そのため区切り文字が 〜(U+301C) / ～(U+FF5E) / ・ と混在し、
 * 実際の区画価格と表示がずれても気づけない状態だった。
 * ここで区画の数値から組み立てることで、入力をなくし、ずれも起きなくする。
 *
 * 上書きしたいときのために range_override_price / range_override_area を残してある。
 * 編集画面ではその入力欄のプレースホルダに計算結果を出すので、
 * 「自動だと何が出るのか」を見てから上書きの要否を判断できる。
 */

if (!defined('ABSPATH')) {
    exit;
}

/** 範囲表示に使う区切り。全角チルダに統一する */
const HB2103_RANGE_SEP = '〜';

/**
 * 範囲の計算対象にする区画。
 * 契約済だけを除く（販売中・申込中が「いま買える価格帯」）。
 * すべて契約済なら、実績として全区画から出す。
 */
function hb2103_lots_for_range(int $property_id): array
{
    $rows = get_field('lots', $property_id);
    if (!is_array($rows) || !$rows) {
        return [];
    }

    $available = array_filter($rows, static function ($row): bool {
        return ($row['status'] ?? '') !== 'contracted';
    });

    return $available ?: $rows;
}

/**
 * 数値の最小・最大を取り出す。値が無い区画は無視する。
 *
 * @param callable $pick 区画1行から数値を取り出す処理
 * @return array{0:float,1:float}|null
 */
function hb2103_minmax(array $rows, callable $pick): ?array
{
    $values = [];
    foreach ($rows as $row) {
        $v = $pick($row);
        if ($v !== null && $v !== '' && is_numeric($v) && (float) $v > 0) {
            $values[] = (float) $v;
        }
    }

    return $values ? [min($values), max($values)] : null;
}

/** 円を万円表記にする。端数が出るときだけ小数を見せる */
function hb2103_format_man(float $yen): string
{
    $man = $yen / 10000;
    $decimals = (abs($man - round($man)) < 0.005) ? 0 : 1;
    return number_format($man, $decimals) . '万円';
}

function hb2103_format_sqm(float $sqm): string
{
    return number_format($sqm, 2) . '㎡';
}

/**
 * 「1,066万円 〜 1,129万円」。最小と最大が同じなら1つだけ返す。
 */
function hb2103_price_range(int $property_id): string
{
    $rows = hb2103_lots_for_range($property_id);
    // 建売は販売価格、宅地は土地価格。新価格があればそちらを優先する
    $range = hb2103_minmax($rows, static function (array $row) {
        // 価格・面積は区画の「詳細」グループの中にある
        $detail = $row['detail'] ?? [];
        $price  = $detail['price'] ?? [];
        foreach (['revised', 'sale'] as $key) {
            if (!empty($price[$key])) {
                return $price[$key];
            }
        }
        return $detail['land_price']['amount'] ?? null;
    });

    if (!$range) {
        return '';
    }

    [$min, $max] = $range;
    return $min === $max
        ? hb2103_format_man($min)
        : hb2103_format_man($min) . ' ' . HB2103_RANGE_SEP . ' ' . hb2103_format_man($max);
}

/**
 * 「165.00㎡ 〜 196.30㎡」
 */
function hb2103_area_range(int $property_id): string
{
    $rows = hb2103_lots_for_range($property_id);
    // ㎡が空で坪だけ入っている区画もあるので、換算込みで取り出す
    $range = hb2103_minmax($rows, static function (array $row) {
        $area = hb2103_lot_area($row, 'land');
        return $area ? $area['sqm'] : null;
    });

    if (!$range) {
        return '';
    }

    [$min, $max] = $range;
    return $min === $max
        ? hb2103_format_sqm($min)
        : hb2103_format_sqm($min) . ' ' . HB2103_RANGE_SEP . ' ' . hb2103_format_sqm($max);
}

/**
 * 表示に使う最終的な値。上書きがあればそれ、無ければ計算結果。
 * テンプレートからはこの2つを呼ぶ。
 */
function hb2103_display_price_range(int $property_id): string
{
    $override = (string) get_field('range_override_price', $property_id);
    return $override !== '' ? $override : hb2103_price_range($property_id);
}

function hb2103_display_area_range(int $property_id): string
{
    $override = (string) get_field('range_override_area', $property_id);
    return $override !== '' ? $override : hb2103_area_range($property_id);
}

/* ------------------------------------------------------------------ *
 * 編集画面：上書き欄のプレースホルダに計算結果を出す
 * ------------------------------------------------------------------ */

add_filter('acf/prepare_field/key=field_hb2103_p_price_override', 'hb2103_placeholder_price_range');
add_filter('acf/prepare_field/key=field_hb2103_p_area_override', 'hb2103_placeholder_area_range');

function hb2103_placeholder_price_range(array $field): array
{
    return hb2103_set_calculated_placeholder($field, 'hb2103_price_range');
}

function hb2103_placeholder_area_range(array $field): array
{
    return hb2103_set_calculated_placeholder($field, 'hb2103_area_range');
}

/**
 * 計算結果をプレースホルダに差し込む。
 * 保存前の区画を反映したいが、この時点ではDBの値しか見えないので
 * 「保存すると反映される」ことが分かる文言にしておく。
 */
function hb2103_set_calculated_placeholder(array $field, callable $calc): array
{
    $post_id = hb2103_current_admin_post_id();
    if ($post_id === 0 || get_post_type($post_id) !== 'property') {
        return $field;
    }

    // プレースホルダには「そのまま入る値」だけを出す。
    // 注記を付けると、その文字列ごと入力されると誤解されるため。
    $value = $calc($post_id);
    $field['placeholder'] = $value;

    return $field;
}

/**
 * 編集画面で表示中の投稿ID。
 * acf/prepare_field はループ外でも呼ばれるので get_the_ID() に頼らない。
 */
function hb2103_current_admin_post_id(): int
{
    if (!is_admin()) {
        return 0;
    }

    foreach (['post', 'post_ID'] as $key) {
        if (isset($_REQUEST[$key]) && is_numeric($_REQUEST[$key])) {
            return (int) $_REQUEST[$key];
        }
    }

    $id = get_the_ID();
    return $id ? (int) $id : 0;
}

/* ------------------------------------------------------------------ *
 * ㎡ と 坪 の換算
 *
 * 1坪 = 6尺四方 = (60/33)^2 = 400/121 ㎡（約3.3058㎡）。
 * 法令上の定義なので定数で持つ。
 * ------------------------------------------------------------------ */

const HB2103_SQM_PER_TSUBO = 400 / 121;

function hb2103_sqm_to_tsubo(float $sqm): float
{
    return $sqm / HB2103_SQM_PER_TSUBO;
}

function hb2103_tsubo_to_sqm(float $tsubo): float
{
    return $tsubo * HB2103_SQM_PER_TSUBO;
}

/**
 * 区画1行から面積を㎡と坪の両方で取り出す。
 * 入力されていない方は換算して埋める。どちらも空なら null。
 *
 * @param string $which 'land' か 'building'
 * @return array{sqm:float,tsubo:float}|null
 */
function hb2103_lot_area(array $row, string $which = 'land'): ?array
{
    // 「詳細」グループに入っている。グループ化前の形でも読めるようにしておく。
    $area  = $row['detail']['area'] ?? ($row['area'] ?? []);
    $sqm   = $area[$which . '_sqm'] ?? '';
    $tsubo = $area[$which . '_tsubo'] ?? '';

    if (is_numeric($sqm) && (float) $sqm > 0) {
        $sqm = (float) $sqm;
        return ['sqm' => $sqm, 'tsubo' => (is_numeric($tsubo) && (float) $tsubo > 0) ? (float) $tsubo : hb2103_sqm_to_tsubo($sqm)];
    }

    if (is_numeric($tsubo) && (float) $tsubo > 0) {
        $tsubo = (float) $tsubo;
        return ['sqm' => hb2103_tsubo_to_sqm($tsubo), 'tsubo' => $tsubo];
    }

    return null;
}

/**
 * 「178.19㎡（53.90坪）」の形。現行サイトの表記に合わせる。
 */
function hb2103_format_area(array $area): string
{
    return sprintf('%s㎡（%s坪）', number_format($area['sqm'], 2), number_format($area['tsubo'], 2));
}

/* ------------------------------------------------------------------ *
 * 区画の街区わけ
 *
 * まれに「A-1」「B-3」のように街区つきで番号を振ることがある
 * （現行データでは503区画中13件・2.6%）。
 * 専用の入力欄は設けず、号地番号のハイフンの前を街区として扱う。
 * 97%の物件では使われないので、常設のフィールドを増やさない判断。
 * ------------------------------------------------------------------ */

/**
 * 号地番号を街区と番号に分ける。
 * ハイフンが無ければ街区なし。
 *
 *   'A-1' → ['block' => 'A', 'number' => '1']
 *   '12'  → ['block' => '',  'number' => '12']
 *
 * @return array{block:string,number:string}
 */
function hb2103_split_lot_no(string $lot_no): array
{
    $lot_no = trim($lot_no);

    // 半角・全角のハイフン類すべてを区切りとして見る
    if (preg_match('/^(.+?)\s*[-‐‑–—―ー－−]\s*(.+)$/u', $lot_no, $m)) {
        return ['block' => trim($m[1]), 'number' => trim($m[2])];
    }

    return ['block' => '', 'number' => $lot_no];
}

/**
 * 区画を街区ごとにまとめる。街区が無ければ空文字のキーに全部入る。
 * テンプレートはこれを回して、キーがあれば見出しを出す。
 *
 *   ['A' => [区画, 区画], 'B' => [区画]]
 *   ['' => [区画, 区画, 区画]]        ← 街区を使っていない物件
 */
function hb2103_lots_by_block(int $property_id): array
{
    $rows = get_field('lots', $property_id);
    if (!is_array($rows)) {
        return [];
    }

    $grouped = [];
    foreach ($rows as $row) {
        $parts = hb2103_split_lot_no((string) ($row['lot_no'] ?? ''));
        $row['_block']  = $parts['block'];
        $row['_number'] = $parts['number'];
        $grouped[$parts['block']][] = $row;
    }

    // 街区ありの物件では街区名で並べる
    if (count($grouped) > 1 || !isset($grouped[''])) {
        ksort($grouped, SORT_NATURAL);
    }

    return $grouped;
}

/**
 * この分譲地が街区わけを使っているか。
 * テンプレートで見出しを出すかどうかの判定に使う。
 */
function hb2103_uses_blocks(int $property_id): bool
{
    $grouped = hb2103_lots_by_block($property_id);
    return count($grouped) > 1 || (count($grouped) === 1 && !isset($grouped['']));
}

/**
 * 分譲地に付いている設備・仕様（設備・仕様CPTの投稿ID配列）。
 */
function hb2103_property_equipment(int $property_id): array
{
    $ids = get_field('equipment', $property_id);
    return is_array($ids) ? array_map('intval', $ids) : [];
}

/**
 * 区画に適用される設備・仕様（投稿ID配列）。
 *
 * 区画側が空なら分譲地のものをそのまま使う。
 * 区画側に選択があれば、その区画はそれだけになる（上書き）。
 *
 * @param array $lot 区画のリピーター1行分
 */
function hb2103_lot_equipment(int $property_id, array $lot): array
{
    $own = $lot['detail']['equipment'] ?? [];
    if (is_array($own) && $own) {
        return array_map('intval', $own);
    }
    return hb2103_property_equipment($property_id);
}

/**
 * 設備・仕様を分類ごとにまとめる。
 *
 * 公開側は「キッチン」「バス」のように分類ごとに並べるため。
 * 分類の付いていないものは末尾の「その他」にまとめる。
 *
 * @param int[] $equipment_ids
 * @return array 分類名 => 設備・仕様の投稿IDの配列
 */
function hb2103_group_equipment(array $equipment_ids): array
{
    $groups = [];
    foreach ($equipment_ids as $id) {
        $terms = wp_get_object_terms($id, 'equipment_type');
        $name  = (!is_wp_error($terms) && $terms) ? $terms[0]->name : 'その他';
        $groups[$name][] = (int) $id;
    }

    // 分類の登録順（term_order ではなく名前順を避け、タクソノミーの並びに合わせる）
    $ordered = [];
    $types = get_terms(['taxonomy' => 'equipment_type', 'hide_empty' => false]);
    if (!is_wp_error($types)) {
        foreach ($types as $t) {
            if (isset($groups[$t->name])) {
                $ordered[$t->name] = $groups[$t->name];
                unset($groups[$t->name]);
            }
        }
    }
    // 残り（その他など）は最後に
    return $ordered + $groups;
}

/**
 * 分譲地の中で、設備・仕様が区画ごとに分かれているか。
 *
 * 公開側で「設備・仕様」を分譲地の概要に1つ出すか、区画ごとに出すかの判断に使う。
 */
function hb2103_equipment_varies_by_lot(int $property_id): bool
{
    $lots = get_field('lots', $property_id);
    if (!is_array($lots)) {
        return false;
    }

    foreach ($lots as $lot) {
        $own = $lot['detail']['equipment'] ?? [];
        if (is_array($own) && $own) {
            return true;
        }
    }
    return false;
}
