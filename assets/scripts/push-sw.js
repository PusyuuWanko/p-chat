/*
  プシューチャットのプッシュ通知用Service Worker。

  【重要】assets/scripts/配下に置かれているが、制御範囲(scope)はサイト全体(/)。
  Service Workerのscopeは既定でこのファイル自身の置き場所より深い階層に限られる
  ため、素の状態だとassets/scripts/配下しか制御できない。これを.htaccessの
  mod_headers(Service-Worker-Allowedヘッダー)で解決すると、.htaccessが失われた
  瞬間にscope: '/'も一緒に失われてしまう(サーバー設定への依存)。そのため、
  このファイルを静的配信するのはやめ、index.phpの`?push_sw=1`(早期GET分岐)
  経由でPHP自身が`Service-Worker-Allowed: /`ヘッダーを付けて配信している
  (pchatPushSwUrl()参照)。サーバー設定にもファイルの置き場所にも依存しない。

  登録側(index.php)では`{ scope: '/' }`を明示指定してscopeをルートへ広げている。
  ただしこの`{scope:'/'}`はページのURLを基準に解決されるが、このファイル内の
  fetch()やopenWindow()の相対URLはあくまで「このスクリプト自身の場所」基準の
  ままなので、下記のとおりルート絶対パス('/...')で書く必要がある(相対パス
  ('./...')のままにすると./assets/scripts/...を指してしまい、以前まさにこれが
  原因でpushイベント受信後のinbox取得(fetch('./?push_inbox=1'))が常に的外れな
  URLへ飛んでいた)。

  p-memoのsw.js(静的ファイルのキャッシュ)とは役割が別で、こちらはプッシュの
  受信専用。オフラインキャッシュは行わない(キャッシュがログイン状態や
  未読件数とズレるとかえって紛らわしいため)。fetchイベントは、Service Workerが
  「インストール可能」と判定されるための最低限の実装として、何もしない
  (素通しする)リスナーだけを置いている。

  中身の無い(empty payload)プッシュしか受け取らない設計になっている。
  詳しくは main/pusyuusystem/apis/pusyuu_push/PUSH_INTEGRATION_SPEC.md 参照。
*/

self.addEventListener('fetch', function () {
  // 何もしない(event.respondWith()を呼ばない=通常のネットワーク取得のまま)。
  // キャッシュは一切行わない(ログイン状態や未読件数を反映したページを
  // キャッシュから返すと古い状態が表示されてしまうため)。
});

// userVisibleOnly:trueで購読している以上、pushイベントを受けたら必ず最低1件は
// 通知を出す契約になっている(出さない=「サイレントプッシュ」が積み重なると、
// ブラウザ側がこのオリジンの通知権限そのものを自動的に剥奪することがある)。
// inbox取得が失敗した/0件だった場合でも、この最低限の通知だけは必ず出す。
function showFallbackNotification() {
  return self.registration.showNotification('プシューチャット', {
    body: '新着があります。アプリを開いて確認してください。',
    tag: 'pchat-push-fallback',
  });
}

self.addEventListener('push', function (event) {
  // 「動作しているか分かりにくい」対策として、各段階をコンソールへ残す
  // (chrome://inspect等のリモートデバッグでこの端末固有の状況を追える)。
  console.log('[p-chat push-sw] push イベントを受信しました。', Date.now());
  event.waitUntil(
    fetch('/?push_inbox=1', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var items = (data && data.items) || [];
        console.log('[p-chat push-sw] inbox取得: ' + items.length + '件');
        if (items.length === 0) { return showFallbackNotification(); }
        return Promise.all(items.map(function (n) {
          return self.registration.showNotification(n.title, {
            body: n.body,
            tag: n.tag || undefined,
            icon: n.icon || undefined,
            data: { url: n.url || '/' }
          });
        }));
      })
      .catch(function (e) {
        // 取得に失敗しても、サイレントプッシュを避けるため最低限の通知は出す。
        console.error('[p-chat push-sw] inboxの取得に失敗しました。', e);
        return showFallbackNotification();
      })
  );
});

// ブラウザが購読を自動更新/失効させた際に発火する(鍵のローテーション・
// ブラウザ側の内部都合等で普通に起こり得る)。ここを実装していないと、
// 発火した瞬間から通知が「何も変えていないのに」永久に届かなくなる
// (再購読しない限り直らない)。event.oldSubscription.optionsに元の
// applicationServerKey等が残っているため、VAPID公開鍵を改めて取得する必要はない。
self.addEventListener('pushsubscriptionchange', function (event) {
  console.log('[p-chat push-sw] pushsubscriptionchange イベントを受信しました。再購読します。');
  event.waitUntil(
    self.registration.pushManager
      .subscribe(event.oldSubscription ? event.oldSubscription.options : { userVisibleOnly: true })
      .then(function (subscription) {
        return fetch('/?push_resubscribe=1', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ subscription: subscription.toJSON() }),
        });
      })
      .catch(function (e) {
        console.error('[p-chat push-sw] 再購読に失敗しました。', e);
      })
  );
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var url = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil(
    clients.matchAll({ type: 'window' }).then(function (list) {
      for (var i = 0; i < list.length; i++) {
        if ('focus' in list[i]) { return list[i].focus(); }
      }
      return clients.openWindow(url);
    })
  );
});
