# wordpress-connect-to-buffer
Bufferへ投稿するプラグイン

## サービス情報

| 項目 | 内容 |
|---|---|
| サービス名 | wordpress-connect-to-buffer |
| 段階 | 開発中 |
| GitHub リポジトリ | https://github.com/smallpiece2024/wordpress-connect-to-buffer |
| URL | 未定 |
| インフラ | WordPress、Buffer |
| 説明 | WordPress から Buffer へ投稿するプラグイン |
| 公開日 | 未公開 |
| UptimeRobot monitor ID | なし |

## できること

投稿を公開すると、タイトル、URL、アイキャッチ画像を Buffer に送る。

- Buffer の下書きとして保存するか、キューに追加するかを選べる。既定は下書き。下書きは、Buffer で予約するまで SNS に公開されない。キューに追加したものは、投稿スケジュールの次の枠で自動的に公開される。
- 対象は通常の「投稿」だけ。固定ページとカスタム投稿タイプは送らない。
- 送るのは、初めて公開したときの 1 回だけ。公開済みの投稿を更新しても送らない。予約投稿は、公開された時点で送る。
- 投稿先のチャンネルは複数選べる。
- チャンネルごとに、アイキャッチ画像をそのまま送るか、タイトル入りの縦長画像（1080×1920）に加工して送るかを選べる。
- タグを指定すると、そのタグのいずれかが付いた投稿だけを送る。指定しなければ、すべての投稿を送る。
- パスワードで保護した投稿は送らない。

## 必要なもの

- WordPress 5.6 以降、PHP 7.4 以降
- Buffer のアカウントと API キー
- タイトル入りの縦長画像を使うときは、PHP の GD 拡張（FreeType 対応）

## 使い方

1. このリポジトリを `wp-content/plugins/wordpress-connect-to-buffer` に置き、プラグインを有効にする。
2. Buffer の [API 設定](https://publish.buffer.com/settings/api) で API キーを作る。
3. 「設定 > Connect to Buffer」を開き、API キーを保存する。
4. 表示されたチャンネルから投稿先を選び、送り方（下書きかキューか）と、必要ならタグを指定して保存する。タイトル入りの縦長画像を送りたいチャンネルには、「Vertical image with the title (1080×1920)」にチェックを入れる。

送信の結果は、設定画面の「Recent activity」に直近 20 件が表示される。

### API キーを wp-config.php に書く

API キーをデータベースに保存したくないときは、`wp-config.php` に定数を書く。定数があれば、設定画面の入力より優先される。

```php
define( 'WCTB_API_KEY', 'ここに API キー' );
```

### 画像について

Buffer は画像を URL から取得する。そのため、アイキャッチ画像は次の条件を満たす必要がある。満たさないと、画像なしで送るか、Buffer 側でエラーになる。

- HTTPS で配信されている。HTTPS でない画像は付けずに送る。
- ログインなしで直接開ける。
- サイトの bot 保護（Cloudflare など）が、Buffer からの取得を拒否しない。

### タイトル入りの縦長画像

Instagram のストーリーズなど、縦長の画像を使う投稿先のための加工。チャンネルごとに有効にできる。

- アイキャッチ画像の中央を 9:16 に切り抜き、1080×1920 にする。元の画像の短いほうの辺をそのまま使う。横長の画像なら縦をすべて使って左右を切り、9:16 より縦長の画像なら横をすべて使って上下を切る。
- 画像の中央に半透明の帯を敷き、投稿のタイトルを白い文字で描く。長いタイトルは折り返し、文字を小さくしても 6 行に収まらない分は「…」で切る。
- 文字は同梱の Noto Sans JP（Bold）で描くので、日本語のタイトルも描ける。
- 生成した画像は `wp-content/uploads/wctb/` に保存する。投稿を完全に削除したときと、プラグインを削除したときに消す。
- アイキャッチ画像がない、サーバーの PHP に GD 拡張（FreeType 対応）がないなどで生成できなければ、アイキャッチ画像をそのまま送る。

見本は次のコマンドで `tests/output/` に書き出せる。

```
npx @wordpress/env run cli wp eval-file wp-content/plugins/wordpress-connect-to-buffer/tests/sample-image.php
```

### フィルター

| フィルター | 用途 |
|---|---|
| `wctb_should_send` | 投稿ごとに送るかどうかを変える |
| `wctb_post_text` | Buffer に送る本文を変える |
| `wctb_post_image_url` | Buffer に送る画像の URL を変える。空文字なら画像を付けない。第 3 引数でチャンネル ID を受け取れる |
| `wctb_title_image_text` | 縦長画像に描き込む文字を変える。空文字なら何も描かない |
| `wctb_title_image_font` | 縦長画像の文字に使うフォントファイル（TrueType か OpenType）のパスを変える |
| `wctb_post_metadata` | サービス固有の指定を変える。Facebook と Instagram には、通常の投稿（`type: post`）として送る指定を付けている |

## 開発

Docker と Node.js があれば、`@wordpress/env` で手元に WordPress を起動できる。

```
npx @wordpress/env start
```

http://localhost:8888/wp-admin/ を開き、ユーザー名 `admin`、パスワード `password` でログインする。

動作確認のスクリプトは次のコマンドで実行する。Buffer への通信は模擬するので、実際の投稿は作られない。保存済みの設定と送信ログは、実行後に元へ戻る。

```
npx @wordpress/env run cli wp eval-file wp-content/plugins/wordpress-connect-to-buffer/tests/smoke.php
```

## インフラ

| サービス | 用途 | 設定の場所 |
|---|---|---|
| WordPress | プラグインを動かすサイト | プラグインを入れるサイトの管理画面で設定 |
| Buffer | SNS への投稿の予約。GraphQL API で下書きまたはキューに追加する | 設定画面「設定 > Connect to Buffer」、または `wp-config.php` の定数 `WCTB_API_KEY` |

## ライセンス

GPL-2.0-or-later

同梱のフォント Noto Sans JP（`assets/fonts/NotoSansJP-Bold.otf`）は、SIL Open Font License 1.1 で配布されている。ライセンスの全文は `assets/fonts/OFL.txt` にある。
