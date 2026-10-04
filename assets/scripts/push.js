(() => {
  'use strict';

  // プッシュ通知の設定項目を組み立てて#pchat-notification-settingsに差し込む。
  // 実際の購読処理は共通ヘルパー(pusyuusystem/scripts/push-subscribe.js の
  // window.PusyuuPush)に任せる。
  //
  // このトグルが表しているのは「この端末で受け取るかどうか」。購読はブラウザ
  // (端末)ごとに作られるものなので、別の端末でも受け取りたければその端末でも
  // 押す必要がある。
  //
  // 【切り替えをここに置いている理由】通知の許可も pushManager.subscribe() も、
  // 呼んだページのオリジンにしか効かない。別オリジンの画面に切り替えだけを置くと、
  // 押しても実際には何も起きない半端なスイッチになる。さらにブラウザは、利用者の
  // 操作を伴わない許可要求を自動的に拒否する(「対話がないため自動的にブロック
  // しました」と言われ、拒否の記録だけが残る)ため、許可を求める処理は必ず
  // クリックハンドラの中から呼ぶ必要がある。押す場所と許可を取る場所は離せない。
  //
  // 「動作しているか分かりにくい」への対応として、項目の下に状態メッセージを
  // 常時表示する(成功/失敗を無言で握りつぶさない)。加えて、開発者ツールの
  // コンソールにも同じ内容を残す(スマホ実機での動作確認時、chrome://inspect等の
  // リモートデバッグでこの端末固有の失敗原因を追えるようにするため)。

  if (!window.PchatSettingsUI || !window.PchatNotifyConfig) { return; }
  const ui = window.PchatSettingsUI;
  const config = window.PchatNotifyConfig;

  const mountEl = ui.mount(config.mountSelector);
  if (!mountEl) { return; }

  function fail(message) {
    const item = ui.appendItem(mountEl, '🔔 プッシュ通知', null);
    const status = ui.appendStatus(item);
    status.textContent = message;
    status.style.color = '#ff5a5a';
    console.error('[p-chat push]', message);
  }

  if (!window.PusyuuPush) {
    fail('プッシュ通知の共通スクリプト(push-subscribe.js)を読み込めませんでした。ネットワークや広告ブロッカーの影響の可能性があります。');
    return;
  }
  if (!window.PusyuuPush.isSupported()) {
    const d = window.PusyuuPush.supportDetails ? window.PusyuuPush.supportDetails() : {};
    const missing = [];
    if (d.secureContext === false) { missing.push('安全な接続(HTTPS)ではありません'); }
    if (d.serviceWorker === false) { missing.push('Service Worker 未対応'); }
    if (d.pushManager === false) { missing.push('Push API 未対応'); }
    if (d.notification === false) { missing.push('通知API 未対応'); }
    const detail = missing.length > 0 ? '(' + missing.join('・') + ')' : '(原因不明)';
    fail(
      'このブラウザ/表示方法ではプッシュ通知に対応していません' + detail + '。' +
      'Chromeアプリ等で直接このページを開いていますか?LINEやX(旧Twitter)などのアプリ内ブラウザで開くと、' +
      '見た目はChromeと同じでもこれらの機能が使えないことがよくあります。' +
      'この環境でも受け取りたい場合は、下の「RSSで受け取る」が使えます。'
    );
    return;
  }
  if (!config.vapidKey || !config.swUrl || !config.subscribeUrl) {
    fail('プッシュ通知の設定を取得できませんでした(サーバー側の一時的な問題の可能性があります)。');
    return;
  }

  const item = ui.appendItem(mountEl, '🔔 プッシュ通知', '有効にすると、タブを閉じていても新着メッセージの通知が届きます。この端末ごとの設定です。');
  const status = ui.appendStatus(item);
  const btn = ui.appendToggle(item, 'プッシュ通知', handleToggle);

  function setStatus(text, isError) {
    status.textContent = text;
    status.style.color = isError ? '#cc3333' : '';
    if (text) {
      if (isError) { console.error('[p-chat push]', text); } else { console.log('[p-chat push]', text); }
    }
  }

  if (typeof Notification !== 'undefined' && Notification.permission === 'denied') {
    setStatus('この端末では通知がブロックされています。ブラウザのアドレスバー左の鍵アイコン(またはサイト設定)から、'
      + 'このサイトの通知を「許可」または「確認する」に戻してください。一度ブロックされると、このトグルを押しても何も起きません。', true);
  }

  window.PusyuuPush.isSubscribed().then((subscribed) => {
    ui.setToggle(btn, subscribed);
    if (subscribed) { setStatus('この端末はプッシュ通知を受け取れる状態です。'); }
  });

  function handleToggle(next, btnEl) {
    btnEl.disabled = true;
    setStatus('処理しています...');
    const action = next
      ? window.PusyuuPush.subscribe({ swUrl: config.swUrl, scope: config.swScope, publicKey: config.vapidKey, subscribeUrl: config.subscribeUrl, extraFields: { csrf: config.csrf, action: 'push_subscribe' } })
      : window.PusyuuPush.unsubscribe({ unsubscribeUrl: config.subscribeUrl, extraFields: { csrf: config.csrf, action: 'push_unsubscribe' } });
    action.then((ok) => {
      if (ok) {
        ui.setToggle(btnEl, next);
        setStatus(next ? 'プッシュ通知を有効にしました。この端末に届くか、実際にメッセージを送って確認してください。' : 'プッシュ通知を無効にしました。');
      } else if (next && typeof Notification !== 'undefined' && Notification.permission === 'denied') {
        setStatus('通知の許可が拒否されているため有効にできませんでした。ブラウザのサイト設定から通知を許可してください。', true);
      } else {
        const detail = window.PusyuuPush.getLastError ? window.PusyuuPush.getLastError() : null;
        setStatus('処理に失敗しました' + (detail ? '(' + detail + ')' : '') + '。電波状況を確認するか、しばらくしてからもう一度お試しください。', true);
      }
    }).catch((e) => {
      setStatus('予期しないエラーが発生しました: ' + (e && e.message ? e.message : e), true);
    }).finally(() => { btnEl.disabled = false; });
  }
})();
