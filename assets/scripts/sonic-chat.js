(() => {
  'use strict';

  // =====================================================================
  // 音波タブ: quiet.js(音響モデム)だけで成立する、サーバーを一切使わない
  // 使い捨ての会話。招待コード・room・アカウントのどれにも依存しない
  // (invite-share.jsの音波送受信は招待コード1個の受け渡しで終わる一回きりの
  // 機能だが、こちらは同じ画面を開いている間ずっと送受信を繰り返す)。
  //
  // メッセージは画面のJS変数上にあるだけで、サーバーへの保存もlocalStorage
  // への保存も行わない。ページを閉じる/再読み込みすると会話は完全に消える
  // (このタブの「使い捨て」という前提そのもの)。
  //
  // このファイルはmode=sonicの独立したページ(index.php参照)でのみ読み込まれる。
  // =====================================================================

  const QUIET_ASSET_PREFIX = './assets/scripts/vendor/quiet/';

  // 1メッセージあたりの本文の上限バイト数(UTF-8換算)。大きくしすぎると
  // 送信に時間がかかり、途中で環境音に負けて失敗する確率も上がるため、
  // チャットの1メッセージとして現実的な長さに抑える。
  const MAX_BODY_BYTES = 500;
  // 本文の前に付ける長さヘッダ。ビッグエンディアン2バイト(0〜65535)で
  // MAX_BODY_BYTESを十分に表現できる。quiet.js側はフレーム単位でしか
  // onReceiveを呼ばない(1メッセージが複数フレームに分かれることがある)ため、
  // 受信側は「今どこまでのバイト列が来ていて、メッセージが何バイトで終わるか」
  // をこのヘッダで判断する。
  const LENGTH_HEADER_BYTES = 2;

  // wideband-dsss等、実機でフリーズする可能性のあるプロファイルの原因切り分け用。
  // フリーズが起きた場合、DevTools側の対応する処理が止まるより前に発生した
  // console.log は(コンソール自体がV8のイベントループとは別経路でDevTools
  // Protocol経由に流れるため)通常はフリーズ後もコンソールに残る。そのため
  // 「[sonic]で始まる最後の1行」が、実際に固まった処理の直前のステップを
  // 示す手がかりになる。
  function sonicLog(...args) {
    try { console.log('[sonic]', ...args); } catch (e) { /* noop */ }
  }

  // Quiet.init()は必ずここ(トップレベル、DOMContentLoaded待ちの外)で同期的に
  // 呼ぶ。quiet-emscripten.jsはasyncで読み込んでおり、DOMContentLoaded前に
  // フェッチが終わって実行され得るため、init()の中(DOMContentLoaded後)まで
  // Quiet.init()を遅らせると、quiet.js内部のmemoryInitializerPrefixURL
  // (Quiet.init()のmemoryInitializerPrefixオプションで設定される、quietの
  // 内部変数)が未設定のままonEmscriptenLocateFileが呼ばれ、
  // ReferenceErrorで初期化に失敗する。invite-share.jsも同じ理由で
  // Quiet.init()を最上位で即時に呼んでおり、それに合わせる。
  if (window.Quiet) {
    Quiet.init({
      profilesPrefix: QUIET_ASSET_PREFIX,
      memoryInitializerPrefix: QUIET_ASSET_PREFIX,
    });
  }

  function init() {
    const root = document.querySelector('[data-pchat-sonic-app]');
    if (!root) { return; }

    if (!window.Quiet) {
      showFatal(root, 'quiet.jsを読み込めませんでした。ページを再読み込みしてください。');
      return;
    }
    if (!(window.AudioContext || window.webkitAudioContext)) {
      showFatal(root, 'お使いのブラウザは音波チャットに対応していません。');
      return;
    }
    if (!window.PchatAudioProfiles) {
      showFatal(root, '音の方式の一覧を読み込めませんでした。ページを再読み込みしてください。');
      return;
    }

    buildUi(root);
  }

  function showFatal(root, msg) {
    const p = document.createElement('p');
    p.className = 'form-message error';
    p.textContent = msg;
    root.appendChild(p);
  }

  /** 本文(UTF-8バイト数上限つき)を「長さヘッダ+本文」の1本のArrayBufferにする。長すぎる場合はnull。 */
  function encodeMessage(text) {
    const bodyBuf = Quiet.str2ab(text);
    if (bodyBuf.byteLength > MAX_BODY_BYTES) { return null; }
    const buf = new ArrayBuffer(LENGTH_HEADER_BYTES + bodyBuf.byteLength);
    new DataView(buf).setUint16(0, bodyBuf.byteLength, false);
    new Uint8Array(buf, LENGTH_HEADER_BYTES).set(new Uint8Array(bodyBuf));
    return buf;
  }

  function buildUi(root) {
    const { wrap: profileWrap, select: profileSelect } = window.PchatAudioProfiles.buildSelect();
    root.appendChild(profileWrap);

    const note = document.createElement('p');
    note.className = 'note';
    note.textContent = 'この会話はサーバーに保存されず、ページを閉じる/再読み込みすると消えます。'
      + '近くにいる相手と同じ「音の方式」を選んでください。';
    root.appendChild(note);

    const status = document.createElement('p');
    status.className = 'status-label';
    status.textContent = '準備完了(受信は停止中)';
    root.appendChild(status);

    const messagesEl = document.createElement('div');
    messagesEl.className = 'chat-messages';
    root.appendChild(messagesEl);

    const controls = document.createElement('div');
    controls.className = 'pchat-share-buttons';
    const listenBtn = document.createElement('button');
    listenBtn.type = 'button';
    listenBtn.className = 'small-button';
    listenBtn.textContent = '受信を開始';
    controls.appendChild(listenBtn);
    root.appendChild(controls);

    const form = document.createElement('div');
    form.className = 'chat-send-form';
    const textarea = document.createElement('textarea');
    textarea.rows = 2;
    textarea.placeholder = 'メッセージを入力...';
    const sendBtn = document.createElement('button');
    sendBtn.type = 'button';
    sendBtn.textContent = '音で送信';
    form.appendChild(textarea);
    form.appendChild(sendBtn);
    root.appendChild(form);

    let listening = false;
    let wantListening = false; // 送信中に一時停止した受信を、送信完了後に自動再開すべきか
    let sending = false;
    let rx = null;
    let recvAccum = new ArrayBuffer(0);

    function appendMessage(text, mine) {
      const item = document.createElement('div');
      item.className = 'chat-message' + (mine ? ' mine' : '');
      const body = document.createElement('div');
      body.className = 'chat-message-body';
      body.textContent = text;
      item.appendChild(body);
      messagesEl.appendChild(item);
      messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    // recvAccumに完成したメッセージ(長さヘッダの示すバイト数ぶん)が溜まって
    // いるだけ取り出す。1回のtransmitで複数メッセージぶんのフレームが連続で
    // 届くことは通常無いが、取りこぼしを避けるためループで処理する。
    function drainMessages() {
      while (recvAccum.byteLength >= LENGTH_HEADER_BYTES) {
        const bodyLen = new DataView(recvAccum).getUint16(0, false);
        const totalLen = LENGTH_HEADER_BYTES + bodyLen;
        if (recvAccum.byteLength < totalLen) { break; }
        const bodyBuf = recvAccum.slice(LENGTH_HEADER_BYTES, totalLen);
        recvAccum = recvAccum.slice(totalLen);
        try {
          appendMessage(Quiet.ab2str(bodyBuf), false);
        } catch (e) {
          status.textContent = '受信したデータを解釈できませんでした(ノイズの可能性)。';
        }
      }
    }

    function stopListening() {
      if (rx) { rx.destroy(); rx = null; }
      Quiet.disconnect();
      listening = false;
      recvAccum = new ArrayBuffer(0);
      listenBtn.textContent = '受信を開始';
      listenBtn.classList.remove('pchat-sonic-active');
      if (!sending) { status.textContent = '準備完了(受信は停止中)'; }
    }

    function startListening() {
      if (listening || sending) { return; }
      listening = true;
      recvAccum = new ArrayBuffer(0);
      listenBtn.textContent = '受信を停止';
      listenBtn.classList.add('pchat-sonic-active');
      status.textContent = 'マイクの許可を確認しています...';
      sonicLog('recv: start. profile=' + profileSelect.value);
      Quiet.addReadyCallback(() => {
        sonicLog('recv: addReadyCallback発火。Quiet.receiver()を呼びます');
        if (!listening) { sonicLog('recv: 準備中に停止されたため中断'); return; } // 準備が整うまでの間に停止された
        try {
          rx = Quiet.receiver({
            profile: profileSelect.value,
            onCreate: () => { sonicLog('recv: onCreate発火'); status.textContent = '受信中...'; },
            onCreateFail: (reason) => {
              sonicLog('recv: onCreateFail', reason);
              status.textContent = 'マイクを開始できませんでした。' + reason;
              listening = false;
              listenBtn.textContent = '受信を開始';
              listenBtn.classList.remove('pchat-sonic-active');
            },
            onReceiveFail: () => {
              // チェックサム不一致等でこのフレームは失われた。1メッセージが
              // 複数フレームに分かれている途中でこれが起きると、以後どれだけ
              // 正常なフレームが届いてもrecvAccumは長さヘッダの示すバイト数に
              // 永遠に到達できず(欠けた分は二度と来ない)、drainMessages()が
              // 詰まったまま次の(無関係な)メッセージのバイト列まで巻き込んで
              // 化けさせてしまう。復元しようがないので、その場で組み立て中の
              // ものは諦めて破棄し、次のメッセージの先頭から仕切り直す。
              if (recvAccum.byteLength > 0) {
                sonicLog('recv: onReceiveFail。組み立て中だった' + recvAccum.byteLength + 'バイトを破棄');
                recvAccum = new ArrayBuffer(0);
              }
              if (listening) { status.textContent = 'ノイズを検出しています。位置を調整してみてください...'; }
            },
            onReceive: (payload) => {
              sonicLog('recv: onReceive発火 bytes=' + payload.byteLength);
              recvAccum = Quiet.mergeab(recvAccum, payload);
              drainMessages();
              if (listening) { status.textContent = '受信中...'; }
            },
          });
          sonicLog('recv: Quiet.receiver()から復帰しました');
        } catch (e) {
          sonicLog('recv: Quiet.receiver()で例外', e);
          status.textContent = '受信を開始できませんでした。' + (e && e.message ? e.message : String(e));
          listening = false;
          listenBtn.textContent = '受信を開始';
          listenBtn.classList.remove('pchat-sonic-active');
        }
      }, (reason) => {
        sonicLog('recv: addReadyCallbackが失敗', reason);
        status.textContent = 'quiet.jsの初期化に失敗しました。' + reason;
        listening = false;
        listenBtn.textContent = '受信を開始';
        listenBtn.classList.remove('pchat-sonic-active');
      });
    }

    listenBtn.addEventListener('click', () => {
      if (listening) { stopListening(); } else { startListening(); }
    });

    // Quiet.receiver()は生成時に渡したprofileで固定される。受信中に「音の方式」
    // セレクトを切り替えても、profileSelect.valueは変わるがrx自体は古いprofileの
    // ままデコードし続けてしまい(相手が新しい方式で送っているのにこちらは
    // 別の方式で聞き続ける)、見た目には「ノイズを検出しています」しか出ず
    // 気づきにくい。受信中の切り替えでは新しいprofileでreceiverを作り直す。
    profileSelect.addEventListener('change', () => {
      if (listening) {
        sonicLog('recv: 受信中にプロファイルが変更されたため再起動 profile=' + profileSelect.value);
        stopListening();
        startListening();
      }
    });

    sendBtn.addEventListener('click', () => {
      const text = textarea.value.trim();
      if (text === '' || sending) { return; }
      const payload = encodeMessage(text);
      if (payload === null) {
        status.textContent = 'メッセージが長すぎます。短くしてお試しください。';
        return;
      }

      // 自分のスピーカーの音を自分のマイクが拾って誤動作させないよう、
      // 送信中は受信を止め、送信完了後に元の状態(聞いていたなら再開)へ戻す。
      wantListening = listening;
      if (listening) { stopListening(); }
      sending = true;
      sendBtn.disabled = true;
      status.textContent = '送信中...';

      sonicLog('send: start. profile=' + profileSelect.value + ' bodyBytes=' + (payload.byteLength - LENGTH_HEADER_BYTES));
      sonicLog('send: Quiet.addReadyCallback() を呼びます');
      Quiet.addReadyCallback(() => {
        sonicLog('send: addReadyCallback発火。Quiet.transmitter()を呼びます');
        let tx;
        const finishSend = () => {
          sending = false;
          sendBtn.disabled = false;
          if (wantListening) { startListening(); }
        };
        try {
          tx = Quiet.transmitter(Object.assign({
            profile: profileSelect.value,
            onFinish: () => {
              sonicLog('send: onFinish発火(送信完了)');
              tx.destroy();
              status.textContent = '送信が完了しました。';
              appendMessage(text, true);
              textarea.value = '';
              finishSend();
            },
          }, window.PchatAudioProfiles.transmitOptsFor(profileSelect.value)));
          sonicLog('send: Quiet.transmitter()から復帰。frameLength=' + tx.frameLength + ' tx.transmit()を呼びます');
        } catch (e) {
          sonicLog('send: Quiet.transmitter()で例外', e);
          status.textContent = '送信に失敗しました。' + (e && e.message ? e.message : String(e));
          finishSend();
          return;
        }
        tx.transmit(payload);
        sonicLog('send: tx.transmit()から復帰しました(onFinish待ち)');
      }, (reason) => {
        sonicLog('send: addReadyCallbackが失敗', reason);
        status.textContent = 'quiet.jsの初期化に失敗しました。' + reason;
        finishSend();
      });
    });

    window.addEventListener('beforeunload', () => { if (listening) { stopListening(); } });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
