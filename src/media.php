<?php
/**
 * メディアの配置と最適化
 *
 * ■ 現行サイトの問題
 *   - 投稿に紐付いていない孤立メディアが 5,596件（43%）
 *   - uploads/sites/2/2021/07/ に 10,530ファイルが1ディレクトリに
 *   - 配置が年月だけなので、ファイルを見ても何の物件の画像か分からない
 *   メディア分類プラグインを2本（FileBird / Enhanced Media Library）入れていたが
 *   どちらも振り分け0件で運用に乗らなかった。13,000件を手で分類する作業が重すぎたため。
 *
 * ■ 方針
 *   「探す」必要をなくす。分類を頑張るのではなく、置き場所に意味を持たせる。
 *
 *     uploads/property/nakamahigashi/        分譲地の画像・区画図・各号地の写真
 *     uploads/facility/kokura-yoshida-po/    周辺施設
 *     uploads/common/2026/10/                共通素材（年月のまま）
 *
 *   これならサーバーのファイルを見るだけで用途が分かり、
 *   物件単位のバックアップ・引き継ぎもできる。
 */

if (!defined('ABSPATH')) {
    exit;
}

const HB2103_MEDIA_FOLDER = '_hb2103_media_folder';

/* ------------------------------------------------------------------ *
 * アップロード先のフォルダ名
 * ------------------------------------------------------------------ */

/**
 * フォルダ名は最初に決めたものを保存し、以後スラッグ変更に追従させない。
 *
 * 追従させるとスラッグを変えた瞬間に既存ファイルのパスが全部ずれて
 * 画像が全滅する。URLは post_name から都度組み立てるが（permalinks.php）、
 * 実ファイルのパスは不変にしておく。これは意図的な非対称。
 */
function hb2103_media_folder_for(int $post_id): string
{
    $saved = get_post_meta($post_id, HB2103_MEDIA_FOLDER, true);
    if (is_string($saved) && $saved !== '') {
        return $saved;
    }

    $post = get_post($post_id);
    if (!$post instanceof WP_Post) {
        return '';
    }

    $slug = $post->post_name !== '' ? $post->post_name : '';

    // 下書き直後などスラッグ未確定のときはIDで決める。
    // あとでスラッグが付いてもフォルダは変えない（上記の理由）。
    $folder = $slug !== '' ? $slug : 'p' . $post_id;
    $folder = sanitize_title($folder);

    if ($folder === '') {
        $folder = 'p' . $post_id;
    }

    update_post_meta($post_id, HB2103_MEDIA_FOLDER, $folder);

    return $folder;
}

/**
 * 投稿IDから uploads 配下の相対ディレクトリを決める。
 * 該当しないものは空文字（＝共通素材として年月に入れる）。
 */
function hb2103_upload_subdir_for(int $post_id): string
{
    $post = get_post($post_id);
    if (!$post instanceof WP_Post) {
        return '';
    }

    switch ($post->post_type) {
        case 'property':
            return 'property/' . hb2103_media_folder_for($post_id);

        case 'facility':
            return 'facility/' . hb2103_media_folder_for($post_id);

        case 'works':
            return 'works/' . hb2103_media_folder_for($post_id);

        case 'attachment':
            // 添付の親を辿る（再アップロード・サイズ再生成のとき）
            return $post->post_parent ? hb2103_upload_subdir_for($post->post_parent) : '';
    }

    return '';
}

/* ------------------------------------------------------------------ *
 * アップロードの文脈
 * ------------------------------------------------------------------ */

/**
 * 移行スクリプトやCLIから「いまどの投稿の画像を入れているか」を明示するための器。
 * 管理画面からのアップロードは $_REQUEST['post_id'] で分かるが、
 * CLIにはその文脈がないので呼び出し側が指定する。
 */
function hb2103_set_upload_context(?int $post_id): void
{
    $GLOBALS['hb2103_upload_context'] = $post_id;
}

function hb2103_get_upload_context(): ?int
{
    if (!empty($GLOBALS['hb2103_upload_context'])) {
        return (int) $GLOBALS['hb2103_upload_context'];
    }

    // メディアアップローダー・ブロックエディタはどちらも post_id を送ってくる
    foreach (['post_id', 'post'] as $key) {
        if (isset($_REQUEST[$key]) && is_numeric($_REQUEST[$key]) && (int) $_REQUEST[$key] > 0) {
            return (int) $_REQUEST[$key];
        }
    }

    return null;
}

/**
 * アップロード先を物件単位に差し替える。
 */
add_filter('upload_dir', 'hb2103_filter_upload_dir');

function hb2103_filter_upload_dir(array $dirs): array
{
    if (!apply_filters('hb2103_enable_structured_uploads', true)) {
        return $dirs;
    }

    $context = hb2103_get_upload_context();
    if ($context === null) {
        return hb2103_common_upload_dir($dirs);
    }

    $subdir = hb2103_upload_subdir_for($context);
    if ($subdir === '') {
        return hb2103_common_upload_dir($dirs);
    }

    return hb2103_replace_upload_subdir($dirs, '/' . $subdir);
}

/**
 * 物件に紐づかない素材は common/年/月 に入れる。
 * ルート直下に散らさないことで、物件フォルダと混ざらない。
 */
function hb2103_common_upload_dir(array $dirs): array
{
    $subdir = '/common' . ($dirs['subdir'] !== '' ? $dirs['subdir'] : '');
    return hb2103_replace_upload_subdir($dirs, $subdir);
}

function hb2103_replace_upload_subdir(array $dirs, string $subdir): array
{
    $dirs['path']   = $dirs['basedir'] . $subdir;
    $dirs['url']    = $dirs['baseurl'] . $subdir;
    $dirs['subdir'] = $subdir;

    return $dirs;
}

/**
 * 孤立メディアを構造的に防ぐ。
 * アップローダーが親を設定しなかった場合でも、文脈が分かっていれば紐付ける。
 */
add_action('add_attachment', 'hb2103_attach_to_upload_context');

function hb2103_attach_to_upload_context(int $attachment_id): void
{
    $attachment = get_post($attachment_id);
    if (!$attachment instanceof WP_Post || $attachment->post_parent !== 0) {
        return;
    }

    $context = hb2103_get_upload_context();
    if ($context === null) {
        return;
    }

    wp_update_post([
        'ID'          => $attachment_id,
        'post_parent' => $context,
    ]);
}

/* ------------------------------------------------------------------ *
 * WebP 生成（ewww-image-optimizer の置き換え）
 * ------------------------------------------------------------------ */

/**
 * JPEG / PNG のサブサイズを WebP で生成する。
 *
 * WordPress 7.1.4 は GD・Imagick とも WebP/AVIF の生成に対応しているが、
 * 既定の image_editor_output_format は空配列＝変換しない設定になっている。
 * ここを埋めるだけで画像最適化プラグインは不要になる。
 *
 * オリジナルは元の形式で残るため、WebP非対応の経路でも破綻しない。
 */
add_filter('image_editor_output_format', 'hb2103_webp_output_format');

function hb2103_webp_output_format(array $formats): array
{
    if (!apply_filters('hb2103_enable_webp_subsizes', true)) {
        return $formats;
    }

    $formats['image/jpeg'] = 'image/webp';
    $formats['image/png']  = 'image/webp';

    return $formats;
}

/**
 * WebPの品質。既定の82は写真には少し低いので、物件写真向けに上げる。
 */
add_filter('wp_editor_set_quality', function (int $quality, string $mime_type): int {
    return $mime_type === 'image/webp' ? 86 : $quality;
}, 10, 2);

/* ------------------------------------------------------------------ *
 * メディア一覧を使いやすくする
 * ------------------------------------------------------------------ */

/**
 * 「紐付き先」列を追加して、どの物件の画像かを一覧で分かるようにする。
 */
add_filter('manage_media_columns', function (array $columns): array {
    $columns['hb2103_owner'] = '紐付き先';
    return $columns;
});

add_action('manage_media_custom_column', function (string $column, int $post_id): void {
    if ($column !== 'hb2103_owner') {
        return;
    }

    $attachment = get_post($post_id);
    $parent     = ($attachment && $attachment->post_parent) ? get_post($attachment->post_parent) : null;

    if (!$parent instanceof WP_Post) {
        echo '<span style="color:#b32d2e">未紐付け</span>';
        return;
    }

    $type_obj = get_post_type_object($parent->post_type);
    $type     = $type_obj ? $type_obj->labels->singular_name : $parent->post_type;

    printf(
        '<a href="%s">%s</a><br><span style="color:#787c82;font-size:11px">%s</span>',
        esc_url((string) get_edit_post_link($parent->ID)),
        esc_html(get_the_title($parent->ID) ?: '(無題)'),
        esc_html($type)
    );
}, 10, 2);

/**
 * メディア一覧に「分譲地で絞る」「未紐付けだけ表示」を足す。
 * 13,000件の中から目的の画像を探せるようにするため。
 */
add_action('restrict_manage_posts', function (string $post_type): void {
    if ($post_type !== 'attachment') {
        return;
    }

    $selected = isset($_GET['hb2103_property']) ? (int) $_GET['hb2103_property'] : 0;

    $properties = get_posts([
        'post_type'      => 'property',
        'posts_per_page' => -1,
        'post_status'    => ['publish', 'draft', 'private'],
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    echo '<select name="hb2103_property"><option value="0">すべての分譲地</option>';
    echo '<option value="-1"' . selected($selected, -1, false) . '>未紐付けのみ</option>';

    foreach ($properties as $property) {
        printf(
            '<option value="%d"%s>%s</option>',
            $property->ID,
            selected($selected, $property->ID, false),
            esc_html(get_the_title($property) ?: '(無題)')
        );
    }

    echo '</select>';
});

add_action('pre_get_posts', function (WP_Query $query): void {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    if ($query->get('post_type') !== 'attachment' || !isset($_GET['hb2103_property'])) {
        return;
    }

    $property_id = (int) $_GET['hb2103_property'];

    if ($property_id === -1) {
        $query->set('post_parent', 0);
        return;
    }

    if ($property_id > 0) {
        $query->set('post_parent', $property_id);
    }
});
