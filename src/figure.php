<?php
/**
 * 図版の出力 — SVGと通常画像の両方を受ける
 *
 * ■ なぜ必要か
 *   区画図はSVGで各区画をクリックできるようにしたい。
 *   ただし <img src="plat.svg"> ではSVG内部の要素にイベントを付けられないため、
 *   クリックさせるにはSVGをHTMLへ直接展開（インライン化）する必要がある。
 *   一方、区画図が通常の画像（PNG/JPG）のこともある。
 *   どちらを入れても同じ呼び出しで出せるようにしておく。
 *
 * ■ 安全性
 *   SVGはスクリプトを含められるので、そのまま吐くと危険。
 *   アップロード時は safe-svg が除去するが、ここでも出力前に落としておく。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 添付IDを受け取り、SVGならインライン展開、画像なら <img> を返す。
 *
 * @param array $args class / alt / sizes などの上書き
 */
function hb2103_figure(int $attachment_id, array $args = []): string
{
    if ($attachment_id <= 0) {
        return '';
    }

    $args = wp_parse_args($args, [
        'class' => '',
        'size'  => 'large',
        'alt'   => '',
    ]);

    if (get_post_mime_type($attachment_id) === 'image/svg+xml') {
        return hb2103_inline_svg($attachment_id, (string) $args['class']);
    }

    $alt = $args['alt'] !== ''
        ? $args['alt']
        : (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

    return (string) wp_get_attachment_image($attachment_id, $args['size'], false, [
        'class'    => trim('hb2103-figure ' . $args['class']),
        'alt'      => $alt,
        'decoding' => 'async',
        'loading'  => 'lazy',
    ]);
}

/**
 * SVGファイルの中身をページへ展開する。
 * 展開するとCSSで色を変えたり、各区画に data 属性を付けてJSから掴んだりできる。
 */
function hb2103_inline_svg(int $attachment_id, string $class = ''): string
{
    $path = get_attached_file($attachment_id);
    if (!$path || !is_readable($path)) {
        return '';
    }

    // 同じファイルを何度も読まないようキャッシュする。
    // 元ファイルが差し替わったら mtime が変わるのでキーも変わる。
    $key    = 'hb2103_svg_' . $attachment_id . '_' . (int) filemtime($path);
    $cached = get_transient($key);
    if (is_string($cached) && $cached !== '') {
        return hb2103_apply_svg_class($cached, $class);
    }

    $svg = file_get_contents($path);
    if ($svg === false) {
        return '';
    }

    $svg = hb2103_sanitize_svg($svg);
    if ($svg === '') {
        return '';
    }

    set_transient($key, $svg, WEEK_IN_SECONDS);

    return hb2103_apply_svg_class($svg, $class);
}

/**
 * 出力前にSVGから実行されうるものを落とす。
 * safe-svg がアップロード時に処理しているが、
 * 既存ファイルの移行分や手動配置に備えてここでも通す。
 */
function hb2103_sanitize_svg(string $svg): string
{
    // XML宣言・DOCTYPE（外部実体参照の経路になる）を除去
    $svg = preg_replace('/<\?xml[^>]*\?>/i', '', $svg);
    $svg = preg_replace('/<!DOCTYPE[^>]*>/is', '', $svg);

    // script / foreignObject と、イベント属性・javascript: URL を除去
    $svg = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $svg);
    $svg = preg_replace('#<foreignObject\b[^>]*>.*?</foreignObject>#is', '', $svg);
    $svg = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg);
    $svg = preg_replace('/(href|xlink:href)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', '', $svg);

    $svg = trim((string) $svg);

    // <svg> で始まらないものは扱わない
    return str_starts_with(strtolower($svg), '<svg') ? $svg : '';
}

/**
 * ルートの <svg> にクラスを足す。
 * テンプレート側からスタイルやJSの取っ掛かりを与えるため。
 */
function hb2103_apply_svg_class(string $svg, string $class): string
{
    $class = trim('hb2103-figure hb2103-svg ' . $class);

    if (preg_match('/<svg\b[^>]*\bclass\s*=\s*"([^"]*)"/i', $svg)) {
        return preg_replace(
            '/(<svg\b[^>]*\bclass\s*=\s*")([^"]*)(")/i',
            '$1$2 ' . esc_attr($class) . '$3',
            $svg,
            1
        );
    }

    return preg_replace('/<svg\b/i', '<svg class="' . esc_attr($class) . '"', $svg, 1);
}

/**
 * 分譲地の区画図。クリックできるSVGのときはJSが掴めるよう印を付ける。
 */
function hb2103_plat_figure(int $property_id): string
{
    $id = (int) get_field('plat', $property_id);
    if ($id <= 0) {
        return '';
    }

    $is_svg = get_post_mime_type($id) === 'image/svg+xml';
    $figure = hb2103_figure($id, ['class' => 'hb2103-plat__figure', 'size' => 'hb2103-plan']);

    if ($is_svg) {
        $figure = hb2103_plat_link_lots($figure, $property_id);
    }

    return sprintf(
        '<div class="hb2103-plat%s" data-property="%d">%s</div>',
        $is_svg ? ' is-interactive' : '',
        $property_id,
        $figure
    );
}

/**
 * 号地番号の照合キーを作る。
 *
 * Illustrator はレイヤー名をそのまま id にするが、使えない文字を
 * `_x20_`（空白）のように逃がし、数字で始まる名前には `_` を足す。
 * 「D-1」「d_1」「lot-D-1」「_x44_-1」などが同じ区画を指せるよう、
 * 比較する前に形をそろえる。
 */
function hb2103_plat_key(string $raw): string
{
    $s = $raw;

    // Illustrator のエスケープ（_x41_ → A）を戻す
    $s = preg_replace_callback('/_x([0-9A-Fa-f]{2,6})_/', static function (array $m): string {
        return mb_chr((int) hexdec($m[1]), 'UTF-8') ?: '';
    }, $s);

    // 全角英数を半角に
    $s = mb_convert_kana($s, 'as', 'UTF-8');
    $s = mb_strtoupper($s, 'UTF-8');

    // 接頭辞・接尾辞を落とす
    $s = preg_replace('/^(LOT|BLOCK|KUKAKU|区画|号地)[-_\s]*/u', '', $s);
    $s = preg_replace('/[-_\s]*(号地|号)$/u', '', $s);

    // 区切り記号とダッシュ類をそろえて、残りの記号は捨てる
    $s = preg_replace('/[‐‑–—―ー－−_\s]+/u', '-', $s);
    $s = trim($s, '-');

    return $s;
}

/**
 * 区画図のSVGに、各区画へのリンクと販売状況を付ける。
 *
 * 対応づけは SVG の `id`（Illustrator のレイヤー名）と号地番号。
 * `data-lot` 属性があればそちらを優先する（手で指定したいとき用）。
 *
 * リンク先は同じページ内の区画一覧（`#lot-xxx`）。
 * 号地は独立した投稿ではないので、個別URLは持たせず同一ページ内で移動する。
 */
function hb2103_plat_link_lots(string $svg, int $property_id): string
{
    $lots = get_field('lots', $property_id);
    if (!is_array($lots) || !$lots || $svg === '') {
        return $svg;
    }

    // 号地番号 → 区画
    $by_key = [];
    foreach ($lots as $lot) {
        $no = trim((string) ($lot['lot_no'] ?? ''));
        if ($no === '') {
            continue;
        }
        $by_key[hb2103_plat_key($no)] = $lot;
    }
    if (!$by_key) {
        return $svg;
    }

    $labels = hb2103_lot_status_labels();

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    // SVGは断片なので HTML としてではなく XML として読む
    $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOENT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) {
        return $svg;
    }

    $ns    = 'http://www.w3.org/2000/svg';
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('svg', $ns);

    $matched = [];

    foreach ($xpath->query('//*[@id or @data-lot]') as $el) {
        if (!$el instanceof DOMElement) {
            continue;
        }

        $raw = $el->getAttribute('data-lot') ?: $el->getAttribute('id');
        $key = hb2103_plat_key($raw);
        if ($key === '' || !isset($by_key[$key]) || isset($matched[$key])) {
            continue;
        }

        $lot    = $by_key[$key];
        $no     = trim((string) $lot['lot_no']);
        $status = (string) ($lot['status'] ?? '');
        $matched[$key] = true;

        $el->setAttribute('data-lot', $no);
        $el->setAttribute('class', trim($el->getAttribute('class') . ' hb2103-plat__lot'
            . ($status !== '' ? ' is-' . sanitize_html_class($status) : '')));

        // 読み上げとホバー用の名前
        $name = $no . '号地'
            . (isset($labels[$status]) ? '（' . $labels[$status] . '）' : '');

        $title = $doc->createElementNS($ns, 'title', esc_html($name));
        $el->insertBefore($title, $el->firstChild);

        // JSが無くても動くよう、要素ごと <a> で包む
        $a = $doc->createElementNS($ns, 'a');
        $a->setAttribute('href', '#lot-' . sanitize_title($no));
        $a->setAttribute('aria-label', $name);
        $el->parentNode->replaceChild($a, $el);
        $a->appendChild($el);
    }

    if (!$matched) {
        return $svg;
    }

    return (string) $doc->saveXML($doc->documentElement);
}

/**
 * 販売状況の値 → 表示名。ACFの選択肢をそのまま使う。
 */
function hb2103_lot_status_labels(): array
{
    $field = function_exists('acf_get_field') ? acf_get_field('field_hb2103_lotr_status') : null;
    return (is_array($field) && !empty($field['choices'])) ? $field['choices'] : [];
}

/**
 * 区画図と号地番号の照合結果。
 *
 * SVGのレイヤー名が号地番号と合っていないと、その区画だけ反応しない。
 * 気づきにくいので、編集画面で知らせるために使う。
 *
 * @return array{matched:string[], missing:string[], extra:string[]}
 */
function hb2103_plat_match_report(int $property_id): array
{
    $out = ['matched' => [], 'missing' => [], 'extra' => []];

    $id = (int) get_field('plat', $property_id);
    $lots = get_field('lots', $property_id);
    if ($id <= 0 || get_post_mime_type($id) !== 'image/svg+xml' || !is_array($lots)) {
        return $out;
    }

    $svg = hb2103_inline_svg($id);
    if ($svg === '') {
        return $out;
    }

    $doc  = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok   = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOENT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) {
        return $out;
    }

    $ids = [];
    foreach ((new DOMXPath($doc))->query('//*[@id or @data-lot]') as $el) {
        if ($el instanceof DOMElement) {
            $raw = $el->getAttribute('data-lot') ?: $el->getAttribute('id');
            $key = hb2103_plat_key($raw);
            if ($key !== '') {
                $ids[$key] = $raw;
            }
        }
    }

    foreach ($lots as $lot) {
        $no = trim((string) ($lot['lot_no'] ?? ''));
        if ($no === '') {
            continue;
        }
        $key = hb2103_plat_key($no);
        if (isset($ids[$key])) {
            $out['matched'][] = $no;
            unset($ids[$key]);
        } else {
            $out['missing'][] = $no;
        }
    }

    $out['extra'] = array_values($ids);

    return $out;
}
