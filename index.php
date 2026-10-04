<?php
/**
 * =====================================================================
 * プシューチャット (index.php) - 単一ファイル構成
 *
 * 2つのタブがあります。
 *   - メイキータブ: プシューメイキィのアカウントでログインして使います。
 *     アカウントを使うことで、ユーザー名を指定した直接招待(コード交換が
 *     不要)ができ、複数端末・複数ブラウザからログインしても同じ会話一覧が
 *     見られます(useridをそのままroomの持ち主として使っているため、
 *     追加の同期処理は不要です)。
 *   - 匿名タブ: アカウント不要。Cookieベースの匿名actorとして、招待コード
 *     (base64の文字列)を相手に渡す→相手が貼り付けて承諾する、という
 *     ハンドシェイクを経て会話(room)を確立します。Meikeeにログイン中でも
 *     このタブでは常に匿名Cookieの身元を使い、実際の身元は漏らしません。
 *
 * どちらのタブも、会話の確立後は同じ仕組みで動きます: メッセージはサーバー
 * 保管の静的鍵でAES-256-GCM暗号化してstorage/messages/配下に永続保存し、
 * 全機能がJavaScript無しの素のフォーム送信(PRG: Post-Redirect-Get)だけで
 * 動きます(参考: pips/index.php のPRGパターン)。JSは新着のライブ更新、
 * 招待コードのQR表示/音波共有、シークレット会話モード(後述)のみを担う
 * 任意の強化層です。
 *
 * どちらのタブの会話にも「シークレットモード」を設定できます。有効にすると
 * メッセージは一定時間(既定24時間)で自動的に消えます(p-5secondの使い捨て
 * ファイルと同じ「アクセスの都度、期限切れを掃除する」方式)。JSが有効なら
 * さらに、会話の中からWebRTCで直接P2P接続する「シークレットモードで
 * WebRTC接続する」機能も使えます(このサーバーを一切経由せず、ページ内
 * メモリにのみメッセージが存在し、保存も一切行いません)。
 *
 * 他サービス(p-meikiee・p-reversi・pips等)と同じく、入口をこの1ファイルに
 * まとめる方針を踏襲しています。詳しくは
 * p-meikiee/ACCOUNTS_INTEGRATION_SPEC.md を参照してください。
 * =====================================================================
 */

declare(strict_types=1);

// 他のプロダクト(main 等)の住所の頭。台帳(pusyuu_registry)から決まります。
// 【ドメインを書き戻さないこと】以前はテンプレートに https://pusyuuwanko.com 等を固定で
// 書いていたため、IPやLAN、別のドメインから入った人の画面でも公開ドメインへ取りに
// 行っていました。$public は OGP のように、よそ(SNS)が取りに来るものだけ true。
// 台帳の係が無い・読めないときは空文字(=このページのホストの / から)に倒れます。
function pchatSiteBase(string $id, bool $public = false): string {
  return class_exists('PusyuuRegistryClient') ? PusyuuRegistryClient::baseHtml($id, $public) : '';
}

// =====================================================================
// セッションの窓口(p-chatの「顔」)
//
// 共有スクリプト pusyuu_session.php があれば、そちらへ委ねます。**クッキーを切って
// いる利用者にも、URLの引き換えコードでセッションが続きます**(ログインも保てます)。
//
// 【$_SESSION はこれまでどおり使えます】共有スクリプト側が $_SESSION と自分の保存先を
// 同期します(PusyuuSession::startWithSuperglobal())。だから本文の $_SESSION は
// 1か所も書き換えていません。
//
// 【PHPのセッション関数だけは置き換えが必要です】session_regenerate_id() などは
// 「使われていないPHPのセッション」を相手にしてしまい、**黙って効かなくなります。**
// 下の PchatSession 経由で呼んでください。
//
// 【共有スクリプトが無い環境でも動くこと】以前とまったく同じ始め方をする枝を残して
// あります。どちらの枝も同じ意味になるように揃えること。片方だけ直すと、片方の
// 環境だけが壊れます。
// =====================================================================
$directry = "./../main/pusyuusystem/scripts/php_scripts/pusyuu_session.php";
if (file_exists($directry)) {
  require_once($directry);
}

class PchatSession {
  /** 共有スクリプトを通しているか。start() で1回だけ決まります。 */
  private static $viaLibrary = false;

  /** セッションを始めます。**出力より前に1回だけ**呼んでください。 */
  public static function start() {
    if (class_exists('PusyuuSession')) {
      self::$viaLibrary = true;
      // 保存先の選び方もCookieの属性も、共有スクリプト側が決めます。
      PusyuuSession::startWithSuperglobal();
    } else {
      self::$viaLibrary = false;
      // 共有スクリプトが無い環境。以前とまったく同じ始め方です。
      //
      // 【SameSiteは必ずLax】Strictにすると、メイキィのログイン画面から戻ってきた
      // トップレベル遷移にCookieが送られず、毎回「別のセッション」として扱われます。
      // 往路で控えたstateごと消えるので照合が必ず外れ、ログインが永久に成立しません。
      //
      // 【secureは実際にHTTPSで来ているかで決める】無条件にtrueにすると、HTTPで
      // 配信されている環境ではブラウザがセッションCookieを一切保存できず、ログイン状態が
      // 1リクエストも保ちません。逆に無条件にfalseだと、HTTPS環境で保護が1段落ちます。
      $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
      session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
      ]);
      session_cache_limiter('nocache');
      session_start();
    }
  }

  /** セッションIDを作り直します(ログイン=権限昇格のときのセッション固定化対策)。 */
  public static function regenerateId() {
    if (self::$viaLibrary) {
      PusyuuSession::regenerateId(true);
    } else {
      session_regenerate_id(true);
    }
  }

  /** セッションを閉じます(以降は書けません)。 */
  public static function close() {
    if (self::$viaLibrary) {
      PusyuuSession::close();
    } else {
      session_write_close();
    }
  }
}

PchatSession::start();

$dir = "./";
$directry = $dir . "../main/pusyuusystem/scripts/php_scripts/panic_handler.php";
if (file_exists($directry)) {
  require_once($directry);
}

$directry = $dir . "../main/pusyuusystem/scripts/php_scripts/usage_tracker.php";
if (file_exists($directry)) {
  require_once($directry);
}
if (function_exists('pusyuuRecordProductUsage')) {
  pusyuuRecordProductUsage('p-chat');
}

$directry = $dir . "../main/pusyuusystem/scripts/php_scripts/notation.php";
if (file_exists($directry)) {
  require_once($directry);
}
// 他のプロダクトの住所・サーバ間で繋ぐ先は、台帳(pusyuu_registry)から受け取ります。
// 無い環境では、プロダクト一覧・プッシュ通知・p-5second連携だけが止まります。
$directry = $dir . "../main/pusyuusystem/scripts/php_scripts/pusyuu_registry_client.php";
if (file_exists($directry)) {
  require_once($directry);
}
// 暗号化ヘルパーは共有スクリプトへ依存させず、このファイル自身が持ちます
// (aesGcmEncrypt()/pchatBlobEncrypt() 等)。以前は
// main/pusyuusystem/scripts/php_scripts/pusyuu_storage_crypto.php をrequireし、
// 見つからなければdie()していましたが、それだと共有部分の置き場所が変わったり
// p-chatフォルダ単体を持ち出したりしただけでサービスが起動しなくなります。
// 方針の理由は pchatBlobEncrypt() 手前のコメントを参照。

// =====================================================================
// アカウント機能の実体は p-chat の中にはありません。共有スクリプトが提供する
// pusyuuAccount〜() へ頼み、p-chat はその答えを自分の言葉へ翻訳するだけです。
//
// 以前はここに、メイキィと話すクラスの全文(500行あまり)が「p-reversi・pipsと同じ
// 実装をそのままコピー」という形で埋め込まれていました。その運用は実際には
// 揃わず、toolboxの複製だけが1文字違っていたせいでtoolboxのアカウント機能が
// 全滅していました(2026-09-06)。持ち物が1つなら、そもそも食い違いようがありません。
//
// 接続先の設定(BASE_URL/HOST/TIMEOUT/鍵の場所)もここでは定義しません。共有
// スクリプト側の既定をそのまま使います。以前ここだけ http:// になっていましたが、
// プレーンHTTPはApacheの強制HTTPSリダイレクトに乗るため、共有側の既定(https)に
// 揃えます。特別な事情があるプロダクトだけが、読み込みより前に自分で define して
// 上書きしてください(p-driveがタイムアウトを10秒にしているのがその例です)。
//
// 【使うときは必ず PchatAccount を通すこと】
// 本文から直接 pusyuuAccount〜() を呼ばないでください。共有スクリプトが無い環境では
// それらの関数自体が存在せず、「Call to undefined function」で致命的エラーになり、
// p-chatのページが1行も表示されなくなります。匿名タブすら開けなくなります。
// =====================================================================
$directry = $dir . "../main/pusyuusystem/scripts/php_scripts/meikiee_client.php";
if (file_exists($directry)) {
  require_once($directry);
}

// ================================================================
// p-chat から見たアカウント機能(p-chatの「顔」)
//
// p-chatは「メイキィでログインして使う」ほかに「匿名タブ」を持ちます。つまり
// アカウント機能が使えなくても、サービスとしては成立します。ここが返す
// 「使えないときの答え」は、その匿名側へ素直に倒れるための形です。
// ================================================================
class PchatAccount {
  /** アカウント機能が今使えるかどうか。他のメソッドは全部これを先に見ます。 */
  public static function ready(): bool {
    if (function_exists('pusyuuAccountReady')) {
      $result = pusyuuAccountReady();
    } else {
      // 共通のアカウント連携ファイルが置かれていないサーバ。単体で動かすための道です。
      $result = false;
    }

    return $result;
  }

  /** 使えないときの理由(利用者にそのまま見せてよい1文)。 */
  public static function unavailableReason(): string {
    if (function_exists('pusyuuAccountUnavailableReason')) {
      $result = pusyuuAccountUnavailableReason();
    } else {
      $result = 'このサーバにはアカウント連携機能が設置されていないため、メイキィでのログインはご利用いただけません。匿名タブは通常どおりお使いいただけます。';
    }

    return $result;
  }

  /** 直近のログインが失敗していれば、その理由を1度だけ返します(読んだら消えます)。 */
  public static function takeSignInError(): string {
    if (function_exists('pusyuuAccountTakeSignInError')) {
      $result = pusyuuAccountTakeSignInError();
    } else {
      $result = '';
    }

    return $result;
  }

  /**
   * 「使えるなら $work を実行し、使えないなら $fallback を返す」。
   * 判定をここ1箇所に集めておけば、メソッドを足すときに書き忘れようがありません。
   * $fallback は引数なので先に評価されます。軽いものだけを渡してください。
   */
  private static function whenReady(callable $work, $fallback) {
    if (self::ready()) {
      $result = $work();
    } else {
      $result = $fallback;
    }

    return $result;
  }

  /** p-chat自身のセッションが持っているトークン。未ログインなら空文字。 */
  public static function token(): string {
    return pchatAccountsToken();
  }

  /**
   * トークンの持ち主。['ok'=>bool,'user'=>array,'rejected'=>bool,'message'=>string]。
   *
   * 【rejected を必ず見てください】p-chatは元々ここを正しく区別していた唯一の
   * プロダクトでしたが、その判定はエラー名の文字列("api_unreachable" 等)を直接
   * 見比べるものでした。共有スクリプト側で失敗の段階を細かく分けた結果、その名前は
   * もう返りません。名前で見比べる書き方に戻すと、次に段階名が増えたときに静かに
   * 壊れ、メイキィが落ちただけで全員が強制ログアウトされる元の不具合が再発します。
   * 判定は共有側の rejected に任せてください。
   */
  public static function sessionResult(): array {
    $token = self::token();

    if ($token === '') {
      // 未ログイン。rejected を false にしておくのが要点です。true にすると
      // 呼び出し側が「無効なトークンだった」と解釈してセッションを破棄します。
      $result = ['ok' => false, 'rejected' => false, 'message' => ''];
    } else {
      $result = self::whenReady(static function () use ($token) {
        return pusyuuAccountSelf($token);
      }, ['ok' => false, 'rejected' => false, 'message' => self::unavailableReason()]);
    }

    return $result;
  }

  /** ログイン画面へのリンク。使えないときは空文字(リンクを出さないでください)。 */
  public static function loginUrl(): string {
    return self::whenReady(static function () {
      return pusyuuAccountSignInUrl();
    }, '');
  }

  /** アカウント作成画面へのリンク。 */
  public static function createUrl(): string {
    return self::whenReady(static function () {
      return pusyuuAccountCreateUrl();
    }, '');
  }

  /** アカウント編集画面へのリンク。 */
  public static function manageUrl(): string {
    return self::whenReady(static function () {
      return pusyuuAccountManageUrl();
    }, '');
  }

  /** ログアウトの戻り先URL。使えないときは自分のトップへ戻します。 */
  public static function logoutUrl(): string {
    return self::whenReady(static function () {
      return pusyuuAccountSignOutUrl();
    }, './');
  }

  /** 手元のトークンを失効させます(画面遷移は伴いません)。 */
  public static function revokeToken(string $token): void {
    if (self::ready()) {
      pusyuuAccountRevokeToken($token);
    }
  }

  /** 今のページのURL(往復の印は落とされます)。使えないときは自分で組み立てます。 */
  public static function currentUrl(): string {
    return self::whenReady(static function () {
      return pusyuuAccountCurrentUrl();
    }, 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
  }

  /**
   * 直近の失敗が「メイキィまで届かなかった」ものかどうか。
   * profile() などが null を返したとき、「居ない」のか「聞けなかった」のかを
   * 見分けるために使います。混ぜると、実在する相手を「居ない」と断定します。
   */
  public static function lastFailureWasConnection(): bool {
    if (function_exists('pusyuuAccountLastFailureWasConnection')) {
      $wasConnection = pusyuuAccountLastFailureWasConnection();
    } else {
      // 基盤そのものが無いなら「届かなかった」側に倒します。
      // 「居ない」と断定してしまうと、生きている相手との会話を消す側へ倒れます。
      $wasConnection = true;
    }

    return $wasConnection;
  }

  /** ユーザー名から公開プロフィールを引きます。見つからなければ null。 */
  public static function profile(string $username): ?array {
    return self::whenReady(static function () use ($username) {
      return pusyuuAccountProfile($username);
    }, null);
  }

  /**
   * その保存先IDのアカウントがまだ存在するか。
   * 確認できなかったときは true を返します。確認できないことを理由に、
   * 生きている相手との会話を消してしまう方が有害だからです。
   */
  /**
   * accountExists() と同じ確認を、「確かめられたのか」まで込みで返します。
   *   ['confirmed' => bool, 'exists' => bool]
   * 掃除のように「居ないなら消す」処理は、必ずこちらを使ってください。
   * 真偽値だけだと、確認できなかった場合の安全側の答え(居る)と、本当に居る場合を
   * 区別できず、基盤へ届かなくなったときに掃除が黙って止まります。
   */
  public static function accountExistsChecked(string $storageId): array {
    if (function_exists('pusyuuAccountExistsChecked')) {
      $result = pusyuuAccountExistsChecked($storageId);
    } else {
      // 確かめられなかった、という答え。exists=true は安全側の値であって
      // 「確かに居る」ではありません。confirmed を見ずに消さないでください。
      $result = ['confirmed' => false, 'exists' => true];
    }

    return $result;
  }

  public static function accountExists(string $storageId): bool {
    return self::whenReady(static function () use ($storageId) {
      return pusyuuAccountExists($storageId);
    }, true);
  }

  /** 自分の設定値を読みます(既読表示のON/OFFなど)。 */
  public static function dataGet(string $key): array {
    $token = self::token();
    return self::whenReady(static function () use ($token, $key) {
      return pusyuuAccountDataGet($token, 'pchat', $key);
    }, ['ok' => false, 'message' => self::unavailableReason()]);
  }

  /** 自分の設定値を書きます。 */
  public static function dataSet(string $key, $value): array {
    $token = self::token();
    return self::whenReady(static function () use ($token, $key, $value) {
      return pusyuuAccountDataSet($token, 'pchat', $key, $value);
    }, ['ok' => false, 'message' => self::unavailableReason()]);
  }
}

// =====================================================================
// プシュー共通プッシュ通知APIクライアント
// (main/pusyuusystem/apis/pusyuu_push。詳しくは同ディレクトリの
// PUSH_INTEGRATION_SPEC.md 参照。accountsクライアントと同じ「別ファイルに
// 切り出さずコピーする」方式)
// =====================================================================

// 【宛先をここに書かないこと】プッシュ通知APIは main(台帳のID)の中にあり、繋ぐ先と
// 名乗るホスト名は台帳(pusyuu_registry)から受け取ります。以前はここで
// http://127.0.0.1/... と pusyuuwanko.com を固定していました。
const PCHAT_PUSH_REGISTRY_ID = 'main';
const PCHAT_PUSH_PATH        = '/pusyuusystem/apis/pusyuu_push/index.php';

/** accountsと同じ鍵ファイルからHMACで導出する合言葉。 */
function pusyuuPushApiSecret(): string {
  static $resolved = false;
  static $secret = '';

  // 2回目以降は $resolved が true なので、この塊ごと素通りして答えを使い回します。
  if ($resolved === false) {
    $resolved = true;
    // 【dirname() を使わないこと】dirname('.') は '.' のままで上へ登れません。
    // 相対パスのまま登るには、このように '/..' を文字列で足していきます。
    $dir = '.';
    $done = false;

    // 上限8段は、見つからないときに延々と登り続けないための歯止めです。
    for ($i = 0; $i <= 8 && $done === false; $i++) {
      $candidate = $dir . '/.pusyuuHiddenFiles/pips_account_key.php';

      if (file_exists($candidate)) {
        $encoded = require $candidate;
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        // 鍵が壊れていたら合言葉は空のまま。探し直しても同じ物しか無いので打ち切ります。
        if ($key !== false && strlen($key) === 32) {
          $secret = hash_hmac('sha256', 'pusyuu_push_api', $key);
        }
        $done = true;
      } else {
        $dir = $dir . '/..';
      }
    }
  }

  return $secret;
}

/**
 * pusyuu_pushへの全アクション共通の呼び出し口。「通知が来ない/遅い」の原因切り分けの
 * ため、動作ログ(ok/エラー種別・所要時間)を必ず1行残す。呼び出し元(メッセージ送信等)を
 * 巻き込まないよう、ここでは例外を投げない。
 */
function pusyuuPushApi(string $action, array $params = []) {
  $startedAt = microtime(true);
  $params['api_secret'] = pusyuuPushApiSecret();
  $body = http_build_query($params);

  // 通信は台帳を読む係(PusyuuRegistryClient::call)の1本だけです。宛先・Host・証明書の
  // 確かめ方はあちらが台帳から決めます。台帳の係が無い環境では届かない扱いにします。
  if (class_exists('PusyuuRegistryClient')) {
    $response = PusyuuRegistryClient::call(PCHAT_PUSH_REGISTRY_ID, 'POST', PCHAT_PUSH_PATH . '?api=' . urlencode($action), ['Content-Type: application/x-www-form-urlencoded'], $body, 5);
    $raw = ($response['problem'] === '') ? $response['body'] : false;
  } else {
    $raw = false;
  }

  $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
  $decoded = ($raw === false || $raw === null || $raw === '') ? null : json_decode($raw, true);

  if ($raw === false || $raw === null || $raw === '') {
    error_log("[p-chat push] {$action} 失敗: プッシュ通知APIへの接続に失敗しました。({$elapsedMs}ms)");
    $result = ['ok' => false, 'error' => 'api_unreachable'];

  } else if (!is_array($decoded)) {
    error_log("[p-chat push] {$action} 失敗: 応答を解釈できませんでした。body=" . substr($raw, 0, 200) . " ({$elapsedMs}ms)");
    $result = ['ok' => false, 'error' => 'api_broken_response'];

  } else {
    // 【成功も失敗も必ず1行残すこと】「通知が来ない/遅い」の切り分けは、
    // ここの記録が無いと手掛かりが1つも残りません。
    if (empty($decoded['ok'])) {
      error_log("[p-chat push] {$action} 失敗: " . ($decoded['error'] ?? 'unknown') . " ({$elapsedMs}ms)");
    } else {
      error_log("[p-chat push] {$action} 成功 ({$elapsedMs}ms)");
    }
    $result = $decoded;
  }

  return $result;
}

/** 通知を1件送る。失敗しても例外を投げないため、呼び出し元(メッセージ送信等)を巻き込まない。 */
function pusyuuPushSend(string $userid, string $title, string $body, string $url = '', ?string $tag = null, ?string $icon = null): array {
  return pusyuuPushApi('send', [
    'service' => 'p-chat', 'userid' => $userid, 'title' => $title,
    'body' => $body, 'url' => $url, 'tag' => $tag ?? '',
    // 通知は要求と無関係に届くので、今の入口ではなく公開の顔の住所を使います。
    'icon' => $icon ?? (class_exists('PusyuuRegistryClient') ? (string)PusyuuRegistryClient::publicUrl('p-chat', '/assets/images/icon512_rounded.png') : ''),
  ]);
}

function pusyuuPushSubscribe(string $userid, string $subscriptionJson, string $ua): array {
  return pusyuuPushApi('subscribe', ['service' => 'p-chat', 'userid' => $userid, 'subscription' => $subscriptionJson, 'ua' => $ua]);
}

function pusyuuPushUnsubscribe(string $userid, string $endpoint): array {
  return pusyuuPushApi('unsubscribe', ['service' => 'p-chat', 'userid' => $userid, 'endpoint' => $endpoint]);
}

/**
 * inboxの中身を既読にする。$idsを渡さなければその利用者ぶんを丸ごと既読にする。
 *
 * API側の実装は「削除」ではなく既読フラグを書く形に変わったが(RSSフィードが
 * 同じinboxを履歴として読むため。PUSH_INTEGRATION_SPEC.md 8節)、未読一覧から
 * 消えるという点はまったく同じなので、こちら側で変えることは何も無い。
 */
function pusyuuPushAck(string $userid, array $ids = []): array {
  return pusyuuPushApi('ack', ['service' => 'p-chat', 'userid' => $userid, 'ids' => $ids]);
}

/**
 * 通知チャンネルの状態(push/rss/desktop)を取る。
 *
 * 【なぜセッションにキャッシュするのか】これを見たいのはページ描画のたびなので、
 * 毎回APIへ往復すると会話画面の表示まで巻き込む。値を変えるのは自分自身
 * (pusyuuPushSetChannel)だけで、そこで必ずキャッシュを捨てるため、古い値が
 * 残ることが無い。
 *
 * 取得に失敗したときは空配列を返す。呼び出し側は「分からない」と「使わない」を
 * 区別すること(APIが一時的に落ちただけで通知が黙って消えるのは避けたい)。
 */
function pusyuuPushChannelStates(string $userid): array {
  $cache = $_SESSION['pusyuu_push_channels'] ?? null;
  $fresh = is_array($cache)
    && (int)($cache['at'] ?? 0) > time() - 300
    && is_array($cache['states'] ?? null);

  if ($fresh) {
    $states = $cache['states'];
  } else {
    $res = pusyuuPushApi('channels_get', ['service' => 'p-chat', 'userid' => $userid]);
    $states = (!empty($res['ok']) && is_array($res['channels'] ?? null)) ? $res['channels'] : [];

    // 取れなかったとき(空)は控えません。空を控えると、APIが一瞬落ちただけの
    // 「分からない」が5分間そのまま居座ります。
    if (!empty($states)) {
      $_SESSION['pusyuu_push_channels'] = ['at' => time(), 'states' => $states];
    }
  }

  return $states;
}

/** 設定画面から戻ってきた直後など、キャッシュを信用してはいけない場面で捨てる。 */
function pusyuuPushForgetChannelStates(): void {
  unset($_SESSION['pusyuu_push_channels']);
}

/**
 * そのチャンネルを使ってよいか。deny のときだけ false。
 * 状態そのものが取れなかったときは null を返し、「分からない」を呼び出し側へ
 * そのまま渡す(false と同じ扱いにすると、APIが一時的に落ちただけで通知が
 * 黙って消えることになり、原因の分からない不具合になる)。
 */
function pusyuuPushChannelAllowed(string $userid, string $channel): ?bool {
  $states = pusyuuPushChannelStates($userid);

  if (empty($states)) {
    // 【null と false を混ぜないこと】ここは「状態が分からなかった」です。
    // false(使わない)と同じ扱いにすると、APIが一時的に落ちただけで
    // 通知が黙って消え、原因の分からない不具合になります。
    $allowed = null;
  } else {
    $allowed = ($states[$channel] ?? 'unset') !== 'deny';
  }

  return $allowed;
}

/**
 * チャンネルの使う/使わないを書き込む。
 *
 * 【なぜp-chatを経由するのか】ブラウザからこのAPIを直接叩かせない、という既存の
 * 方針(合言葉をページに出さないため)。購読の push_subscribe と同じ形で、JSは
 * 自分のサービスへPOSTし、サーバー側がサーバー間で通知APIを呼ぶ。
 * 書いた直後はキャッシュが古くなるので必ず捨てる。
 */
function pusyuuPushSetChannel(string $userid, string $channel, string $state): array {
  $res = pusyuuPushApi('channels_set', [
    'service' => 'p-chat', 'userid' => $userid, 'channel' => $channel, 'state' => $state,
  ]);
  pusyuuPushForgetChannelStates();
  return $res;
}

/**
 * 通知設定画面(pusyuu_push側が持っているページ)のURLを取り、押した人をそこへ飛ばす。
 *
 * 【なぜページ描画時にリンクを埋めないのか】このURLには短命(30分)の資格情報が
 * そのまま載っている。設定タブを描くたびに発行してHTMLへ埋めると、ページのソース・
 * ブラウザのキャッシュ・スクリーンショットにそれが写り込む。ボタンを押した瞬間に
 * 発行して即リダイレクトすれば、HTMLには一度も現れない。設定タブを開くだけで
 * APIへ1往復する無駄も無くなる。
 *
 * チャンネルの切り替えもRSSフィードの発行も、飛んだ先のページで完結する。
 * p-chat側が知っておくことは何も無い(PUSH_INTEGRATION_SPEC.md 0.1節)。
 *
 * ただし return_url だけは渡す必要がある。ブラウザの通知許可とプッシュの購読は
 * 「そのオリジンでしか行えない」ため(p-chatは p-chat.pusyuuwanko.com、設定画面は
 * pusyuuwanko.com で別オリジン)、設定画面で「使う」を押した人は一度ここへ
 * 戻ってこないと、実際の許可・購読ができない。その戻り先を教えている。
 */
function pusyuuPushSettingsUrl(string $userid): string {
  $res = pusyuuPushApi('settings_ticket', [
    'service'    => 'p-chat',
    'userid'     => $userid,
    'return_url' => pchatSelfOrigin() . '/?mode=' . rawurlencode($GLOBALS['mode'] ?? 'meikee'),
  ]);
  return !empty($res['ok']) ? (string)($res['url'] ?? '') : '';
}

/**
 * 自分自身の住所(末尾の / 無し)。台帳の p-chat の顔のうち、今の入口に合うもの。
 * 台帳が読めないときは空文字(これを使う設定画面への往復は、どのみち台帳経由の
 * プッシュ通知APIが要るので、一緒に止まります)。
 */
function pchatSelfOrigin(): string {
  return class_exists('PusyuuRegistryClient') ? (string)PusyuuRegistryClient::url('p-chat') : '';
}

/** 毎回APIを叩かないよう、取得できた公開鍵はセッションにキャッシュする(値は変わらないため)。 */
function pusyuuPushVapidPublicKey(): string {
  if (!empty($_SESSION['pusyuu_push_vapid_key'])) {
    $key = (string)$_SESSION['pusyuu_push_vapid_key'];
  } else {
    $res = pusyuuPushApi('vapid_public_key');
    $key = !empty($res['ok']) ? (string)($res['public_key'] ?? '') : '';

    // 取れなかったとき(空)は控えません。空を控えると、次からAPIを叩かなくなります。
    if ($key !== '') { $_SESSION['pusyuu_push_vapid_key'] = $key; }
  }

  return $key;
}

/**
 * push-sw.jsの`push`イベントから呼ばれ、通知として表示する内容をJSONで返す。
 * pusyuu_pushの`inbox`は「未読を一時的に預かる」だけで自動的には消えない
 * (`ack`で明示的に既読化する設計。PUSH_INTEGRATION_SPEC.md参照)ため、
 * ここで取得した内容は必ずその場でackする。これを怠ると、次にpushイベントが
 * 起きるたびに過去ぶんまで含めて何度でも同じ通知が再表示され続けてしまう
 * (実際に発生していた不具合: 既読にしたはずの通知が繰り返し出る)。
 */
function pusyuuPushInboxJson(string $userid): string {
  $res = pusyuuPushApi('inbox', ['service' => 'p-chat', 'userid' => $userid]);
  $items = !empty($res['ok']) ? ($res['items'] ?? []) : [];
  if (!empty($items)) {
    $ids = array_values(array_filter(array_map(static fn($it) => is_string($it['id'] ?? null) ? $it['id'] : null, $items)));
    pusyuuPushAck($userid, $ids);
  }
  return json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
}

// =====================================================================
// プシューチャット自身のログイン状態
// =====================================================================

function pchatAccountsToken(): string {
  return isset($_SESSION['pusyuu_accounts_token']) ? (string)$_SESSION['pusyuu_accounts_token'] : '';
}

/**
 * プシューメイキィのセッション確認結果を1リクエスト中1回だけ問い合わせてキャッシュする。
 * 「セッションが無効」と「プシューメイキィに接続できない」を区別することが重要:
 * 後者でトークンを消してしまうと、プシューメイキィ側の一時的な障害だけでログイン中の
 * 全ユーザーが強制ログアウトされてしまう(以前のバグ)。接続できない間は「今回は確認
 * できなかった」扱いにとどめ、トークン自体は保持して次回アクセス時に再確認する。
 */
function pchatResolveMeikeeSession(): array {
  static $checked = false;
  static $result = ['user' => null, 'unreachable' => false];

  // 2回目以降は $checked が true なので、この塊ごと素通りして控えを返します。
  if ($checked === false) {
    $checked = true;
    $token = pchatAccountsToken();
    $res = ($token === '') ? null : PchatAccount::sessionResult();

    if ($res === null) {
      // 未ログイン。user は null、unreachable も false のままです。

    } else if (!empty($res['ok'])) {
      $result['user'] = $res['user'];

    } else if (!empty($res['rejected'])) {
      // 【エラー名で見比べないこと】以前ここは "api_unreachable" 等の文字列を直接
      // 見比べていました。共有スクリプト側で失敗の段階を細かく分けたため、その名前は
      // もう返りません。名前で見比べる書き方に戻すと、次に段階が増えたときに静かに
      // 壊れ、メイキィが落ちただけで全員が強制ログアウトされる不具合が再発します。
      // 「メイキィ自身がトークンを拒否したのか」の判断は共有側の rejected に任せます。
      //
      // ここまで来て初めて「プシューメイキィ自身がセッション無効と答えた」と
      // 言えるので、このときだけトークンを削除します。
      unset($_SESSION['pusyuu_accounts_token']);

    } else {
      // 届かなかっただけ。トークンは残し、次回のアクセスでもう一度確かめます。
      $result['unreachable'] = true;
    }
  }

  return $result;
}

/** ログイン中ならユーザー情報(userid/username/name等)、そうでなければnull。 */
function pchatCurrentUser(): ?array {
  return pchatResolveMeikeeSession()['user'];
}

/** プシューメイキィに接続できずログイン状態を確認できなかった場合にtrue。 */
function pchatAccountsUnreachable(): bool {
  return pchatResolveMeikeeSession()['unreachable'];
}

function pchatLogout(): void {
  $token = pchatAccountsToken();
  if ($token !== '') { PchatAccount::revokeToken($token); }
  unset($_SESSION['pusyuu_accounts_token']);
  PchatSession::regenerateId();
}

// ---------------------------------------------------------------------
// 匿名actor(匿名タブ用、Cookieベースの継続識別)
//
// Cookieの値を知っている者=その匿名アイデンティティを名乗れる、という点は
// 通常のセッションCookieと同じ性質であり、ログイン管理された強固な身元では
// ないことに注意。
// ---------------------------------------------------------------------

function pchatAnonId(): string {
  // 形を厳しく見るのが要点です。Cookieの中身は利用者が書き換えられるので、
  // 16進32文字以外はすべて「無い」と同じ扱いにして発行し直します。
  $given = (string)($_COOKIE['pchat_anon_id'] ?? '');

  if ($given !== '' && preg_match('/^[a-f0-9]{32}$/', $given) === 1) {
    $id = $given;
  } else {
    $id = bin2hex(random_bytes(16));
    setcookie('pchat_anon_id', $id, [
      'expires'  => time() + 365 * 86400,
      'path'     => '/',
      'secure'   => true,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
    // 同じリクエストの中でもう一度呼ばれても同じidになるよう、その場で入れておきます。
    $_COOKIE['pchat_anon_id'] = $id;
  }

  return $id;
}

/** メイキータブのactor。未ログインならnull。 */
function pchatMeikeeActor(): ?array {
  $user = pchatCurrentUser();

  if ($user === null) {
    $actor = null;
  } else {
    $actor = [
      'userid' => $user['userid'], 'username' => $user['username'], 'name' => $user['name'],
      'avatar_url' => $user['avatar_url'] ?? null, 'anonymous' => false,
    ];
  }

  return $actor;
}

/** 匿名タブのactor。Meikeeにログイン中でも常にこちら(身元を漏らさない)。 */
function pchatAnonymousActor(): array {
  $actor = ['userid' => 'anon:' . pchatAnonId(), 'username' => null, 'name' => '匿名ユーザー', 'avatar_url' => null, 'anonymous' => true];

  return $actor;
}

/**
 * p-chat自身のリダイレクト先に使う「今のURL」。
 *
 * 共通クライアントのcurrentUrl()(SSOの印である pusyuu_code / pusyuu_silent /
 * pusyuu_state を落とす)に、p-chat固有の事情として p5s_url も落とす処理を重ねただけ。
 * p5s_urlはp-5secondへのアップロード中継から戻ってきたときに付く、p-chatだけの
 * パラメータなので、共通クラス側には入れない(入れると「全プロダクトで同一テキスト」
 * という前提が崩れる)。
 */
function pchatCurrentUrl(): string {
  $url = PchatAccount::currentUrl();
  $parts = explode('?', $url, 2);

  if (count($parts) < 2) {
    // クエリが無いので落とすものもありません。
    $result = $url;
  } else {
    $query = [];
    parse_str($parts[1], $query);
    // p5s_url は p-5second から戻ったときに付く p-chat だけの印です。
    // 共通クラス側には入れません(全プロダクトで同一テキスト、という前提が崩れます)。
    unset($query['p5s_url']);
    $qs = http_build_query($query);
    $result = $parts[0] . ($qs !== '' ? '?' . $qs : '');
  }

  return $result;
}

// ---------------------------------------------------------------------
// p-5second(一時ファイル置き場)の「サーバー間API」(p-5second/index.php?api=...。
// 旧api.phpはindex.phpへ統合済み)との連携。p-5secondのメインページ(ブラウザ完結の
// ゼロ知識暗号化UI)へは遷移させず、このファイル自身がファイルを受け取り、サーバー間で
// p-5secondのAPIへ中継する。APIはUIを持たない前提(フォームは呼び出し側=ここが用意する)。
// 詳しくは p-5second/P5S_INTEGRATION_SPEC.md 参照。accountsクライアントと同じ
// 「別ファイルに切り出さずコピーする」方式。
// ---------------------------------------------------------------------

// 【宛先をここに書かないこと】p-5second の繋ぐ先と名乗るホスト名は、台帳
// (pusyuu_registry)のID p-5second から受け取ります。以前はここで
// http://127.0.0.1/index.php と p-5second.pusyuuwanko.com を固定していました。
const PCHAT_P5S_REGISTRY_ID = 'p-5second';

/** accountsと同じ鍵ファイルからHMACで導出する合言葉。 */
function pchatP5sApiSecret(): string {
  static $resolved = false;
  static $secret = '';

  // 2回目以降は $resolved が true なので、この塊ごと素通りして答えを使い回します。
  if ($resolved === false) {
    $resolved = true;
    // 【dirname() を使わないこと】dirname('.') は '.' のままで上へ登れません。
    $dir = '.';
    $done = false;

    // 上限8段は、見つからないときに延々と登り続けないための歯止めです。
    for ($i = 0; $i <= 8 && $done === false; $i++) {
      $candidate = $dir . '/.pusyuuHiddenFiles/pips_account_key.php';

      if (file_exists($candidate)) {
        $encoded = require $candidate;
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($key !== false && strlen($key) === 32) {
          $secret = hash_hmac('sha256', 'p5s_api', $key);
        }
        $done = true;
      } else {
        $dir = $dir . '/..';
      }
    }
  }

  return $secret;
}

/** multipart/form-dataのボディを手組みする(curlが使えない環境向けのフォールバック用)。 */
function pchatBuildMultipart(array $fields, string $fileFieldName, string $tmpPath, string $fileName, string $mime): array {
  $boundary = '----PchatBoundary' . bin2hex(random_bytes(16));
  $body = '';
  foreach ($fields as $k => $v) {
    $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
  }
  $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$fileFieldName}\"; filename=\"" . str_replace('"', '', $fileName) . "\"\r\n";
  $body .= "Content-Type: {$mime}\r\n\r\n";
  $body .= (string)file_get_contents($tmpPath);
  $body .= "\r\n--{$boundary}--\r\n";

  return ['boundary' => $boundary, 'body' => $body];
}

/**
 * p-5secondのAPIへPOSTする汎用ヘルパー。$filePathを渡すとmultipartでファイルも
 * 一緒に送る(action=upload/chunk用)。渡さなければ通常のフォームPOST
 * (action=limits/finalize/delete用)。ルーティングはp-5second側がindex.php?api=<action>の
 * クエリパラメータ1本に統一しているため、$fields['action']をそのままURLのクエリへ
 * 載せ替える(POSTボディ側には残さない。api_secret等の他フィールドと混ざらないように)。
 */
function pchatP5sApiCall(array $fields, ?string $filePath = null, ?string $fileFieldName = null, ?string $fileName = null, ?string $mime = null): array {
  $action = (string)($fields['action'] ?? '');
  unset($fields['action']);
  $fields['api_secret'] = pchatP5sApiSecret();
  $path = '/index.php?api=' . rawurlencode($action);
  $route = class_exists('PusyuuRegistryClient') ? PusyuuRegistryClient::route(PCHAT_P5S_REGISTRY_ID, $path) : null;
  // curl は証明書を確かめるホスト名を指定できないので、「内側の経路へ https で繋ぎ、
  // 別のホスト名を名乗る」ときは使えません(証明書の確認で必ず落ちます)。その時だけ
  // PHP本体の送り方(下)に任せます。http で繋ぐ今の台帳の値なら curl を使います。
  $curlUsable = $route !== null && function_exists('curl_init') && ($route['host'] === null || strncmp($route['url'], 'http://', 7) === 0);
  $raw = null;

  if ($curlUsable) {
    $ch = curl_init($route['url']);
    $postFields = $fields;
    if ($filePath !== null) {
      $postFields[(string)$fileFieldName] = new CURLFile($filePath, (string)$mime, (string)$fileName);
    }
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ($route['host'] === null) ? [] : ['Host: ' . $route['host']]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $raw = curl_exec($ch);
    curl_close($ch);
  }

  // curlで届かなかった(使えなかった)ときだけ、PHP本体だけで組み立てて送り直します。
  if ($route !== null && ($raw === false || $raw === null)) {
    if ($filePath !== null) {
      $mp = pchatBuildMultipart($fields, (string)$fileFieldName, $filePath, (string)$fileName, (string)$mime);
      $response = PusyuuRegistryClient::call(PCHAT_P5S_REGISTRY_ID, 'POST', $path, ["Content-Type: multipart/form-data; boundary={$mp['boundary']}"], $mp['body'], 60);
    } else {
      $response = PusyuuRegistryClient::call(PCHAT_P5S_REGISTRY_ID, 'POST', $path, ['Content-Type: application/x-www-form-urlencoded'], http_build_query($fields), 60);
    }
    $raw = ($response['problem'] === '') ? $response['body'] : false;
  }

  $decoded = ($raw === false || $raw === null || $raw === '') ? null : json_decode($raw, true);

  if ($raw === false || $raw === null || $raw === '') {
    error_log('[p-chat] p-5secondのAPIへの接続に失敗しました。');
    $result = ['ok' => false, 'error' => 'api_unreachable'];
  } else if (!is_array($decoded)) {
    $result = ['ok' => false, 'error' => 'api_broken_response'];
  } else {
    $result = $decoded;
  }

  return $result;
}

/** アップロード済み一時ファイル($tmpPath)をp-5secondのAPIへ単発で中継する(小さいファイル向け)。 */
function pchatP5sUpload(string $tmpPath, string $fileName, string $mime, int $ttl): array {
  return pchatP5sApiCall(['action' => 'upload', 'ttl' => (string)$ttl], $tmpPath, 'file', $fileName, $mime);
}

/** このサーバーとp-5second、両方のPHP ini上限の小さい方を、チャンクサイズ計算用に返す。 */
function pchatP5sLimits(): array {
  return pchatP5sApiCall(['action' => 'limits']);
}

/** チャンク分割アップロードの1チャンクを中継する。 */
function pchatP5sChunk(string $uploadId, int $index, string $chunkTmpPath): array {
  return pchatP5sApiCall(
    ['action' => 'chunk', 'uploadId' => $uploadId, 'index' => (string)$index],
    $chunkTmpPath, 'chunk', 'chunk', 'application/octet-stream'
  );
}

/** チャンク分割アップロードの結合確定を中継する。 */
function pchatP5sFinalize(string $uploadId, int $totalChunks, string $fileName, string $mime, int $ttl): array {
  return pchatP5sApiCall([
    'action' => 'finalize', 'uploadId' => $uploadId, 'totalChunks' => (string)$totalChunks,
    'name' => $fileName, 'mime' => $mime, 'ttl' => (string)$ttl,
  ]);
}

/** "8M" "512K" "1G" のようなphp.iniのサイズ表記をバイト数に変換する(p-5second/index.phpと同じ)。 */
function pchatIniBytes(string $val): int {
  $val = trim($val);

  if ($val === '') {
    $bytes = 0;
  } else {
    $unit = strtolower(substr($val, -1));
    $num = (int)$val;
    $bytes = match ($unit) {
      'g' => $num * 1024 * 1024 * 1024,
      'm' => $num * 1024 * 1024,
      'k' => $num * 1024,
      default => (int)$val,
    };
  }

  return $bytes;
}

/** p-5secondから戻ってきたp5s_urlを検証する。https始まりの妥当なURLのみ受け付ける。 */
function pchatIncomingFileShareUrl(): ?string {
  $raw = (string)($_GET['p5s_url'] ?? '');

  // 【httpsで始まることの確認を外さないこと】ここはURLの文字列がそのまま
  // 画面のリンクになります。javascript: のようなものを通すと踏み台になります。
  if ($raw === '' || strpos($raw, 'https://') !== 0) {
    $url = null;
  } else {
    $url = (filter_var($raw, FILTER_VALIDATE_URL) !== false) ? $raw : null;
  }

  return $url;
}

// ---------------------------------------------------------------------
// CSRFトークン(p-reversiの prevarsi_csrf と同じ考え方)
// ---------------------------------------------------------------------

function pchatCsrfToken(): string {
  if (empty($_SESSION['pchat_csrf'])) {
    $_SESSION['pchat_csrf'] = bin2hex(random_bytes(32));
  }

  return (string)$_SESSION['pchat_csrf'];
}

function pchatCsrfIsValid(): bool {
  $sent = $_POST['csrf'] ?? null;
  // is_string を先に見るのは、配列で送られてきたときに hash_equals が
  // TypeError で落ちるのを防ぐためです。
  $valid = is_string($sent)
    && !empty($_SESSION['pchat_csrf'])
    && hash_equals((string)$_SESSION['pchat_csrf'], $sent);

  return $valid;
}

// ---------------------------------------------------------------------
// フラッシュメッセージ(pipsの$_SESSION["post_complete_msg"]と同じ考え方。
// POST処理の結果を、リダイレクト後のGET描画で1回だけ表示する)
// ---------------------------------------------------------------------

function pchatFlash(string $msg): void {
  if (!isset($_SESSION['pchat_flash']) || !is_array($_SESSION['pchat_flash'])) {
    $_SESSION['pchat_flash'] = [];
  }
  $_SESSION['pchat_flash'][] = $msg;
}

function pchatTakeFlashes(): array {
  $msgs = $_SESSION['pchat_flash'] ?? [];
  $_SESSION['pchat_flash'] = []; // 取り出したら空にする(次の描画で出続けないように)

  return is_array($msgs) ? $msgs : [];
}

// ---------------------------------------------------------------------
// 簡易表示モード(pipsの$isLiteと同じ考え方: セッションとURLパラメータの
// 二重持ち。軽量な端末・古いブラウザではCookie自体の扱いも怪しい場合が
// あるため、URLに明示的に付けるlite=1/0でも切り替えられるようにする)。
// 有効な間はJSファイルを一切読み込まず、JS前提の機能は代わりに
// 「簡易表示モードでは利用できません」という文言で明示する。
// ---------------------------------------------------------------------

function pchatIsLite(): bool {
  // URLに印があれば、それを新しい好みとしてセッションへ書き写します。
  // 印が無ければ、前に決めた好みがそのまま続きます。
  if (isset($_GET['lite'])) {
    $_SESSION['pchat_lite'] = ($_GET['lite'] === '1');
  }

  return !empty($_SESSION['pchat_lite']);
}

/** 現在のGETパラメータを保ったまま、liteだけを反転させたURLを組み立てる。 */
function pchatToggleLiteUrl(bool $currentlyLite): string {
  $query = $_GET;
  $query['lite'] = $currentlyLite ? '0' : '1';

  return './?' . http_build_query($query);
}

// =====================================================================
// メッセージ本文の暗号化(AES-256-GCM)。
//
// p-5secondはクライアント側で暗号化したものをサーバーが復号するだけ
// (ゼロ知識モデル)だが、チャットは非公開DMという性質上サーバー保管の
// 静的鍵で保存時に暗号化・読み出し時に復号する方式にしている。
// ブロブ形式は p-5second と揃えて [12byte IV][暗号文][16byte タグ]。
// =====================================================================

function getChatKeyPath(): string {
  $envPath = getenv('PCHAT_KEY_PATH');

  if (is_string($envPath) && $envPath !== '') {
    $path = $envPath;
  } else {
    $path = './../.pusyuuHiddenFiles/p-chat_key.php';
  }

  return $path;
}

/**
 * 鍵が無ければ32byteのAES鍵を生成して保存し、あれば読み込んで返す(生バイト列)。
 * .pusyuuHiddenFiles/ 直下は既存の.htaccessでHTTP直接アクセスが禁止済みのため、
 * ここでは.htaccessの設置は行わない。
 */
function ensureChatKey(): ?string {
  static $cached = null;
  static $checked = false;

  // 2回目以降は $checked が true なので、この塊ごと素通りして答えを使い回します。
  if ($checked === false) {
    $checked = true;
    $path = getChatKeyPath();
    $dir = dirname($path);
    // 鍵ファイルを用意できたか。無ければこの後の読み込みへ進みません。
    $ready = true;

    // mkdir が失敗しても、他のリクエストが同時に作っていれば is_dir は真になります。
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
      error_log('[p-chat] 鍵の保管ディレクトリを作成できません: ' . $dir);
      $ready = false;
    }

    if ($ready && !is_file($path)) {
      $raw = random_bytes(32);
      $php = "<?php\nreturn '" . base64_encode($raw) . "';\n";

      if (@file_put_contents($path, $php) === false) {
        // 【保存できなかったら鍵を使わないこと】使ってしまうと、次のリクエストで
        // 別の鍵が生成され、今書いたメッセージが二度と読めなくなります。
        error_log("[p-chat] 鍵ファイルを保存できません: {$path}");
        $ready = false;
      } else {
        @chmod($path, 0600);
      }
    }

    if ($ready === false) {
      $cached = null;
    } else {
      // 生成した直後もここから読み直します。書けた物をそのまま使うのではなく
      // 実際に読めることまで確かめるためです。
      $encoded = @include $path;
      $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;

      if (!is_string($encoded)) {
        error_log("[p-chat] 鍵ファイルを読み込めません: {$path}");
        $cached = null;
      } else if ($decoded === false || strlen($decoded) !== 32) {
        error_log("[p-chat] 鍵ファイルの内容が不正です: {$path}");
        $cached = null;
      } else {
        $cached = $decoded;
      }
    }
  }

  return $cached;
}

/**
 * 【重要】このファイルには互換性の無いAES-256-GCMの保存形式が2つあります。統合しないこと。
 *
 *   1. aesGcmEncrypt()/aesGcmDecrypt()  … メッセージ用。生バイト列で IV + 暗号文 + TAG。
 *   2. pchatBlobEncrypt()/pchatBlobDecrypt() … rooms.jsonl用。base64( IV + TAG + 暗号文 )。
 *
 * タグの位置とbase64化の有無が違うため、片方の関数で書いたデータをもう片方では
 * 復号できません。「同じAES-GCMだから1つにまとめよう」とすると、既存のメッセージか
 * rooms.jsonlのどちらかが丸ごと読めなくなります。どちらの形式も、既にディスク上に
 * 積み上がったデータがあるため変更不可です。
 */
function aesGcmEncrypt(string $plain, string $rawKey): string {
  $iv = random_bytes(12);
  $tag = '';
  $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag);

  // 【この並び順(IV + 暗号文 + TAG、base64化なし)を変えないこと】
  // 下の pchatBlobEncrypt() とは別形式です。混ぜると既存のメッセージが読めなくなります。
  return $iv . $ciphertext . $tag;
}

function aesGcmDecrypt(string $blob, string $rawKey): ?string {
  // IVとTAGだけでも28バイト要ります。それ未満はこの形式ではありません。
  if (strlen($blob) < 28) {
    $plain = null;
  } else {
    $iv = substr($blob, 0, 12);
    $rest = substr($blob, 12);
    $tag = substr($rest, -16);
    $ciphertext = substr($rest, 0, -16);
    $opened = openssl_decrypt($ciphertext, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag);
    $plain = ($opened === false) ? null : $opened;
  }

  return $plain;
}

/**
 * rooms.jsonl用の暗号化(上のaesGcmEncrypt()とは別形式。冒頭の【重要】参照)。
 * 保存形式は base64( IV 12バイト + TAG 16バイト + 暗号文 ) で、変更不可です。
 *
 * 【なぜ共有スクリプトを使わず自前で持つか】以前は
 * main/pusyuusystem/scripts/php_scripts/pusyuu_storage_crypto.php の
 * pusyuuAesGcmDecrypt()/pusyuuWriteEncryptedJsonlBlob() を呼んでいましたが、共有する
 * 意味が実は無い形でした: この形式で書いたrooms.jsonlを読むのは書いた本人
 * (=このp-chat、鍵はp-chat_key.php)だけで、他プロダクトは自分専用の鍵で自分の
 * ファイルを読み書きしています。互いのファイルを読む場面が無い一方、共有していると
 * 共有スクリプトが無い環境でp-chatごと起動不能になっていました。
 */
function pchatBlobEncrypt(string $plaintext, string $rawKey): ?string {
  $iv = random_bytes(12);
  $tag = '';
  $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag);

  if ($cipher === false) {
    $encoded = null;
  } else {
    // 【この並び順(base64( IV + TAG + 暗号文 ))を変えないこと】
    // 上の aesGcmEncrypt() とはタグの位置もbase64の有無も違います。
    // 「同じAES-GCMだから1つに」とすると rooms.jsonl が丸ごと読めなくなります。
    $encoded = base64_encode($iv . $tag . $cipher);
  }

  return $encoded;
}

function pchatBlobDecrypt(string $encoded, string $rawKey): ?string {
  $raw = base64_decode($encoded, true);

  // 28バイト未満は、IVとTAGだけでも足りない = そもそもこの形式ではありません。
  if ($raw === false || strlen($raw) < 28) {
    $plain = null;
  } else {
    $iv     = substr($raw, 0, 12);
    $tag    = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $opened = openssl_decrypt($cipher, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag);
    $plain = ($opened === false) ? null : $opened;
  }

  return $plain;
}

/** 行の配列をJSONLとして連結し、まとめて暗号化して保存する(tmp+rename方式)。 */
function pchatWriteEncryptedJsonlBlob(string $file, array $rows, string $rawKey): bool {
  $lines = [];
  foreach ($rows as $row) {
    $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE);
  }

  $encrypted = pchatBlobEncrypt(implode("\n", $lines), $rawKey);
  $dir = dirname($file);

  if ($encrypted === null) {
    $ok = false;
  } else if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    // mkdir が失敗しても、他のリクエストが同時に作っていれば is_dir は真になります。
    $ok = false;
  } else {
    // いったん別名で書いてから rename します。途中で落ちても、読み手が
    // 半分だけ書かれたファイルを掴むことがありません(rename は不可分)。
    $tmp = $dir . '/.' . basename($file) . '.' . bin2hex(random_bytes(6)) . '.tmp';

    if (@file_put_contents($tmp, $encrypted) === false) {
      $ok = false;
    } else if (!@rename($tmp, $file)) {
      @unlink($tmp);
      $ok = false;
    } else {
      $ok = true;
    }
  }

  return $ok;
}

// =====================================================================
// room(会話)の永続化(自分自身のストレージ。storage/rooms.jsonl)
//
// p-reversiのstorage/matches.jsonlと同じ transact/lock パターン。
// =====================================================================

// storageディレクトリは推測不能なランダム名にして.htaccessに依存せず隠蔽する
// (中身の rooms.jsonl 自体もAES-256-GCMで暗号化して保存する。下記参照)。
const PCHAT_STORAGE_DIR = './assets/90922d7ba602445888ff532284bfaec9';
const ROOMS_FILE = PCHAT_STORAGE_DIR . '/rooms.jsonl';
const PCHAT_PENDING_TTL = 7 * 86400; // 招待コードを承諾されないまま放置した場合の有効期限
const PCHAT_SECRET_MESSAGE_TTL = 86400; // シークレットモードのメッセージ保持期間
const PCHAT_REACTION_EMOJI = ['👍', '❤️', '😂', '😮', '😢']; // 選べる絵文字リアクション

function pchatReadJsonl(string $file): array {
  $raw = file_exists($file) ? file_get_contents($file) : false;
  $rows = [];

  // 無い・読めない・空、のどれでも「まだ1件も無い」と同じ扱いにします。
  if ($raw !== false && trim($raw) !== '') {
    foreach (preg_split('/\r?\n/', $raw) as $line) {
      $line = trim($line);
      if ($line !== '') {
        $row = json_decode($line, true);
        if (is_array($row)) { $rows[] = $row; }
      }
    }
  }

  return $rows;
}

function pchatWriteJsonl(string $file, array $rows): bool {
  $lines = [];
  foreach ($rows as $row) { $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE); }

  $dir = dirname($file);
  // mkdir が失敗しても、他のリクエストが同時に作っていれば is_dir は真になります。
  $noDir = (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir));
  $tmp = $dir . '/.' . uniqid('r_', true) . '.tmp';
  $fp = $noDir ? false : fopen($tmp, 'w');

  if ($fp === false) {
    $ok = false;
  } else {
    flock($fp, LOCK_EX);
    fwrite($fp, implode("\n", $lines));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    // いったん別名で書いてから rename します。途中で落ちても、読み手が
    // 半分だけ書かれたファイルを掴むことがありません(rename は不可分)。
    if (!rename($tmp, $file)) {
      @unlink($tmp);
      $ok = false;
    } else {
      $ok = true;
    }
  }

  return $ok;
}

/**
 * rooms.jsonlをAES-256-GCMで暗号化して読み書きする(p-chat_key.phpをmessages/と共用)。
 * 復号に失敗した場合(暗号化前の旧データ、または鍵未取得)は、旧形式の平文JSONLに
 * フォールバックして読み込む。書き込みは鍵が取得できる限り常に暗号化して保存するため、
 * 一度でも書き込みが発生したroomから順次、自然に暗号化データへ移行する。
 */
function pchatReadRooms(): array {
  $key = ensureChatKey();
  $raw = ($key === null) ? '' : (file_exists(ROOMS_FILE) ? trim((string)file_get_contents(ROOMS_FILE)) : '');
  $plain = ($key === null || $raw === '') ? null : pchatBlobDecrypt($raw, $key);

  if ($key !== null && $raw === '') {
    // 鍵はあるがファイルが空。まだ1件も無い、で確定です。
    $rows = [];
  } else if ($plain !== null) {
    $rows = [];
    foreach (preg_split('/\r?\n/', $plain) as $line) {
      $line = trim($line);
      if ($line !== '') {
        $row = json_decode($line, true);
        if (is_array($row)) { $rows[] = $row; }
      }
    }
  } else {
    // 鍵が無い、または復号できなかった場合。暗号化前の旧データの可能性があるので、
    // 平文として読み直します。ここで諦めると、移行前の会話が見えなくなります。
    $rows = pchatReadJsonl(ROOMS_FILE);
  }

  return $rows;
}

function pchatWriteRooms(array $rows): bool {
  $key = ensureChatKey();

  if ($key === null) {
    $ok = pchatWriteJsonl(ROOMS_FILE, $rows);
  } else {
    // 鍵が取れる限り常に暗号化して保存します。一度でも書き込みが起きた時点で
    // このファイルは自然に暗号化データへ移行します。
    $ok = pchatWriteEncryptedJsonlBlob(ROOMS_FILE, $rows, $key);
  }

  return $ok;
}

/**
 * ロックを取ってから読み込み→変更→(必要なら)書き込みを行う。
 * $mutator は array&$rows を受け取り ['save'=>bool, 'result'=>mixed] を返す。
 */
function pchatTransactRooms(callable $mutator): array {
  $lockFile = ROOMS_FILE . '.lock';
  $dir = dirname($lockFile);
  // mkdir が失敗しても、他のリクエストが同時に作っていれば is_dir は真になります。
  $noDir = (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir));
  if ($noDir) {
    error_log('[p-chat] pchatTransactRooms: ロック用ディレクトリを作成できませんでした: ' . $dir);
  }

  $lockHandle = $noDir ? false : fopen($lockFile, 'c');
  if (!$noDir && $lockHandle === false) {
    error_log('[p-chat] pchatTransactRooms: ロックファイルを開けませんでした: ' . $lockFile);
  }

  // flock()はネットワーク越しのファイルシステム(NFS等)では機能しないことがある
  // (PHPマニュアルにも明記された既知の制限)。ここが機能していないと、既読
  // (last_viewed_seq)等の更新が他リクエストの書き込みで上書きされて消える
  // (メッセージ本体は別ファイルへの追記のみのため影響を受けない)不具合につながり
  // 得るため、flock()自体が失敗を返すケースだけは確実に検知してログへ残す
  // (ロックが取れたと言いながら実際には効いていないケースまでは検知できない)。
  $locked = ($lockHandle !== false) && flock($lockHandle, LOCK_EX);
  if ($lockHandle !== false && $locked === false) {
    error_log('[p-chat] pchatTransactRooms: flock()に失敗しました(ファイルシステムがロックに対応していない可能性があります)。file=' . $lockFile);
    fclose($lockHandle);
  }

  if ($locked === false) {
    // 鍵をかけられないまま読み書きすると、同時に来た2つの更新が互いを消します。
    $outcomeArr = ['status' => null, 'result' => null];
  } else {
    $rows = pchatReadRooms();
    $outcome = $mutator($rows);
    $status = false;

    // status は3値です。false=保存不要、true=保存できた、null=保存に失敗した。
    if (!empty($outcome['save'])) {
      $status = pchatWriteRooms($rows) ? true : null;
      if ($status === null) {
        error_log('[p-chat] pchatTransactRooms: rooms.jsonlの書き込みに失敗しました。');
      }
    }

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    $outcomeArr = ['status' => $status, 'result' => $outcome['result'] ?? null];
  }

  return $outcomeArr;
}

function pchatFindRoomIndex(array $rooms, string $roomId): ?int {
  $found = null;

  foreach ($rooms as $i => $r) {
    if (($r['room_id'] ?? '') === $roomId) {
      $found = $i;
      break;
    }
  }

  return $found;
}

function pchatIsValidRoomId(string $id): bool {
  return preg_match('/^[a-f0-9]{32}$/', $id) === 1;
}

/** $myIdがそのroomの正式なmembersか、直接招待の宛先(invited_userid)であればtrue。 */
function pchatRoomAccessibleBy(array $room, string $myId): bool {
  // 正式なメンバーか、まだ承諾していない直接招待の宛先なら通します。
  $allowed = in_array($myId, $room['members'] ?? [], true)
    || (($room['invited_userid'] ?? null) === $myId);

  return $allowed;
}

// =====================================================================
// p-drive連携: 「相手のアカウントがまだ存在するか」の確認。
//
// 以前はp-drive上に各参加者ごとの目印(マーカー)を書き込み、その存在を
// もって相手の生死を判定する方式を検討したが、これは結局p-meikieeが既に
// 持っている正解データ(account.jsonl)への間接的な代理チェックに過ぎず、
// マーカー自体がアカウント削除と無関係な理由で本体の状態からズレて存在し
// 得るという弱点があった。そのためp-meikieeのアカウントAPI`account_exists`
// (ACCOUNTS_INTEGRATION_SPEC.md参照)を直接呼ぶ方式に一本化した。p-drive上に
// 何かを書き込む必要が無いため、書き込み失敗時のロールバックも不要になる
// (P_DRIVE_INTEGRATION_SPEC.md 7節参照)。
//
// 匿名タブのユーザーにはstorage_idが無く、アカウント削除イベントも発生
// しないため確認の対象外(=部屋に匿名が1人でも絡む場合はこの仕組み自体を
// 使わず、従来通りローカルのみで完結させる)。
// =====================================================================

function pchatIsRealAccountId(string $userid): bool {
  return $userid !== '' && !str_starts_with($userid, 'anon:');
}

/** 指定したstorage_idが実在するアカウントかどうかをp-meikieeへ直接確認する。 */
function pchatAccountExists(string $storageId): bool {
  // 【戻り値はboolです。配列として触らないこと】
  // 以前ここは $res['ok'] / $res['exists'] と配列で受けていました。顔の側がboolを
  // 返すようになったあともその形が残っていて、boolへの添字アクセスは常にnullなので
  // **この関数がいつでもfalseを返す**状態になっていました。
  // その結果 pchatRoomForMember() が「相手のアカウントが消えた」と誤判定し、
  // 生きている部屋とメッセージを削除していました(匿名の部屋はこの判定を通らないため
  // 無事で、「匿名だけ読める」という形で表に出ました)。
  //
  // なお、確認できなかったときにfalseを返す設計自体が危険です。メイキィが一瞬
  // 落ちただけで全員の部屋が消えます。顔の側は確認できない場合trueを返します。
  return PchatAccount::accountExists($storageId);
}

/**
 * 部屋本体+付随データ(メッセージ・リアクション)をまとめて片付ける内部
 * ヘルパー。pchatDeleteRoom()(本人の明示操作)と、pchatRoomForMember()の
 * 孤立検知(相手のアカウントが既に削除されていた場合の自己修復)の両方から
 * 呼ぶため、アクセス確認はここでは行わない(呼び出し元の責務)。
 */
function pchatDeleteRoomInternal(string $roomId): bool {
  $tx = pchatTransactRooms(static function (array &$rows) use ($roomId): array {
    $idx = pchatFindRoomIndex($rows, $roomId);

    if ($idx === null) {
      $outcome = ['save' => false, 'result' => false];
    } else {
      array_splice($rows, $idx, 1);
      $outcome = ['save' => true, 'result' => true];
    }

    return $outcome;
  });

  if ($tx['status'] === true) {
    // 部屋の行を消せたときだけメッセージ本体も消します。順番が逆だと、
    // 行だけ残ってメッセージが無い部屋ができます。
    pchatDeleteMessagesFile($roomId);
    $deleted = true;
  } else {
    $deleted = false;
  }

  return $deleted;
}

/**
 * room_idが存在し、$myIdがmembersまたは招待の宛先である場合だけそのroomを返す。
 * それ以外はnull。実アカウント同士のactiveな部屋については、相手のアカウントが
 * まだ存在するかもここで確認し、既に削除されていれば自分のローカルデータも
 * その場で片付けてnullを返す(P_DRIVE_INTEGRATION_SPEC.md 7節の設計方針)。
 */
function pchatRoomForMember(string $roomId, string $myId): ?array {
  $rooms = pchatIsValidRoomId($roomId) ? pchatReadRooms() : [];
  $idx = pchatFindRoomIndex($rooms, $roomId);
  $room = ($idx === null) ? null : $rooms[$idx];
  $mine = ($room !== null) && pchatRoomAccessibleBy($room, $myId);

  // 相手のアカウントが既に消えているかどうか。匿名が絡む部屋は対象外です
  // (匿名にはstorage_idが無く、アカウント削除も起きないため)。
  $peerGone = false;
  if ($mine && ($room['status'] ?? '') === 'active' && pchatIsRealAccountId($myId)) {
    $peer = pchatOpponentInfo($room, $myId);
    $peerId = (string)($peer['userid'] ?? '');
    $peerGone = ($peerId !== '') && pchatIsRealAccountId($peerId) && !pchatAccountExists($peerId);
  }

  if (!$mine) {
    // 部屋が無い、またはこの人のものではない。どちらも同じ null で答えます
    // (「有るが見せない」と「無い」を区別させないため)。
    $result = null;
  } else if ($peerGone) {
    pchatDeleteRoomInternal($roomId);
    $result = null;
  } else {
    $result = $room;
  }

  return $result;
}

/** 承諾されないまま古くなった招待(pending_handshake)を掃除する。 */
function pchatSweepPendingRooms(): void {
  pchatTransactRooms(static function (array &$rows): array {
    $now = time();
    $before = count($rows);

    $rows = array_values(array_filter($rows, static function ($r) use ($now): bool {
      // 承諾待ち以外は対象外(そのまま残す)。
      if (($r['status'] ?? '') !== 'pending_handshake') {
        $keep = true;
      } else {
        $keep = (($now - (int)($r['created'] ?? 0)) < PCHAT_PENDING_TTL);
      }

      return $keep;
    }));

    return ['save' => count($rows) !== $before, 'result' => null];
  });
}

/** 監査ログ(pchatSweepStaleRooms()専用)への追記先。恒久的な記録のため削除・無効化スイッチは持たない。 */
function pchatOrphanSweepLogFile(): string {
  return PCHAT_STORAGE_DIR . '/stale_room_sweep_log.jsonl';
}

function pchatOrphanSweepLog(array $record): void {
  $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  // 組み立てられなかったときは何も書きません(壊れた行を残す方が困るため)。
  if ($line !== false) {
    @file_put_contents(pchatOrphanSweepLogFile(), $line . "\n", FILE_APPEND | LOCK_EX);
  }
}

/**
 * pchatRoomForMember()の遅延自己修復(誰かがその部屋を開いたときだけ働く)を
 * 補う能動版。全roomを巡回し、実アカウント同士のactiveな部屋について双方の
 * アカウントがまだ実在するか(`account_exists`)を確認する。片方でも既に
 * 削除されていれば、誰も開かなくてもここで削除する。判定基準は
 * pchatRoomForMember()と同一。
 *
 * 通常の呼び出しはpchatRunDailyMaintenanceIfNeeded()経由、1日1回のみを想定する
 * (全room数ぶんp-meikieeへの問い合わせが発生するコストがあるため)。
 * 管理画面(oppai)からは ?api=admin_sweep_stale_rooms 経由で手動でも呼べる。
 *
 * $dryRun を true にすると、判断だけ行って**何も削除せず**結果を返す。
 * 管理画面から「まず確認だけ」を選べるようにするため。過去に、確認できないことを
 * 「相手が居ない」と誤判定して生きている部屋を消した事故があったので、
 * 消す前に何が対象になるのかを見られる道を必ず残しておくこと。
 */
function pchatSweepStaleRooms(bool $dryRun = false): array {
  $removed = [];      // 実際に削除した(dryRunなら「削除する予定の」)部屋
  $unconfirmed = [];  // メイキィへ確認できず、判断を見送った部屋
  $checked = 0;       // 判定の対象になった部屋の数

  foreach (pchatReadRooms() as $room) {
    $roomId = (string)($room['room_id'] ?? '');
    if ($roomId === '' || ($room['status'] ?? '') !== 'active') { continue; }

    $members = $room['members'] ?? [];
    if (count($members) < 2) { continue; }
    $a = (string)$members[0];
    $b = (string)$members[1];
    if (!pchatIsRealAccountId($a) || !pchatIsRealAccountId($b)) { continue; } // 匿名絡みは対象外

    $checked++;

    // 【「居ない」と確かめられたときだけ消すこと】
    // 確認できなかった場合、pchatAccountExists() は安全側に倒して「居る」を返します。
    // その答えをそのまま使うと、メイキィへ恒久的に届かない環境では毎回「全員居る」と
    // 判断し、掃除が黙って止まったまま誰も気づけません。ここでは確かめられたか
    // どうかまで見て、確かめられなかった回はその旨を記録に残します。
    $aRes = PchatAccount::accountExistsChecked($a);
    $bRes = PchatAccount::accountExistsChecked($b);
    if (!$aRes['confirmed'] || !$bRes['confirmed']) {
      $unconfirmed[] = $roomId;
      pchatOrphanSweepLog([
        'ts' => date('c'), 'room_id' => $roomId, 'members' => [$a, $b],
        'result' => '判断できず(メイキィへ確認できなかったため、この部屋はそのまま残しました)',
        'dry_run' => $dryRun,
      ]);
      continue;
    }
    $aOk = $aRes['exists'];
    $bOk = $bRes['exists'];
    if ($aOk && $bOk) { continue; }

    pchatOrphanSweepLog([
      'ts' => date('c'), 'room_id' => $roomId, 'members' => [$a, $b],
      'a_exists' => $aOk, 'b_exists' => $bOk, 'dry_run' => $dryRun,
    ]);

    if ($dryRun) {
      $removed[] = $roomId;   // 確認だけ。削除はしない
      continue;
    }
    if (pchatDeleteRoomInternal($roomId)) {
      $removed[] = $roomId;
    }
  }
  return [
    'ok'          => true,
    'dry_run'     => $dryRun,
    'checked'     => $checked,      // 判定の対象になった部屋の数(匿名絡み・非activeは除く)
    'removed'     => $removed,      // 削除した(dryRunなら削除される予定の)部屋
    'unconfirmed' => $unconfirmed,  // メイキィへ確認できず見送った部屋
  ];
}

/** pchatRunDailyMaintenanceIfNeeded()が最終実行日を記録する先。JSON1件だけの軽量なファイル。 */
function pchatDailyMaintenanceStateFile(): string {
  return PCHAT_STORAGE_DIR . '/daily_maintenance_state.json';
}

/**
 * pchatSweepStaleRooms()を専用cronを組まずに1日1回だけ自動的に回す
 * (p-meikieeのpmeikieeRunDailyMaintenanceIfNeeded()と同じ発想・同じ実装
 * パターン)。「最終実行日」をJSON1件に記録し、通常のリクエストのたびに
 * 今日の日付と比較、変わっていた場合だけその場で実行する。日付が変わった
 * 瞬間に複数リクエストが同時に来ても二重実行しないよう、実行前に排他ロックを
 * 取り、ロック取得後にもう一度日付を確認する。ロックが取れなければ
 * (既に他のリクエストが実行中)待たずに諦める。
 */
function pchatRunDailyMaintenanceIfNeeded(): void {
  $file = pchatDailyMaintenanceStateFile();
  $today = date('Y-m-d');
  $dir = dirname($file);

  // 1回目の確認。ほとんどの要求はここで素通りします(ロックも取りません)。
  $raw = @file_get_contents($file);
  $state = $raw !== false ? json_decode($raw, true) : null;
  $doneToday = is_array($state) && ($state['last_run'] ?? '') === $today;

  // mkdir が失敗しても、他のリクエストが同時に作っていれば is_dir は真になります。
  $noDir = (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir));
  $fp = ($doneToday || $noDir) ? false : @fopen($file . '.lock', 'c');

  if ($fp !== false) {
    // ロックが取れなければ、既に他の要求が実行中。待たずに諦めます。
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
      fclose($fp);
    } else {
      // 【ロックを取った後にもう一度確かめること】日付が変わった瞬間に複数の
      // 要求が同時に来ると、1回目の確認は全員素通りします。ここで二重実行を止めます。
      $raw = @file_get_contents($file);
      $state = $raw !== false ? json_decode($raw, true) : null;

      if (!is_array($state) || ($state['last_run'] ?? '') !== $today) {
        pchatSweepStaleRooms();

        $body = json_encode(['last_run' => $today], JSON_UNESCAPED_UNICODE);
        if ($body !== false) {
          $tmp = $dir . '/.' . bin2hex(random_bytes(6)) . '.tmp';
          if (@file_put_contents($tmp, $body) !== false) {
            @rename($tmp, $file);
          }
        }
      }

      flock($fp, LOCK_UN);
      fclose($fp);
    }
  }
}


// =====================================================================
// 管理画面(oppai)から呼ばれるサーバー間API
//
// 【なぜ管理画面から直接ファイルを触らせないか】
// 部屋の一覧は p-chat 自身の鍵で暗号化されており、どの部屋を消してよいかの判定も
// p-chat 側にしかありません(匿名絡みは対象外、確認できないときは消さない、等)。
// 管理画面がファイルを直接書き換えると、その判定を二重に持つことになり、片方だけ
// 直したときに食い違います。実際、その手の食い違いで生きている部屋が消えました。
// ここを唯一の入口にして、判定は p-chat が持ったままにします。
//
// 【認証】全プロダクト共通の合言葉(api_secret)の一致だけで判定します。呼び出せるのは
// 同じサーバ内で鍵ファイルを読めるものだけです。ログイン中の利用者は関係ありません。
//
// 【この入口はセッションもSSOも通しません】ページ描画より前、往復の判定より前に
// 処理して即座に終わります。管理画面からの呼び出しでログインの往復に入ったり、
// 利用者のセッションを触ったりしても意味が無く、害しかないためです。
// =====================================================================
if (isset($_GET['api']) && is_string($_GET['api'])) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');

  $pchatApiRespond = static function (bool $ok, array $payload = [], int $status = 200): void {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok], $payload), JSON_UNESCAPED_UNICODE);
    exit;
  };

  // 合言葉は共有スクリプトが導出します。無ければ誰も認証できないので、その旨を返します。
  if (!class_exists('PusyuuMeikieeClient')) {
    $pchatApiRespond(false, [
      'error'   => 'gateway_missing',
      'message' => 'アカウント連携の共有スクリプトが無いため、このAPIは利用できません。',
    ], 500);
  }
  $pchatApiExpected = PusyuuMeikieeClient::apiSecret();
  $pchatApiGiven = (string)($_POST['api_secret'] ?? '');
  if ($pchatApiExpected === null || !hash_equals($pchatApiExpected, $pchatApiGiven)) {
    error_log('[p-chat API] 合言葉が一致しないリクエストを拒否しました。addr=' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $pchatApiRespond(false, ['error' => 'forbidden', 'message' => 'このAPIは合言葉(api_secret)が必要です。'], 403);
  }

  switch ($_GET['api']) {
    // 取り残された部屋(相手のアカウントが既に削除されている部屋)の点検と掃除。
    // dry_run=1 を付けると、判断だけ行って何も削除しません。
    // 【消す前にまず dry_run を見られること】が、この入口を作った一番の理由です。
    case 'admin_sweep_stale_rooms': {
      $dryRun = !empty($_POST['dry_run']);
      $pchatApiRespond(true, pchatSweepStaleRooms($dryRun));
    }

    // どの部屋にも属さなくなった付随ファイル(孤立ファイル)の点検と掃除。
    // dry_run=1 を付けると、探すだけで何も削除しません。
    case 'admin_sweep_orphan_files': {
      $dryRun = !empty($_POST['dry_run']);
      $pchatApiRespond(true, pchatSweepOrphanFiles($dryRun));
    }

    // 掃除の記録(新しい順)。「判断できず」が並んでいれば、メイキィへ確認できない
    // 状態が続いている=掃除が実質止まっている、と読めます。
    case 'admin_sweep_log': {
      $limit = max(1, min(200, (int)($_POST['limit'] ?? 20)));
      $rows = [];
      $file = pchatOrphanSweepLogFile();
      if (is_file($file)) {
        foreach (preg_split('/\r?\n/', (string)file_get_contents($file)) as $line) {
          $line = trim($line);
          if ($line === '') { continue; }
          $row = json_decode($line, true);
          if (is_array($row)) { $rows[] = $row; }
        }
      }
      $pchatApiRespond(true, ['entries' => array_slice(array_reverse($rows), 0, $limit)]);
    }

    // 部屋の概況(件数だけ。中身は返しません)。
    case 'admin_rooms_summary': {
      $total = 0; $active = 0; $anon = 0; $real = 0;
      foreach (pchatReadRooms() as $room) {
        $total++;
        if (($room['status'] ?? '') !== 'active') { continue; }
        $active++;
        $members = $room['members'] ?? [];
        $hasAnon = false;
        foreach ($members as $m) {
          if (!pchatIsRealAccountId((string)$m)) { $hasAnon = true; break; }
        }
        if ($hasAnon) { $anon++; } else { $real++; }
      }
      $pchatApiRespond(true, [
        'total' => $total, 'active' => $active,
        'anonymous_rooms' => $anon, 'account_rooms' => $real,
      ]);
    }
  }

  $pchatApiRespond(false, ['error' => 'unknown_action', 'message' => '不明なアクションです。'], 404);
}
// =====================================================================
// メッセージ本文の永続化(room毎に storage/messages/<room_id>.jsonl へ追記専用)
// =====================================================================

function pchatMessagesFile(string $roomId): string {
  return PCHAT_STORAGE_DIR . '/messages/' . $roomId . '.jsonl';
}

/**
 * roomId宛にメッセージを1件追記する。seqはファイル内の行数から採番する。
 * $replyToを渡すと、返信先メッセージのseqを平文メタデータ(seq/sender/created等と
 * 同じ扱い)として一緒に記録する。呼び出し側(case 'send':)が既に
 * pchatMessageSeqExists()で実在確認済みであることを前提とする(このファイルの
 * 内側では検証しない)。
 */
function pchatAppendMessage(string $roomId, string $senderId, string $plainText, string $rawKey, ?int $replyTo = null): ?array {
  $dir = PCHAT_STORAGE_DIR . '/messages';
  // mkdir が失敗しても、他のリクエストが同時に作っていれば is_dir は真になります。
  $noDir = (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir));
  $fp = $noDir ? false : fopen(pchatMessagesFile($roomId), 'c+');

  if ($fp === false) {
    $row = null;
  } else {
    // 【採番はロックの中で行うこと】ロックの外で数えると、同時に2通送られた
    // ときに同じseqが2つできて、返信先の指定が別のメッセージを指します。
    flock($fp, LOCK_EX);

    $seq = 0;
    rewind($fp);
    while (!feof($fp)) {
      $line = fgets($fp);
      if ($line !== false && trim($line) !== '') { $seq++; }
    }
    $seq++;

    $row = [
      'seq'     => $seq,
      'sender'  => $senderId,
      'enc'     => base64_encode(aesGcmEncrypt($plainText, $rawKey)),
      'created' => time(),
    ];
    if ($replyTo !== null) {
      $row['reply_to'] = $replyTo;
    }

    fwrite($fp, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
  }

  return $row;
}

/**
 * 指定seqのメッセージがそのroomに実在するかだけを確認する(送信時のreply_to検証用)。
 * 本文の復号は不要なので、seqだけを見る軽量な専用スキャンにしている
 * (pchatReadAllMessages()は全行を復号するため、検証だけの用途には重い)。
 */
function pchatMessageSeqExists(string $roomId, int $seq): bool {
  $file = pchatMessagesFile($roomId);
  // seqが0以下、ファイルが無い、開けない、のどれでも「無い」で答えます。
  $fp = ($seq <= 0 || !is_file($file)) ? false : fopen($file, 'r');
  $found = false;

  if ($fp !== false) {
    flock($fp, LOCK_SH);

    while ($found === false && !feof($fp)) {
      $line = fgets($fp);
      if ($line !== false && trim($line) !== '') {
        $row = json_decode($line, true);
        if (is_array($row) && (int)($row['seq'] ?? 0) === $seq) { $found = true; }
      }
    }

    flock($fp, LOCK_UN);
    fclose($fp);
  }

  return $found;
}

/** roomの全メッセージを復号して返す。復号できなかった行はスキップする。 */
function pchatReadAllMessages(string $roomId, string $rawKey): array {
  $file = pchatMessagesFile($roomId);
  $fp = is_file($file) ? fopen($file, 'r') : false;
  $out = [];

  if ($fp !== false) {
    flock($fp, LOCK_SH);

    while (!feof($fp)) {
      $line = fgets($fp);
      $row = ($line === false || trim($line) === '') ? null : json_decode($line, true);
      $plain = is_array($row)
        ? aesGcmDecrypt(base64_decode((string)($row['enc'] ?? ''), true) ?: '', $rawKey)
        : null;

      // 復号できない行は飛ばします。鍵を替えた前後の行が混ざっていても止まりません。
      if ($plain !== null) {
        $out[] = [
          'seq'      => (int)($row['seq'] ?? 0),
          'sender'   => (string)($row['sender'] ?? ''),
          'body'     => $plain,
          'created'  => (int)($row['created'] ?? 0),
          'reply_to' => isset($row['reply_to']) ? (int)$row['reply_to'] : null,
        ];
      }
    }

    flock($fp, LOCK_UN);
    fclose($fp);
  }

  return $out;
}

function pchatDeleteMessagesFile(string $roomId): void {
  $file = pchatMessagesFile($roomId);
  if (is_file($file)) { @unlink($file); }
  $reactionsFile = pchatReactionsFile($roomId);
  if (is_file($reactionsFile)) { @unlink($reactionsFile); }
  // 【入力中の印も一緒に消すこと】
  // ここに書き忘れると、部屋を消すたびに <room_id>.typing.json だけが残り続けます。
  // 1件79バイトと小さいので誰も気づかないまま溜まります。実際2026-09-07時点で、
  // とうに存在しない部屋の分が4件残っていました。
  // messages/ に room_id を名前に持つファイルを新しく足すときは、必ずここへも足すこと。
  $typingFile = pchatTypingFile($roomId);
  if (is_file($typingFile)) { @unlink($typingFile); }
}

/**
 * どの部屋にも属さなくなった付随ファイル(孤立ファイル)を探します。
 * $dryRun が true なら見つけるだけで削除しません。
 *
 * 【なぜ要るか】部屋を消したときの後始末が1箇所でも漏れると、そのぶんのファイルが
 * 永久に残ります。1件は小さくても、消し忘れた種類ぶん・消した部屋数ぶん溜まり続け、
 * しかも中身は「もう誰のものでもないデータ」です。後始末の漏れは今後も起こり得るので、
 * 「今どうなっているか」を後から確かめて片付けられる道を用意しておきます。
 *
 * 判定は「ファイル名の先頭にある room_id が、今の部屋一覧に無い」だけです。
 * 部屋一覧を読めなかったときは**何もしません**。読めていないのに「どの部屋にも
 * 属さない」と判断すると、生きているファイルを全部消してしまいます。
 */
function pchatSweepOrphanFiles(bool $dryRun = false): array {
  $rooms = pchatReadRooms();
  $dir = PCHAT_STORAGE_DIR . '/messages';

  // 【部屋一覧が読めないときは中断すること】
  // 空配列は「部屋が1つも無い」とも「読めなかった」とも解釈できます。ファイルが
  // 存在するのに部屋が0件という状況は、鍵が読めない・復号に失敗した等の異常の方が
  // ずっとありそうです。その状態で掃除を続けると、全ファイルが孤立扱いになります。
  $unreadable = ($rooms === [])
    && is_file(ROOMS_FILE)
    && trim((string)@file_get_contents(ROOMS_FILE)) !== '';

  if (!is_dir($dir)) {
    $result = ['ok' => true, 'dry_run' => $dryRun, 'scanned' => 0, 'orphans' => [], 'removed' => []];

  } else if ($unreadable) {
    $result = [
      'ok' => false, 'error' => 'rooms_unreadable',
      'message' => '部屋の一覧を読めなかったため、孤立ファイルの判定を中止しました(鍵ファイルを確認してください)。',
    ];

  } else {
    $alive = [];
    foreach ($rooms as $room) {
      $id = (string)($room['room_id'] ?? '');
      if ($id !== '') { $alive[$id] = true; }
    }

    $orphans = [];
    $removed = [];
    $scanned = 0;

    foreach (scandir($dir) ?: [] as $name) {
      $path = $dir . '/' . $name;

      if ($name !== '.' && $name !== '..' && is_file($path)) {
        $scanned++;

        // 付随ファイルの名前は必ず「<room_id>.なにか」の形です。先頭の32桁だけを見ます
        // (room_idは bin2hex(random_bytes(16)) なので常に32桁の16進)。
        $isOrphan = (preg_match('/^([0-9a-f]{32})\./', $name, $m) === 1)
          && !isset($alive[$m[1]]);

        if ($isOrphan) {
          $orphans[] = ['file' => $name, 'bytes' => (int)filesize($path)];
          if (!$dryRun && @unlink($path)) {
            $removed[] = $name;
          }
        }
      }
    }

    $result = [
      'ok' => true, 'dry_run' => $dryRun,
      'scanned' => $scanned, 'orphans' => $orphans, 'removed' => $removed,
    ];
  }

  return $result;
}

// =====================================================================
// 絵文字リアクション。roomごとに追記専用のイベントログ(storage/messages/配下、
// メッセージ本体とは別ファイル)として持つ。メッセージ本体のseq採番(ファイルの
// 行数から採番する方式)を絶対に乱さないよう、メッセージ本体のファイルには
// 混ぜない。1件のイベントは「誰が」「どのメッセージ(target_seq)に」
// 「どの絵文字を(空文字なら解除)」の1回分の操作を表し、読み出し時に
// (target_seq, sender)組ごとの最新のイベントだけを見て現在の状態を決める
// (同じ絵文字をもう一度押すとトグルで解除、という挙動もこの1本のログだけで表現できる)。
// =====================================================================

function pchatReactionsFile(string $roomId): string {
  return PCHAT_STORAGE_DIR . '/messages/' . $roomId . '.reactions.jsonl';
}

/** $emojiに''を渡すとその発言者自身のリアクションを解除したことになる。 */
function pchatAppendReaction(string $roomId, string $senderId, int $targetSeq, string $emoji, string $rawKey): bool {
  $dir = PCHAT_STORAGE_DIR . '/messages';
  // mkdir が失敗しても、他のリクエストが同時に作っていれば is_dir は真になります。
  $noDir = (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir));
  $fp = $noDir ? false : fopen(pchatReactionsFile($roomId), 'a');

  if ($fp === false) {
    $ok = false;
  } else {
    flock($fp, LOCK_EX);

    // 【メッセージ本体のファイルへ混ぜないこと】本体のseqは行数から採番するので、
    // リアクションを混ぜると採番が狂って返信先の指定が別のメッセージを指します。
    $row = [
      'target_seq' => $targetSeq,
      'sender'     => $senderId,
      'enc'        => base64_encode(aesGcmEncrypt($emoji, $rawKey)),
      'created'    => time(),
    ];

    fwrite($fp, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
    flock($fp, LOCK_UN);
    fclose($fp);
    $ok = true;
  }

  return $ok;
}

/**
 * roomの全リアクションを復号し、target_seq毎に「各senderの最新の絵文字」だけを
 * 集約して返す(解除された=空文字列の状態は除外する)。
 * 戻り値: [target_seq => [['sender'=>string,'emoji'=>string], ...]]
 */
function pchatReadReactions(string $roomId, string $rawKey): array {
  $file = pchatReactionsFile($roomId);
  $fp = is_file($file) ? fopen($file, 'r') : false;
  $latest = []; // "target_seq:sender" => emoji(''なら解除)

  if ($fp !== false) {
    flock($fp, LOCK_SH);

    while (!feof($fp)) {
      $line = fgets($fp);
      $row = ($line === false || trim($line) === '') ? null : json_decode($line, true);
      $plain = is_array($row)
        ? aesGcmDecrypt(base64_decode((string)($row['enc'] ?? ''), true) ?: '', $rawKey)
        : null;

      if ($plain !== null) {
        $key = ((int)($row['target_seq'] ?? 0)) . ':' . (string)($row['sender'] ?? '');
        $latest[$key] = $plain; // ファイルは追記順=時系列順なので、後に読んだ方が新しい
      }
    }

    flock($fp, LOCK_UN);
    fclose($fp);
  }

  // 最後の操作が空文字 = その人が解除した、ということなので一覧に出しません。
  $out = [];
  foreach ($latest as $key => $emoji) {
    if ($emoji !== '') {
      [$targetSeq, $sender] = explode(':', $key, 2);
      $out[(int)$targetSeq][] = ['sender' => $sender, 'emoji' => $emoji];
    }
  }

  return $out;
}

// =====================================================================
// 「入力中…」インジケーター(JS強化のみ。任意の演出であり内容を含まない
// ため、メッセージ本体と違い暗号化しない軽量な一時ファイルに留める)。
// =====================================================================

function pchatTypingFile(string $roomId): string {
  return PCHAT_STORAGE_DIR . '/messages/' . $roomId . '.typing.json';
}

const PCHAT_TYPING_TTL = 8; // この秒数入力が無ければ「入力中」ではなくなる

/** 自分がこのroomで入力中であることを記録する(chat.jsが数秒おきに呼ぶ)。 */
function pchatSetTyping(string $roomId, string $myId): void {
  $file = pchatTypingFile($roomId);
  $dir = dirname($file);
  // mkdir が失敗しても、他のリクエストが同時に作っていれば is_dir は真になります。
  $noDir = (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir));
  $fp = $noDir ? false : fopen($file, 'c+');

  // 入力中の印は演出なので、書けなくても黙って諦めます(会話そのものは続きます)。
  if ($fp !== false) {
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) { $data = []; }

    $now = time();
    $data = array_filter($data, static fn($expires) => (int)$expires > $now); // 期限切れは掃除
    $data[$myId] = $now + PCHAT_TYPING_TTL;

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
  }
}

/** 自分以外の誰か(1対1なので実質は相手)が現在入力中かどうか。 */
function pchatIsAnyoneElseTyping(string $roomId, string $myId): bool {
  $file = pchatTypingFile($roomId);
  $raw = is_file($file) ? @file_get_contents($file) : false;
  $data = ($raw !== false) ? json_decode($raw, true) : null;
  $typing = false;

  if (is_array($data)) {
    $now = time();
    foreach ($data as $userid => $expires) {
      // 自分自身は数えません(自分が入力中なのは画面に出す意味がないため)。
      if ((string)$userid !== $myId && (int)$expires > $now) {
        $typing = true;
        break;
      }
    }
  }

  return $typing;
}

/**
 * シークレットモードのroom用。PCHAT_SECRET_MESSAGE_TTLより古いメッセージ行を
 * 削除する(p-5secondのpurgeExpired()と同じ「アクセスの都度掃除する」方式)。
 * 暗号化そのものは変えない(保持期間だけが短くなる)。
 */
function pchatPurgeSecretMessages(string $roomId): void {
  $file = pchatMessagesFile($roomId);
  $fp = is_file($file) ? fopen($file, 'c+') : false;

  if ($fp !== false) {
    flock($fp, LOCK_EX);
    rewind($fp);

    $now = time();
    $kept = [];
    $changed = false;

    while (!feof($fp)) {
      $line = fgets($fp);
      $row = ($line === false || trim($line) === '') ? null : json_decode($line, true);

      if (is_array($row)) {
        if (($now - (int)($row['created'] ?? 0)) < PCHAT_SECRET_MESSAGE_TTL) {
          $kept[] = $line;
        } else {
          $changed = true;
        }
      }
    }

    // 【消す行が1つも無ければ書き戻さないこと】書き戻すと、空行や壊れた行を
    // 落としただけのファイルで毎回上書きすることになります。
    if ($changed) {
      rewind($fp);
      ftruncate($fp, 0);
      fwrite($fp, implode('', $kept));
    }

    flock($fp, LOCK_UN);
    fclose($fp);
  }
}

// =====================================================================
// 招待コードのハンドシェイク(匿名タブ・メイキータブ共通)
//
// このサーバーは招待者・受諾者双方のuseridをログインセッションから
// 既に把握しているため、招待コードは「意図した相手に届いたことの証明」
// (invite_token)だけで足りる。p-reversiのP2Pモードのoffer/answerが
// 双方向なのはWebRTCのSDPネゴシエーション自体の必然であり、サーバーが
// 仲介するこちらでは技術的に不要と判断し、片方向のコードのみを使う。
// 「双方の明示的な意思確認」という儀式性は、受諾側の確認画面(招待者の
// 名前表示+警告文)で代替する。
// =====================================================================

/**
 * 実アカウント同士の部屋を有効化する前に確認する。招待作成後、承諾される前に
 * 招待者がアカウントを削除しているかもしれないため、有効化直前に念のため
 * 確認する(承諾者自身は今まさにこのリクエストをしているので存在確認は
 * 不要)。匿名が1人でも絡む場合はそもそもこの仕組みの対象外なのでtrueを返す。
 */
function pchatCanActivateRoom(string $inviterId, string $myId): bool {
  if (!pchatIsRealAccountId($inviterId) || !pchatIsRealAccountId($myId)) {
    // 匿名が1人でも絡む部屋は、そもそもこの仕組みの対象外です。
    $canActivate = true;
  } else {
    // 承諾者自身は今まさにこのリクエストをしているので、確認は招待者だけで足ります。
    $canActivate = pchatAccountExists($inviterId);
  }

  return $canActivate;
}

function pchatCreateInvite(array $actor): array {
  $roomId = bin2hex(random_bytes(16));
  $inviteToken = bin2hex(random_bytes(16));
  $room = [
    'room_id'         => $roomId,
    'status'          => 'pending_handshake',
    'invite_token'    => $inviteToken,
    'members'         => [$actor['userid']],
    'members_info'    => [
      ['userid' => $actor['userid'], 'username' => $actor['username'], 'name' => $actor['name']],
    ],
    'created'         => time(),
    'last_message_at' => null,
    'last_seq'        => 0,
    'read_seq'        => [],
    'secret'          => false,
  ];

  $tx = pchatTransactRooms(static function (array &$rows) use ($room): array {
    $rows[] = $room;
    return ['save' => true, 'result' => $room];
  });

  if ($tx['status'] === null) {
    $result = ['ok' => false, 'error' => '招待コードの作成に失敗しました。'];
  } else {
    $code = base64_encode(json_encode(['v' => 1, 'r' => $roomId, 't' => $inviteToken], JSON_UNESCAPED_UNICODE));
    $result = ['ok' => true, 'room_id' => $roomId, 'code' => $code];
  }

  return $result;
}

function pchatDecodeInviteCode(string $code): ?array {
  $raw = base64_decode(trim($code), true);
  $data = ($raw === false) ? null : json_decode($raw, true);
  $valid = is_array($data) && ($data['v'] ?? null) === 1 && isset($data['r'], $data['t']);
  $roomId = $valid ? (string)$data['r'] : '';
  $token  = $valid ? (string)$data['t'] : '';

  // 【形を厳しく見ること】ここを通った値がそのままファイル名の一部になります。
  // 16進32文字以外を通すと、別のファイルを指す名前を作られます。
  if (!$valid) {
    $decoded = null;
  } else if (!pchatIsValidRoomId($roomId) || preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
    $decoded = null;
  } else {
    $decoded = ['room_id' => $roomId, 'token' => $token];
  }

  return $decoded;
}

function pchatInviterIsAnonymous(array $room): bool {
  $inviterId = $room['members'][0] ?? null;

  return is_string($inviterId) && str_starts_with($inviterId, 'anon:');
}

/** 招待コードを検証だけする(状態は変更しない)。確認画面の表示・承諾処理の両方から使う。 */
function pchatPreviewInvite(string $code, array $actor): array {
  $myId = $actor['userid'];
  $decoded = pchatDecodeInviteCode($code);
  $rooms = ($decoded === null) ? [] : pchatReadRooms();
  $idx = ($decoded === null) ? null : pchatFindRoomIndex($rooms, $decoded['room_id']);
  $room = ($idx === null) ? null : $rooms[$idx];

  // トークンの照合は hash_equals(タイミング攻撃を避けるため)。
  $usable = ($room !== null)
    && (($room['status'] ?? '') === 'pending_handshake')
    && hash_equals((string)($room['invite_token'] ?? ''), $decoded['token']);

  if ($decoded === null) {
    $result = ['ok' => false, 'error' => 'このコードは読み取れませんでした。コピーミスがないか確認してください。'];

  } else if (!$usable) {
    $result = ['ok' => false, 'error' => 'このコードは無効か、既に使用済み・期限切れです。'];

  } else if (pchatInviterIsAnonymous($room) !== $actor['anonymous']) {
    // メイキータブと匿名タブは実質別システムとして分離する(身元の種類が混ざったroomを作らない)。
    $result = ['ok' => false, 'error' => 'このコードは別のタブ(メイキー/匿名)用です。同じタブで開いてください。'];

  } else if (($room['members'][0] ?? null) === $myId) {
    $result = ['ok' => false, 'error' => '自分自身が作成した招待コードは使用できません。'];

  } else {
    $result = ['ok' => true, 'room_id' => $room['room_id'], 'code' => $code, 'inviter' => $room['members_info'][0] ?? null];
  }

  return $result;
}

function pchatAcceptInvite(array $actor, string $code): array {
  $myId = $actor['userid'];
  $preview = pchatPreviewInvite($code, $actor);
  // 実アカウント同士なら、部屋を有効化する前に招待者がまだ実在するかを確認する
  // (P_DRIVE_INTEGRATION_SPEC.md 7節)。匿名が絡む場合は対象外として素通しする。
  $inviterGone = !empty($preview['ok'])
    && !pchatCanActivateRoom((string)($preview['inviter']['userid'] ?? ''), $myId);

  if (!$preview['ok']) {
    $result = $preview;

  } else if ($inviterGone) {
    $result = ['ok' => false, 'error' => 'この招待の作成者は既に退会しています。'];

  } else {
    $roomId = $preview['room_id'];

    // 【同じ確認をロックの中でもう一度すること】上の preview はロックの外で
    // 見ています。その間に誰かが承諾すると、2人目が同じ部屋へ入れてしまいます。
    $tx = pchatTransactRooms(static function (array &$rows) use ($roomId, $myId, $actor): array {
      $idx = pchatFindRoomIndex($rows, $roomId);

      if ($idx === null || ($rows[$idx]['status'] ?? '') !== 'pending_handshake') {
        $outcome = ['save' => false, 'result' => ['ok' => false, 'error' => 'この招待は既に使用済みか無効になっています。']];

      } else if (pchatInviterIsAnonymous($rows[$idx]) !== $actor['anonymous']) {
        $outcome = ['save' => false, 'result' => ['ok' => false, 'error' => 'このコードは別のタブ(メイキー/匿名)用です。']];

      } else if (in_array($myId, $rows[$idx]['members'] ?? [], true)) {
        $outcome = ['save' => false, 'result' => ['ok' => false, 'error' => '自分自身が作成した招待コードは使用できません。']];

      } else {
        $rows[$idx]['members'][] = $myId;
        $rows[$idx]['members_info'][] = ['userid' => $myId, 'username' => $actor['username'], 'name' => $actor['name']];
        $rows[$idx]['status'] = 'active';
        $outcome = ['save' => true, 'result' => ['ok' => true, 'room_id' => $roomId]];
      }

      return $outcome;
    });

    if ($tx['status'] === null) {
      $result = ['ok' => false, 'error' => '保存に失敗しました。もう一度お試しください。'];
    } else {
      $result = $tx['result'];
    }
  }

  return $result;
}

// =====================================================================
// メイキー限定: ユーザー名での直接招待(コード交換不要)
//
// アカウントを持つことの付加価値の1つ。招待コードのやり取りが要らない分、
// 「相手のユーザー名を知っている」ことだけが前提になる。匿名タブでは
// 使えない(pchatCreateDirectInvite自体がactorの匿名判定で拒否する)。
// =====================================================================

function pchatCreateDirectInvite(array $actor, string $targetUsername): array {
  $targetUsername = trim($targetUsername);
  // profile() は「見つかった相手そのもの」か null を返します(以前の生API応答とは形が
  // 違います)。null のときは、居ないのか・聞けなかったのかを必ず見分けること。
  // 混ぜると、メイキィが一瞬詰まっただけで実在する相手を「見つかりません」と断定します。
  $target = (!$actor['anonymous'] && $targetUsername !== '')
    ? PchatAccount::profile($targetUsername)
    : null;

  if ($actor['anonymous']) {
    $result = ['ok' => false, 'error' => 'ユーザー名での招待にはプシューメイキィアカウントが必要です。'];

  } else if ($targetUsername === '') {
    $result = ['ok' => false, 'error' => 'ユーザー名を入力してください。'];

  } else if ($target === null && PchatAccount::lastFailureWasConnection()) {
    $result = ['ok' => false, 'error' => 'プシューメイキィに接続できませんでした。しばらくしてから再度お試しください。'];

  } else if ($target === null) {
    $result = ['ok' => false, 'error' => 'そのユーザー名のプシューメイキィが見つかりません。'];

  } else if ($target['userid'] === $actor['userid']) {
    $result = ['ok' => false, 'error' => '自分自身は招待できません。'];

  } else {
    $roomId = bin2hex(random_bytes(16));
    $room = [
      'room_id'          => $roomId,
      'status'           => 'pending_handshake',
      'invite_token'     => bin2hex(random_bytes(16)),
      'invited_userid'   => $target['userid'],
      'invited_username' => $target['username'],
      'members'          => [$actor['userid']],
      'members_info'     => [
        ['userid' => $actor['userid'], 'username' => $actor['username'], 'name' => $actor['name']],
      ],
      'created'          => time(),
      'last_message_at'  => null,
      'last_seq'         => 0,
      'read_seq'         => [],
      'secret'           => false,
    ];

    $tx = pchatTransactRooms(static function (array &$rows) use ($room): array {
      $rows[] = $room;
      return ['save' => true, 'result' => $room];
    });

    if ($tx['status'] === null) {
      $result = ['ok' => false, 'error' => '招待の作成に失敗しました。'];
    } else {
      $result = ['ok' => true, 'room_id' => $roomId];
    }
  }

  return $result;
}

function pchatAcceptDirectInvite(array $actor, string $roomId): array {
  $myId = $actor['userid'];

  // ユーザー名での直接招待は常に実アカウント同士(pchatCreateDirectInvite()が
  // 匿名を拒否し、招待先もアカウント検索経由でしか指定できないため)。
  // 部屋を有効化する前に招待者がまだ実在するかを確認する。
  $rooms = pchatReadRooms();
  $idx = pchatFindRoomIndex($rooms, $roomId);
  $inviterId = $idx !== null ? (string)($rooms[$idx]['members'][0] ?? '') : '';

  if (!pchatCanActivateRoom($inviterId, $myId)) {
    $result = ['ok' => false, 'error' => 'この招待の作成者は既に退会しています。'];
  } else {
    // 【宛先の確認はロックの中で行うこと】上の読み取りはロックの外です。
    $tx = pchatTransactRooms(static function (array &$rows) use ($roomId, $myId, $actor): array {
      $idx = pchatFindRoomIndex($rows, $roomId);

      if ($idx === null || ($rows[$idx]['status'] ?? '') !== 'pending_handshake') {
        $outcome = ['save' => false, 'result' => ['ok' => false, 'error' => 'この招待は既に使用済みか無効になっています。']];

      } else if (($rows[$idx]['invited_userid'] ?? null) !== $myId) {
        $outcome = ['save' => false, 'result' => ['ok' => false, 'error' => 'この招待はあなた宛てではありません。']];

      } else {
        $rows[$idx]['members'][] = $myId;
        $rows[$idx]['members_info'][] = ['userid' => $myId, 'username' => $actor['username'], 'name' => $actor['name']];
        $rows[$idx]['status'] = 'active';
        unset($rows[$idx]['invited_userid'], $rows[$idx]['invited_username']);
        $outcome = ['save' => true, 'result' => ['ok' => true, 'room_id' => $roomId]];
      }

      return $outcome;
    });

    if ($tx['status'] === null) {
      $result = ['ok' => false, 'error' => '保存に失敗しました。もう一度お試しください。'];
    } else {
      $result = $tx['result'];
    }
  }

  return $result;
}

function pchatDeleteRoom(string $roomId, string $myId): bool {
  // 自分が入っていない部屋は pchatRoomForMember() が null を返すので、
  // ここが「他人の部屋を消せない」という関門そのものです。
  $room = pchatRoomForMember($roomId, $myId);

  if ($room === null) {
    $deleted = false;
  } else {
    $deleted = pchatDeleteRoomInternal($roomId);
  }

  return $deleted;
}

function pchatSetSecret(string $roomId, string $myId, bool $secret): bool {
  $room = pchatRoomForMember($roomId, $myId);

  if ($room === null || ($room['status'] ?? '') !== 'active') {
    $ok = false;
  } else {
    $tx = pchatTransactRooms(static function (array &$rows) use ($roomId, $secret): array {
      $idx = pchatFindRoomIndex($rows, $roomId);

      if ($idx === null) {
        $outcome = ['save' => false, 'result' => false];
      } else {
        $rows[$idx]['secret'] = $secret;
        $outcome = ['save' => true, 'result' => true];
      }

      return $outcome;
    });

    $ok = ($tx['status'] === true);
  }

  return $ok;
}

// =====================================================================
// 既読設定・記録
//
// 「既読にしたことを相手に伝えるかどうか」は既読する側(閲覧者)自身の
// 設定で決める。有効な場合のみread_seqを記録する。表示自体は相手の
// read_seqを見て通常のGET描画時に行えるためJS無しでも成立するが、
// 記録はGETページ表示の副作用にしない(POSTでのみ更新する)。
// =====================================================================

/**
 * 「既読にしたことを相手に伝えるか」の設定値。メイキーログイン中は汎用userDataに、
 * 匿名actorはMeikeeトークンを持てないためCookieに保存する(アカウントとは
 * 別の設定として扱われ、ログイン状態が変わると設定も引き継がれない)。
 */
function pchatReadReceiptsEnabled(array $actor): bool {
  if ($actor['anonymous']) {
    // 匿名はメイキィのトークンを持てないので、設定はcookieに置きます。
    $enabled = ($_COOKIE['pchat_anon_read_receipts'] ?? '0') === '1';
  } else {
    $res = PchatAccount::dataGet('read_receipts_enabled');
    $enabled = !empty($res['ok']) && (string)($res['value'] ?? '0') === '1';
  }

  return $enabled;
}

function pchatSetReadReceiptsEnabled(array $actor, bool $enabled): bool {
  if ($actor['anonymous']) {
    setcookie('pchat_anon_read_receipts', $enabled ? '1' : '0', [
      'expires' => time() + 365 * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    $ok = true;
  } else {
    $res = PchatAccount::dataSet('read_receipts_enabled', $enabled ? '1' : '0');
    $ok = !empty($res['ok']);
  }

  return $ok;
}

/**
 * 送信・既読操作の後始末。$newMessageRowがあればroomのlast_seq/last_message_atを更新する。
 * read_seqの更新は「既読を相手に伝える」設定(pchatReadReceiptsEnabled)が有効な場合か、
 * $forceReadがtrueの場合に行う。
 *
 * $forceReadは会話画面の「一覧に戻る(既読を記録)」ボタン(mark_read)専用のもの。
 * 設定がOFFのままだとこのボタンを押しても画面上は何も起きたように見えず
 * 「機能していない」ように感じられていたため、この明示的なボタン操作だけは
 * 設定の有無に関わらず必ず記録される、独立した手動操作として扱う。
 * 一方、タブにフォーカスがあるだけで裏から自動送信されるmark_read_ping(chat.js)や、
 * 送信時の副次的な既読更新(send)は、引き続き設定に従う(能動的にボタンを
 * 押したわけではない挙動まで強制的に既読化すると、設定をOFFにしている意味が
 * 無くなってしまうため)。
 */
function pchatUpdateRoomAfterActivity(string $roomId, array $actor, ?array $newMessageRow, int $upToSeq, bool $forceRead = false): void {
  $myId = $actor['userid'];
  $readEnabled = $forceRead || pchatReadReceiptsEnabled($actor);

  pchatTransactRooms(static function (array &$rows) use ($roomId, $myId, $newMessageRow, $upToSeq, $readEnabled): array {
    $idx = pchatFindRoomIndex($rows, $roomId);

    if ($idx === null) {
      $outcome = ['save' => false, 'result' => null];
    } else {
      $changed = false;

      if ($newMessageRow !== null) {
        $rows[$idx]['last_seq'] = $newMessageRow['seq'];
        $rows[$idx]['last_message_at'] = $newMessageRow['created'];
        $changed = true;
      }

      if ($readEnabled) {
        if (!isset($rows[$idx]['read_seq']) || !is_array($rows[$idx]['read_seq'])) {
          $rows[$idx]['read_seq'] = [];
        }
        $current = (int)($rows[$idx]['read_seq'][$myId] ?? 0);
        $effectiveUpTo = max($upToSeq, (int)($newMessageRow['seq'] ?? 0));

        // 既読の位置は前へ戻しません(戻すと相手の画面で既読が取り消されます)。
        if ($effectiveUpTo > $current) {
          $rows[$idx]['read_seq'][$myId] = $effectiveUpTo;
          $changed = true;
        }
      }

      $outcome = ['save' => $changed, 'result' => true];
    }

    return $outcome;
  });
}

function pchatOpponentInfo(array $room, string $myId): ?array {
  $found = null;

  // 1対1なので、自分でない最初の1人がそのまま相手です。
  foreach (($room['members_info'] ?? []) as $m) {
    if (($m['userid'] ?? null) !== $myId) {
      $found = $m;
      break;
    }
  }

  return $found;
}

// =====================================================================
// 会話一覧の新着件数バッジ
//
// read_seq(既読)は「相手に既読を伝えるか」の設定次第で更新されない
// (意図的なプライバシー機能。詳しくはpchatUpdateRoomAfterActivity()参照)ため、
// 新着件数の計算にそのまま使うと、設定をOFFにしているだけの人には
// いつまでも件数が減らないバグになってしまう。そのため「自分が実際に
// この会話を開いたか」だけを見る、相手には一切公開されない別のフィールド
// (last_viewed_seq)を新設し、新着件数はこちらだけを基準に計算する。
// =====================================================================

/**
 * 会話を開いた(閲覧した)ことを記録する。read_seqと違い設定に関わらず常に記録する
 * (相手には見えない、自分専用のブックマークのため)。
 * chat.jsの3秒間隔ポーリングも同じGETルートを通るため、進みが無い(=新着が無い)
 * ときはpchatTransactRooms(全ルーム分のロック・復号・再暗号化を伴う)自体を
 * 呼ばないよう、呼び出し前に軽く比較して不要な書き込みを避ける。
 */
function pchatMarkRoomViewed(array $room, string $myId): void {
  $roomId = (string)($room['room_id'] ?? '');
  $lastSeq = (int)($room['last_seq'] ?? 0);
  $already = (int)($room['last_viewed_seq'][$myId] ?? 0);

  // 進みが無ければ何もしません。chat.jsが3秒おきに通るので、ここで畳まないと
  // 全ルームのロック・復号・再暗号化が3秒ごとに走ります。
  if ($lastSeq > $already) {
    pchatTransactRooms(static function (array &$rows) use ($roomId, $myId, $lastSeq): array {
      $idx = pchatFindRoomIndex($rows, $roomId);
      $current = ($idx === null) ? 0 : (int)($rows[$idx]['last_viewed_seq'][$myId] ?? 0);

      // ロックを取る間に他の要求が進めているかもしれないので、ここでも確かめます。
      if ($idx === null || $lastSeq <= $current) {
        $outcome = ['save' => false, 'result' => null];
      } else {
        if (!isset($rows[$idx]['last_viewed_seq']) || !is_array($rows[$idx]['last_viewed_seq'])) {
          $rows[$idx]['last_viewed_seq'] = [];
        }
        $rows[$idx]['last_viewed_seq'][$myId] = $lastSeq;
        $outcome = ['save' => true, 'result' => true];
      }

      return $outcome;
    });
  }
}

/** その会話の未読件数(自分がまだ開いていない新着メッセージの数)。 */
function pchatUnreadCount(array $room, string $myId): int {
  $lastSeq = (int)($room['last_seq'] ?? 0);
  $viewed = (int)($room['last_viewed_seq'][$myId] ?? 0);

  return max(0, $lastSeq - $viewed);
}

/**
 * ナビゲーションバーの「会話」アイコンに出す合計未読件数。$actorが持つ全roomを
 * 対象に計算する(通常のページ描画とnav-badge-poll.jsのポーリング、両方から
 * 同じ定義を共有するための共通関数)。
 */
function pchatTotalUnreadFor(array $actor): int {
  $total = 0;
  foreach (pchatReadRooms() as $r) {
    if (($r['status'] ?? '') === 'active' && pchatRoomAccessibleBy($r, $actor['userid'])) {
      $total += pchatUnreadCount($r, $actor['userid']);
    }
  }
  return $total;
}

function pchatH($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * 本文中のhttp(s)のURLをアンカーリンク化しつつ、それ以外の部分は通常通り
 * HTMLエスケープする(生の$bodyに対して行うため、二重エスケープにならない)。
 * 文末の句読点・閉じ括弧をURLに巻き込まないよう、末尾のそれらだけ切り離して
 * 平文として扱う。
 */
// attach_uploadが画像を添付した際、p5s_urlの末尾にこのフラグメントを付けて
// 本文へ差し込む(pchatIncomingFileShareUrl()参照)。URLのフラグメントは
// サーバーへのリクエストには含まれない(ブラウザが送らない)ため、保存先の
// p-5second自体は一切変更せずに済む。pchatLinkify()側でこの目印だけを見て、
// 通常のリンクではなく本文中にインライン画像として表示する。
const PCHAT_IMAGE_URL_MARKER = '#pchat-img';

function pchatLinkify(string $body): string {
  $parts = preg_split('/(https?:\/\/[^\s<>"\']+)/u', $body, -1, PREG_SPLIT_DELIM_CAPTURE);

  if ($parts === false) {
    // 分割できなかった(不正なUTF-8など)。リンクにはせず、そのまま安全に出します。
    // 【必ず pchatH() を通すこと】素で出すと本文がHTMLとして解釈されます。
    $html = pchatH($body);
  } else {
    $html = '';

    foreach ($parts as $i => $part) {
      if ($part === '') {
        // 何も足しません

      } else if ($i % 2 === 0) {
        // 偶数番はURLでない普通の文。必ずエスケープします。
        $html .= pchatH($part);

      } else {
        // 末尾の句読点や閉じ括弧はURLに含めません(文章の一部なので)。
        $url = rtrim($part, ".,;:!?)]}、。」』'\"");
        $trailing = substr($part, strlen($url));
        $isImage = str_ends_with($url, PCHAT_IMAGE_URL_MARKER);

        if ($isImage) {
          $url = substr($url, 0, -strlen(PCHAT_IMAGE_URL_MARKER));
          $html .= '<a href="' . pchatH($url) . '" target="_blank" rel="noopener noreferrer nofollow">'
            . '<img class="chat-inline-image" src="' . pchatH($url) . '" alt="添付画像" loading="lazy" />'
            . '</a>' . pchatH($trailing);
        } else {
          $html .= '<a href="' . pchatH($url) . '" target="_blank" rel="noopener noreferrer nofollow">' . pchatH($url) . '</a>' . pchatH($trailing);
        }
      }
    }
  }

  return $html;
}

function pchatRedirect(string $path): void {
  header('Location: ' . $path);
  exit;
}

/**
 * 他のプシューサービスへのリンク集(プロダクト一覧)。台帳(pusyuu_registry)から
 * 受け取り、自分自身は除外して描画する
 * (考え方の転用。見た目はp-chat既存の.match-listに合わせている)。
 * JSは使わずサーバー側だけで完結する。
 */
function pchatProductListHtml(): string {
  // 台帳(pusyuu_registry)から受け取り、自分(台帳のID p-chat)は外します。
  // 【自分を URL で見分けないこと】ドメインや入口が変わると自分が一覧に混ざります。
  $links = class_exists('PusyuuRegistryClient') ? PusyuuRegistryClient::links('p-chat') : null;

  if ($links === null) {
    $html = '<p class="note">プロダクト一覧を読み込めませんでした。</p>';

  } else {
    $fallbackImage = (string)PusyuuRegistryClient::url('main', '/pusyuusystem/images/avater.jpg');
    $rows = '';

    foreach ($links as $product) {
      $url = (string)$product['url'];

      if ($url !== '') {
        $title = pchatH((string)($product['title'] ?? $url));
        $image = ($product['image'] !== '') ? $product['image'] : $fallbackImage;
        $rows .= '
      <li class="match-row">
        <img class="match-row-icon" src="' . pchatH($image) . '" alt="" loading="lazy" />
        <div class="match-row-info"><strong>' . $title . '</strong></div>
        <a class="small-button ghost" href="' . pchatH($url) . '" target="_blank" rel="noopener noreferrer">開く</a>
      </li>
    ';
      }
    }

    if ($rows === '') {
      $html = '<p class="note">現在、表示できるプロダクトはありません。</p>';
    } else {
      $html = '<ul class="match-list">' . $rows . '</ul>';
    }
  }

  return $html;
}

/** pchatProductListHtml()の簡易表示版。画像を含めず、文字リンクの一覧だけにする。 */
function pchatProductListHtmlLite(): string {
  $links = class_exists('PusyuuRegistryClient') ? PusyuuRegistryClient::links('p-chat') : null;

  if ($links === null) {
    $html = '<p>プロダクト一覧を読み込めませんでした。</p>';

  } else {
    $rows = '';

    foreach ($links as $product) {
      $url = (string)$product['url'];

      if ($url !== '') {
        $title = pchatH((string)($product['title'] ?? $url));
        $rows .= '<li><a href="' . pchatH($url) . '">' . $title . '</a></li>';
      }
    }

    if ($rows === '') {
      $html = '<p>現在、表示できるプロダクトはありません。</p>';
    } else {
      $html = '<ul>' . $rows . '</ul>';
    }
  }

  return $html;
}

function pchatAssetUrl(string $relativePath): string {
  $absolute = './' . ltrim($relativePath, '/.');
  $version = @filemtime($absolute);
  return $relativePath . ($version !== false ? '?v=' . $version : '');
}

// push-sw.jsは assets/scripts/ 配下に置いたまま(他のスクリプト類と同じ場所に
// 集約する)、クエリパラメータ経由(?push_sw=1、下記の早期分岐を参照)で配信する。
// これによりルート直下へのファイル配置にもサーバー設定(.htaccessの
// Service-Worker-Allowedヘッダー)にも依存せず、scope: '/'を成立させられる。
function pchatPushSwUrl(): string {
  $absolute = './assets/scripts/push-sw.js';
  $version = @filemtime($absolute);
  return './?push_sw=1' . ($version !== false ? '&v=' . $version : '');
}

// =====================================================================
// 音波タブ(mode=sonic): アカウント・招待コード・room・サーバー保存のどれも
// 使わない、quiet.js(音波送受信)だけで成立する使い捨ての会話。
// メイキー/匿名タブとは仕組みが根本的に別物なので、下のタブ/actor決定より
// 前でここだけ完結させて抜ける(以降のroom読み書き・CSRF・PRGフォーム等は
// 一切通らない)。
//
// タブ自体へのリンクはJS(sonic-tab-link.js)でだけ挿入している。quiet.js無しでは
// 何も出来ない機能を、JS無効環境の常設HTMLに置きたくないため。ここではURLを
// 直接叩かれた場合の入口として、ページ自体は用意しておく(その場合でも
// <noscript>で明示する)。
function pchatRenderSonicChatPage(): void {
  $title = 'プシューチャット - 音波タブ(実験的)';
  $description = 'アカウント・招待コードを使わず、近くにいる相手とだけ音波(quiet.js)でメッセージをやり取りする使い捨ての会話。サーバーには何も保存されません。';
  ?>
<!DOCTYPE html>
<html lang="ja">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, user-scalable=yes, maximum-scale=6.0, minimum-scale=1.0" />
    <meta name="description" content="<?php echo pchatH($description); ?>" />
    <title><?php echo pchatH($title); ?></title>
    <link rel="shortcut icon" href="<?= pchatSiteBase('main') ?>/pusyuusystem/images/favicon.ico" />
    <?php /* フォント "pixel" は main の中のファイルなので、住所は台帳から決めます(style.css には書けないためここに置いています)。 */ ?>
    <style>@font-face { font-family: "pixel"; src: url("<?= pchatSiteBase('main') ?>/pusyuusystem/fonts/PixelMplus12-Regular.ttf") format("truetype"); font-display: swap; }</style>
    <link href="<?php echo pchatH(pchatAssetUrl('./assets/styles/style.css')); ?>" rel="stylesheet" />
    <meta name="theme-color" content="#009900" />
    <script>
      window.PusyuuPrivacyNoticeConfig = {
        title: "プライバシーに関するお知らせ",
        serviceName: "プシューチャット",
        closeButtonText: "同意する",
        hostsIntroText: "このページ読み込み時に検出した外部の通信先：",
        hostsNoneText: "このページ読み込み時には、外部への通信は検出されませんでした。",
        storageKey: "pusyuu_privacy_notice_dismissed",
        jsDependency: {
          level: "high",
          fallbackNote: "音波タブはJavaScriptが無いと何も表示・動作しません。"
        },
        triggerCloseButtonText: "閉じる"
      };
    </script>
    <script src="<?= pchatSiteBase('main') ?>/pusyuusystem/scripts/privacy_notice.js"></script>
    <!--
        *----------------------------------
        |  © 2021-2026 By ISAMI ABE     |
        |  LastUpdate: 2026-09-20       |
        |  License: MIT License         |
        |  PusyuuChat....              |
      ----------------------------------*
    -->
  </head>
  <body>
    <header>
      <h1>プシューチャット</h1>
    </header>
    <main>
      <noscript>
        <p class="form-message error">音波タブはJavaScriptが無いと使えません。ブラウザの設定でJavaScriptを有効にしてください。</p>
      </noscript>
      <nav class="mode-tabs">
        <a href="./?mode=meikee">メイキー</a>
        <a href="./?mode=anon">匿名</a>
        <span class="pchat-sonic-tab-link active">音波</span>
      </nav>
      <p class="lead">実験的機能です。アカウント・招待コードは使わず、近くにいる相手とスピーカー/マイクだけで直接メッセージをやり取りします。サーバーには何も保存されず、ページを閉じる/再読み込みすると会話は消えます。</p>
      <div data-pchat-sonic-app></div>
    </main>
    <script src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/audio-profiles.js')); ?>"></script>
    <script src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/vendor/quiet/quiet.js')); ?>"></script>
    <script src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/sonic-chat.js')); ?>"></script>
    <script async src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/vendor/quiet/quiet-emscripten.js')); ?>"></script>
  </body>
</html>
  <?php
}

// =====================================================================
// タブとactorの決定
// =====================================================================

$mode = (string)($_GET['mode'] ?? 'meikee');
if (!in_array($mode, ['anon', 'sonic'], true)) { $mode = 'meikee'; }

if ($mode === 'sonic') {
  pchatRenderSonicChatPage();
  exit;
}

$currentUser = pchatCurrentUser(); // ヘッダーのログイン/ログアウト表示にのみ使う
$actor = ($mode === 'anon') ? pchatAnonymousActor() : pchatMeikeeActor(); // meikeeタブ+未ログインならnull
$invitePreview = null; // invite_previewアクション成功時、確認画面の描画に使う


// push-sw.js自体の配信口。assets/scripts/配下の実ファイルをそのまま返しつつ、
// Service-Worker-Allowed: / をPHP側から付与することで、.htaccessのmod_headersに
// 依存せずscope: '/'を成立させる(サーバー設定・ファイル配置のどちらも変更しない)。
// ログイン状態は問わないため、actor解決より前に済ませてよい。
if (isset($_GET['push_sw'])) {
  header('Content-Type: text/javascript; charset=UTF-8');
  header('Service-Worker-Allowed: /');
  header('Cache-Control: no-cache');
  readfile('./assets/scripts/push-sw.js');
  exit;
}

// プッシュ通知のService Workerが push イベントで叩く、自分自身の受け口。
// 通常のページ描画より先に処理する(p-memoの ?download= と同じ「特殊GETパラメータの
// 早期分岐」パターン)。詳しくは main/pusyuusystem/apis/pusyuu_push/PUSH_INTEGRATION_SPEC.md 参照。
if (isset($_GET['push_inbox'])) {
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  if ($actor === null) {
    // ここでactorが取れないと通知は一切表示されない(SW側は空配列を受け取って何もしない)。
    // 「通知が来ない」の原因がこれ(セッション切れ・accounts側の疎通不良)である可能性を
    // 切り分けられるよう、必ずログへ残す。
    error_log('[p-chat push] push_inbox: actorを解決できませんでした(未ログイン、またはaccounts接続不良の可能性)。mode=' . $mode);
    echo json_encode(['items' => []]);
  } else {
    echo pusyuuPushInboxJson($actor['userid']);
  }
  exit;
}

// ナビゲーションバーの「会話」バッジ(assets/scripts/nav-badge-poll.js)が、会話一覧を
// 開いていない画面(設定・会話中など)にいる間もポーリングで拾うための軽量な受け口。
// ページ全体を再取得するより軽く、pchatSweepPendingRooms()等の副作用も伴わない。
if (isset($_GET['unread_count'])) {
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  echo json_encode(['total' => $actor !== null ? pchatTotalUnreadFor($actor) : 0]);
  exit;
}

/**
 * push-sw.jsの`pushsubscriptionchange`から直接叩かれる再購読の受け口。
 * ブラウザがこのイベントを発火させるのは、サイト自身のページが1つも開いて
 * いない状態(SW単体がバックグラウンドで起こされただけ)のことも普通にあり得る
 * ため、ページ側が持つCSRFトークンを前提にできない。ここは他サイトが
 * ブラウザに「肩代わりさせて」叩かせるCSRFの脅威モデル(=第三者が任意の
 * 内容を送り込める)には当てはまらない: subscriptionは本人のブラウザの
 * PushManagerが実際に発行した購読情報そのものであり、攻撃者が別サイトから
 * 任意の値を偽装しても本人のプッシュ購読としては成立しないため、CSRF
 * トークンを要求せずセッション(=$actor)の有無だけで許可する。
 */
if (isset($_GET['push_resubscribe'])) {
  header('Content-Type: application/json; charset=UTF-8');
  if ($actor === null) {
    echo json_encode(['ok' => false]);
    exit;
  }
  $raw = (string)file_get_contents('php://input');
  $data = json_decode($raw, true);
  $subscription = is_array($data) ? ($data['subscription'] ?? null) : null;
  if (!is_array($subscription)) {
    echo json_encode(['ok' => false]);
    exit;
  }
  $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
  $res = pusyuuPushSubscribe($actor['userid'], json_encode($subscription, JSON_UNESCAPED_UNICODE), $ua);
  echo json_encode(['ok' => !empty($res['ok'])]);
  exit;
}


// JS有効時のチャンク分割アップロード(assets/scripts/attach.js)が、送信を始める前に
// 安全なチャンクサイズを知るための受け口。ブラウザは常にp-chat自身としか
// 通信しない(p-5secondへ直接触れさせない)方針をここでも踏襲し、p-5second側の
// 上限はサーバー間で問い合わせる。詳しくは p-5second/P5S_INTEGRATION_SPEC.md 参照。
if (isset($_GET['attach_limits'])) {
  header('Content-Type: application/json; charset=UTF-8');

  $ownLimit = min(
    pchatIniBytes(ini_get('post_max_size') ?: '0'),
    pchatIniBytes(ini_get('upload_max_filesize') ?: '0')
  );

  $p5sRes = pchatP5sLimits();
  $p5sLimit = !empty($p5sRes['ok'])
    ? min((int)($p5sRes['post_max_size'] ?? 0), (int)($p5sRes['upload_max_filesize'] ?? 0))
    : 0;

  $safeLimit = ($p5sLimit > 0) ? min($ownLimit, $p5sLimit) : $ownLimit;

  if ($safeLimit <= 0) {
    // ini_get()が空/解釈不能だった場合、決め打ちの既定値へは逃げない
    // (それ自体が「べた書き」になり、実際のサーバー設定と食い違う原因になるため)。
    // エラーとして呼び出し側へ伝え、原因が分かるようにする。
    error_log('[p-chat attach] attach_limits: post_max_size/upload_max_filesizeを取得できませんでした。');
    echo json_encode(['ok' => false, 'error' => 'limits_unavailable', 'message' => 'サーバーのアップロード上限を取得できませんでした。']);
    exit;
  }

  // 固定の下限・上限(256KBや64MB等)は設けない。マルチパートの境界文字列・
  // 他のフィールド・HTTPヘッダーぶんのオーバーヘッドを見込んだ安全マージン
  // (index.php本体のクライアント側JSと同じ0.8倍)だけを掛けた、サーバーが
  // 実際に申告した値そのものを使う。これにより、サーバーの設定
  // (post_max_size/upload_max_filesize)がどんな値であっても、決め打ちの数値に
  // よる食い違いなく自動的に追従する。
  $chunkSize = (int)floor($safeLimit * 0.8);

  echo json_encode(['ok' => true, 'chunk_size' => $chunkSize, 'max_file_bytes' => 5 * 1024 * 1024 * 1024]);
  exit;
}

// =====================================================================
// POSTアクションの処理(すべて処理後にリダイレクトする、または
// invite_previewのようにその場でHTMLを描画する)
// =====================================================================

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  // post_max_size超過検知。超過するとPHPは$_POST/$_FILESを丸ごと空にしてしまうため、
  // 対策が無いと「何も送られてこなかった」ように見え、素通りしてそのまま通常描画に
  // 落ちてしまう(ユーザーからは成功も失敗も分からないまま戻るだけになる)。
  // p-5second/index.phpの「post_max_size超過検知」と同じ考え方で、リロードを跨いでも
  // 消えないセッションのフラッシュメッセージ(pchatFlash)に理由を残してから戻す。
  if (empty($_POST) && empty($_FILES)) {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMaxSize = ini_get('post_max_size') ?: '0';
    if ($contentLength > 0 && $contentLength > pchatIniBytes($postMaxSize)) {
      error_log('[p-chat] POSTサイズ超過: 送信 ' . $contentLength . ' bytes > post_max_size ' . $postMaxSize);
      // $_POSTが空なのでactionが読めず、通常のPRGアクションかfetch()専用アクションかを
      // ここでは判別できない。そのため呼び出し側(attach.jsのpostForm())に
      // X-Requested-Withヘッダーを付けてもらい、それで判別する
      // (無いとattach_chunk一発が上限を超えた稀なケースで、pchatRedirect()の
      // リダイレクト先HTMLをfetch()がそのまま受け取りJSONパースに失敗してしまう)。
      if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'payload_too_large', 'message' => "サーバーの上限({$postMaxSize})を超えています。"]);
        exit;
      }
      pchatFlash(
        '送信しようとした内容(約' . number_format($contentLength / 1024 / 1024, 1) . 'MB)が、'
        . 'サーバーの上限(' . $postMaxSize . ')を超えているため送信できませんでした。'
        . 'JSが有効なブラウザからであれば、添付ファイルは分割アップロードで大きいものも送れます。'
      );
      pchatRedirect('./?mode=' . $mode);
    }
  }

  if (($_POST['account_action'] ?? '') === 'logout') {
    pchatLogout();
    // p-chat自身のトークンを失効させただけでは、accounts自身のログイン状態が
    // 生きたままになる。次回「ログイン」を押したときに確認無しで再ログインさせない
    // よう、accountsのログアウト画面を一瞬経由してから戻る。
    pchatRedirect(PchatAccount::logoutUrl());
  }

  $postAction = (string)($_POST['action'] ?? '');

  // fetch()専用(ページ遷移を伴わない)アクション。ここで認証/CSRF失敗を
  // pchatRedirect()してしまうと、fetch()がリダイレクト先のHTMLページを
  // そのまま本文として受け取ってしまい、呼び出し側でJSONとしてパースしようとして
  // 「Unexpected token '<'」のようなエラーになる(実際に発生した不具合)。
  // そのため、これらのアクションだけは認証/CSRF失敗時もJSONで応答する。
  $ajaxOnlyActions = ['mark_read_ping', 'attach_chunk', 'attach_finalize', 'push_subscribe', 'push_unsubscribe', 'notify_channel', 'typing_ping', 'typing_status'];
  $isAjaxAction = in_array($postAction, $ajaxOnlyActions, true);

  if ($postAction !== '' && $actor === null) {
    if ($isAjaxAction) {
      header('Content-Type: application/json; charset=UTF-8');
      http_response_code(401);
      echo json_encode(['ok' => false, 'error' => 'unauthorized', 'message' => 'ログインが必要です。ページを再読み込みしてください。']);
      exit;
    }
    pchatFlash('ログインが必要です。');
    pchatRedirect('./?mode=' . $mode);
  }

  if ($postAction !== '' && !pchatCsrfIsValid()) {
    if ($isAjaxAction) {
      header('Content-Type: application/json; charset=UTF-8');
      http_response_code(403);
      echo json_encode(['ok' => false, 'error' => 'invalid_csrf', 'message' => 'ページの有効期限が切れました。再読み込みしてもう一度お試しください。']);
      exit;
    }
    pchatFlash('ページの有効期限が切れました。再読み込みしてもう一度お試しください。');
    pchatRedirect('./?mode=' . $mode);
  }

  switch ($postAction) {
    case 'invite_create': {
      $result = pchatCreateInvite($actor);
      if (!$result['ok']) {
        pchatFlash($result['error']);
        pchatRedirect('./?mode=' . $mode);
      }
      pchatRedirect('./?mode=' . $mode . '&room=' . urlencode($result['room_id']));
      break;
    }

    case 'invite_direct_create': {
      $username = (string)($_POST['username'] ?? '');
      $result = pchatCreateDirectInvite($actor, $username);
      if (!$result['ok']) {
        pchatFlash($result['error']);
        pchatRedirect('./?mode=' . $mode);
      }
      pchatFlash('招待を送りました。相手が承諾すると会話が始まります。');
      pchatRedirect('./?mode=' . $mode . '&room=' . urlencode($result['room_id']));
      break;
    }

    case 'invite_preview': {
      $code = (string)($_POST['code'] ?? '');
      $invitePreview = pchatPreviewInvite($code, $actor);
      // リダイレクトせず、このまま下の通常ページ描画で確認画面/エラーを表示する
      // (招待トークンをURLに露出させないため)。
      break;
    }

    case 'invite_accept': {
      $code = (string)($_POST['code'] ?? '');
      $result = pchatAcceptInvite($actor, $code);
      if (!$result['ok']) {
        pchatFlash($result['error']);
        pchatRedirect('./?mode=' . $mode);
      }
      pchatFlash('会話を開始しました。');
      pchatRedirect('./?mode=' . $mode . '&room=' . urlencode($result['room_id']));
      break;
    }

    case 'accept_direct': {
      $roomId = (string)($_POST['room'] ?? '');
      $result = pchatAcceptDirectInvite($actor, $roomId);
      if (!$result['ok']) {
        pchatFlash($result['error']);
        pchatRedirect('./?mode=' . $mode . '#pchat-window-rooms');
      }
      pchatFlash('会話を開始しました。');
      pchatRedirect('./?mode=' . $mode . '&room=' . urlencode($result['room_id']));
      break;
    }

    case 'attach_upload': {
      $roomId = (string)($_POST['room'] ?? '');
      $room = pchatRoomForMember($roomId, $actor['userid']);
      if ($room === null || ($room['status'] ?? '') !== 'active') {
        pchatFlash('この会話にアクセスする権限がありません。');
        pchatRedirect('./?mode=' . $mode);
      }
      if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        pchatFlash('添付するファイルを選択してください。');
        pchatRedirect('./?mode=' . $mode . '&room=' . urlencode($roomId));
      }
      $fileName = (string)$_FILES['file']['name'];
      $mime = (string)($_FILES['file']['type'] !== '' ? $_FILES['file']['type'] : 'application/octet-stream');
      $ttl = (int)($_POST['ttl'] ?? 2592000);
      $res = pchatP5sUpload($_FILES['file']['tmp_name'], $fileName, $mime, $ttl);
      if (empty($res['ok']) || empty($res['url'])) {
        pchatFlash('ファイルの添付に失敗しました。' . (isset($res['message']) ? (string)$res['message'] : 'しばらくしてからもう一度お試しください。'));
        pchatRedirect('./?mode=' . $mode . '&room=' . urlencode($roomId));
      }
      // 画像なら、(1) p-5second自身に「ブラウザでそのまま表示させてよい」と伝える
      // ?disposition=inline(p-5second側で安全なラスター画像形式のみ許可する
      // ホワイトリストと突き合わせる。付けなければ従来通りattachment=強制ダウンロード
      // のままなので、<img>タグでは表示されない)と、(2) メッセージ本文中で
      // pchatLinkify()がインライン画像として描画すべき目印(PCHAT_IMAGE_URL_MARKER。
      // フラグメントなのでp-5second自体への実際のリクエストには含まれず無関係)を
      // 付けておく。
      $shareUrl = (string)$res['url'];
      if (preg_match('#^image/#i', $mime)) {
        $shareUrl .= '&disposition=inline' . PCHAT_IMAGE_URL_MARKER;
      }
      pchatRedirect('./?mode=' . $mode . '&room=' . urlencode($roomId) . '&p5s_url=' . urlencode($shareUrl));
      break;
    }

    // JS有効時のチャンク分割アップロード用。ページ遷移を伴わず、fetch()から
    // 呼ばれてJSONを返すだけの版(mark_read_pingと同じ考え方)。ブラウザから
    // 受け取ったチャンクをその場でp-5secondへ中継するだけで、p-chat自身の
    // ディスクには保存しない。
    case 'attach_chunk': {
      header('Content-Type: application/json; charset=UTF-8');
      $roomId = (string)($_POST['room'] ?? '');
      $room = pchatRoomForMember($roomId, $actor['userid']);
      if ($room === null || ($room['status'] ?? '') !== 'active') {
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
      }
      if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        $filesErr = isset($_FILES['chunk']) ? (string)$_FILES['chunk']['error'] : '(未受信)';
        error_log('[p-chat attach] attach_chunk: チャンクファイルを受信できませんでした。$_FILES[chunk][error]=' . $filesErr . ' POSTキー=[' . implode(',', array_keys($_POST)) . ']');
        echo json_encode(['ok' => false, 'error' => 'no_chunk_file', 'message' => 'チャンクファイルを受信できませんでした(サーバー側エラーコード: ' . $filesErr . ')。']);
        exit;
      }
      $uploadId = (string)($_POST['uploadId'] ?? '');
      $index = (int)($_POST['index'] ?? -1);
      if ($uploadId === '' || $index < 0) {
        error_log('[p-chat attach] attach_chunk: uploadId/indexが不正です。uploadId="' . $uploadId . '" index=' . $index);
        echo json_encode(['ok' => false, 'error' => 'missing_chunk_params', 'message' => 'uploadId・indexが正しく送られていません(uploadId="' . $uploadId . '", index=' . $index . ')。']);
        exit;
      }
      echo json_encode(pchatP5sChunk($uploadId, $index, $_FILES['chunk']['tmp_name']));
      exit;
    }

    case 'attach_finalize': {
      header('Content-Type: application/json; charset=UTF-8');
      $roomId = (string)($_POST['room'] ?? '');
      $room = pchatRoomForMember($roomId, $actor['userid']);
      if ($room === null || ($room['status'] ?? '') !== 'active') {
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
      }
      $uploadId = (string)($_POST['uploadId'] ?? '');
      $totalChunks = (int)($_POST['totalChunks'] ?? 0);
      $fileName = (string)($_POST['name'] ?? 'file');
      $mime = (string)($_POST['mime'] ?? 'application/octet-stream');
      $ttl = (int)($_POST['ttl'] ?? 2592000);
      if ($uploadId === '' || $totalChunks <= 0) {
        error_log('[p-chat attach] attach_finalize: uploadId/totalChunksが不正です。uploadId="' . $uploadId . '" totalChunks=' . $totalChunks);
        echo json_encode(['ok' => false, 'error' => 'missing_finalize_params', 'message' => 'uploadId・totalChunksが正しく送られていません(uploadId="' . $uploadId . '", totalChunks=' . $totalChunks . ')。']);
        exit;
      }
      $res = pchatP5sFinalize($uploadId, $totalChunks, $fileName, $mime, $ttl);
      if (empty($res['ok']) || empty($res['url'])) {
        echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'unknown', 'message' => $res['message'] ?? '']);
        exit;
      }
      // attach_uploadと同じロジック: 画像なら「ブラウザでそのまま表示してよい」
      // (?disposition=inline)と、pchatLinkify()向けのインライン画像マーカー
      // (PCHAT_IMAGE_URL_MARKER)を付ける。以前はここに無く、チャンク分割
      // アップロード(JS有効時の通常経路)経由の画像だけプレビューされなかった。
      $shareUrl = (string)$res['url'];
      if (preg_match('#^image/#i', $mime)) {
        $shareUrl .= '&disposition=inline' . PCHAT_IMAGE_URL_MARKER;
      }
      // JS側(attach.js)はurlを直接本文へ挿入し、ページ遷移(リロード)を伴わずに完結させる。
      // redirect_urlはJSが使えない/失敗した場合の保険としてだけ残す。
      echo json_encode([
        'ok' => true,
        'url' => $shareUrl,
        'expires_at' => $res['expires_at'] ?? null,
        'redirect_url' => './?mode=' . $mode . '&room=' . urlencode($roomId) . '&p5s_url=' . urlencode($shareUrl),
      ]);
      exit;
    }

    case 'send': {
      $roomId = (string)($_POST['room'] ?? '');
      $body = trim((string)($_POST['body'] ?? ''));
      $upToSeq = max(0, (int)($_POST['up_to_seq'] ?? 0));
      $replyTo = max(0, (int)($_POST['reply_to'] ?? 0));
      $myId = $actor['userid'];

      if ($body === '') {
        pchatFlash('メッセージを入力してください。');
        pchatRedirect('./?mode=' . $mode . '&room=' . urlencode($roomId));
      }
      if (mb_strlen($body) > 4000) {
        pchatFlash('メッセージは4000文字以内にしてください。');
        pchatRedirect('./?mode=' . $mode . '&room=' . urlencode($roomId));
      }
      $room = pchatRoomForMember($roomId, $myId);
      if ($room === null || ($room['status'] ?? '') !== 'active') {
        pchatFlash('この会話にアクセスする権限がありません。');
        pchatRedirect('./?mode=' . $mode);
      }
      $rawKey = ensureChatKey();
      if ($rawKey === null) {
        pchatFlash('サーバー側の暗号化鍵を準備できませんでした。');
        pchatRedirect('./?mode=' . $mode . '&room=' . urlencode($roomId));
      }
      // 返信先は実在するseqのみ受け付ける(他room由来の値や、シークレットモードで
      // 既に消えたメッセージへの偽装参照を防ぐため)。存在しなければ通常送信として扱う
      // (エラーにはしない。返信先が消えていただけで、本文自体は有効なため)。
      if ($replyTo > 0 && !pchatMessageSeqExists($roomId, $replyTo)) {
        $replyTo = 0;
      }
      $row = pchatAppendMessage($roomId, $myId, $body, $rawKey, $replyTo > 0 ? $replyTo : null);
      if ($row === null) {
        pchatFlash('送信に失敗しました。');
        pchatRedirect('./?mode=' . $mode . '&room=' . urlencode($roomId));
      }
      pchatUpdateRoomAfterActivity($roomId, $actor, $row, $upToSeq);
      if (!empty($room['secret'])) { pchatPurgeSecretMessages($roomId); }
      $opponent = pchatOpponentInfo($room, $myId);
      if ($opponent !== null && !empty($opponent['userid'])) {
        pusyuuPushSend(
          $opponent['userid'],
          ($actor['name'] ?? '相手') . 'さんから新着メッセージ',
          mb_substr($body, 0, 100),
          pchatCurrentUrl() . '&room=' . urlencode($roomId),
          'pchat-room-' . $roomId,
          $actor['avatar_url'] ?? null // 匿名actorや未設定時はnull(呼び出し先で既定アイコンにfallback)
        );
      } else {
        error_log('[p-chat push] send: 相手のuseridを解決できなかったため通知を送っていません。room=' . $roomId);
      }
      pchatRedirect('./?mode=' . $mode . '&room=' . urlencode($roomId));
      break;
    }

    // 会話画面の「一覧に戻る(既読を記録)」ボタン。設定の「既読を相手に伝える」が
    // OFFでも、この明示的なボタン操作だけは必ず既読を記録する(forceRead=true。
    // 理由はpchatUpdateRoomAfterActivity()のコメント参照)。
    case 'mark_read': {
      $roomId = (string)($_POST['room'] ?? '');
      $upToSeq = max(0, (int)($_POST['up_to_seq'] ?? 0));
      $room = pchatRoomForMember($roomId, $actor['userid']);
      if ($room !== null) {
        pchatUpdateRoomAfterActivity($roomId, $actor, null, $upToSeq, true);
      }
      pchatRedirect('./?mode=' . $mode . '#pchat-window-rooms');
      break;
    }

    // chat.jsが裏で送るping専用。mark_readと処理内容は同じだが、ページ遷移
    // (PRGのリダイレクト)を伴わず、fetch()から呼ばれる短い応答を返すだけの版。
    case 'mark_read_ping': {
      $roomId = (string)($_POST['room'] ?? '');
      $upToSeq = max(0, (int)($_POST['up_to_seq'] ?? 0));
      $room = pchatRoomForMember($roomId, $actor['userid']);
      if ($room !== null) {
        pchatUpdateRoomAfterActivity($roomId, $actor, null, $upToSeq);
      }
      http_response_code(204);
      exit;
    }

    // 「入力中…」表示用。chat.jsが本文入力のたびに(数秒おきへ間引いて)呼ぶ。
    case 'typing_ping': {
      $roomId = (string)($_POST['room'] ?? '');
      $room = pchatRoomForMember($roomId, $actor['userid']);
      if ($room !== null && ($room['status'] ?? '') === 'active') {
        pchatSetTyping($roomId, $actor['userid']);
      }
      http_response_code(204);
      exit;
    }

    // 「相手が入力中か」の確認。GETのクエリパラメータ(room=<id>)だと、
    // アクセスログやブラウザ履歴に「いつ・どの会話の入力状況を覗いたか」が
    // 平文で残ってしまうため、他のポーリング系(mark_read_ping・typing_ping)と
    // 同じPOST(+CSRF)の受け口にしている。応答も画面に残さないようキャッシュ禁止にする。
    case 'typing_status': {
      header('Cache-Control: no-store');
      $roomId = (string)($_POST['room'] ?? '');
      $room = pchatRoomForMember($roomId, $actor['userid']);
      $typing = ($room !== null) && pchatIsAnyoneElseTyping($roomId, $actor['userid']);
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode(['typing' => $typing]);
      exit;
    }

    case 'delete_room': {
      $roomId = (string)($_POST['room'] ?? '');
      pchatDeleteRoom($roomId, $actor['userid']);
      pchatFlash('削除しました。再度話すには、もう一度招待のやり取りが必要です。');
      pchatRedirect('./?mode=' . $mode . '#pchat-window-rooms');
      break;
    }

    // 設定画面の「すべてのチャット履歴と連絡先を削除」。破壊的操作かつ
    // JS無しでも安全に確認できるよう、確認ボタン→本当の削除ボタンの2段階に
    // する(JSのconfirm()には依存しない、実際のページ遷移による確認)。
    // 1段階目: 確認フラグをセッションへ立てるだけで、まだ何も削除しない。
    case 'delete_all_rooms_confirm': {
      $_SESSION['pchat_confirm_delete_all_rooms'] = time() + 300; // 5分だけ有効
      pchatRedirect('./?mode=' . $mode . '#pchat-window-settings');
      break;
    }

    case 'delete_all_rooms_cancel': {
      unset($_SESSION['pchat_confirm_delete_all_rooms']);
      pchatRedirect('./?mode=' . $mode . '#pchat-window-settings');
      break;
    }

    // 2段階目: 直前にconfirmを踏んでいる場合のみ実際に削除する。連絡先(=会話の
    // 相手との紐付き)ごと消えるため、自分が関わっているすべての会話を
    // delete_roomと同じ処理(pchatDeleteRoom)でまとめて削除する。
    case 'delete_all_rooms': {
      $confirmedUntil = (int)($_SESSION['pchat_confirm_delete_all_rooms'] ?? 0);
      unset($_SESSION['pchat_confirm_delete_all_rooms']);
      if ($confirmedUntil < time()) {
        pchatFlash('確認の有効期限が切れました。もう一度お試しください。');
        pchatRedirect('./?mode=' . $mode . '#pchat-window-settings');
        break;
      }
      $deleted = 0;
      foreach (pchatReadRooms() as $r) {
        if (pchatRoomAccessibleBy($r, $actor['userid']) && pchatDeleteRoom((string)$r['room_id'], $actor['userid'])) {
          $deleted++;
        }
      }
      pchatFlash($deleted > 0 ? 'すべてのチャット履歴と連絡先を削除しました(' . $deleted . '件)。' : '削除できる会話履歴はありませんでした。');
      pchatRedirect('./?mode=' . $mode . '#pchat-window-settings');
      break;
    }

    case 'toggle_secret': {
      $roomId = (string)($_POST['room'] ?? '');
      $newVal = ($_POST['secret'] ?? '') === '1';
      pchatSetSecret($roomId, $actor['userid'], $newVal);
      pchatFlash($newVal ? 'シークレットモードにしました。メッセージは一定時間で自動的に消えます。' : 'シークレットモードを解除しました。');
      pchatRedirect('./?mode=' . $mode . '&room=' . urlencode($roomId));
      break;
    }

    // 絵文字リアクション。同じ絵文字をもう一度押すとトグルで解除する
    // (JS不要のPRGフォームだけで完結。#pchat-msg-<seq>へ戻すことで、反応した
    // メッセージの近くのまま戻れるようにする)。
    case 'react': {
      $roomId = (string)($_POST['room'] ?? '');
      $targetSeq = (int)($_POST['target_seq'] ?? 0);
      $emoji = (string)($_POST['emoji'] ?? '');
      $room = pchatRoomForMember($roomId, $actor['userid']);
      if (
        $room !== null && ($room['status'] ?? '') === 'active'
        && $targetSeq > 0 && in_array($emoji, PCHAT_REACTION_EMOJI, true)
        && pchatMessageSeqExists($roomId, $targetSeq)
      ) {
        $rawKey = ensureChatKey();
        if ($rawKey !== null) {
          $current = pchatReadReactions($roomId, $rawKey);
          $myCurrentEmoji = null;
          foreach (($current[$targetSeq] ?? []) as $r) {
            if ($r['sender'] === $actor['userid']) { $myCurrentEmoji = $r['emoji']; break; }
          }
          $finalEmoji = ($myCurrentEmoji === $emoji) ? '' : $emoji;
          pchatAppendReaction($roomId, $actor['userid'], $targetSeq, $finalEmoji, $rawKey);
        }
      }
      pchatRedirect('./?mode=' . $mode . '&room=' . urlencode($roomId) . '#pchat-msg-' . $targetSeq);
      break;
    }

    case 'set_read_receipts': {
      $enabled = ($_POST['enabled'] ?? '') === '1';
      if (pchatSetReadReceiptsEnabled($actor, $enabled)) {
        pchatFlash($enabled ? '既読を相手に伝えるようにしました。' : '既読を相手に伝えないようにしました。');
      } else {
        pchatFlash('設定の保存に失敗しました。プシューメイキィに接続できなかった可能性があります。');
      }
      pchatRedirect('./?mode=' . $mode . '#pchat-window-settings');
      break;
    }

    // プッシュ通知の購読登録/解除。JSから直接fetch()で呼ばれる(mark_read_pingと同じ、
    // ページ遷移を伴わない版)。
    case 'push_subscribe': {
      $subscriptionJson = (string)($_POST['subscription'] ?? '');
      $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
      $res = pusyuuPushSubscribe($actor['userid'], $subscriptionJson, $ua);
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode(['ok' => !empty($res['ok'])]);
      exit;
    }

    case 'push_unsubscribe': {
      $endpoint = (string)($_POST['endpoint'] ?? '');
      $res = pusyuuPushUnsubscribe($actor['userid'], $endpoint);
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode(['ok' => !empty($res['ok'])]);
      exit;
    }

    // 通知チャンネル(デスクトップ通知の出す/出さない等)の切り替え。
    // push_subscribeと同じく、JSから直接fetch()で呼ばれる。
    case 'notify_channel': {
      $channel = (string)($_POST['channel'] ?? '');
      $state   = (string)($_POST['state'] ?? '');
      $res = pusyuuPushSetChannel($actor['userid'], $channel, $state);
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode(['ok' => !empty($res['ok'])]);
      exit;
    }

    // RSS設定画面へ送り出すだけのアクション。押されたその場でチケットを発行し、
    // pusyuu_push側のページへ転送する(URLをHTMLに残さないため。理由の詳細は
    // pusyuuPushSettingsUrl()のコメント)。
    //
    // ただのフォーム送信なので、$ajaxOnlyActionsには入れないこと
    // (入れるとJS無効環境で押せなくなる)。
    case 'notify_settings': {
      $url = pusyuuPushSettingsUrl($actor['userid']);
      if ($url === '') {
        // 通知APIへ届かないときは黙って設定タブへ戻す。何も起きないと
        // 押した人には区別がつかないので、理由を必ず1行出す。
        pchatFlash('通知設定を開けませんでした。しばらく待ってからもう一度お試しください。');
        pchatRedirect('./?mode=' . $mode . '#pchat-notification-settings');
      }
      pchatRedirect($url);
      break;
    }
  }
}

// ログインの往復(メイキィへ行って戻ってくるまで)
//
// 手順そのものは全プロダクト共通なので、共有スクリプトに任せます。
// p-chat固有なのは次の3点だけで、それぞれ options として渡しています。
//
//   1. 無音ログインを試す条件が独特
//        匿名タブ(mode!=='meikee')では試さない。メイキィへ届かないときも試さない。
//        一度もログインしたことが無いブラウザ(pchat_meikee_seen Cookie無し)では
//        毎回往復しても無駄なので試さない。pusyuu_silentが既に付いているときは、
//        メイキィが直前に「未ログイン」と答えた直後なので素通りする。
//   2. 失敗を独自のフラッシュ表示で見せる
//   3. リダイレクトに pchatRedirect() を使う(戻り先から p5s_url を落とすため)
//
// 【往復を自前で書き直さないこと】
// 以前ここには、共通クラスと同じ手順を丸ごと写したものが並んでいました。当時は
// 「共通クラスは全プロダクトで一字一句同じ」という決まりだったため、上の3点を
// あちらへ入れることも、あちらを使うこともできなかったからです。しかしその結果、
// **引き渡しコードを交換する前の照合**が pips・p-drive・p-chat・p-reversi の
// 4箇所に手書きで複製されていました。あの照合は、コードを盗んだ第三者が自分の
// ブラウザでこのページを開くだけで、こちらが代わりに交換して盗んだ側へ被害者の
// トークンを渡してしまうのを防ぐ、唯一の砦です。1箇所でも書き忘れれば穴が開き、
// しかも普段は正常に動くので誰も気づけません。固有の事情は options で表現できます。
if (function_exists('pusyuuAccountHandleSignIn')) {
  pusyuuAccountHandleSignIn(
    static function (): bool {
      return pchatAccountsToken() !== '';
    },
    static function (array $user, string $token): void {
      PchatSession::regenerateId(); // ログイン=権限昇格。セッション固定化を防ぐ
      $_SESSION['pusyuu_accounts_token'] = $token;
      // 「この端末は過去にメイキーへログインしたことがある」というだけの目印。
      // 認証情報は一切含まない(下の無音ログインを、一度もログインしていない
      // 訪問者にまで毎回試みて無駄な往復を発生させないためだけに使う)。
      setcookie('pchat_meikee_seen', '1', [
        'expires' => time() + 365 * 86400, 'path' => '/', 'secure' => true, 'httponly' => false, 'samesite' => 'Lax',
      ]);
    },
    [
      'silent' => static function () use ($mode, $currentUser): bool {
        // 無音サインインを試してよいのは、この5つをすべて抜けたときだけです。
        if ($mode !== 'meikee') {
          $try = false; // 匿名タブでは往復しない
        } else if ($currentUser !== null) {
          $try = false; // 既にログイン済み
        } else if (pchatAccountsUnreachable()) {
          $try = false; // メイキィの状態を確認できていない
        } else if (isset($_GET['pusyuu_silent'])) {
          $try = false; // 直前に「未ログイン」と答えられた直後
        } else if (empty($_COOKIE['pchat_meikee_seen'])) {
          $try = false; // 一度もログインしたことが無いブラウザ
        } else {
          $try = true;
        }

        return $try;
      },
      'onFailure' => static function (string $message): void {
        // 共有側は理由も残しますが、p-chatは元々フラッシュで見せる作りなので
        // そちらへも流します。理由が具体的になったぶん、以前の決め打ち文言より
        // 何が起きたのか分かるようになっています。
        pchatFlash('ログインに失敗しました。' . $message);
      },
      'redirect' => static function (string $url): void {
        // 【p5s_url を落とすこと】p-chatの戻り先は pchatCurrentUrl() が作る
        // 「p5s_url を外したURL」でなければなりません。付いたまま戻ると、ページを
        // 開き直すたびに同じ一時ファイルの取り込みが走ります。
        //
        // ただし行き先がメイキィのログイン画面の場合は組み直してはいけません。
        // あちらのURLは return_to をURLエンコードして抱えており、クエリを分解して
        // 組み直すと二重エンコード等で戻り先が壊れます。そこで「このURLの一番外側に
        // p5s_url が付いているときだけ」外します。
        $parts = explode('?', $url, 2);
        if (count($parts) === 2) {
          $query = [];
          parse_str($parts[1], $query);
          if (array_key_exists('p5s_url', $query)) {
            unset($query['p5s_url']);
            $qs = http_build_query($query);
            $url = $parts[0] . ($qs !== '' ? '?' . $qs : '');
          }
        }
        pchatRedirect($url);
      },
    ]
  );
}

// ログインに失敗していた場合、上の onFailure がフラッシュへ積んでいます。
// ここでは共有側の控えを読み捨てて、同じ内容が二重に出ないようにします。
PchatAccount::takeSignInError();

pchatSweepPendingRooms();
pchatRunDailyMaintenanceIfNeeded();

$csrfToken = pchatCsrfToken();
$flashes = pchatTakeFlashes();
$viewRoomId = (string)($_GET['room'] ?? '');
$readReceiptsEnabled = $actor !== null ? pchatReadReceiptsEnabled($actor) : false;
$isLite = pchatIsLite();
$incomingFileShareUrl = pchatIncomingFileShareUrl();

// 画面内容を組み立てる(actorが無い=meikeeタブ未ログインなら何もしない、ログイン導線のみ表示する)。
// 招待/会話一覧/既読設定はtoolboxと同じCSS `:target`のフルスクリーン「ウィンドウ」方式にした
// ため(JS不要、URLのフラグメントだけで開閉する)、ページ遷移(?view=...)は使わず、
// ハブを表示するすべてのリクエストで内容を用意しておく(どのウィンドウが開くかはCSS側で
// フラグメントを見て決まり、サーバー側では区別しない)。
// [target_seq => [['sender'=>...,'emoji'=>...], ...]]
if ($actor === null) {
  $viewRoom = null;
  $viewMessages = [];
  $viewRoomList = [];
  $viewReactions = [];
} elseif ($viewRoomId !== '') {
  $viewRoom = pchatRoomForMember($viewRoomId, $actor['userid']);
  $viewRoomList = [];
  if ($viewRoom !== null && ($viewRoom['status'] ?? '') === 'active') {
    pchatMarkRoomViewed($viewRoom, $actor['userid']);
    if (!empty($viewRoom['secret'])) {
      pchatPurgeSecretMessages($viewRoomId);
    }
    $rawKey = ensureChatKey();
    if ($rawKey !== null) {
      $viewMessages = pchatReadAllMessages($viewRoomId, $rawKey);
      $viewReactions = pchatReadReactions($viewRoomId, $rawKey);
    } else {
      $viewMessages = [];
      $viewReactions = [];
    }
  } else {
    $viewMessages = [];
    $viewReactions = [];
  }
} else {
  $viewRoom = null;
  $viewReactions = [];
  $viewMessages = [];
  $rooms = pchatReadRooms();
  $viewRoomList = [];
  foreach ($rooms as $r) {
    if (pchatRoomAccessibleBy($r, $actor['userid'])) {
      $viewRoomList[] = $r;
    }
  }
  usort($viewRoomList, static fn($a, $b) => ($b['last_message_at'] ?? $b['created'] ?? 0) <=> ($a['last_message_at'] ?? $a['created'] ?? 0));
}

// 会話内検索(?q=キーワード)。$viewMessagesは返信の引用先ルックアップ
// (messagesBySeq)のため常に全件のまま保持し、実際に画面へ並べる方だけを
// $viewMessagesDisplayとして別に持つ(検索結果に「返信先」だけが写っていない
// メッセージがあっても、引用プレビュー自体は正しく解決できるようにするため)。
$searchQuery = trim((string)($_GET['q'] ?? ''));
$viewMessagesDisplay = $viewMessages;
if ($searchQuery !== '') {
  $viewMessagesDisplay = array_values(array_filter($viewMessages, static function (array $m) use ($searchQuery): bool {
    return mb_stripos($m['body'], $searchQuery) !== false;
  }));
}

// ナビゲーションバーの「会話」アイコンに出す合計未読件数。
$totalUnread = ($actor !== null) ? pchatTotalUnreadFor($actor) : 0;

$title = 'プシューチャット - 招待コードで繋がる相手とだけ話せるチャット';
$description = 'プシューメイキィのアカウント(メイキータブ)またはアカウント不要の匿名タブで、招待のハンドシェイクを経た相手とだけ1対1でチャットできます。会話ごとにシークレットモードも選べます。';

// =====================================================================
// 簡易表示(?lite=1)本体。
//
// pips/index.phpの軽量版と同じ考え方: 通常版と完全に別の最小限のHTMLを
// ここで出力してexitする。actor解決・room読み書き等のロジックは上ですべて
// 計算済みのものをそのまま再利用し、ここでは「見た目」だけを素のHTMLに
// 差し替える。style.css(CSS変数・:has()等IE等では解釈できない記法に依存)も
// privacy_notice.js等の外部スクリプトも一切読み込まない。
// 招待/会話一覧/設定/プロダクトは、通常版のようなCSS `:target`のフルスクリーン
// 「ウィンドウ」方式にはせず、1枚のページに上から順に並べるだけにする
// (JS・CSSどちらにも依存しないナビゲーション)。
// セッションやCookie自体の扱いが怪しい古いブラウザでも表示のたびに素の状態へ
// 戻ってしまわないよう、ページ内のリンク・フォームには常に明示的にlite=1を
// 付けて遷移する(pchatIsLite()のセッションフォールバックだけに頼らない)。
// =====================================================================
if ($isLite) {
  $liteUrl = static function (array $params = []) use ($mode): string {
    return './?' . http_build_query(array_merge(['mode' => $mode, 'lite' => '1'], $params));
  };
  $liteAction = pchatH($liteUrl());
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8" />
<title><?php echo pchatH($title); ?></title>
<meta name="description" content="<?php echo pchatH($description); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<!--
    *----------------------------------
    |  © 2021-2026 By ISAMI ABE     |
    |  LastUpdate: 2026-09-20       |
    |  License: MIT License         |
    |  PusyuuChat....              |
  ----------------------------------*
-->
</head>
<body>
<h1>プシューチャット(簡易表示)</h1>
<p><a href="<?php echo pchatH(pchatToggleLiteUrl($isLite)); ?>">通常表示で見る</a></p>

<?php foreach ($flashes as $flash): ?>
<p><?php echo pchatH($flash); ?></p>
<?php endforeach; ?>

<hr />
<p>
<?php if ($mode === 'meikee'): ?>メイキー<?php else: ?><a href="<?php echo pchatH($liteUrl(['mode' => 'meikee'])); ?>">メイキー</a><?php endif; ?>
 |
<?php if ($mode === 'anon'): ?>匿名<?php else: ?><a href="<?php echo pchatH($liteUrl(['mode' => 'anon'])); ?>">匿名</a><?php endif; ?>
</p>

<?php if ($actor === null): ?>
<hr />
<?php if (pchatAccountsUnreachable() || !PchatAccount::ready()): ?>
<p>プシューメイキィに接続できないため、ログイン状態を確認できませんでした。しばらくしてから再読み込みしてお試しください。<?php echo pchatH(PchatAccount::unavailableReason()); ?> ログインせずに使いたい場合は匿名タブをどうぞ。</p>
<?php else: ?>
<p><a href="<?php echo pchatH(PchatAccount::loginUrl()); ?>">ログイン</a></p>
<p>プシューメイキィをお持ちでない場合は<a href="<?php echo pchatH(PchatAccount::createUrl()); ?>">こちらで作成</a>してください。ログインせずに使いたい場合は<a href="<?php echo pchatH($liteUrl(['mode' => 'anon'])); ?>">匿名タブ</a>をどうぞ。</p>
<?php endif; ?>

<?php elseif ($invitePreview !== null): ?>
<hr />
<h2>招待コードの確認</h2>
<?php if ($invitePreview['ok']): ?>
<p><strong><?php echo pchatH($invitePreview['inviter']['name'] ?? '(不明なユーザー)'); ?></strong><?php if (!empty($invitePreview['inviter']['username'])): ?> (@<?php echo pchatH($invitePreview['inviter']['username']); ?>)<?php endif; ?> さんから届いた招待コードです。</p>
<p>この操作を行うと、このコードを渡してきた相手と直接メッセージのやり取りができるようになります。本当にこの相手を信頼できますか?よく知らない相手や、正当な経路以外で受け取ったコードは追加しないでください。一度追加した後で削除した場合、再び話すには最初から招待のやり取りをやり直す必要があります。</p>
<form method="post" action="<?php echo $liteAction; ?>">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="invite_accept" />
  <input type="hidden" name="code" value="<?php echo pchatH($invitePreview['code']); ?>" />
  <button type="submit">信頼して追加する</button>
</form>
<p><a href="<?php echo pchatH($liteUrl()); ?>">キャンセルして戻る</a></p>
<?php else: ?>
<p><?php echo pchatH($invitePreview['error']); ?></p>
<p><a href="<?php echo pchatH($liteUrl()); ?>">戻る</a></p>
<?php endif; ?>

<?php elseif ($viewRoomId !== '' && $viewRoom !== null && ($viewRoom['status'] ?? '') === 'active'): ?>
<?php
  $opponent = pchatOpponentInfo($viewRoom, $actor['userid']);
  $opponentReadSeq = (int)($viewRoom['read_seq'][$opponent['userid'] ?? ''] ?? 0);
  $lastSeq = (int)($viewRoom['last_seq'] ?? 0);
  $isSecret = !empty($viewRoom['secret']);
  $messagesBySeq = [];
  foreach ($viewMessages as $vm) { $messagesBySeq[$vm['seq']] = $vm; }
  $replyToSeq = max(0, (int)($_GET['reply_to'] ?? 0));
  $replyToMsg = $replyToSeq > 0 ? ($messagesBySeq[$replyToSeq] ?? null) : null;
?>
<hr />
<p><a href="<?php echo pchatH($liteUrl()); ?>#rooms">← 会話一覧に戻る</a></p>
<h2><?php echo pchatH($opponent['name'] ?? $opponent['username'] ?? '(不明なユーザー)'); ?> さんとの会話<?php if ($isSecret): ?> (🔒 シークレット)<?php endif; ?></h2>

<form method="get" action="./">
  <input type="hidden" name="mode" value="<?php echo pchatH($mode); ?>" />
  <input type="hidden" name="lite" value="1" />
  <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
  <input type="text" name="q" value="<?php echo pchatH($searchQuery); ?>" placeholder="会話内を検索" />
  <button type="submit">検索</button>
<?php if ($searchQuery !== ''): ?>
  <a href="<?php echo pchatH($liteUrl(['room' => $viewRoomId])); ?>#send">検索を解除</a>
<?php endif; ?>
</form>

<?php if ($searchQuery !== ''): ?>
<p>「<?php echo pchatH($searchQuery); ?>」の検索結果: <?php echo count($viewMessagesDisplay); ?>件</p>
<?php endif; ?>
<?php if (empty($viewMessagesDisplay)): ?>
<p><?php echo $searchQuery !== '' ? '一致するメッセージが見つかりませんでした。' : 'まだメッセージがありません。'; ?></p>
<?php endif; ?>
<?php foreach ($viewMessagesDisplay as $m):
  $quoted = !empty($m['reply_to']) ? ($messagesBySeq[$m['reply_to']] ?? null) : null;
  $quotedMissing = !empty($m['reply_to']) && $quoted === null;
  $mine = $m['sender'] === $actor['userid'];
?>
<p id="pchat-msg-<?php echo (int)$m['seq']; ?>">
<?php if ($quoted !== null): ?>
<br />&gt; <?php echo pchatH($quoted['sender'] === $actor['userid'] ? 'あなた' : ($opponent['name'] ?? $opponent['username'] ?? '相手')); ?>: <?php echo pchatH(mb_strimwidth($quoted['body'], 0, 60, '…')); ?><br />
<?php elseif ($quotedMissing): ?>
<br />(返信先のメッセージは見つかりませんでした)<br />
<?php endif; ?>
<strong><?php echo $mine ? 'あなた' : pchatH($opponent['name'] ?? $opponent['username'] ?? '相手'); ?>:</strong>
<?php echo nl2br(pchatLinkify($m['body'])); ?>
<br /><small><?php echo pchatH(date('n/j H:i', $m['created'])); ?><?php if ($mine && $opponentReadSeq >= $m['seq']): ?> 既読<?php endif; ?> - <a href="<?php echo pchatH($liteUrl(['room' => $viewRoomId, 'reply_to' => $m['seq']])); ?>#send">返信</a></small>
<?php if (!empty($viewReactions[$m['seq']])): ?>
<br />反応:<?php foreach ($viewReactions[$m['seq']] as $rx): ?> <?php echo pchatH($rx['emoji']); ?><?php endforeach; ?>
<?php endif; ?>
<br />
<form method="post" action="<?php echo $liteAction; ?>#pchat-msg-<?php echo (int)$m['seq']; ?>" style="display:inline;">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="react" />
  <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
  <input type="hidden" name="target_seq" value="<?php echo (int)$m['seq']; ?>" />
<?php foreach (PCHAT_REACTION_EMOJI as $emojiOption): ?>
  <button type="submit" name="emoji" value="<?php echo pchatH($emojiOption); ?>"><?php echo pchatH($emojiOption); ?></button>
<?php endforeach; ?>
</form>
</p>
<?php endforeach; ?>

<hr />
<form method="post" action="<?php echo $liteAction; ?>" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="attach_upload" />
  <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
  <p>添付ファイル: <input type="file" name="file" required />
  保存期間:
  <select name="ttl">
    <option value="86400">1日</option>
    <option value="259200">3日</option>
    <option value="604800">1週間</option>
    <option value="1209600">2週間</option>
    <option value="2592000" selected>30日(既定)</option>
  </select>
  <button type="submit">添付して送れるリンクを作る</button></p>
  <p><small>添付ファイルは指定した保存期間で自動的に消滅します。作成後、メッセージ欄にリンクが入ります。</small></p>
</form>

<a id="send"></a>
<?php if ($replyToSeq > 0): ?>
<p>
<?php if ($replyToMsg !== null): ?>
<?php echo pchatH($replyToMsg['sender'] === $actor['userid'] ? 'あなた' : ($opponent['name'] ?? $opponent['username'] ?? '相手')); ?>へ返信: <?php echo pchatH(mb_strimwidth($replyToMsg['body'], 0, 80, '…')); ?>
<?php else: ?>
返信先のメッセージが見つかりませんでした(削除された可能性があります)。
<?php endif; ?>
 <a href="<?php echo pchatH($liteUrl(['room' => $viewRoomId])); ?>#send">キャンセル</a>
</p>
<?php endif; ?>
<form method="post" action="<?php echo $liteAction; ?>">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="send" />
  <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
  <input type="hidden" name="up_to_seq" value="<?php echo (int)$lastSeq; ?>" />
  <input type="hidden" name="reply_to" value="<?php echo $replyToMsg !== null ? (int)$replyToSeq : ''; ?>" />
  <p><label>メッセージ:<br /><textarea name="body" rows="3" maxlength="4000" required><?php echo $incomingFileShareUrl !== null ? pchatH($incomingFileShareUrl) : ''; ?></textarea></label></p>
  <button type="submit">送信</button>
</form>

<form method="post" action="<?php echo $liteAction; ?>">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="mark_read" />
  <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
  <input type="hidden" name="up_to_seq" value="<?php echo (int)$lastSeq; ?>" />
  <button type="submit">一覧に戻る(既読を記録)</button>
</form>

<form method="post" action="<?php echo $liteAction; ?>">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="toggle_secret" />
  <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
  <input type="hidden" name="secret" value="<?php echo $isSecret ? '0' : '1'; ?>" />
  <button type="submit"><?php echo $isSecret ? 'シークレットモードを解除する' : '🔒 シークレットモードにする'; ?></button>
</form>

<form method="post" action="<?php echo $liteAction; ?>">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="delete_room" />
  <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
  <button type="submit">この連絡先を削除する</button>
</form>
<?php if ($isSecret): ?>
<p><small>シークレットモードのメッセージは約24時間で自動的に消えます。WebRTCでの直接接続は簡易表示モードでは利用できません(<a href="<?php echo pchatH(pchatToggleLiteUrl($isLite)); ?>">通常表示に戻す</a>とお使いいただけます)。</small></p>
<?php endif; ?>

<?php elseif ($viewRoomId !== '' && $viewRoom !== null && ($viewRoom['status'] ?? '') === 'pending_handshake'): ?>
<?php
  $isInviter = ($viewRoom['members'][0] ?? null) === $actor['userid'];
  $isInvitedRecipient = !$isInviter && (($viewRoom['invited_userid'] ?? null) === $actor['userid']);
?>
<hr />
<p><a href="<?php echo pchatH($liteUrl()); ?>#rooms">← 会話一覧に戻る</a></p>
<?php if ($isInvitedRecipient): ?>
<?php $inviter = $viewRoom['members_info'][0] ?? null; ?>
<h2>招待が届いています</h2>
<p><strong><?php echo pchatH($inviter['name'] ?? $inviter['username'] ?? '(不明なユーザー)'); ?></strong> (@<?php echo pchatH($inviter['username'] ?? ''); ?>) さんから会話への招待が届いています。</p>
<form method="post" action="<?php echo $liteAction; ?>">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="accept_direct" />
  <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
  <button type="submit">承諾する</button>
</form>
<form method="post" action="<?php echo $liteAction; ?>">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="delete_room" />
  <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
  <button type="submit">辞退する</button>
</form>
<?php elseif (isset($viewRoom['invited_userid'])): ?>
<h2>招待の返答待ち</h2>
<p><?php echo pchatH($viewRoom['invited_username'] ?? '相手'); ?> さんに招待を送りました。承諾されるとここで会話できるようになります。</p>
<form method="post" action="<?php echo $liteAction; ?>">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="delete_room" />
  <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
  <button type="submit">この招待を取り消す</button>
</form>
<?php else: ?>
<?php $code = base64_encode(json_encode(['v' => 1, 'r' => $viewRoom['room_id'], 't' => $viewRoom['invite_token']], JSON_UNESCAPED_UNICODE)); ?>
<h2>招待コードを作成しました</h2>
<p>この文字列を相手に安全な方法で渡してください。相手が承諾すると、この会話が始まります。(簡易表示モードのため、QR/音波での共有は利用できません。<a href="<?php echo pchatH(pchatToggleLiteUrl($isLite)); ?>">通常表示に戻す</a>とお使いいただけます。)</p>
<p><textarea readonly rows="3" cols="50"><?php echo pchatH($code); ?></textarea></p>
<form method="post" action="<?php echo $liteAction; ?>">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="delete_room" />
  <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
  <button type="submit">この招待を取り消す</button>
</form>
<?php endif; ?>

<?php elseif ($viewRoomId !== ''): ?>
<hr />
<p>見つかりませんでした。削除されたか、権限がありません。</p>
<p><a href="<?php echo pchatH($liteUrl()); ?>#rooms">会話一覧に戻る</a></p>

<?php else: ?>
<?php if ($mode === 'anon'): ?>
<hr />
<p>匿名で利用中です(ログインしなくてもこのまま使えます)。<a href="<?php echo pchatH($liteUrl(['mode' => 'meikee'])); ?>">メイキータブ</a>に切り替えると、プシューメイキィのアカウントとして使えます。</p>
<?php endif; ?>

<a id="invite"></a>
<hr />
<h2>招待コードを作る</h2>
<p>相手に安全な方法(直接会う・信頼できる別の連絡手段など)で渡してください。URLではなく、この文字列そのものをコピーして渡します。</p>
<form method="post" action="<?php echo $liteAction; ?>">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="invite_create" />
  <button type="submit">招待コードを作成する</button>
</form>

<h2>招待コードを使う</h2>
<p>相手から受け取ったコードを貼り付けてください。追加する前に確認画面が表示されます。</p>
<form method="post" action="<?php echo $liteAction; ?>">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="invite_preview" />
  <p><textarea name="code" rows="3" required placeholder="招待コードを貼り付け"></textarea></p>
  <button type="submit">確認する</button>
</form>

<?php if ($mode === 'meikee'): ?>
<h2>ユーザー名で招待する</h2>
<p>プシューメイキィのアカウントを持つ相手なら、コードのやり取り無しでユーザー名だけで招待できます(メイキータブ限定)。</p>
<form method="post" action="<?php echo $liteAction; ?>">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="invite_direct_create" />
  <p><input type="text" name="username" required placeholder="相手のユーザー名" /></p>
  <button type="submit">招待する</button>
</form>
<?php endif; ?>

<a id="rooms"></a>
<hr />
<h2>会話一覧<?php if ($totalUnread > 0): ?>(未読<?php echo $totalUnread; ?>件)<?php endif; ?></h2>
<?php if (empty($viewRoomList)): ?>
<p>まだ会話がありません。</p>
<?php endif; ?>
<ul>
<?php foreach ($viewRoomList as $r): ?>
<?php
  $status = $r['status'] ?? '';
  $isInviter = ($r['members'][0] ?? null) === $actor['userid'];
  $unread = ($status === 'active') ? pchatUnreadCount($r, $actor['userid']) : 0;
  if ($status === 'active') {
    $opp = pchatOpponentInfo($r, $actor['userid']);
    $label = $opp['name'] ?? $opp['username'] ?? '(不明なユーザー)';
    $sub = !empty($r['secret']) ? '🔒 シークレット' : null;
  } elseif ($isInviter) {
    $label = isset($r['invited_username']) ? ('招待中: ' . $r['invited_username']) : '招待コードの相手からの返答待ち';
    $sub = 'まだ承諾されていません';
  } else {
    $inviter = $r['members_info'][0] ?? null;
    $label = ($inviter['name'] ?? $inviter['username'] ?? '不明なユーザー') . ' さんから招待が届いています';
    $sub = '開いて承諾/辞退できます';
  }
?>
<li>
  <a href="<?php echo pchatH($liteUrl(['room' => $r['room_id']])); ?>"><?php echo pchatH($label); ?></a>
<?php if ($unread > 0): ?> (未読<?php echo $unread > 99 ? '99+' : $unread; ?>)<?php endif; ?>
<?php if ($sub !== null): ?> - <?php echo pchatH($sub); ?><?php endif; ?>
</li>
<?php endforeach; ?>
</ul>

<a id="settings"></a>
<hr />
<h2>設定</h2>
<p>既読設定: 有効にすると、自分が読んだメッセージに相手側で「既読」が表示されるようになります(無効の間は記録されません)。</p>
<form method="post" action="<?php echo $liteAction; ?>">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="set_read_receipts" />
  <input type="hidden" name="enabled" value="<?php echo $readReceiptsEnabled ? '0' : '1'; ?>" />
  <button type="submit"><?php echo $readReceiptsEnabled ? '既読を伝えるのをやめる' : '既読を相手に伝える'; ?></button>
</form>

<?php if ($currentUser !== null): ?>
<p>アカウント: 名前やユーザー名などのプロフィールに加え、「ログイン状態を保持する」設定もプシューメイキィ側の編集画面から変更できます。<a href="<?php echo pchatH(PchatAccount::manageUrl()); ?>">メイキーで編集する</a></p>
<?php endif; ?>

<h3>すべてのチャット履歴と連絡先を削除</h3>
<?php if (($_SESSION['pchat_confirm_delete_all_rooms'] ?? 0) > time()): ?>
<p><strong>本当に削除しますか?</strong> すべての会話・メッセージ・連絡先(相手との紐付き)が削除されます。相手側からも見えなくなり、<strong>元に戻せません</strong>。再度話すには、もう一度招待のやり取りが必要です。</p>
<form method="post" action="<?php echo $liteAction; ?>">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="delete_all_rooms" />
  <button type="submit">本当にすべて削除する</button>
</form>
<form method="post" action="<?php echo $liteAction; ?>">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="delete_all_rooms_cancel" />
  <button type="submit">キャンセル</button>
</form>
<?php else: ?>
<p>すべての会話・メッセージ・連絡先(相手との紐付き)を削除します。相手側からも見えなくなり、<strong>元に戻せません</strong>。再度話すには、もう一度招待のやり取りが必要です。</p>
<form method="post" action="<?php echo $liteAction; ?>">
  <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
  <input type="hidden" name="action" value="delete_all_rooms_confirm" />
  <button type="submit">すべてのチャット履歴と連絡先を削除する</button>
</form>
<?php endif; ?>

<a id="products"></a>
<hr />
<h2>プロダクト一覧</h2>
<p>その他のプシューサービスへのリンクです。</p>
<?php echo pchatProductListHtmlLite(); ?>
<?php endif; ?>

<hr />
<p><?php echo function_exists('notationDisp') ? notationDisp('notation') : ''; ?></p>
<p><?php echo function_exists('notationDisp') ? notationDisp('copyright') : ''; ?></p>
</body>
</html>
<?php
  exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, user-scalable=yes, maximum-scale=6.0, minimum-scale=1.0" />
    <meta name="copyright" content="© 2026 created by PusyuuWanko/" />
    <meta name="description" content="<?php echo pchatH($description); ?>" />
    <meta name="keywords" content="プシューサービス,PusyuuService,プシューチャット,チャット,DM,メッセージ,プシューメイキィ,シークレット" />
    <meta property="og:title" content="<?php echo pchatH($title); ?>" />
    <meta property="og:site_name" content="<?php echo pchatH($title); ?>" />
    <meta property="og:description" content="<?php echo pchatH($description); ?>" />
    <meta property="og:image" content="<?= pchatSiteBase('main', true) ?>/pusyuusystem/images/NanimoyakunitatanaiPage.png" />
    <meta property="og:type" content="application" />
    <title><?php echo pchatH($title); ?></title>
    <link rel="shortcut icon" href="<?= pchatSiteBase('main') ?>/pusyuusystem/images/favicon.ico" />
    <?php /* フォント "pixel" は main の中のファイルなので、住所は台帳から決めます(style.css には書けないためここに置いています)。 */ ?>
    <style>@font-face { font-family: "pixel"; src: url("<?= pchatSiteBase('main') ?>/pusyuusystem/fonts/PixelMplus12-Regular.ttf") format("truetype"); font-display: swap; }</style>
    <link href="<?php echo pchatH(pchatAssetUrl('./assets/styles/style.css')); ?>" rel="stylesheet" />
    <link rel="manifest" href="<?php echo pchatH(pchatAssetUrl('./assets/documents/manifest.json')); ?>" />
    <meta name="theme-color" content="#009900" />
    <!-- privacy_notice.js の script タグより前に置く -->
    <!-- ここから下(</html>まで)は$isLiteがtrueの場合には到達しない: 簡易表示は
         この位置よりずっと前で完全に別のHTMLを出力してexitしている(上のほうの
         「簡易表示(?lite=1)本体」を参照)。 -->
    <script>
      window.PusyuuPrivacyNoticeConfig = {
        title: "プライバシーに関するお知らせ",
        serviceName: "プシューチャット",
        closeButtonText: "同意する",
        hostsIntroText: "このページ読み込み時に検出した外部の通信先：",
        hostsNoneText: "このページ読み込み時には、外部への通信は検出されませんでした。",
        storageKey: "pusyuu_privacy_notice_dismissed",
        jsDependency: {
          level: "low",
          fallbackNote:
            "このページの基本機能はJavaScriptが無くても利用できます。新着の自動更新・招待コードのQR/音波共有・" +
            "シークレットモードでのWebRTC接続のみJavaScriptによる強化機能です。"
        },
        triggerCloseButtonText: "閉じる"
      };
    </script>
    <script src="<?= pchatSiteBase('main') ?>/pusyuusystem/scripts/privacy_notice.js"></script>
    <script src="<?= pchatSiteBase('main') ?>/pusyuusystem/scripts/request-indicator.js" data-include-async="false"></script>
    <!--
        *----------------------------------
        |  © 2021-2026 By ISAMI ABE     |
        |  LastUpdate: 2026-09-20       |
        |  License: MIT License         |
        |  PusyuuChat....              |
      ----------------------------------*
    -->
  </head>
  <body>
    <header>
      <h1>プシューチャット</h1>
      <nav>
        <ul>
<?php if ($currentUser !== null): ?>
          <li>
            <form method="post" action="./?mode=<?php echo pchatH($mode); ?>" class="nav_form">
              <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
              <input type="hidden" name="account_action" value="logout" />
              <button type="submit" class="link_button" title="ログアウト"><?php echo pchatH($currentUser['name']); ?>さん / ログアウト</button>
            </form>
          </li>
<?php else: ?>
          <li><a href="<?php echo pchatH(PchatAccount::loginUrl()); ?>" title="プシューメイキィでログイン">ログイン</a></li>
<?php endif; ?>
          <li><a href="<?php echo pchatH(pchatToggleLiteUrl(false)); ?>">簡易表示に切り替える</a></li>
        </ul>
      </nav>
    </header>

    <main>
<?php foreach ($flashes as $flash): ?>
      <p class="form-message ok"><?php echo pchatH($flash); ?></p>
<?php endforeach; ?>

      <p class="lead">招待のハンドシェイクを経た相手とだけ1対1でチャットできます。メイキータブはプシューメイキィのアカウントで、ユーザー名を指定した直接招待や、複数端末での同じ会話一覧の閲覧ができます。匿名タブはアカウント不要で、招待コードの受け渡しだけで始められます。</p>

      <nav class="mode-tabs">
        <a href="./?mode=meikee" class="<?php echo $mode === 'meikee' ? 'active' : ''; ?>">メイキー</a>
        <a href="./?mode=anon" class="<?php echo $mode === 'anon' ? 'active' : ''; ?>">匿名</a>
      </nav>

<?php if ($actor === null): ?>
      <div class="mode-panel">
<?php if (pchatAccountsUnreachable() || !PchatAccount::ready()): ?>
        <p class="form-message error">プシューメイキィに接続できないため、ログイン状態を確認できませんでした。しばらくしてから再読み込みしてお試しください。<?php echo pchatH(PchatAccount::unavailableReason()); ?> ログインせずに使いたい場合は匿名タブをどうぞ。</p>
<?php else: ?>
        <a href="<?php echo pchatH(PchatAccount::loginUrl()); ?>" class="button">ログイン</a>
        <p class="note">
          プシューメイキィをお持ちでない場合は
          <a href="<?php echo pchatH(PchatAccount::createUrl()); ?>">こちらで作成</a>
          してください。ログインせずに使いたい場合は<a href="./?mode=anon">匿名タブ</a>をどうぞ。
        </p>
<?php endif; ?>
      </div>
<?php elseif ($invitePreview !== null): ?>
      <div class="mode-panel">
        <h2>招待コードの確認</h2>
<?php if ($invitePreview['ok']): ?>
        <p class="note">
          <strong><?php echo pchatH($invitePreview['inviter']['name'] ?? '(不明なユーザー)'); ?></strong>
<?php if (!empty($invitePreview['inviter']['username'])): ?>
          (@<?php echo pchatH($invitePreview['inviter']['username']); ?>)
<?php endif; ?>
          さんから届いた招待コードです。
        </p>
        <p class="form-message error">
          この操作を行うと、このコードを渡してきた相手と直接メッセージのやり取りができるようになります。
          本当にこの相手を信頼できますか？よく知らない相手や、正当な経路以外で受け取ったコードは追加しないでください。
          一度追加した後で削除した場合、再び話すには最初から招待のやり取りをやり直す必要があります。
        </p>
        <form method="post" action="./?mode=<?php echo pchatH($mode); ?>">
          <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
          <input type="hidden" name="action" value="invite_accept" />
          <input type="hidden" name="code" value="<?php echo pchatH($invitePreview['code']); ?>" />
          <button type="submit" class="button">信頼して追加する</button>
        </form>
        <p><a href="./?mode=<?php echo pchatH($mode); ?>">キャンセルして戻る</a></p>
<?php else: ?>
        <p class="form-message error"><?php echo pchatH($invitePreview['error']); ?></p>
        <p><a href="./?mode=<?php echo pchatH($mode); ?>">戻る</a></p>
<?php endif; ?>
      </div>

<?php elseif ($viewRoomId !== '' && $viewRoom !== null && ($viewRoom['status'] ?? '') === 'active'): ?>
<?php
      $opponent = pchatOpponentInfo($viewRoom, $actor['userid']);
      $opponentReadSeq = (int)($viewRoom['read_seq'][$opponent['userid'] ?? ''] ?? 0);
      $lastSeq = (int)($viewRoom['last_seq'] ?? 0);
      $isSecret = !empty($viewRoom['secret']);

      // 返信/引用機能: seqでの参照ルックアップ用マップと、URLの?reply_to=で
      // 指定された「これから返信する対象」。$_GET['reply_to']は「返信」リンクを
      // クリックした際にJS無しでも成立するよう、ページ再読み込みで渡している。
      $messagesBySeq = [];
      foreach ($viewMessages as $vm) { $messagesBySeq[$vm['seq']] = $vm; }
      $replyToSeq = max(0, (int)($_GET['reply_to'] ?? 0));
      $replyToMsg = $replyToSeq > 0 ? ($messagesBySeq[$replyToSeq] ?? null) : null;
?>
      <div class="mode-panel">
        <p><a href="./?mode=<?php echo pchatH($mode); ?>#pchat-window-rooms">← 会話一覧に戻る</a></p>
        <p class="players-label">
          <?php echo pchatH($opponent['name'] ?? $opponent['username'] ?? '(不明なユーザー)'); ?> さんとの会話
<?php if ($isSecret): ?>
          <span class="pchat-secret-badge">🔒 シークレット</span>
<?php endif; ?>
        </p>

        <form method="get" action="./" class="inline-form pchat-search-form">
          <input type="hidden" name="mode" value="<?php echo pchatH($mode); ?>" />
          <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
          <input type="text" name="q" value="<?php echo pchatH($searchQuery); ?>" placeholder="会話内を検索" />
          <button type="submit" class="small-button ghost">検索</button>
<?php if ($searchQuery !== ''): ?>
          <a class="small-button ghost" href="./?mode=<?php echo pchatH($mode); ?>&amp;room=<?php echo urlencode($viewRoomId); ?>">検索を解除</a>
<?php endif; ?>
        </form>
<?php if ($searchQuery !== ''): ?>
        <p class="note">「<?php echo pchatH($searchQuery); ?>」の検索結果: <?php echo count($viewMessagesDisplay); ?>件</p>
<?php endif; ?>

        <div id="pchat-messages" class="chat-messages" data-room="<?php echo pchatH($viewRoomId); ?>" data-csrf="<?php echo pchatH($csrfToken); ?>" data-mode="<?php echo pchatH($mode); ?>" data-opponent-name="<?php echo pchatH($opponent['name'] ?? $opponent['username'] ?? '相手'); ?>">
<?php if (empty($viewMessagesDisplay)): ?>
          <p class="empty-note"><?php echo $searchQuery !== '' ? '一致するメッセージが見つかりませんでした。' : 'まだメッセージがありません。'; ?></p>
<?php endif; ?>
<?php foreach ($viewMessagesDisplay as $m):
            $quoted = !empty($m['reply_to']) ? ($messagesBySeq[$m['reply_to']] ?? null) : null;
            $quotedMissing = !empty($m['reply_to']) && $quoted === null;
          ?>
          <div class="chat-message<?php echo $m['sender'] === $actor['userid'] ? ' mine' : ''; ?>" id="pchat-msg-<?php echo (int)$m['seq']; ?>" data-seq="<?php echo (int)$m['seq']; ?>" data-mine="<?php echo $m['sender'] === $actor['userid'] ? '1' : '0'; ?>">
<?php if ($quoted !== null): ?>
            <div class="chat-message-quote">
              <span class="chat-message-quote-sender"><?php echo pchatH($quoted['sender'] === $actor['userid'] ? 'あなた' : ($opponent['name'] ?? $opponent['username'] ?? '相手')); ?></span>
              <span class="chat-message-quote-body"><?php echo pchatH(mb_strimwidth($quoted['body'], 0, 60, '…')); ?></span>
            </div>
<?php elseif ($quotedMissing): ?>
            <div class="chat-message-quote missing">(返信先のメッセージは見つかりませんでした)</div>
<?php endif; ?>
            <div class="chat-message-body"><?php echo nl2br(pchatLinkify($m['body'])); ?></div>
<?php if (!empty($viewReactions[$m['seq']])): ?>
            <div class="chat-message-reactions">
<?php foreach ($viewReactions[$m['seq']] as $rx): ?>
              <span class="chat-reaction-badge" title="<?php echo pchatH($rx['sender'] === $actor['userid'] ? 'あなた' : ($opponent['name'] ?? $opponent['username'] ?? '相手')); ?>"><?php echo pchatH($rx['emoji']); ?></span>
<?php endforeach; ?>
            </div>
<?php endif; ?>
            <div class="chat-message-time">
              <?php echo pchatH(date('n/j H:i', $m['created'])); ?>
<?php if ($m['sender'] === $actor['userid'] && $opponentReadSeq >= $m['seq']): ?>
              <span class="chat-read-tick">既読</span>
<?php endif; ?>
              <a class="chat-message-reply-link" href="./?mode=<?php echo pchatH($mode); ?>&amp;room=<?php echo urlencode($viewRoomId); ?>&amp;reply_to=<?php echo (int)$m['seq']; ?>#pchat-send-form" data-seq="<?php echo (int)$m['seq']; ?>">返信</a>
            </div>
            <form method="post" action="./?mode=<?php echo pchatH($mode); ?>#pchat-msg-<?php echo (int)$m['seq']; ?>" class="chat-reaction-form">
              <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
              <input type="hidden" name="action" value="react" />
              <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
              <input type="hidden" name="target_seq" value="<?php echo (int)$m['seq']; ?>" />
<?php foreach (PCHAT_REACTION_EMOJI as $emojiOption): ?>
              <button type="submit" name="emoji" value="<?php echo pchatH($emojiOption); ?>" class="chat-reaction-button"><?php echo pchatH($emojiOption); ?></button>
<?php endforeach; ?>
            </form>
          </div>
<?php endforeach; ?>
        </div>

        <form method="post" action="./?mode=<?php echo pchatH($mode); ?>" enctype="multipart/form-data" class="inline-form pchat-attach-form" id="pchat-attach-form" data-limits-url="./?attach_limits=1">
          <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
          <input type="hidden" name="action" value="attach_upload" />
          <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
          <input type="file" name="file" id="pchat-attach-input" required />
          <label for="pchat-attach-ttl" class="note">保存期間</label>
          <select name="ttl" id="pchat-attach-ttl">
            <option value="86400">1日</option>
            <option value="259200">3日</option>
            <option value="604800">1週間</option>
            <option value="1209600">2週間</option>
            <option value="2592000" selected>30日(既定)</option>
          </select>
          <button type="submit" class="small-button ghost" id="pchat-attach-submit">📎 添付して送れるリンクを作る</button>
        </form>
        <div class="pchat-attach-progress" id="pchat-attach-progress" hidden>
          <div class="pchat-attach-progress-fill" id="pchat-attach-progress-fill"></div>
        </div>
        <p class="note" id="pchat-attach-status">添付ファイルは指定した保存期間で自動的に消滅します。作成後、メッセージ欄にリンクが入ります。</p>

<?php if ($replyToSeq > 0): ?>
        <div class="chat-reply-preview" id="pchat-reply-preview">
<?php if ($replyToMsg !== null): ?>
          <div class="chat-reply-preview-body">
            <span class="chat-message-quote-sender"><?php echo pchatH($replyToMsg['sender'] === $actor['userid'] ? 'あなた' : ($opponent['name'] ?? $opponent['username'] ?? '相手')); ?>へ返信</span>
            <span class="chat-message-quote-body"><?php echo pchatH(mb_strimwidth($replyToMsg['body'], 0, 80, '…')); ?></span>
          </div>
<?php else: ?>
          <div class="chat-reply-preview-body missing">返信先のメッセージが見つかりませんでした(削除された可能性があります)。</div>
<?php endif; ?>
          <a class="small-button ghost" href="./?mode=<?php echo pchatH($mode); ?>&amp;room=<?php echo urlencode($viewRoomId); ?>#pchat-send-form" id="pchat-reply-cancel">キャンセル</a>
        </div>
<?php endif; ?>
        <p class="pchat-typing-indicator" id="pchat-typing-indicator" hidden><span class="pchat-typing-dots" aria-hidden="true"><i></i><i></i><i></i></span><?php echo pchatH($opponent['name'] ?? $opponent['username'] ?? '相手'); ?>さんが入力中…</p>
        <form method="post" action="./?mode=<?php echo pchatH($mode); ?>" class="chat-send-form" id="pchat-send-form">
          <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
          <input type="hidden" name="action" value="send" />
          <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
          <input type="hidden" name="up_to_seq" value="<?php echo (int)$lastSeq; ?>" />
<?php if ($replyToMsg !== null): ?>
          <input type="hidden" name="reply_to" value="<?php echo (int)$replyToSeq; ?>" id="pchat-reply-to-input" />
<?php else: ?>
          <input type="hidden" name="reply_to" value="" id="pchat-reply-to-input" />
<?php endif; ?>
          <textarea name="body" id="pchat-body-input" rows="2" maxlength="4000" required><?php echo $incomingFileShareUrl !== null ? pchatH($incomingFileShareUrl) : ''; ?></textarea>
          <button type="submit">送信</button>
        </form>

        <form method="post" action="./?mode=<?php echo pchatH($mode); ?>" class="inline-form">
          <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
          <input type="hidden" name="action" value="mark_read" />
          <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
          <input type="hidden" name="up_to_seq" value="<?php echo (int)$lastSeq; ?>" />
          <button type="submit" class="small-button ghost">一覧に戻る(既読を記録)</button>
        </form>

        <form method="post" action="./?mode=<?php echo pchatH($mode); ?>" class="inline-form">
          <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
          <input type="hidden" name="action" value="toggle_secret" />
          <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
          <input type="hidden" name="secret" value="<?php echo $isSecret ? '0' : '1'; ?>" />
          <button type="submit" class="small-button ghost"><?php echo $isSecret ? 'シークレットモードを解除する' : '🔒 シークレットモードにする'; ?></button>
        </form>

        <form method="post" action="./?mode=<?php echo pchatH($mode); ?>" class="inline-form" onsubmit="return confirm('この連絡先を削除しますか?再び話すには招待のやり取りを最初からやり直す必要があります。');">
          <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
          <input type="hidden" name="action" value="delete_room" />
          <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
          <button type="submit" class="small-button ghost">この連絡先を削除する</button>
        </form>

<?php if ($isSecret): ?>
        <p class="note">シークレットモードのメッセージは約24時間で自動的に消えます。</p>
        <details class="pchat-secret-details">
          <summary class="small-button ghost">🔒 シークレットモードでWebRTC接続する(実験的、JS必須)</summary>
          <p class="note">この会話の相手と、ブラウザ同士を直接WebRTCで接続します。サーバーは一切経由せず、接続用の文字列だけをこの会話(または他の連絡手段)で送り合ってください。ページを閉じると内容は残りません。</p>
          <noscript><p class="form-message error">この機能の利用にはJavaScriptが必要です。</p></noscript>
          <div id="pchat-secret-root"></div>
        </details>
<?php endif; ?>
      </div>

<?php elseif ($viewRoomId !== '' && $viewRoom !== null && ($viewRoom['status'] ?? '') === 'pending_handshake'): ?>
<?php
      $isInviter = ($viewRoom['members'][0] ?? null) === $actor['userid'];
      $isInvitedRecipient = !$isInviter && (($viewRoom['invited_userid'] ?? null) === $actor['userid']);
?>
      <div class="mode-panel">
        <p><a href="./?mode=<?php echo pchatH($mode); ?>#pchat-window-rooms">← 会話一覧に戻る</a></p>
<?php if ($isInvitedRecipient): ?>
<?php $inviter = $viewRoom['members_info'][0] ?? null; ?>
        <h2>招待が届いています</h2>
        <p class="note">
          <strong><?php echo pchatH($inviter['name'] ?? $inviter['username'] ?? '(不明なユーザー)'); ?></strong>
          (@<?php echo pchatH($inviter['username'] ?? ''); ?>) さんから会話への招待が届いています。
        </p>
        <form method="post" action="./?mode=<?php echo pchatH($mode); ?>">
          <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
          <input type="hidden" name="action" value="accept_direct" />
          <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
          <button type="submit" class="button">承諾する</button>
        </form>
        <form method="post" action="./?mode=<?php echo pchatH($mode); ?>" class="inline-form">
          <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
          <input type="hidden" name="action" value="delete_room" />
          <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
          <button type="submit" class="small-button ghost">辞退する</button>
        </form>
<?php elseif (isset($viewRoom['invited_userid'])): ?>
        <h2>招待の返答待ち</h2>
        <p class="note"><?php echo pchatH($viewRoom['invited_username'] ?? '相手'); ?> さんに招待を送りました。承諾されるとここで会話できるようになります。</p>
        <form method="post" action="./?mode=<?php echo pchatH($mode); ?>">
          <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
          <input type="hidden" name="action" value="delete_room" />
          <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
          <button type="submit" class="small-button ghost">この招待を取り消す</button>
        </form>
<?php else: ?>
<?php $code = base64_encode(json_encode(['v' => 1, 'r' => $viewRoom['room_id'], 't' => $viewRoom['invite_token']], JSON_UNESCAPED_UNICODE)); ?>
        <h2>招待コードを作成しました</h2>
        <p class="note">この文字列を相手に安全な方法で渡してください。JavaScriptが有効な場合はQRコードや音波での共有も選べます。相手が承諾すると、この会話が始まります。</p>
        <textarea readonly rows="3" onclick="this.select()" data-pchat-invite-code><?php echo pchatH($code); ?></textarea>
        <form method="post" action="./?mode=<?php echo pchatH($mode); ?>">
          <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
          <input type="hidden" name="action" value="delete_room" />
          <input type="hidden" name="room" value="<?php echo pchatH($viewRoomId); ?>" />
          <button type="submit" class="small-button ghost">この招待を取り消す</button>
        </form>
<?php endif; ?>
      </div>

<?php elseif ($viewRoomId !== ''): ?>
      <div class="mode-panel">
        <p class="form-message error">見つかりませんでした。削除されたか、権限がありません。</p>
        <p><a href="./?mode=<?php echo pchatH($mode); ?>#pchat-window-rooms">会話一覧に戻る</a></p>
      </div>

<?php else: ?>
<?php if ($mode === 'anon'): ?>
      <div class="mode-panel">
        <p class="note">
          匿名で利用中です(ログインしなくてもこのまま使えます)。
          <a href="./?mode=meikee">メイキータブ</a>に切り替えると、プシューメイキィのアカウントとして使えます。
        </p>
      </div>
<?php endif; ?>
      <div class="mode-panel">
        <ul class="match-list">
          <li class="match-row">
            <div class="match-row-info"><strong>🔗 招待</strong><span class="match-status">コードの作成・使用・ユーザー名招待</span></div>
            <a class="small-button" href="#pchat-window-invite">開く</a>
          </li>
          <li class="match-row">
            <div class="match-row-info"><strong>💬 会話一覧</strong></div>
            <a class="small-button" href="#pchat-window-rooms">開く</a>
          </li>
          <li class="match-row">
            <div class="match-row-info"><strong>⚙️ 設定</strong></div>
            <a class="small-button" href="#pchat-window-settings">開く</a>
          </li>
          <li class="match-row">
            <div class="match-row-info"><strong>🌐 プロダクト一覧</strong><span class="match-status">その他のプシューサービスへのリンク</span></div>
            <a class="small-button" href="#pchat-window-products">開く</a>
          </li>
        </ul>
      </div>

      <a id="pchat-mc"></a>

      <div class="pchat-window" id="pchat-window-invite">
        <div class="pchat-window-inner">
          <a href="#pchat-mc" class="pchat-window-close" aria-label="閉じる">✕</a>
          <div class="mode-panel">
            <h2>招待コードを作る</h2>
            <p class="note">相手に安全な方法(直接会う・信頼できる別の連絡手段など)で渡してください。URLではなく、この文字列そのものをコピーして渡します。JavaScriptが有効な場合はQRコードや音波での共有も選べます。</p>
            <form method="post" action="./?mode=<?php echo pchatH($mode); ?>">
              <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
              <input type="hidden" name="action" value="invite_create" />
              <button type="submit" class="button">招待コードを作成する</button>
            </form>

            <h2>招待コードを使う</h2>
            <p class="note">相手から受け取ったコードを貼り付けてください。追加する前に確認画面が表示されます。</p>
            <form method="post" action="./?mode=<?php echo pchatH($mode); ?>">
              <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
              <input type="hidden" name="action" value="invite_preview" />
              <textarea name="code" rows="3" required placeholder="招待コードを貼り付け" data-pchat-invite-input></textarea>
              <button type="submit">確認する</button>
            </form>

<?php if ($mode === 'meikee'): ?>
            <h2>ユーザー名で招待する</h2>
            <p class="note">プシューメイキィのアカウントを持つ相手なら、コードのやり取り無しでユーザー名だけで招待できます(メイキータブ限定)。</p>
            <form method="post" action="./?mode=<?php echo pchatH($mode); ?>">
              <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
              <input type="hidden" name="action" value="invite_direct_create" />
              <input type="text" name="username" required placeholder="相手のユーザー名" />
              <button type="submit">招待する</button>
            </form>
<?php endif; ?>
          </div>
        </div>
      </div>

      <div class="pchat-window" id="pchat-window-rooms">
        <div class="pchat-window-inner">
          <a href="#pchat-mc" class="pchat-window-close" aria-label="閉じる">✕</a>
          <div class="mode-panel">
            <h2>会話一覧</h2>
            <ul class="match-list">
<?php if (empty($viewRoomList)): ?>
              <li class="empty-note">まだ会話がありません。</li>
<?php endif; ?>
<?php foreach ($viewRoomList as $r): ?>
<?php
              $status = $r['status'] ?? '';
              $isInviter = ($r['members'][0] ?? null) === $actor['userid'];
              $unread = ($status === 'active') ? pchatUnreadCount($r, $actor['userid']) : 0;
              if ($status === 'active') {
                $opp = pchatOpponentInfo($r, $actor['userid']);
                $label = $opp['name'] ?? $opp['username'] ?? '(不明なユーザー)';
                $sub = !empty($r['secret']) ? '🔒 シークレット' : null;
              } elseif ($isInviter) {
                $label = isset($r['invited_username']) ? ('招待中: ' . $r['invited_username']) : '招待コードの相手からの返答待ち';
                $sub = 'まだ承諾されていません';
              } else {
                $inviter = $r['members_info'][0] ?? null;
                $label = ($inviter['name'] ?? $inviter['username'] ?? '不明なユーザー') . ' さんから招待が届いています';
                $sub = '開いて承諾/辞退できます';
              }
?>
              <li class="match-row">
                <div class="match-row-info">
                  <strong><?php echo pchatH($label); ?></strong>
<?php if ($unread > 0): ?>
                  <span class="pchat-unread-badge"><?php echo $unread > 99 ? '99+' : $unread; ?></span>
<?php endif; ?>
<?php if ($sub !== null): ?>
                  <span class="match-status"><?php echo pchatH($sub); ?></span>
<?php endif; ?>
                </div>
                <a class="small-button" href="./?mode=<?php echo pchatH($mode); ?>&amp;room=<?php echo urlencode($r['room_id']); ?>">開く</a>
              </li>
<?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>

      <div class="pchat-window" id="pchat-window-settings">
        <div class="pchat-window-inner">
          <a href="#pchat-mc" class="pchat-window-close" aria-label="閉じる">✕</a>
          <div class="mode-panel">
            <div class="pchat-settings-item">
              <h2>既読設定</h2>
              <p class="note">有効にすると、自分が読んだメッセージに相手側で「既読」が表示されるようになります(無効の間は記録されません)。</p>
              <form method="post" action="./?mode=<?php echo pchatH($mode); ?>">
                <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
                <input type="hidden" name="action" value="set_read_receipts" />
                <input type="hidden" name="enabled" value="<?php echo $readReceiptsEnabled ? '0' : '1'; ?>" />
                <button type="submit" class="small-button"><?php echo $readReceiptsEnabled ? '既読を伝えるのをやめる' : '既読を相手に伝える'; ?></button>
              </form>
            </div>

            <?php /*
              プッシュ通知・デスクトップ通知・通知音は、いずれもJavaScriptなしでは
              成立しない機能(Service Worker/Push API/Notification API/音声再生の
              購読・許可・切り替え操作そのものがJS前提)。そのため、no-JS環境向けの
              「非表示のプレースホルダーをJSが表示する」形にはせず、この#pchat-notification-settings
              自体をJSが完全に組み立てる(JSが動かない環境ではこの中身は最初から
              何も存在しない)。中身はsettings-ui.js/push.js/desktop-notify-toggle.js/
              notify-sound-toggle.jsが生成する。

              デスクトップ通知のトグルだけは、ブラウザの許可(取り消せない)と
              pusyuu_push側のdesktopチャンネル(取り消せる)の2つを扱う。詳しくは
              desktop-notify-toggle.js の冒頭。
            */ ?>
            <div id="pchat-notification-settings"></div>

<?php if ($actor !== null): ?>
            <?php /*
              上の#pchat-notification-settingsとは逆に、ここはPHPが常に描画する。
              飛んだ先(pusyuu_pushが持っている通知設定画面)はJSを一切使わない
              ただのフォームなので、JSで組み立てるとJS無効環境から理由なく
              取り上げることになるため。

              リンクではなくボタン(POST)にしているのは、設定画面のURLに短命の
              資格情報が載っていて、HTMLに埋めるとページのソースやキャッシュに
              残ってしまうから。押した瞬間に発行してリダイレクトする。
              詳しくは pusyuuPushSettingsUrl() のコメント。
            */ ?>
            <div class="pchat-settings-item">
              <h2>📰 RSSで受け取る</h2>
              <p class="note">プッシュ通知に対応していない環境(ホーム画面に追加していないiPhoneや、アプリ内ブラウザなど)でも、お使いのRSSリーダーで新着を受け取れます。本文をそのまま載せるか、新着があることだけ載せるか、暗号化して載せるかを選べます。</p>
              <form method="post" action="./?mode=<?php echo pchatH($mode); ?>">
                <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
                <input type="hidden" name="action" value="notify_settings" />
                <button type="submit" class="small-button">RSSの設定を開く</button>
              </form>
            </div>
<?php endif; ?>

<?php if ($currentUser !== null): ?>
            <div class="pchat-settings-item">
              <h2>アカウント</h2>
              <p class="note">名前やユーザー名などのプロフィールに加え、「ログイン状態を保持する」設定もプシューメイキィ側の編集画面から変更できます。</p>
              <a class="small-button" href="<?php echo pchatH(PchatAccount::manageUrl()); ?>">メイキーで編集する</a>
            </div>
<?php endif; ?>

            <div class="pchat-settings-item">
              <h2>すべてのチャット履歴と連絡先を削除</h2>
<?php if (($_SESSION['pchat_confirm_delete_all_rooms'] ?? 0) > time()): ?>
              <p class="note"><strong>本当に削除しますか?</strong> すべての会話・メッセージ・連絡先(相手との紐付き)が削除されます。相手側からも見えなくなり、<strong>元に戻せません</strong>。再度話すには、もう一度招待のやり取りが必要です。</p>
              <form method="post" action="./?mode=<?php echo pchatH($mode); ?>" class="inline-form">
                <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
                <input type="hidden" name="action" value="delete_all_rooms" />
                <button type="submit" class="small-button danger">本当にすべて削除する</button>
              </form>
              <form method="post" action="./?mode=<?php echo pchatH($mode); ?>" class="inline-form">
                <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
                <input type="hidden" name="action" value="delete_all_rooms_cancel" />
                <button type="submit" class="small-button ghost">キャンセル</button>
              </form>
<?php else: ?>
              <p class="note">すべての会話・メッセージ・連絡先(相手との紐付き)を削除します。相手側からも見えなくなり、<strong>元に戻せません</strong>。再度話すには、もう一度招待のやり取りが必要です。</p>
              <form method="post" action="./?mode=<?php echo pchatH($mode); ?>">
                <input type="hidden" name="csrf" value="<?php echo pchatH($csrfToken); ?>" />
                <input type="hidden" name="action" value="delete_all_rooms_confirm" />
                <button type="submit" class="small-button ghost">すべてのチャット履歴と連絡先を削除する</button>
              </form>
<?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="pchat-window" id="pchat-window-products">
        <div class="pchat-window-inner">
          <a href="#pchat-mc" class="pchat-window-close" aria-label="閉じる">✕</a>
          <div class="mode-panel">
            <div class="pchat-settings-item">
              <h2>プロダクト一覧</h2>
              <p class="note">その他のプシューサービスへのリンクです。</p>
              <?php echo pchatProductListHtml(); ?>
            </div>
          </div>
        </div>
      </div>
<?php endif; ?>
    </main>

<?php if ($actor !== null): ?>
    <nav class="pchat-arcade-nav">
      <a href="./?mode=<?php echo pchatH($mode); ?>#pchat-window-rooms"><span class="pchat-nav-icon">💬</span>会話<?php if ($totalUnread > 0): ?><span class="pchat-unread-badge pchat-nav-badge"><?php echo $totalUnread > 99 ? '99+' : $totalUnread; ?></span><?php endif; ?></a>
      <a href="./?mode=<?php echo pchatH($mode); ?>#pchat-window-invite"><span class="pchat-nav-icon">➕</span>招待</a>
      <a href="./?mode=<?php echo pchatH($mode); ?>#pchat-window-settings"><span class="pchat-nav-icon">⚙️</span>設定</a>
      <a href="./?mode=<?php echo pchatH($mode); ?>#pchat-window-products"><span class="pchat-nav-icon">🌐</span>プロダクト</a>
    </nav>
<?php endif; ?>

    <footer>
      <div><?php echo function_exists('notationDisp') ? notationDisp('notation') : ''; ?></div>
      <div><?php echo function_exists('notationDisp') ? notationDisp('copyright') : ''; ?></div>
    </footer>

<?php $isActiveThread = ($viewRoomId !== '' && $viewRoom !== null && ($viewRoom['status'] ?? '') === 'active'); ?>
    <script>
      // PWAとしてのインストール判定(ホーム画面に追加)は、manifestに加えて有効なService
      // Workerの登録が必要なため、プッシュ通知ボタンを押す前段階から、会話を開いていない
      // ページも含めてサイト全体で登録しておく(通知の購読自体はボタンを押すまで行わない)。
      // push-sw.jsはassets/scripts/配下に置いたままだが、?push_sw=1(index.php)が
      // Service-Worker-Allowed: / を付けて配信するため、.htaccessにもファイルの
      // 置き場所にも依存せずscope: '/'が成立する。
      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('<?php echo pchatH(pchatPushSwUrl()); ?>', { scope: '/' }).catch(function () {});
      }
    </script>
<?php if ($actor !== null): ?>
    <script>
      // プッシュ通知/デスクトップ通知/通知音は、下記スクリプト群が
      // #pchat-notification-settings の中身を丸ごとJSから組み立てる際に使う設定値。
      // (settings-ui.js・push.js・desktop-notify-toggle.js・notify-sound-toggle.js参照)
      //
      // desktopAllowed は「デスクトップ通知を出してよいか」という本人の意思で、
      // pusyuu_push側のdesktopチャンネルに預けてある値。ブラウザの通知許可とは
      // 別物で、こちらは取り消せる(だからトグルが往復する)。
      // 取得できなかったときは null になり、その場合は従来どおり許可だけで判断する。
      window.PchatNotifyConfig = <?php echo json_encode([
        'mountSelector' => '#pchat-notification-settings',
        'vapidKey' => pusyuuPushVapidPublicKey(),
        'csrf' => $csrfToken,
        'swUrl' => pchatPushSwUrl(),
        'swScope' => '/',
        'subscribeUrl' => './?mode=' . $mode,
        'desktopAllowed' => pusyuuPushChannelAllowed($actor['userid'], 'desktop'),
      ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/settings-ui.js')); ?>"></script>
    <script src="<?= pchatSiteBase('main') ?>/pusyuusystem/scripts/push-subscribe.js"></script>
    <script src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/push.js')); ?>"></script>
    <script src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/desktop-notify-toggle.js')); ?>"></script>
    <script src="<?= pchatSiteBase('main') ?>/pusyuusystem/scripts/notify-sound.js"></script>
    <script src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/notify-sound-toggle.js')); ?>"></script>
    <script src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/nav-badge-poll.js')); ?>"></script>
<?php endif; ?>
<?php if ($isActiveThread): ?>
    <script src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/chat.js')); ?>"></script>
    <script src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/attach.js')); ?>"></script>
<?php if (!empty($viewRoom['secret'])): ?>
    <script src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/p2p.js')); ?>"></script>
<?php endif; ?>
<?php endif; ?>
    <script src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/audio-profiles.js')); ?>"></script>
    <script src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/vendor/quiet/quiet.js')); ?>"></script>
    <script src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/invite-share.js')); ?>"></script>
    <script async src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/vendor/quiet/quiet-emscripten.js')); ?>"></script>
    <script src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/sonic-tab-link.js')); ?>"></script>
    <script src="<?php echo pchatH(pchatAssetUrl('./assets/scripts/window-scroll-lock.js')); ?>"></script>
    <script src="<?= pchatSiteBase('main') ?>/pusyuusystem/scripts/touch-device-tool-tip.js"></script>
  </body>
</html>
