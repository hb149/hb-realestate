<?php
/**
 * URL周りの調整
 *
 *   分譲地   /property/nakamahigashi/
 *   一覧     /property/
 *
 * 号地は分譲地の lots リピーターで持つため、号地ごとのURLは存在しない。
 * 現行サイトの号地URL（/kitakyushu/nakamahigashi/nakamahigashi-1/ など478件）は、
 * 移行時に legacy.php のリダイレクト表へ登録し、分譲地ページへ301で逃がす。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPressの「404になったらスラッグから推測して301する」機能を止める。
 *
 * 旧URLの転送は legacy.php のリダイレクト表で確定的に処理したい。
 * 推測に任せると、たとえば廃止した号地URLが無関係な投稿へ飛ぶ事故が起きる。
 * 不動産サイトで別物件が表示されるのは実害が大きいので、推測はさせない。
 */
add_filter('do_redirect_guess_404_permalink', '__return_false');
