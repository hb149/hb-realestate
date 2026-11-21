<?php
/**
 * スラッグ（URL）の入力を必須にする
 *
 * ■ なぜ必要か
 *   WordPress は標準だとタイトルからスラッグを作る。タイトルが日本語なら
 *   スラッグも日本語になり、URLはパーセントエンコードされて読めなくなる。
 *     /property/%e5%8b%95%e4%bd%9c%e7%a2%ba%e8%aa%8d.../
 *   旧サイトはこれで日本語URLが大量に残っており、リニューアルで英字へ振り直す方針。
 *   移行データは英字を振るが、**公開後に手で追加する分**も同じ方針を保つ必要がある。
 *
 * ■ やり方
 *   自動でローマ字化はしない（地名の読みは機械的に決まらず誤変換が出る）。
 *   英字スラッグが入るまで公開させず、下書きで止めて入力を促す。
 *   営業資料にも載るURLなので、人が決めた英字のほうが都合がよい。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * スラッグを必須にする投稿タイプ。
 */
function hb2103_slug_guard_types(): array
{
    return ['property', 'facility', 'works', 'voice'];
}

/**
 * 英字スラッグとして使えるか。
 */
function hb2103_slug_is_ascii(string $slug): bool
{
    // post_name は保存時にパーセントエンコードされるので戻してから見る
    $slug = urldecode($slug);
    return $slug !== '' && (bool) preg_match('/^[a-z0-9][a-z0-9\-_]*$/', $slug);
}

/**
 * 英字スラッグが無いまま公開しようとしたら下書きで止める。
 */
add_filter('wp_insert_post_data', 'hb2103_require_ascii_slug', 10, 2);

function hb2103_require_ascii_slug(array $data, array $postarr): array
{
    if (!in_array($data['post_type'], hb2103_slug_guard_types(), true)) {
        return $data;
    }
    // 自動下書き・リビジョン・ゴミ箱は対象外
    if (in_array($data['post_status'], ['auto-draft', 'inherit', 'trash'], true)) {
        return $data;
    }
    // 下書きのまま保存するのは止めない（あとで入れればよい）
    if (!in_array($data['post_status'], ['publish', 'future', 'pending', 'private'], true)) {
        return $data;
    }
    if (hb2103_slug_is_ascii((string) $data['post_name'])) {
        return $data;
    }

    $data['post_status'] = 'draft';
    set_transient('hb2103_slug_blocked_' . get_current_user_id(), (int) ($postarr['ID'] ?? 0), 60);

    return $data;
}

/**
 * 止めた理由を編集画面に出す。
 */
add_action('admin_notices', 'hb2103_slug_blocked_notice');

function hb2103_slug_blocked_notice(): void
{
    $key = 'hb2103_slug_blocked_' . get_current_user_id();
    if (get_transient($key) === false) {
        return;
    }
    delete_transient($key);

    printf(
        '<div class="notice notice-warning"><p><strong>%s</strong><br>%s</p></div>',
        esc_html('URL（スラッグ）が英字でないため、下書きのまま保存しました。'),
        esc_html('タイトル下の「パーマリンク」か右側の「スラッグ」欄に英小文字・数字・ハイフンで入力してから公開してください。例: mitsusadadai')
    );
}

/**
 * 公開前にその場で気づけるようにする。
 *
 * 保存してから下書きに落ちると戻るのが手間なので、
 * 送信前に見て、入っていなければ止めてスラッグ欄へ案内する。
 */
add_action('admin_footer-post.php', 'hb2103_slug_guard_script');
add_action('admin_footer-post-new.php', 'hb2103_slug_guard_script');

function hb2103_slug_guard_script(): void
{
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->post_type, hb2103_slug_guard_types(), true)) {
        return;
    }
    ?>
<script>
jQuery(function ($) {
    // スラッグはパーマリンク行（#editable-post-name-full）と
    // 「スラッグ」カード（#post_name）の2か所にある。入っているほうを見る。
    function currentSlug() {
        var box = $('#post_name').val();
        if (typeof box === 'string' && box !== '') { return box; }
        var perma = $('#editable-post-name-full').text() || $('#editable-post-name').text();
        return $.trim(perma || '');
    }

    function isAscii(slug) {
        try { slug = decodeURIComponent(slug); } catch (e) {}
        return /^[a-z0-9][a-z0-9\-_]*$/.test(slug);
    }

    $('#post').on('submit', function (e) {
        // 下書き保存・プレビューは止めない
        var publishing = $(document.activeElement).attr('id') === 'publish'
            || $('#post_status').val() === 'publish';
        if (!publishing) { return; }

        if (isAscii(currentSlug())) { return; }

        e.preventDefault();
        $('.spinner').removeClass('is-active');
        $('#publish, #save-post').removeClass('disabled').prop('disabled', false);

        window.alert('URL（スラッグ）を英字で入れてください。\n英小文字・数字・ハイフンが使えます。例: mitsusadadai');

        // 入力欄へ案内する。パーマリンク行の「編集」を開ければそれを使う。
        var $edit = $('#edit-slug-buttons .edit-slug');
        if ($edit.length) {
            $edit.trigger('click');
            setTimeout(function () { $('#new-post-slug').trigger('focus').trigger('select'); }, 120);
        } else if ($('#post_name').length) {
            $('#post_name').trigger('focus');
        }
    });
});
</script>
    <?php
}
