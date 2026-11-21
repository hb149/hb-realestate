<?php
/**
 * 管理画面の既定値とスタイル
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ギャラリー枠の高さを下げる。
 *
 * ACFは枠の高さをフィールド設定ではなくユーザーごとの設定 `gallery_height`
 * から読んでインラインstyleに書き出している（pro/fields/class-acf-field-gallery.php）。
 *   $height = acf_get_user_setting('gallery_height', 400);
 *   $height = max($height, 200);   // 下限200px
 * 既定の400pxは区画リピーターでは高すぎるので、未設定のユーザーに200pxを入れる。
 * 本人が値を持っている場合は尊重して触らない。
 */
add_action('admin_init', function (): void {
    $user_id = get_current_user_id();
    if ($user_id === 0) {
        return;
    }

    $settings = get_user_meta($user_id, 'acf_user_settings', true);
    $settings = is_array($settings) ? $settings : [];

    if (isset($settings['gallery_height'])) {
        return;
    }

    $settings['gallery_height'] = 200;
    update_user_meta($user_id, 'acf_user_settings', $settings);
});

/**
 * ACFの設定では実現できない見た目だけをCSSで補う。
 * 優先度20でACFが自身のスタイルを登録し終えたあとに走らせ、
 * 依存にACFのハンドルを入れて必ず後に読ませる（先に読まれると同じ詳細度で負ける）。
 */
add_action('admin_enqueue_scripts', function (string $hook): void {
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
        return;
    }

    $path = HB2103_RE_DIR . '/assets/admin.css';
    if (!is_readable($path)) {
        return;
    }

    $deps = array_values(array_filter(
        ['acf-global', 'acf-input', 'acf-pro-input'],
        static fn(string $handle): bool => wp_style_is($handle, 'registered')
    ));

    wp_enqueue_style(
        'hb2103-admin',
        plugins_url('assets/admin.css', HB2103_RE_FILE),
        $deps,
        (string) filemtime($path)
    );
}, 20);

/**
 * 分譲地の編集画面でメタボックスの既定の並びを決める。
 *
 * メイン画像は既定だとサイドバーの最後に出るが、物件では一覧カードの顔になる
 * 重要な項目なので公開カードの直下に置く。
 * add_meta_box の priority で動かすと登録順に左右されて安定しないため、
 * WordPressが並び順を読む get_user_option をフィルタして明示する。
 * ユーザーが自分で並べ替えていればその設定を優先する。
 */
add_filter('get_user_option_meta-box-order_property', function ($order) {
    if (!empty($order)) {
        return $order;
    }

    return [
        'side'     => 'submitdiv,postimagediv,areadiv,equipmentdiv,pageparentdiv',
        'normal'   => 'acf-group_hb2103_lots,acf-group_hb2103_property,postexcerpt,slugdiv,ssp_metabox',
        'advanced' => '',
    ];
});
