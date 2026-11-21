<?php
/**
 * 説明文を本文へ同期する
 *
 * ■ なぜ必要か
 *   分譲地は editor サポートを外している（ACFの項目が主役で、ブロックエディタを
 *   有効にするとフィールドが下部の引き出しに押し込まれて実用にならないため）。
 *   その結果 post_content が空になり、WordPress本体の次の仕組みが動かなくなる。
 *
 *     - 抜粋の自動生成   post_content が空なので何も作られない
 *     - サイト内検索     ACFの値は postmeta にあり、本体の検索は post_content しか見ない
 *
 *   実測: 説明文に「下曽根」と書いても検索結果は0件だった。
 *
 * ■ 方針
 *   ACFの説明文（summary）を保存時に post_content へ写す。
 *   こうすると抜粋も検索もフィードもREST APIも、本体の既定の挙動のまま動く。
 *   編集するのは常にACFの欄で、post_content はそこから作られる複製。
 *   editor サポートを外してあるので、本文が直接編集されて食い違うこともない。
 */

if (!defined('ABSPATH')) {
    exit;
}

/** 本文へ同期する投稿タイプと、同期元のフィールド */
function hb2103_content_sync_map(): array
{
    return [
        'property' => 'summary',
    ];
}

/**
 * ACFの保存が終わったあとに本文を更新する。
 * 優先度20は acf/save_post の既定(10)より後。フィールドの値が確定してから読む。
 */
add_action('acf/save_post', 'hb2103_sync_summary_to_content', 20);

function hb2103_sync_summary_to_content($post_id): void
{
    if (!is_numeric($post_id)) {
        return; // options ページなどの保存
    }

    $post_id = (int) $post_id;
    $map     = hb2103_content_sync_map();
    $type    = get_post_type($post_id);

    if (!isset($map[$type])) {
        return;
    }

    $summary = (string) get_field($map[$type], $post_id);
    $current = (string) get_post_field('post_content', $post_id);

    if ($summary === $current) {
        return; // 変化が無ければリビジョンを増やさない
    }

    // wp_update_post が acf/save_post を再び呼ぶことはないが、
    // save_post 経由の処理が二重に走らないよう外しておく
    remove_action('acf/save_post', 'hb2103_sync_summary_to_content', 20);

    wp_update_post([
        'ID'           => $post_id,
        'post_content' => $summary,
    ]);

    add_action('acf/save_post', 'hb2103_sync_summary_to_content', 20);
}

/**
 * 抜粋の長さ。日本語は単語で切れないので文字数で詰める。
 */
add_filter('excerpt_length', static function (int $length): int {
    return is_admin() ? $length : 110;
});

add_filter('excerpt_more', static function (string $more): string {
    return '…';
});

/**
 * 日本語の抜粋は wp_trim_words が空白区切りで数えるため効きにくい。
 * 文字数で切り、途中で切れた場合だけ記号を足す。
 */
add_filter('wp_trim_words', 'hb2103_trim_japanese', 10, 4);

function hb2103_trim_japanese(string $text, int $num_words, string $more, string $original_text): string
{
    // 全角を含まなければ本体の処理に任せる
    if (!preg_match('/[^\x20-\x7E]/u', $original_text)) {
        return $text;
    }

    $plain = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($original_text)));

    return mb_strlen($plain) <= $num_words
        ? $plain
        : mb_substr($plain, 0, $num_words) . $more;
}
