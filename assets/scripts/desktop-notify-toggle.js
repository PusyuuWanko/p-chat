(() => {
  'use strict';

  // デスクトップ通知の設定項目。実際の通知表示はchat.jsのnotifyNewMessage()
  // (会話を開いている時だけ)。
  //
  // 【このトグルが往復できるようになった理由】
  // ブラウザの通知許可はJSから取り消せない(Notification APIに revoke が無い)。
  // 以前のトグルはそれだけを見ていたので片道で、「一度許可すると、ここからは
  // 無効にできません」と案内するしかなかった。
  //
  // 原因は2つの別物が混ざっていたこと:
  //   ブラウザの許可 … そのブラウザが通知を出せるかという"能力"。取り消せない
  //   利用者の意思   … 通知を出してほしいかどうか。これはサーバに預けられる
  //
  // 意思の方を pusyuu_push の desktop チャンネルへ移した(p-chatの
  // action=notify_channel 経由で書き込む)。chat.jsがその値を見るので、許可を
  // 取り消せなくても「出さない」が確実に成立する。つまりこのトグルが操作するのは
  // 意思の方で、許可は「まだ取っていなければ、オンにするついでに取る」だけ。
  //
  // 【許可を求めるのは必ずクリックの中から】ブラウザは、利用者の操作を伴わない
  // 許可要求を自動的に拒否する(「対話がないため自動的にブロックしました」と
  // 言われ、拒否の記録だけが残って以後は求め直せなくなる)。ページ読み込み時に
  // requestPermission()を呼ぶ形へ「親切に」変えないこと。

  if (!window.PchatSettingsUI || !window.PchatNotifyConfig) { return; }
  if (!('Notification' in window)) { return; }
  const ui = window.PchatSettingsUI;
  const config = window.PchatNotifyConfig;

  const mountEl = ui.mount(config.mountSelector);
  if (!mountEl) { return; }

  const item = ui.appendItem(mountEl, '🔔 デスクトップ通知', 'タブを開いている間だけ、新着メッセージをブラウザの通知として表示します。');
  const status = ui.appendStatus(item);
  const btn = ui.appendToggle(item, 'デスクトップ通知', handleToggle);

  // 「出す」と言えるのは、ブラウザが許可していて、かつ本人が止めていないとき。
  // desktopAllowed が null(サーバの設定を取得できなかった)ときは、従来どおり
  // 許可だけで判断する。
  function wanted() {
    return config.desktopAllowed !== false;
  }
  function effective() {
    return Notification.permission === 'granted' && wanted();
  }

  function sync() {
    ui.setToggle(btn, effective());
    btn.disabled = false;

    if (Notification.permission === 'denied') {
      status.textContent = 'この端末では通知がブロックされています。ブラウザのアドレスバー左の鍵アイコン(またはサイト設定)から、'
        + 'このサイトの通知を「許可」または「確認する」に戻してください。';
      status.style.color = '#cc3333';
      btn.disabled = true; // ここからは許可を求め直せないので、押せても何も起きない
      return;
    }
    status.style.color = '';
    if (Notification.permission === 'default') {
      status.textContent = wanted()
        ? 'オンにすると、この端末のブラウザに通知の許可を求めます。'
        : '「出さない」に設定されています。';
      return;
    }
    // granted
    status.textContent = wanted()
      ? 'この端末は許可済みです。会話を開いている間、新着を通知します。'
      : 'この端末は許可済みですが、「出さない」に設定されているため表示しません。';
  }
  sync();

  function saveChannel(state) {
    return fetch(config.subscribeUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'notify_channel', csrf: config.csrf, channel: 'desktop', state: state }).toString(),
    }).then((r) => r.json()).then((d) => !!(d && d.ok));
  }

  function handleToggle(next, btnEl) {
    btnEl.disabled = true;
    status.style.color = '';
    status.textContent = '処理しています...';

    // オンにするとき、まだ許可を取っていなければここで求める。
    // この関数はクリックハンドラの中なので、許可を求めてよい唯一の場所。
    const permission = (next && Notification.permission === 'default')
      ? Notification.requestPermission()
      : Promise.resolve(Notification.permission);

    permission.then(() => saveChannel(next ? 'allow' : 'deny')).then((ok) => {
      if (ok) {
        config.desktopAllowed = next;
        sync();
        return;
      }
      status.textContent = '設定を保存できませんでした。しばらくしてからもう一度お試しください。';
      status.style.color = '#cc3333';
      btnEl.disabled = false;
    }).catch((e) => {
      status.textContent = '予期しないエラーが発生しました: ' + (e && e.message ? e.message : e);
      status.style.color = '#cc3333';
      btnEl.disabled = false;
    });
  }
})();
