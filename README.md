# HB 不動産コア

不動産サイト共通の土台。物件・区画・周辺施設のデータ構造、URL設計、メディア配置、入力補助。
**見た目はここに置かない**（テンプレート・CSSはテーマ側）。

| 投稿タイプ | |
|---|---|
| `property` | 分譲地。号地は独立投稿にせず `lots` ACFリピーターで持つ |
| `facility` | 周辺施設マスタ（非公開CPT） |
| `equipment` | 設備・仕様 |
| `works` | 施工事例 |
| `voice` | お客様の声 |

| タクソノミー | |
|---|---|
| `area` | エリア。市区町村を階層で持つ |
| `facility_type` / `equipment_type` | 種別 |
| `media_category` | メディアの分類 |

## 入れかた

1. [Releases](https://github.com/hb149/hb-realestate/releases) の `hb-realestate.zip` をアップロード
2. 有効化し、プラグイン一覧で「自動更新を有効化」を押す

更新の仕組みは [hb-base](../hb-base/) と共通。hb-base が入っていれば自動更新が効く
（`hb_github_updater_register()` を借りている）。単体でも動くが、その場合は手動更新になる。

## 停止できないようにしてある

投稿タイプの登録が止まると、**投稿はDBに残ったまま管理画面から消える**。
事故を避けるためプラグイン一覧の「停止」リンクを外してある。

開発中に止めたいときは:

```php
define('HB2103_RE_ALLOW_DEACTIVATE', true);   // wp-config.php
```

または `wp plugin deactivate hb-realestate`。

## 接頭辞

関数・定数・ACFキー・CSSクラスはすべて `hb2103` で始める。
`hb2103_get_...()` / `HB2103_RE_DIR` / `field_hb2103_p_price` / `.hb2103-card`。

案件固有のコードと混ざらないようにするための決めごと。案件側は別の接頭辞を使うこと。

## ACFの定義

`acf-json/` にファイルで持つ。管理画面で編集するとここに書き出される。

```
acf-json/group_hb2103_property.json   分譲地情報
acf-json/group_hb2103_lots.json       区画（更新頻度が高いので別グループ）
acf-json/group_hb2103_facility.json   周辺施設
acf-json/group_hb2103_equipment.json  設備・仕様
acf-json/group_hb2103_settings.json   サイト共通設定
```

DBではなくファイルに置くのは、定義を案件のリポジトリで追えるようにするため。

## 構成

```
hb-realestate.php      読み込み・更新登録・停止ロック
src/post-types.php     投稿タイプ
src/taxonomies.php     タクソノミー
src/area.php           住所から市区町村を読み取ってエリアを付ける
src/permalinks.php     URL周りの調整
src/slug.php           スラッグを英字必須にする（日本語URLを作らせない）
src/media.php          物件単位のアップロード配置・WebP・メディア一覧の物件カラム
src/acf.php            ACF定義をファイルで管理する
src/admin.php          管理画面CSS（ACFの設定で解決できないものだけ）
src/property-calc.php  価格・面積の範囲を区画から計算する
src/admin-assist.php   編集画面の入力補助（面積換算・住所の自動提示）
src/content-sync.php   説明文を本文へ同期
src/figure.php         区画図などの図版出力
```

## 案件側に置くもの

旧サイトからの移行、リダイレクト表、その案件にしか無い文言や分岐。
第一ホームでは `wp-content/mu-plugins/dai1-core/legacy.php` が該当する。

## リリースのしかた

`hb-realestate.php` の `Version:` を上げてタグを打つ。

```bash
git tag v1.0.1 && git push origin v1.0.1
```
