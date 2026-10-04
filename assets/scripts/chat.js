(() => {
  'use strict';

  // =====================================================================
  // サーバー中継モードの新着ライブ更新(任意の強化層)。
  //
  // 送信・削除・シークレット切替は常に通常のフォーム送信(フルページ遷移)の
  // ままで、JS無しでも全く同じ内容が見られる。このスクリプトが追加するのは:
  //   1. 裏で定期的にページを再取得し、#pchat-messages の中身だけ差し替える。
  //   2. タブがフォーカスされている間、閲覧している分の既読(mark_read)を
  //      自動でPOSTする(no-JS用の「一覧に戻る(既読を記録)」ボタンは
  //      引き続き有効。JSがあれば読んでいる最中にも既読が伝わるようにする、
  //      という強化)。
  //   3. タブがフォーカスされていない間に新着(相手からの発言)が来たら、
  //      ブラウザ標準のNotification API(ライブラリ不要)でデスクトップ通知を
  //      出す。Service Worker/Push APIを使わないため、このタブ/ページが
  //      開いたままである間だけ機能する(ブラウザを閉じていても届く
  //      「本物のプッシュ通知」ではない)。
  // =====================================================================

  const container = document.getElementById('pchat-messages');
  if (!container) { return; }

  const roomId = container.getAttribute('data-room');
  const csrfToken = container.getAttribute('data-csrf');
  const mode = container.getAttribute('data-mode');
  const opponentName = container.getAttribute('data-opponent-name') || '相手';
  if (!roomId || !csrfToken) { return; }

  // ページ自身のURLには既にmode=meikee|anon&room=<id>が入っているので、
  // そのまま使う。
  const currentUrl = new URL(window.location.href);
  currentUrl.searchParams.set('room', roomId);

  function isNearBottom() {
    return container.scrollHeight - container.scrollTop - container.clientHeight < 80;
  }

  function maxSeqIn(el) {
    let max = 0;
    el.querySelectorAll('.chat-message[data-seq]').forEach((node) => {
      const seq = parseInt(node.getAttribute('data-seq'), 10);
      if (!isNaN(seq) && seq > max) { max = seq; }
    });
    return max;
  }

  function hasUnseenFromOpponent(beforeMaxSeq, freshEl) {
    let found = false;
    freshEl.querySelectorAll('.chat-message[data-seq]').forEach((node) => {
      const seq = parseInt(node.getAttribute('data-seq'), 10);
      if (!isNaN(seq) && seq > beforeMaxSeq && node.getAttribute('data-mine') === '0') {
        found = true;
      }
    });
    return found;
  }

  function bodyTextOf(node) {
    const body = node.querySelector('.chat-message-body');
    return body ? body.textContent : '';
  }

  // ---- 既読の自動送信(タブがフォーカスされている間だけ) ----
  let lastMarkedSeq = maxSeqIn(container);
  let marking = false;

  function markReadIfNeeded() {
    if (marking || !document.hasFocus()) { return; }
    const seq = maxSeqIn(container);
    if (seq <= lastMarkedSeq) { return; }
    marking = true;
    const body = new URLSearchParams({ csrf: csrfToken, action: 'mark_read_ping', room: roomId, up_to_seq: String(seq) });
    fetch('./?mode=' + encodeURIComponent(mode), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    })
      .then(() => { lastMarkedSeq = seq; })
      .catch(() => { /* 次回のポーリングでまた試す */ })
      .finally(() => { marking = false; });
  }

  // ---- デスクトップ通知の実際の表示そのもの(許可を求めるボタンの配線は、
  // 会話を開いていない設定画面からでも使えるよう
  // assets/scripts/desktop-notify-toggle.js に分離した) ----
  function notifyNewMessage(previewText) {
    if (!('Notification' in window) || Notification.permission !== 'granted') { return; }
    // ブラウザの「許可」と、本人の「出してほしい」は別物。許可はJSから取り消せない
    // (Notification APIにrevokeが無い)ので、許可だけを見ていると、一度許可した人は
    // 二度と止められなくなる(desktop-notify-toggle.jsが「ここから無効にはできません」と
    // 案内していたのはこのため)。止める意思の方はpusyuu_push側のdesktopチャンネルに
    // 預けてあり、PchatNotifyConfig.desktopAllowed として渡ってくる。
    // 設定を取得できなかったとき(APIに届かない等)は undefined になるので、その場合は
    // 従来どおり許可だけで判断する。「取得できない=止める」にすると、APIが一時的に
    // 落ちただけで通知が黙って消える方が困る。
    const cfg = window.PchatNotifyConfig;
    if (cfg && cfg.desktopAllowed === false) { return; }
    if (document.hasFocus()) { return; } // 見ている最中は通知しない
    try {
      const n = new Notification(opponentName + 'さんから新着メッセージ', {
        body: previewText.slice(0, 100),
        tag: 'pchat-room-' + roomId, // 同じ会話の通知は1件に集約する
      });
      n.onclick = () => { window.focus(); n.close(); };
    } catch (e) { /* 通知を出せない環境では黙って諦める */ }
  }

  // ---- 通知音の再生そのもの(ON/OFF切り替えボタンの配線は、会話を開いていない
  // 設定画面からでも使えるよう assets/scripts/notify-sound-toggle.js に分離した) ----
  function playNotifySound() {
    if (window.PusyuuNotifySound) { window.PusyuuNotifySound.play(); }
  }

  // ---- 送信の動作音(自分の操作へのフィードバック。通知音とは別の音色) ----
  // 送信フォームは素のPOST→ページ遷移(PRG)なので、遷移が始まる前の submit
  // イベントで鳴らす(preventDefaultはしない。送信自体は通常通り続行する)。
  const sendForm = document.getElementById('pchat-send-form');
  if (sendForm) {
    sendForm.addEventListener('submit', () => {
      if (window.PusyuuNotifySound) { window.PusyuuNotifySound.playAction(); }
    });
  }

  function refresh() {
    fetch(currentUrl.toString(), { credentials: 'same-origin' })
      .then((r) => r.text())
      .then((html) => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const fresh = doc.getElementById('pchat-messages');
        if (!fresh || fresh.innerHTML === container.innerHTML) { return; }

        const beforeMaxSeq = maxSeqIn(container);
        const shouldScroll = isNearBottom();

        // 今回の更新で新しく増えたメッセージのseq(送信者を問わない。枠の色を
        // 一時的に変えるハイライト演出に使う)。
        const newSeqs = [];
        fresh.querySelectorAll('.chat-message[data-seq]').forEach((node) => {
          const seq = parseInt(node.getAttribute('data-seq'), 10);
          if (!isNaN(seq) && seq > beforeMaxSeq) { newSeqs.push(seq); }
        });

        if (hasUnseenFromOpponent(beforeMaxSeq, fresh)) {
          let previewText = '';
          fresh.querySelectorAll('.chat-message[data-seq]').forEach((node) => {
            const seq = parseInt(node.getAttribute('data-seq'), 10);
            if (!isNaN(seq) && seq > beforeMaxSeq && node.getAttribute('data-mine') === '0') {
              previewText = bodyTextOf(node);
            }
          });
          notifyNewMessage(previewText);
          playNotifySound(); // デスクトップ通知の許可状況やフォーカス有無に関わらず、これだけは常に鳴らす
        }

        container.innerHTML = fresh.innerHTML;
        if (shouldScroll) {
          container.scrollTop = container.scrollHeight;
        }

        // 新着メッセージだけ、枠の色を一時的に変えて気づきやすくする(約5秒後に
        // 元の色へ戻す。style.cssの.chat-message-flashとtransitionで実現)。
        newSeqs.forEach((seq) => {
          const el = container.querySelector('.chat-message[data-seq="' + seq + '"]');
          if (!el) { return; }
          el.classList.add('chat-message-flash');
          setTimeout(() => { el.classList.remove('chat-message-flash'); }, 5000);
        });

        markReadIfNeeded();
      })
      .catch(() => { /* ネットワークエラー時は次回のポーリングに任せる */ });
  }

  container.scrollTop = container.scrollHeight;
  markReadIfNeeded();
  window.addEventListener('focus', markReadIfNeeded);
  setInterval(refresh, 3000);

  // ---- 返信/引用(強化層) ----
  // no-JS版は「返信」リンクの素のhref遷移(?reply_to=<seq>でページ再読み込み)で
  // 完結する(サーバー側でreply_toを検証・保存する処理はindex.php側にある)。
  // ここではその遷移を省略し、既にDOM上にある本文からその場でプレビューを
  // 組み立てるだけの強化を行う。返信リンクは3秒ごとのrefresh()でcontainerの
  // 中身ごと差し替わるため、個々のリンクへ直接bindはせずcontainer自体に
  // イベント委任する。sendFormは上の送信音配線で既に取得済みのものをそのまま使う。
  const replyToInput = document.getElementById('pchat-reply-to-input');
  const bodyInput = document.getElementById('pchat-body-input');

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function clearReply() {
    if (replyToInput) { replyToInput.value = ''; }
    const preview = document.getElementById('pchat-reply-preview');
    if (preview) { preview.remove(); }
  }

  function bindCancelLink(link) {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      clearReply();
    });
  }

  function setReply(seq, senderLabel, bodyText) {
    if (!replyToInput || !sendForm) { return; }
    replyToInput.value = seq;

    let preview = document.getElementById('pchat-reply-preview');
    if (!preview) {
      preview = document.createElement('div');
      preview.id = 'pchat-reply-preview';
      preview.className = 'chat-reply-preview';
      sendForm.parentNode.insertBefore(preview, sendForm);
    }
    preview.innerHTML =
      '<div class="chat-reply-preview-body">' +
      '<span class="chat-message-quote-sender">' + escapeHtml(senderLabel) + 'へ返信</span>' +
      '<span class="chat-message-quote-body">' + escapeHtml(bodyText.slice(0, 80)) + '</span>' +
      '</div>' +
      '<a class="small-button ghost" id="pchat-reply-cancel">キャンセル</a>';
    bindCancelLink(preview.querySelector('#pchat-reply-cancel'));

    if (bodyInput) { bodyInput.focus(); }
  }

  container.addEventListener('click', (e) => {
    const link = e.target.closest('.chat-message-reply-link');
    if (!link) { return; }
    e.preventDefault();
    const messageEl = link.closest('.chat-message');
    const bodyEl = messageEl ? messageEl.querySelector('.chat-message-body') : null;
    const isMine = messageEl && messageEl.getAttribute('data-mine') === '1';
    setReply(link.getAttribute('data-seq'), isMine ? 'あなた' : opponentName, bodyEl ? bodyEl.textContent : '');
  });

  // サーバー側で描画された(no-JS遷移直後の)キャンセルリンクにも同じ挙動を割り当てる。
  const initialCancelLink = document.getElementById('pchat-reply-cancel');
  if (initialCancelLink) { bindCancelLink(initialCancelLink); }

  // ---- 「入力中…」表示(強化層。JS無しでは何も起きない) ----
  // 自分の入力状態を送るのは数秒に1回まで(キー入力のたびに送らない)。
  // 表示側は既存の3秒間隔refresh()と同じ周期でポーリングする。
  const typingIndicator = document.getElementById('pchat-typing-indicator');
  let lastTypingPingAt = 0;

  function pingTyping() {
    const now = Date.now();
    if (now - lastTypingPingAt < 4000) { return; }
    lastTypingPingAt = now;
    const body = new URLSearchParams({ csrf: csrfToken, action: 'typing_ping', room: roomId });
    fetch('./?mode=' + encodeURIComponent(mode), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    }).catch(() => { /* 次の入力でまた試す */ });
  }

  if (bodyInput) {
    bodyInput.addEventListener('input', pingTyping);
  }

  // GETのクエリパラメータ(?typing_status=1&room=...)にしないのは意図的。
  // アクセスログやブラウザ履歴に「いつ・どの会話の入力状況を覗いたか」が
  // 平文で残らないよう、他のポーリング(mark_read_ping等)と同じPOSTにしている。
  function pollTyping() {
    if (!typingIndicator) { return; }
    const body = new URLSearchParams({ csrf: csrfToken, action: 'typing_status', room: roomId });
    fetch('./?mode=' + encodeURIComponent(mode), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    })
      .then((r) => r.json())
      .then((data) => { typingIndicator.hidden = !data.typing; })
      .catch(() => { /* 次回のポーリングでまた試す */ });
  }

  setInterval(pollTyping, 3000);
})();
