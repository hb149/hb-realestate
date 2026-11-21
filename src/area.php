<?php
/**
 * エリアの自動付与
 *
 * 所在地から市区町村を読み取り、area タクソノミーに付ける。
 * 該当するタームが無ければ作る。
 *
 * 階層は「市郡 > 区町村」。
 *   福岡県北九州市小倉南区上吉田1丁目4番 → 北九州市 > 小倉南区
 *   福岡県那珂川市後野2丁目              → 那珂川市
 *   福岡県京都郡苅田町新津                → 京都郡 > 苅田町
 *
 * 旧サイトの area_category は市・区・町名が同一階層に314ターム混在していた。
 * 住所から機械的に決めることで、同じ地域に別のタームが増えるのを防ぐ。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 市区町村名 → スラッグ。
 *
 * area は公開タクソノミーでURLに出るため、日本語スラッグだと
 * パーセントエンコードされて読めないURLになる。福岡県の市区町村は数が決まっているので表で持つ。
 * 表に無い場合は WordPress 既定（日本語のまま）になるので、必要なら手で直す。
 */
function hb2103_area_slugs(): array
{
    return [
        // 市
        '北九州市' => 'kitakyushu', '福岡市' => 'fukuoka', '大牟田市' => 'omuta',
        '久留米市' => 'kurume', '直方市' => 'nogata', '飯塚市' => 'iizuka',
        '田川市' => 'tagawa', '柳川市' => 'yanagawa', '八女市' => 'yame',
        '筑後市' => 'chikugo', '大川市' => 'okawa', '行橋市' => 'yukuhashi',
        '豊前市' => 'buzen', '中間市' => 'nakama', '小郡市' => 'ogori',
        '筑紫野市' => 'chikushino', '春日市' => 'kasuga', '大野城市' => 'onojo',
        '宗像市' => 'munakata', '太宰府市' => 'dazaifu', '古賀市' => 'koga',
        '福津市' => 'fukutsu', 'うきは市' => 'ukiha', '宮若市' => 'miyawaka',
        '嘉麻市' => 'kama', '朝倉市' => 'asakura', 'みやま市' => 'miyama',
        '糸島市' => 'itoshima', '那珂川市' => 'nakagawa',
        // 郡
        '糟屋郡' => 'kasuya-gun', '遠賀郡' => 'onga-gun', '鞍手郡' => 'kurate-gun',
        '嘉穂郡' => 'kaho-gun', '朝倉郡' => 'asakura-gun', '三井郡' => 'mii-gun',
        '三潴郡' => 'mizuma-gun', '八女郡' => 'yame-gun', '田川郡' => 'tagawa-gun',
        '京都郡' => 'miyako-gun', '築上郡' => 'chikujo-gun',
        // 福岡市の区
        '東区' => 'higashi', '博多区' => 'hakata', '中央区' => 'chuo',
        '南区' => 'minami', '西区' => 'nishi', '城南区' => 'jonan', '早良区' => 'sawara',
        // 北九州市の区
        '門司区' => 'moji', '若松区' => 'wakamatsu', '戸畑区' => 'tobata',
        '小倉北区' => 'kokurakita', '小倉南区' => 'kokuraminami',
        '八幡東区' => 'yahatahigashi', '八幡西区' => 'yahatanishi',
        // 町村
        '宇美町' => 'umi', '篠栗町' => 'sasaguri', '志免町' => 'shime',
        '須恵町' => 'sue', '須惠町' => 'sue', '新宮町' => 'shingu',
        '久山町' => 'hisayama', '粕屋町' => 'kasuya',
        '芦屋町' => 'ashiya', '水巻町' => 'mizumaki', '岡垣町' => 'okagaki', '遠賀町' => 'onga',
        '小竹町' => 'kotake', '鞍手町' => 'kurate', '桂川町' => 'keisen',
        '筑前町' => 'chikuzen', '東峰村' => 'toho', '大刀洗町' => 'tachiarai',
        '大木町' => 'oki', '広川町' => 'hirokawa',
        '香春町' => 'kawara', '添田町' => 'soeda', '糸田町' => 'itoda',
        '川崎町' => 'kawasaki', '大任町' => 'otou', '赤村' => 'aka', '福智町' => 'fukuchi',
        '苅田町' => 'kanda', 'みやこ町' => 'miyako',
        '吉富町' => 'yoshitomi', '上毛町' => 'koge', '築上町' => 'chikujo',
    ];
}

/**
 * 住所から「市郡」と「区町村」を取り出す。
 *
 * @return array{city:string, ward:string} 取れなかった要素は空文字
 */
function hb2103_parse_address_area(string $address): array
{
    $none = ['city' => '', 'ward' => ''];

    $s = trim($address);
    if ($s === '') {
        return $none;
    }

    // Googleが付ける接頭辞を落とす
    $s = preg_replace('/^[23456789CFGHJMPQRVWX]{4,8}\+[23456789CFGHJMPQRVWX]{2,3}\s*/i', '', $s);
    $s = preg_replace('/^日本[、,]\s*/u', '', $s);
    $s = preg_replace('/〒\s*[0-9０-９]{3}[-－−]?[0-9０-９]{4}\s*/u', '', $s);
    $s = trim($s);

    // 都道府県を落とす
    $s = preg_replace('/^(東京都|北海道|京都府|大阪府|.{2,3}?県)/u', '', $s, 1);
    $s = trim($s);

    // 政令指定都市（市のあとに区が続く）
    if (preg_match('/^(.+?市)(.+?区)/u', $s, $m)) {
        return ['city' => $m[1], 'ward' => $m[2]];
    }
    // 郡＋町村
    if (preg_match('/^(.+?郡)(.+?[町村])/u', $s, $m)) {
        return ['city' => $m[1], 'ward' => $m[2]];
    }
    // 市のみ
    if (preg_match('/^(.+?市)/u', $s, $m)) {
        return ['city' => $m[1], 'ward' => ''];
    }
    // 郡を挟まない町村
    if (preg_match('/^(.+?[町村])/u', $s, $m)) {
        return ['city' => $m[1], 'ward' => ''];
    }
    // 特別区（東京）
    if (preg_match('/^(.+?区)/u', $s, $m)) {
        return ['city' => $m[1], 'ward' => ''];
    }

    return $none;
}

/**
 * エリアのタームを名前で引く。無ければ作る。
 *
 * @param int $parent 親タームID（0なら最上位）
 * @return int ターム ID。作れなかったら0
 */
function hb2103_area_term(string $name, int $parent = 0, bool $create = true): int
{
    $name = trim($name);
    if ($name === '') {
        return 0;
    }

    // 同じ親の下から探す（別の市の同名区と混ざらないように）
    $found = get_terms([
        'taxonomy'   => 'area',
        'hide_empty' => false,
        'name'       => $name,
        'parent'     => $parent,
        'number'     => 1,
    ]);
    if (!is_wp_error($found) && $found) {
        return (int) $found[0]->term_id;
    }

    if (!$create) {
        return 0;
    }

    $args = ['parent' => $parent];
    $slugs = hb2103_area_slugs();
    if (isset($slugs[$name])) {
        // 同じスラッグが埋まっていたら連番を足す。
        // ここで諦めて WordPress 任せにすると、日本語のままスラッグになり
        // URLがパーセントエンコードされて読めなくなる。
        $base = $slugs[$name];
        $slug = $base;
        for ($i = 2; get_term_by('slug', $slug, 'area') && $i < 20; $i++) {
            $slug = $base . '-' . $i;
        }
        $args['slug'] = $slug;
    }

    $res = wp_insert_term($name, 'area', $args);
    if (is_wp_error($res)) {
        return 0;
    }
    return (int) $res['term_id'];
}

/**
 * 住所からエリアのタームIDを決める。
 *
 * @param bool $create 無いタームを作るか（下書き表示だけしたいときは false）
 * @return int[] 上位から順。例 [北九州市, 小倉南区]
 */
function hb2103_resolve_area(string $address, bool $create = true): array
{
    $parts = hb2103_parse_address_area($address);
    if ($parts['city'] === '') {
        return [];
    }

    $ids = [];
    $city = hb2103_area_term($parts['city'], 0, $create);
    if (!$city) {
        return [];
    }
    $ids[] = $city;

    if ($parts['ward'] !== '') {
        $ward = hb2103_area_term($parts['ward'], $city, $create);
        if ($ward) {
            $ids[] = $ward;
        }
    }

    return $ids;
}

/**
 * 分譲地・周辺施設の保存時にエリアを付ける。
 *
 * 手で付けたタームは消さない。前回この処理が付けたものだけを
 * `_hb2103_area_auto` で覚えておき、住所が変わったらそれだけ外す。
 */
add_action('acf/save_post', 'hb2103_sync_area_from_address', 30);

function hb2103_sync_area_from_address($post_id): void
{
    if (!is_numeric($post_id)) {
        return;
    }
    $post_id = (int) $post_id;

    if (!in_array(get_post_type($post_id), ['property', 'facility'], true)) {
        return;
    }

    $address = (string) get_field('address', $post_id);
    if (trim($address) === '') {
        $map = get_field('map', $post_id);
        $address = is_array($map) ? (string) ($map['address'] ?? '') : '';
    }

    $auto = hb2103_resolve_area($address, true);
    $prev = get_post_meta($post_id, '_hb2103_area_auto', true);
    $prev = is_array($prev) ? array_map('intval', $prev) : [];

    $current = wp_get_object_terms($post_id, 'area', ['fields' => 'ids']);
    $current = is_wp_error($current) ? [] : array_map('intval', $current);

    // 手で付けたもの = 今付いているもの から 前回自動で付けたもの を引いたもの
    $manual = array_values(array_diff($current, $prev));
    $next   = array_values(array_unique(array_merge($manual, $auto)));

    sort($next);
    sort($current);
    if ($next !== $current) {
        wp_set_object_terms($post_id, $next, 'area');
    }

    if ($auto) {
        update_post_meta($post_id, '_hb2103_area_auto', $auto);
    } else {
        delete_post_meta($post_id, '_hb2103_area_auto');
    }
}
