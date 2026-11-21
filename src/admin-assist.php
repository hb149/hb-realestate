<?php
/**
 * 編集画面の入力補助
 *
 * 片方を入れたら、もう片方のプレースホルダに「こう入る」を出す。
 * 値は書き込まない。編集者が見て判断し、違えば自分で入力する。
 *
 * サーバー側のプレースホルダ（acf/prepare_field）は保存済みの値からしか作れず、
 * 入力中の内容に追従できない。ここで扱うのは打ち込みながら反映したいものなので
 * JavaScriptで行う。見た目の調整ではないのでCSSは入れない。
 *
 * 対象:
 *   面積    ㎡ ⇄ 坪 の相互換算
 *   所在地  Googleマップで決めた位置 → 所在地・カーナビ入力用
 *   号地    分譲地の所在地 → 各区画の所在地
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_enqueue_scripts', 'hb2103_enqueue_admin_assist');

function hb2103_enqueue_admin_assist(string $hook): void
{
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->post_type, ['property', 'facility'], true)) {
        return;
    }

    wp_register_script('hb2103-assist', false, ['jquery'], HB2103_RE_VERSION, true);
    wp_enqueue_script('hb2103-assist');

    wp_add_inline_script('hb2103-assist', sprintf(
        'window.HB2103 = %s;',
        wp_json_encode([
            'sqmPerTsubo' => round(HB2103_SQM_PER_TSUBO, 10),
            // 区画を畳んだときに見せるサブフィールド。
            // ACFの collapsed 設定は1つしか指定できないので、残りはここで補う。
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'geoNonce' => wp_create_nonce('hb2103_facility_geo'),
            'createNonce' => wp_create_nonce('hb2103_create_facility'),
            // 地図の吹き出しで選ぶ施設カテゴリ（Googleの施設種別との対応つき）
            'facilityTypes' => hb2103_facility_type_choices(),
            'typeNonce' => wp_create_nonce('hb2103_set_facility_type'),
            // 不動産公正競争規約: 徒歩所要時間は道路距離80mにつき1分、端数切り上げ
            'walkMetersPerMinute' => 80,
            // 車には法令上の計算式が無い（規約も実測が前提）。
            // 市街地の平均 30km/h = 500m/分 を仮定した目安としてのみ出す。
            'carKmPerHour' => 30,
            'carMetersPerMinute' => 500,
        ])
    ), 'before');

    wp_add_inline_script('hb2103-assist', hb2103_admin_assist_js());
}

function hb2103_admin_assist_js(): string
{
    return <<<'JS'
jQuery(function ($) {
    var RATE = window.HB2103.sqmPerTsubo;

    function num(v) {
        var n = parseFloat(v);
        return isFinite(n) && n > 0 ? n : null;
    }

    // プレースホルダには「そのまま入る値」だけを出す。
    // 「自動:」「直線」などの注記を混ぜると、その文字列ごと入力されると
    // 誤解されるため。計算の前提はフィールドの説明文に書いてある。
    function hint($el, text) {
        $el.attr('placeholder', text || '');
        addCopyButton($el);
    }

    /* ── 目安の値をクリップボードへ ───────────────────── */

    // 目安をそのまま使いたいことが多いので、ワンクリックで取れるようにする。
    // 見た目はWordPress標準のボタンクラスに任せ、独自CSSは入れない。
    function addCopyButton($el) {
        if (!$el.length) { return; }
        var ph = $el.attr('placeholder') || '';
        var $btn = $el.closest('.acf-input').find('.hb2103-copy').first();

        // 目安が無いならボタンも出さない
        if (!ph) { $btn.remove(); return; }

        if (!$btn.length) {
            // 入力欄の中（右端）に収めるアイコンボタン。
            // WordPress同梱の dashicons を使うので画像の追加は不要。
            // 線画のアイコン。dashicons は塗りつぶしで主張が強いので使わない。
            var ICON_COPY = '<svg class="hb2103-copy__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" ' +
                'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                '<rect x="9" y="9" width="12" height="12" rx="2"/>' +
                '<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
            var ICON_DONE = '<svg class="hb2103-copy__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" ' +
                'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                '<path d="M20 6 9 17l-5-5"/></svg>';
            $btn = $('<button type="button" class="hb2103-copy" title="目安をコピー" aria-label="目安をコピー"></button>');
            $btn.html(ICON_COPY);
            $btn.data('iconCopy', ICON_COPY).data('iconDone', ICON_DONE);
            $btn.on('click', function (e) {
                e.preventDefault();
                var text = $el.attr('placeholder') || '';
                if (!text) { return; }
                var done = function () {
                    $btn.html($btn.data('iconDone')).addClass('is-done');
                    setTimeout(function () {
                        $btn.html($btn.data('iconCopy')).removeClass('is-done');
                    }, 1200);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done);
                } else {
                    // 古い環境向けの退避手段
                    var $tmp = $('<textarea>').val(text).appendTo('body').select();
                    document.execCommand('copy');
                    $tmp.remove();
                    done();
                }
            });
            // 入力欄のすぐ外側の箱に入れ、CSSで内側の右端に重ねる。
            // .acf-input-wrap があるとき（単位バッジつきの数値欄など）はそちらへ。
            var $host = $el.closest('.acf-input-wrap');
            if (!$host.length) { $host = $el.closest('.acf-input'); }
            $host.append($btn);
        }
    }

    /* ── 面積 ㎡ ⇄ 坪 ─────────────────────────────── */

    function bindArea(scope) {
        var $sqm = $(scope).find('.hb2103-area--sqm input[type="number"]');
        var $tsubo = $(scope).find('.hb2103-area--tsubo input[type="number"]');
        if ($sqm.length !== $tsubo.length) { return; }

        $sqm.each(function (i) {
            var a = this, b = $tsubo[i];
            function toTsubo() { var v = num($(a).val()); hint($(b), v ? (v / RATE).toFixed(2) : ''); }
            function toSqm()   { var v = num($(b).val()); hint($(a), v ? (v * RATE).toFixed(2) : ''); }
            $(a).off('.hb2103').on('input.hb2103 change.hb2103', toTsubo);
            $(b).off('.hb2103').on('input.hb2103 change.hb2103', toSqm);
            toTsubo(); toSqm();
        });
    }

    /* ── Googleマップ → 所在地 ───────────────────────── */

    // Googleの整形住所は「日本、〒800-0251 福岡県…」の形で全角数字を含む。
    // 現行サイトの表記（福岡県北九州市…1丁目4番）に寄せて整える。
    function cleanAddress(raw) {
        if (!raw) { return ''; }
        return String(raw)
            // 番地の無い地点をクリックするとGoogleはプラスコード（FC34+XW）を頭に付けて返す。
            // 所在地としては使えないので落とす。
            .replace(/^[23456789CFGHJMPQRVWX]{4,8}\+[23456789CFGHJMPQRVWX]{2,3}\s*/i, '')
            .replace(/^日本[、,]\s*/, '')
            .replace(/〒\s*[0-9０-９]{3}[-－−]?[0-9０-９]{4}\s*/, '')
            .replace(/[０-９]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) - 0xFEE0); })
            // 全角ハイフン類だけ半角に。長音符「ー」は地名に出るので触らない
            .replace(/[－−‐–—]/g, '-')
            .trim();
    }

    function mapAddress($mapField) {
        var raw = $mapField.find('input[type="hidden"]').val();
        if (!raw) { return ''; }
        try {
            var v = JSON.parse(raw);
            return cleanAddress(v.address || '');
        } catch (e) {
            return '';
        }
    }

    function bindMap(scope) {
        $(scope).find('.acf-field[data-type="google_map"]').each(function () {
            var $map = $(this);
            // 同じフィールドグループ内の所在地・カーナビに反映する
            var $scope = $map.closest('.acf-fields');
            var $targets = $scope
                .find('.acf-field[data-type="text"]')
                .filter(function () {
                    var label = $(this).children('.acf-label').find('label').text().trim();
                    return label === '所在地' || label === 'カーナビ入力用';
                })
                .find('input[type="text"]');

            function apply() {
                var addr = mapAddress($map);
                $targets.each(function () { hint($(this), addr); });
                // 分譲地の所在地が決まったら、区画側にも配る
                spreadToLots(addr);
                // 物件の位置が変わると周辺施設までの距離も変わる
                bindNearby(document);
            }

            $map.find('input[type="hidden"]').off('.hb2103').on('change.hb2103', apply);
            apply();
        });
    }

    /* ── 分譲地の所在地 → 区画の所在地 ─────────────────── */

    function lotAddressInputs() {
        return $('#acf-group_hb2103_lots .acf-field[data-type="text"]').filter(function () {
            return $(this).children('.acf-label').find('label').text().trim() === '所在地';
        }).find('input[type="text"]');
    }

    function spreadToLots(fallback) {
        var $propAddr = $('#acf-group_hb2103_property .acf-field[data-type="text"]').filter(function () {
            return $(this).children('.acf-label').find('label').text().trim() === '所在地';
        }).find('input[type="text"]').first();

        var addr = ($propAddr.val() || '').trim() || fallback || '';
        lotAddressInputs().each(function () { hint($(this), addr); });
    }

    function bindPropertyAddress() {
        $('#acf-group_hb2103_property .acf-field[data-type="text"]').filter(function () {
            return $(this).children('.acf-label').find('label').text().trim() === '所在地';
        }).find('input[type="text"]').off('.hb2103addr').on('input.hb2103addr change.hb2103addr', function () {
            spreadToLots('');
        });
    }

    /* ── 周辺施設の距離・徒歩分 ─────────────────────── */

    // 2点間の直線距離（m）。地球を半径6,371kmの球とみなすハヴァサイン公式。
    function haversine(a, b) {
        var R = 6371000, toRad = Math.PI / 180;
        var dLat = (b.lat - a.lat) * toRad;
        var dLng = (b.lng - a.lng) * toRad;
        var s = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(a.lat * toRad) * Math.cos(b.lat * toRad) *
                Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return Math.round(R * 2 * Math.atan2(Math.sqrt(s), Math.sqrt(1 - s)));
    }

    function propertyLatLng() {
        var $map = $('#acf-group_hb2103_property .acf-field[data-type="google_map"]').first();
        var raw = $map.find('input[type="hidden"]').val();
        if (!raw) { return null; }
        try {
            var v = JSON.parse(raw);
            return (v.lat && v.lng) ? { lat: parseFloat(v.lat), lng: parseFloat(v.lng) } : null;
        } catch (e) { return null; }
    }

    var geoCache = {};

    function fetchGeo(ids, done) {
        var need = ids.filter(function (id) { return id && !(id in geoCache); });
        if (!need.length) { done(); return; }
        $.getJSON(window.HB2103.ajaxUrl, {
            action: 'hb2103_facility_geo',
            _wpnonce: window.HB2103.geoNonce,
            ids: need
        }).done(function (res) {
            if (res && res.success) {
                need.forEach(function (id) { geoCache[id] = res.data[id] || null; });
            }
        }).always(done);
    }

    // 徒歩分は距離から出す（規約: 80mにつき1分・切り上げ）。
    //   距離が入力されていれば   → それを使う（道路距離のはずなので規約どおり）
    //   入力が無く直線距離がある → 直線からの目安を出す。道路距離より必ず短く出るので、
    //                              そのまま採用されないよう「直線なら」と明示する。
    function updateWalk($row, straightMeters) {
        var $dist = $row.find('.acf-field[data-key="field_hb2103_p_nearby_dist"] input[type="number"]');
        var $walk = $row.find('.acf-field[data-key="field_hb2103_p_nearby_walk"] input[type="number"]');
        var $car  = $row.find('.acf-field[data-key="field_hb2103_p_nearby_car"] input[type="number"]');
        var per = window.HB2103.walkMetersPerMinute;
        var carPer = window.HB2103.carMetersPerMinute;
        var d = num($dist.val());
        var base = d || straightMeters || null;

        // 徒歩: 入力された距離があればそれ、無ければ直線距離からの目安
        if (d) {
            hint($walk, String(Math.ceil(d / per)));
        } else if (straightMeters) {
            $walk.attr('placeholder', String(Math.ceil(straightMeters / per)));
            addCopyButton($walk);
        } else {
            $walk.attr('placeholder', '');
            addCopyButton($walk);
        }

        // 車: 法令上の式が無いので、あくまで前提速度を置いた目安。
        // 「自動」ではなく「目安」と書き、そのまま採用されないようにする。
        $car.attr('placeholder', base ? String(Math.ceil(base / carPer)) : '');
        addCopyButton($car);
    }

    // 距離は座標から出せるが、それは直線距離であって道路距離ではない。
    // 規約で求められるのは道路距離なので、あくまで参考値と分かる文言にする。
    function updateDistance($row) {
        var origin = propertyLatLng();
        // ACFのpost_objectは「空のhidden → select」の順で出力される。
        // 両方を拾うと jQuery の .val() が先頭の空hiddenを返すので、selectを優先する。
        var $field = $row.find('.acf-field[data-key="field_hb2103_p_nearby_facility"]');
        var $sel = $field.find('select').first();
        if (!$sel.length) { $sel = $field.find('input[type="hidden"]').last(); }
        var id = parseInt($sel.val(), 10);
        var $dist = $row.find('.acf-field[data-key="field_hb2103_p_nearby_dist"] input[type="number"]');

        if (!origin || !id) { $dist.attr('placeholder', ''); return; }

        fetchGeo([id], function () {
            var g = geoCache[id];
            if (!g) { $dist.attr('placeholder', ''); addCopyButton($dist); updateWalk($row, null); return; }
            var m = haversine(origin, g);
            $dist.attr('placeholder', String(m));
            addCopyButton($dist);
            updateWalk($row, m);
        });
    }

    // 距離欄のプレースホルダに出している直線距離を読み戻す。
    // 距離を打ったり消したりしたときに、徒歩側の表示を正しく切り替えるため。
    function lastStraight($row) {
        var ph = $row.find('.acf-field[data-key="field_hb2103_p_nearby_dist"] input[type="number"]').attr('placeholder') || '';
        var m = ph.match(/^(\d+)$/);
        return m ? parseInt(m[1], 10) : null;
    }

    /**
     * 周辺環境の表に「カテゴリ」の列を足す。
     *
     * カテゴリは施設側の情報なのでACFのフィールドにはしない（分譲地に保存されてしまう）。
     * 施設セルの中に入れるとACFがselect2を掛けてドロップダウンが二重に見えるため、
     * 独立したセルとして表に差し込む。
     */
    var NEARBY = '.acf-field[data-key="field_hb2103_p_nearby"]';
    var FACILITY_CELL = '[data-key="field_hb2103_p_nearby_facility"]';

    function typeOptionsHtml() {
        var html = '<option value="">カテゴリ未設定</option>';
        (window.HB2103.facilityTypes || []).forEach(function (t) {
            html += '<option value="' + t.id + '">' + t.name + '</option>';
        });
        return html;
    }

    // 見出しの行にカテゴリの列を足す（表ごとに一度だけ）
    function ensureTypeColumn($table) {
        var $th = $table.find('> thead > tr > th' + FACILITY_CELL).first();
        if (!$th.length || $th.next('.hb2103-th-type').length) { return; }
        $th.after('<th class="acf-th hb2103-th-type">カテゴリ</th>');
    }

    // 施設 / カテゴリ / 距離 / 徒歩 / 車 の取り分
    var NEARBY_RATIOS = [0.28, 0.18, 0.18, 0.18, 0.18];

    /**
     * 周辺環境の表の列幅を割り付ける。
     *
     * ACFは th に width:XX% をインラインで出すが、この表では % が解決されず
     * 全列が等幅に落ちてしまう（px なら効く）。そのため実幅から px で計算する。
     * 行番号・削除のハンドル列は中身の都合で幅が決まるので、実測して差し引く。
     */
    function layoutNearbyTable($table) {
        var th = $table.find('> thead > tr > th').toArray();
        if (th.length !== NEARBY_RATIOS.length + 2) { return; }

        var total = $table[0].getBoundingClientRect().width;
        var handles = th[0].getBoundingClientRect().width
                    + th[th.length - 1].getBoundingClientRect().width;
        var avail = total - handles;
        if (avail <= 0) { return; }

        th.slice(1, -1).forEach(function (cell, i) {
            var cs = window.getComputedStyle(cell);
            // th は content-box なので、余白と枠のぶんを引いた値を指定する
            var extra = parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight)
                      + parseFloat(cs.borderLeftWidth) + parseFloat(cs.borderRightWidth);
            var w = Math.max(0, Math.floor(avail * NEARBY_RATIOS[i] - extra));
            cell.style.setProperty('width', w + 'px', 'important');
        });
    }

    function layoutNearbyTables() {
        $(NEARBY).find('.acf-table').each(function () { layoutNearbyTable($(this)); });
    }

    // 画面幅が変わったら割り付け直す
    var layoutTimer = null;
    $(window).on('resize.hb2103nearby', function () {
        clearTimeout(layoutTimer);
        layoutTimer = setTimeout(layoutNearbyTables, 150);
    });

    // 各行にカテゴリのセルを足す（行を増やしたときの複製行にも効くよう中身だけ作る）
    function ensureTypeCell($row) {
        var $td = $row.find('> td' + FACILITY_CELL).first();
        if (!$td.length) { return null; }

        var $cell = $td.next('.hb2103-td-type');
        if (!$cell.length) {
            $cell = $('<td class="hb2103-td-type"></td>')
                .append('<select class="hb2103-fac-type">' + typeOptionsHtml() + '</select>');
            $td.after($cell);
        }
        return $cell.find('.hb2103-fac-type');
    }

    function bindFacilityType($row) {
        var $sel = $row.find('td' + FACILITY_CELL + ' select:not(.hb2103-fac-type)').first();
        var $type = ensureTypeCell($row);
        if (!$type) { return; }

        var id = parseInt($sel.val(), 10);
        if (!id) { $type.val('').prop('disabled', true); return; }

        $type.prop('disabled', false);
        // 施設の現在のカテゴリを映す（座標と一緒に取ってある）
        fetchGeo([id], function () {
            var g = geoCache[id];
            $type.val(g && g.type ? String(g.type) : '');
        });
    }

    // 行を増やすとACFが行ごと複製するのでハンドラは個別に付けず、まとめて受ける
    $(document).on('change.hb2103type', '.hb2103-fac-type', function () {
        var $type = $(this);
        var $row = $type.closest('.acf-row');
        var fid = parseInt($row.find('td' + FACILITY_CELL + ' select:not(.hb2103-fac-type)').first().val(), 10);
        if (!fid) { return; }

        var val = $type.val();
        $type.prop('disabled', true);
        $.post(window.HB2103.ajaxUrl, {
            action: 'hb2103_set_facility_type',
            _wpnonce: window.HB2103.typeNonce,
            id: fid,
            type: val
        }).always(function (res) {
            $type.prop('disabled', false);
            if (!res || !res.success) {
                window.alert((res && res.data && res.data.message) || 'カテゴリを変更できませんでした');
                return;
            }
            if (geoCache[fid]) { geoCache[fid].type = parseInt(val, 10) || 0; }
            $type.addClass('is-saved');
            setTimeout(function () { $type.removeClass('is-saved'); }, 1200);
        });
    });

    function bindNearby(scope) {
        $(scope).find(NEARBY).find('.acf-table').each(function () {
            ensureTypeColumn($(this));
        });
        // 列を足したあとに幅を割り付ける（タブ表示直後は幅が0なので少し待つ）
        layoutNearbyTables();
        setTimeout(layoutNearbyTables, 300);
        // 複製元の行にもセルを用意しておく（行を足したときに列がずれない）
        $(scope).find(NEARBY + ' .acf-row.acf-clone').each(function () { ensureTypeCell($(this)); });

        $(scope).find('.acf-field[data-key="field_hb2103_p_nearby"] .acf-row:not(.acf-clone)').each(function () {
            var $row = $(this);
            bindFacilityType($row);
            $row.find('.acf-field[data-key="field_hb2103_p_nearby_dist"] input[type="number"]')
                .off('.hb2103nb').on('input.hb2103nb change.hb2103nb', function () { updateWalk($row, lastStraight($row)); });
            $row.find('.acf-field[data-key="field_hb2103_p_nearby_facility"] select:not(.hb2103-fac-type)')
                .off('.hb2103nb').on('change.hb2103nb', function () {
                    updateDistance($row);
                    renderFacilityPins();
                    bindFacilityType($row);
                });
            updateWalk($row, lastStraight($row));
            updateDistance($row);
        });
    }

    /* ── 地図に周辺施設のピンを立てる ─────────────────── */

    // ACFの地図フィールドは google.maps.Map を field.map に持っている。
    // そこへ施設のマーカーを足すだけなので、地図を自前で作り直す必要はない。
    var facilityMarkers = [];

    function clearFacilityMarkers() {
        facilityMarkers.forEach(function (m) { m.setMap(null); });
        facilityMarkers = [];
    }

    function selectedFacilityIds() {
        var ids = [];
        $('.acf-field[data-key="field_hb2103_p_nearby"] .acf-row:not(.acf-clone)').each(function () {
            var $sel = $(this).find('.acf-field[data-key="field_hb2103_p_nearby_facility"] select:not(.hb2103-fac-type)').first();
            var id = parseInt($sel.val(), 10);
            if (id) { ids.push(id); }
        });
        return ids;
    }

    // 物件の地図。google_map_init で受け取って保持する。
    // ページ読み込み直後はGoogleのスクリプトがまだ来ておらず、
    // jQuery ready の時点では地図を触れないため。
    var propertyMap = null;

    function renderFacilityPins() {
        if (!window.google || !google.maps) { return; }
        var f = acf.getFields({ type: 'google_map' })[0];
        var map = propertyMap || (f && f.map);
        if (!map) { return; }

        var ids = selectedFacilityIds();
        clearFacilityMarkers();
        if (!ids.length) { return; }

        fetchGeo(ids, function () {
            ids.forEach(function (id) {
                var g = geoCache[id];
                if (!g) { return; }
                var marker = new google.maps.Marker({
                    position: { lat: g.lat, lng: g.lng },
                    map: map,
                    title: g.title || '',
                    // 物件は標準のピン型、施設は丸。形が違うので同じ赤でも見分けられる。
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        scale: 7,
                        fillColor: '#d63638',
                        fillOpacity: 1,
                        strokeColor: '#fff',
                        strokeWeight: 2,
                    },
                });
                var info = new google.maps.InfoWindow({ content: g.title || '' });
                marker.addListener('click', function () { info.open(map, marker); });
                facilityMarkers.push(marker);
            });
        });
    }

    /* ── 周辺施設をその場で作る ─────────────────────── */

    function nearbyRepeater() {
        return acf.getFields({ key: 'field_hb2103_p_nearby' })[0] || null;
    }

    // 作った施設を周辺施設の表に追加する。
    // 行を足してから select に候補を入れて選択する（まだ選択肢に無いため）。
    function appendFacilityRow(id, title) {
        var rep = nearbyRepeater();
        if (!rep) { return; }

        rep.add();
        setTimeout(function () {
            var $rows = $('.acf-field[data-key="field_hb2103_p_nearby"] .acf-row:not(.acf-clone)');
            var $row = $rows.last();
            var $sel = $row.find('.acf-field[data-key="field_hb2103_p_nearby_facility"] select:not(.hb2103-fac-type)').first();
            if (!$sel.length) { return; }

            if (!$sel.find('option[value="' + id + '"]').length) {
                $sel.append(new Option(title, id, true, true));
            }
            $sel.val(String(id)).trigger('change');

            bindNearby(document);
            renderFacilityPins();
        }, 300);
    }

    function createFacility(title, lat, lng, typeId, address) {
        return $.post(window.HB2103.ajaxUrl, {
            action: 'hb2103_create_facility',
            _wpnonce: window.HB2103.createNonce,
            title: title,
            lat: lat || '',
            lng: lng || '',
            type: typeId || '',
            address: address || ''
        }).then(function (res) {
            if (!res || !res.success) {
                window.alert((res && res.data && res.data.message) || '施設を作成できませんでした');
                return null;
            }
            // 座標を持って帰ってキャッシュに入れる（すぐ距離が出せる）
            if (lat && lng) {
                geoCache[res.data.id] = { lat: parseFloat(lat), lng: parseFloat(lng), title: res.data.title };
            }
            return res.data;
        });
    }

    // Googleの施設種別から、こちらの施設カテゴリを推測する。
    function guessFacilityType(googleTypes) {
        var types = window.HB2103.facilityTypes || [];
        var list = googleTypes || [];
        for (var i = 0; i < list.length; i++) {
            for (var j = 0; j < types.length; j++) {
                if ((types[j].google || []).indexOf(list[i]) !== -1) { return types[j].id; }
            }
        }
        return '';
    }

    // 地図に出ている施設（POI）の詳細をGoogleに問い合わせる。
    // 取れなくても名前を手入力すれば追加できるよう、失敗はnullで返す。
    function placeDetails(map, placeId, done) {
        if (!google.maps.places || !google.maps.places.PlacesService) { done(null); return; }
        var svc = new google.maps.places.PlacesService(map);
        svc.getDetails({
            placeId: placeId,
            fields: ['name', 'formatted_address', 'geometry', 'types']
        }, function (res, status) {
            done(status === google.maps.places.PlacesServiceStatus.OK ? res : null);
        });
    }

    var poiWindow = null;

    function closePoiBubble() {
        if (poiWindow) { poiWindow.close(); poiWindow = null; }
    }

    /**
     * 地図の吹き出しから周辺環境に追加する。
     * 施設名はGoogleの表記をそのまま使わないことが多いので、追加前に直せるようにする。
     */
    function openPoiBubble(map, latLng, place) {
        closePoiBubble();

        var $box = $('<div class="hb2103-poi"></div>');
        var $name = $('<input type="text" class="hb2103-poi__name" placeholder="施設名">')
            .val((place && place.name) || '');
        var address = (place && place.formatted_address) || '';
        var $type = $('<select class="hb2103-poi__type"></select>')
            .append(new Option('カテゴリを選ぶ', ''));

        (window.HB2103.facilityTypes || []).forEach(function (t) {
            $type.append(new Option(t.name, t.id));
        });
        $type.val(String(guessFacilityType(place && place.types)) || '');

        var $add = $('<button type="button" class="button button-primary hb2103-poi__add">周辺環境に追加</button>');
        var $note = $('<p class="hb2103-poi__note"></p>');

        $box.append($name);
        if (address) {
            $box.append($('<p class="hb2103-poi__addr"></p>').text(address));
        }
        $box.append($('<div class="hb2103-poi__foot"></div>').append($type, $add), $note);

        $add.on('click', function () {
            var name = $.trim($name.val());
            if (!name) { $name.trigger('focus'); return; }

            $add.prop('disabled', true).text('追加中…');
            createFacility(name, latLng.lat(), latLng.lng(), $type.val(), address)
                .then(function (data) {
                    if (!data) { $add.prop('disabled', false).text('周辺環境に追加'); return; }
                    appendFacilityRow(data.id, data.title);
                    $note.text(data.existing ? '登録済みの施設を追加しました' : '追加しました');
                    setTimeout(closePoiBubble, 900);
                });
        });

        // Enterで追加できるように（フォーム送信にはしない）
        $name.on('keydown', function (e) {
            if (e.which === 13) { e.preventDefault(); $add.trigger('click'); }
        });

        poiWindow = new google.maps.InfoWindow({ content: $box[0], position: latLng });
        poiWindow.open(map);
        google.maps.event.addListener(poiWindow, 'closeclick', function () { poiWindow = null; });
        setTimeout(function () { $name.trigger('focus').trigger('select'); }, 120);
    }

    /**
     * 地図のクリックを引き取る。
     *
     * ACFは地図のクリックで物件ピンを動かしているので、素直にリスナーを足すと
     * 施設を選んだだけで物件の位置が変わってしまう。いったん外して、
     * 施設（placeIdを持つクリック）とそれ以外を自分で振り分ける。
     */
    function bindMapFacilityCreate(map, field) {
        google.maps.event.clearListeners(map, 'click');

        map.addListener('click', function (e) {
            if (e && e.placeId) {
                // Google標準の吹き出しは出さない
                if (e.stop) { e.stop(); }
                placeDetails(map, e.placeId, function (place) {
                    openPoiBubble(map, (place && place.geometry && place.geometry.location) || e.latLng, place);
                });
                return;
            }
            closePoiBubble();
            // 施設以外のクリックはACFと同じ（物件ピンを移動）
            if (field && e && e.latLng) {
                field.searchPosition(e.latLng.lat(), e.latLng.lng());
            }
        });

        // 地図に出ていない施設も足せるよう、右クリックは空欄の吹き出しを出す
        ['rightclick', 'contextmenu'].forEach(function (evt) {
            map.addListener(evt, function (e) {
                if (!e || !e.latLng) { return; }
                openPoiBubble(map, e.latLng, null);
            });
        });
    }

    /* ── 初期化 ───────────────────────────────────── */

    function bindAll(scope) {
        bindArea(scope);
        bindMap(scope);
        bindNearby(scope);
        spreadToLots('');
        renderFacilityPins();
    }

    bindAll(document);
    bindPropertyAddress();

    if (window.acf) {
        // リピーターの行追加・タブ切替のあとにも効かせる
        acf.addAction('append', function ($el) { bindAll($el); });
        acf.addAction('remove', function () { renderFacilityPins(); });
        // ACFは地図を初期化し終えると google_map_init を発火する。
        // Googleのスクリプトは非同期で来るので、ここで受けるのが確実。
        acf.addAction('google_map_init', function (map, marker, field) {
            propertyMap = map;

            // ACFの既定は scrollwheel:false でズームボタンも無く、拡大縮小ができない。
            // ボタンを出し、ホイールは Ctrl/⌘ を押したときだけ効くようにする
            // （cooperative）。ページをスクロールしただけで地図が動く事故を避けつつ、
            // 意図したズームはできる状態にする。
            map.setOptions({
                zoomControl: true,
                gestureHandling: 'cooperative',
                mapTypeControl: true,
            });

            bindMapFacilityCreate(map, field);
            renderFacilityPins();
        });
        acf.addAction('ready_field/type=google_map', function (field) { bindMap(field.$el.parent()); });
    }
});
JS;
}

/**
 * 周辺施設の座標を返す（編集画面の距離計算に使う）
 *
 * 施設を選んだ瞬間に距離の目安を出したいが、座標はサーバー側にしかない。
 * 選択のたびに必要な分だけ取りに来る。
 */
add_action('wp_ajax_hb2103_facility_geo', function (): void {
    check_ajax_referer('hb2103_facility_geo');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(null, 403);
    }

    $ids = isset($_GET['ids']) ? array_map('intval', (array) $_GET['ids']) : [];
    $out = [];

    foreach (array_slice($ids, 0, 100) as $id) {
        if ($id <= 0 || get_post_type($id) !== 'facility') {
            continue;
        }
        $map = get_field('map', $id);
        if (is_array($map) && isset($map['lat'], $map['lng'])) {
            $out[$id] = [
                'lat'   => (float) $map['lat'],
                'lng'   => (float) $map['lng'],
                'title' => get_the_title($id),
                // 周辺環境の行からカテゴリを直せるようにするため一緒に返す
                'type'  => hb2103_facility_type_id($id),
            ];
        }
    }

    wp_send_json_success($out);
});

/**
 * 周辺施設をその場で作る
 *
 * 施設は「周辺施設」の一覧から登録するのが基本だが、分譲地を編集している最中に
 * 「この施設がまだ無い」と気づくことが多い。別画面へ移動して戻ってくるのは手間なので、
 * 分譲地の編集画面から直接作れるようにする。
 *
 * 位置は地図に出ている施設をクリックして選ぶ。地図に無い場所は右クリックで指定する。
 */
add_action('wp_ajax_hb2103_create_facility', function (): void {
    check_ajax_referer('hb2103_create_facility');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => '権限がありません'], 403);
    }

    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    $lat   = isset($_POST['lat']) ? (float) $_POST['lat'] : 0.0;
    $lng   = isset($_POST['lng']) ? (float) $_POST['lng'] : 0.0;
    $type  = isset($_POST['type']) ? (int) $_POST['type'] : 0;
    $addr  = isset($_POST['address']) ? sanitize_text_field(wp_unslash($_POST['address'])) : '';

    if ($title === '') {
        wp_send_json_error(['message' => '施設名を入力してください']);
    }

    // 同じ名前の施設が既にあれば作らずにそれを返す（重複を増やさない）
    $existing = get_posts([
        'post_type'   => 'facility',
        'title'       => $title,
        'numberposts' => 1,
        'fields'      => 'ids',
        'post_status' => 'any',
    ]);

    if ($existing) {
        wp_send_json_success([
            'id'       => (int) $existing[0],
            'title'    => $title,
            'existing' => true,
        ]);
    }

    $id = wp_insert_post([
        'post_type'   => 'facility',
        'post_title'  => $title,
        'post_status' => 'publish',
    ], true);

    if (is_wp_error($id)) {
        wp_send_json_error(['message' => $id->get_error_message()]);
    }

    if ($lat && $lng) {
        update_field('field_hb2103_fa_map', [
            'address' => $addr,
            'lat'     => $lat,
            'lng'     => $lng,
            'zoom'    => 16,
        ], $id);
    }

    if ($type > 0) {
        wp_set_object_terms($id, [$type], 'facility_type');
    }

    wp_send_json_success([
        'id'       => (int) $id,
        'title'    => $title,
        'existing' => false,
        'editUrl'  => get_edit_post_link($id, 'raw'),
    ]);
});

/**
 * 施設に付いている施設カテゴリのID（無ければ0）。
 */
function hb2103_facility_type_id(int $id): int
{
    $terms = wp_get_object_terms($id, 'facility_type', ['fields' => 'ids']);
    if (is_wp_error($terms) || !$terms) {
        return 0;
    }
    return (int) $terms[0];
}

/**
 * 周辺環境の行から施設カテゴリを付け替える。
 *
 * カテゴリは施設そのものの情報なので、ここで変えると施設側が書き換わる
 * （この分譲地だけの設定にはならない）。分譲地を保存しなくてもすぐ反映される。
 */
add_action('wp_ajax_hb2103_set_facility_type', function (): void {
    check_ajax_referer('hb2103_set_facility_type');

    $id   = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $type = isset($_POST['type']) ? (int) $_POST['type'] : 0;

    if ($id <= 0 || get_post_type($id) !== 'facility') {
        wp_send_json_error(['message' => '施設が見つかりません']);
    }
    if (!current_user_can('edit_post', $id)) {
        wp_send_json_error(['message' => '権限がありません'], 403);
    }

    if ($type > 0) {
        $term = get_term($type, 'facility_type');
        if (!$term || is_wp_error($term)) {
            wp_send_json_error(['message' => 'カテゴリが見つかりません']);
        }
        wp_set_object_terms($id, [$type], 'facility_type');
    } else {
        wp_set_object_terms($id, [], 'facility_type');
    }

    wp_send_json_success(['id' => $id, 'type' => $type]);
});
