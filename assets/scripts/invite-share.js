(() => {
  'use strict';

  // =====================================================================
  // 招待コードのQR表示/読み取り・音波送受信(すべて任意の強化層)。
  //
  // サーバー側の初期HTMLには一切依存しない。[data-pchat-invite-code]
  // (自分の招待コードを表示するreadonlyのtextarea)と
  // [data-pchat-invite-input](招待コードを貼り付ける入力欄)を探し、
  // ボタン・モーダルなど必要なDOMをすべてこのファイルの中で組み立てる。
  // どの機能も手動コピー&ペーストの代替補助であり、失敗しても常に
  // テキストでの貼り付けが行えることを前提にしている。
  // =====================================================================

  const QR_ENDPOINT = './assets/qr/php/qr_img.php';

  // ---- 音波送受信(quiet.js) ----
  // 自前のFSK実装(固定タイミングで周波数のピークを多数決で読む方式)は、送受信
  // 2台が別々の時計で動いていて同期手段が無いこと・部屋の反響・スピーカー/マイクの
  // 周波数特性のムラに対して原理的に脆弱で、実機テストで安定した復号に至らなかった。
  // そのため実績のある音響モデムライブラリ quiet.js (libquietのJSバインディング、
  // エラー訂正符号・チェックサム・フレーミングを内蔵)に置き換える。CDNではなく
  // p-chat/assets/scripts/vendor/quiet/ 配下に固定バージョンをダウンロード保存して
  // 読み込む(index.php側のscriptタグを参照)。
  const QUIET_ASSET_PREFIX = './assets/scripts/vendor/quiet/';
  if (window.Quiet) {
    Quiet.init({
      profilesPrefix: QUIET_ASSET_PREFIX,
      memoryInitializerPrefix: QUIET_ASSET_PREFIX,
    });
  }

  // プロファイル一覧・選択UI・localStorageでの選択保存は、sonic-chat.js
  // (音波での継続的な会話タブ)と共有するためaudio-profiles.jsに一本化した
  // (index.php側でこのファイルより前に読み込む)。経緯のコメントもそちらを参照。
  const buildAudioProfileSelect = () => window.PchatAudioProfiles.buildSelect();

  /** hexは64桁の16進文字列(32バイト相当をUTF-8文字として送るため実ペイロードは64バイト)。 */
  function sendAudioCode(hex, profile, onFinish, onError) {
    if (!window.Quiet) { onError('quiet.jsを読み込めませんでした。'); return; }
    Quiet.addReadyCallback(() => {
      let tx;
      try {
        tx = Quiet.transmitter(Object.assign({
          profile: profile,
          onFinish: () => { tx.destroy(); onFinish(); },
        }, window.PchatAudioProfiles.transmitOptsFor(profile)));
      } catch (e) {
        onError(e && e.message ? e.message : String(e));
        return;
      }
      tx.transmit(Quiet.str2ab(hex));
    }, onError);
  }

  const AUDIO_PAYLOAD_BYTES = 64; // 64桁hex文字列をstr2ab()でそのまま送るため

  /**
   * マイクから音波コードを読み取る。quiet.js側がフレーム単位でしかコールバックを
   * 呼ばないため(1フレームに収まらない場合は複数回に分かれる)、期待バイト数に
   * 達するまでonReceiveの結果を連結する。戻り値はstop関数を解決するPromise。
   * onResultは (hex64桁 | null) で呼ばれる。
   */
  function receiveAudioCode(profile, onResult, onStatus) {
    return new Promise((resolve, reject) => {
      if (!window.Quiet) { reject(new Error('quiet.jsを読み込めませんでした。')); return; }
      let received = new ArrayBuffer(0);
      let rx = null;
      let destroyed = false;
      const stop = () => {
        if (destroyed) { return; }
        destroyed = true;
        if (rx) { rx.destroy(); }
        Quiet.disconnect();
      };
      Quiet.addReadyCallback(() => {
        if (onStatus) { onStatus('マイクの許可を確認しています...'); }
        try {
          rx = Quiet.receiver({
            profile: profile,
            onCreate: () => { if (onStatus) { onStatus('聞き取り中...'); } },
            onCreateFail: (reason) => { reject(new Error(reason)); },
            onReceiveFail: () => { if (onStatus) { onStatus('ノイズを検出しています。位置を調整してみてください...'); } },
            onReceive: (payload) => {
              received = Quiet.mergeab(received, payload);
              if (received.byteLength >= AUDIO_PAYLOAD_BYTES) {
                const hex = Quiet.ab2str(received.slice(0, AUDIO_PAYLOAD_BYTES));
                // quiet.js内部のreadbuf()はwhile(true)ループの中でonReceiveを呼んでおり、
                // このコールバックの中で同期的にrx.destroy()すると、そのループが次の
                // 周回で使おうとするデコーダ/バッファのポインタをまだループが終わって
                // いないうちに解放してしまう(use-after-free)。これがasm.jsのヒープを
                // 破壊し、無限ループ状態のフリーズを起こしていた。setTimeoutで次の
                // タスクへ遅延させ、readbuf()のループが確実に抜けきってから破棄する。
                setTimeout(() => { stop(); onResult(hex); }, 0);
              }
            },
          });
        } catch (e) {
          reject(e);
          return;
        }
        resolve(stop);
      }, (reason) => { reject(new Error(reason)); });
    });
  }

  // ---- 招待コード <-> hex変換 ----
  // 招待コード(base64のJSON、{"v":1,"r":"<32桁hex>","t":"<32桁hex>"})全体を送ると
  // 冗長なため、音波送信だけは中身のr(room_id)とt(invite_token)、合わせて64桁の
  // 16進文字列だけを送り、受信側でJSONを組み立て直す(意味的に同一の招待コードが
  // 復元できればよく、元のbase64文字列とバイト一致している必要はない)。
  function inviteCodeToHex(code) {
    try {
      const data = JSON.parse(atob(code.trim()));
      if (!data || data.v !== 1 || typeof data.r !== 'string' || typeof data.t !== 'string') { return null; }
      if (!/^[a-f0-9]{32}$/.test(data.r) || !/^[a-f0-9]{32}$/.test(data.t)) { return null; }
      return data.r + data.t;
    } catch (e) {
      return null;
    }
  }

  function hexToInviteCode(hex) {
    if (!/^[a-f0-9]{64}$/.test(hex)) { return null; }
    return btoa(JSON.stringify({ v: 1, r: hex.slice(0, 32), t: hex.slice(32, 64) }));
  }

  // ---- モーダルの共通枠 ----
  function createModal(titleText) {
    const overlay = document.createElement('div');
    overlay.className = 'pchat-modal-overlay';
    const modal = document.createElement('div');
    modal.className = 'pchat-modal';
    const h = document.createElement('h3');
    h.textContent = titleText;
    const body = document.createElement('div');
    body.className = 'pchat-modal-body';
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'small-button ghost';
    closeBtn.textContent = '閉じる';

    modal.appendChild(h);
    modal.appendChild(body);
    modal.appendChild(closeBtn);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    let onCloseFn = null;
    let closed = false;
    function close() {
      if (closed) { return; }
      closed = true;
      if (onCloseFn) { onCloseFn(); }
      overlay.remove();
    }
    closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) { close(); } });

    return { body, close, setOnClose(fn) { onCloseFn = fn; } };
  }

  // ---- QR表示 ----
  function showQrModal(code) {
    const modal = createModal('招待コードのQRコード');
    const img = document.createElement('img');
    img.alt = '招待コードのQRコード';
    img.className = 'pchat-qr-image';
    img.src = QR_ENDPOINT + '?d=' + encodeURIComponent(code) + '&e=M&s=5';
    modal.body.appendChild(img);
    const note = document.createElement('p');
    note.className = 'note';
    note.textContent = '相手にこの画面を見せて、「QRを読み取る」でカメラをかざしてもらってください。';
    modal.body.appendChild(note);
  }

  // ---- QR読み取り(ブラウザ標準のBarcodeDetectorのみ使用、外部ライブラリなし) ----
  function showQrScanModal(targetEl) {
    const modal = createModal('QRコードを読み取る');
    const status = document.createElement('p');
    status.className = 'note';
    status.textContent = 'カメラの許可を確認しています...';
    modal.body.appendChild(status);
    const video = document.createElement('video');
    video.setAttribute('playsinline', '');
    video.muted = true;
    video.className = 'pchat-scan-video';
    modal.body.appendChild(video);

    let stream = null;
    let stopped = false;
    modal.setOnClose(() => {
      stopped = true;
      if (stream) { stream.getTracks().forEach((t) => t.stop()); }
    });

    let detector;
    try {
      detector = new window.BarcodeDetector({ formats: ['qr_code'] });
    } catch (e) {
      status.textContent = 'QRコードの読み取りを初期化できませんでした。';
      return;
    }

    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
      .then((s) => {
        stream = s;
        video.srcObject = s;
        return video.play();
      })
      .then(() => {
        status.textContent = 'QRコードを画面に写してください。';
        const loop = () => {
          if (stopped) { return; }
          detector.detect(video).then((codes) => {
            if (stopped) { return; }
            if (codes.length > 0) {
              targetEl.value = codes[0].rawValue;
              status.textContent = '読み取りました。';
              stopped = true;
              stream.getTracks().forEach((t) => t.stop());
              setTimeout(() => modal.close(), 600);
              return;
            }
            requestAnimationFrame(loop);
          }).catch(() => { requestAnimationFrame(loop); });
        };
        requestAnimationFrame(loop);
      })
      .catch((err) => {
        status.textContent = 'カメラを起動できませんでした。' + (err && err.message ? err.message : '');
      });
  }

  function showAudioSendModal(code) {
    const modal = createModal('音波で送信(実験的機能)');
    const hex = inviteCodeToHex(code);
    if (hex === null) {
      const err = document.createElement('p');
      err.className = 'form-message error';
      err.textContent = 'この招待コードは音波送信に対応していない形式です。';
      modal.body.appendChild(err);
      return;
    }
    const note = document.createElement('p');
    note.className = 'note';
    note.textContent = '実験的機能です。近くで静かな環境でお試しください。相手に「音波で受信」を押してもらい、同じ「音の方式」を選んでから再生してください。';
    modal.body.appendChild(note);
    const { wrap: selectWrap, select: profileSelect } = buildAudioProfileSelect();
    modal.body.appendChild(selectWrap);
    const status = document.createElement('p');
    status.className = 'status-label';
    status.textContent = '準備完了';
    modal.body.appendChild(status);
    const playBtn = document.createElement('button');
    playBtn.type = 'button';
    playBtn.className = 'button';
    playBtn.textContent = '再生する';
    modal.body.appendChild(playBtn);

    playBtn.addEventListener('click', () => {
      playBtn.disabled = true;
      status.textContent = '送信中...';
      sendAudioCode(
        hex,
        profileSelect.value,
        () => { status.textContent = '送信が完了しました。'; playBtn.disabled = false; },
        (reason) => { status.textContent = '送信に失敗しました。' + (reason || ''); playBtn.disabled = false; }
      );
    });
  }

  function showAudioReceiveModal(targetEl) {
    const modal = createModal('音波で受信(実験的機能)');
    const note = document.createElement('p');
    note.className = 'note';
    note.textContent = '実験的機能です。相手に近づき、静かな環境で「音波で送信」を再生してもらってください。送信側と同じ「音の方式」を選び、マイクの使用を許可してください。';
    modal.body.appendChild(note);
    const { wrap: selectWrap, select: profileSelect } = buildAudioProfileSelect();
    modal.body.appendChild(selectWrap);
    const status = document.createElement('p');
    status.className = 'status-label';
    status.textContent = '準備中...';
    modal.body.appendChild(status);
    const retryBtn = document.createElement('button');
    retryBtn.type = 'button';
    retryBtn.className = 'small-button';
    retryBtn.textContent = 'もう一度聞き取る';
    retryBtn.style.display = 'none';
    modal.body.appendChild(retryBtn);

    let stopListening = null;
    let closed = false;
    modal.setOnClose(() => { closed = true; if (stopListening) { stopListening(); } });

    // receiveAudioCode()は1回の結果(成功/失敗)が出るとマイクを止めて終わる作り
    // なので、失敗したときに「もう一度送信してもらう」だけでは、こちら側は
    // もう聞いていない(モーダルを開き直すまで再開しない)状態になっていた。
    // それだと送信側で何度再生し直しても届かないため、失敗時は「もう一度
    // 聞き取る」ボタンでこの関数自体を呼び直し、新しい状態で聞き取りを再開
    // できるようにする。
    function startListening() {
      retryBtn.style.display = 'none';
      status.textContent = '準備中...';
      stopListening = null;
      receiveAudioCode(
        profileSelect.value,
        (hex) => {
          if (closed) { return; }
          const code = hexToInviteCode(hex);
          if (code === null) {
            status.textContent = '読み取ったデータを解釈できませんでした。';
            retryBtn.style.display = '';
            return;
          }
          targetEl.value = code;
          status.textContent = '読み取りました。';
          setTimeout(() => modal.close(), 600);
        },
        (msg) => { if (!closed) { status.textContent = msg; } }
      ).then((stop) => { if (closed) { stop(); return; } stopListening = stop; })
        .catch((err) => {
          if (closed) { return; }
          status.textContent = 'マイクを開始できませんでした。' + (err && err.message ? err.message : '');
          retryBtn.style.display = '';
        });
    }

    retryBtn.addEventListener('click', startListening);
    startListening();
  }

  // ---- ボタンの差し込み ----
  function attachOutputButtons(el) {
    const wrap = document.createElement('div');
    wrap.className = 'pchat-share-buttons';

    // QRコード表示はカメラ・マイク等の実機に依存しない(画像を表示するだけ)ため常に出す。
    const qrBtn = document.createElement('button');
    qrBtn.type = 'button';
    qrBtn.className = 'small-button';
    qrBtn.textContent = 'QRで表示';
    qrBtn.addEventListener('click', () => showQrModal(el.value.trim()));
    wrap.appendChild(qrBtn);

    if (window.AudioContext || window.webkitAudioContext) {
      const audioBtn = document.createElement('button');
      audioBtn.type = 'button';
      audioBtn.className = 'small-button';
      audioBtn.textContent = '音波で送信';
      audioBtn.addEventListener('click', () => showAudioSendModal(el.value.trim()));
      wrap.appendChild(audioBtn);
    } else {
      appendUnavailableNote(wrap, 'お使いのブラウザは音波での送信に対応していません。QR表示か手動でのコピーをお使いください。');
    }

    el.insertAdjacentElement('afterend', wrap);
  }

  /**
   * カメラ/マイクの実機の有無を確認する。enumerateDevices()は権限付与前でも
   * kind(videoinput/audioinput)自体は返す仕様なので、「APIが存在するか」だけでなく
   * 「実際にそのデバイスがあるか」まで確認できる。
   */
  function hasMediaDeviceOfKind(kind) {
    if (!navigator.mediaDevices || typeof navigator.mediaDevices.enumerateDevices !== 'function') {
      return Promise.resolve(false);
    }
    return navigator.mediaDevices.enumerateDevices()
      .then((devices) => devices.some((d) => d.kind === kind))
      .catch(() => false);
  }

  /** 非対応の場合、disabled+titleではなく常に見えるテキストで理由を示す。 */
  function appendUnavailableNote(container, message) {
    const note = document.createElement('p');
    note.className = 'note pchat-unavailable-note';
    note.textContent = message;
    container.appendChild(note);
  }

  function attachInputButtons(el) {
    const wrap = document.createElement('div');
    wrap.className = 'pchat-share-buttons';
    el.insertAdjacentElement('afterend', wrap);

    const qrSupported = 'BarcodeDetector' in window;
    if (!qrSupported) {
      appendUnavailableNote(wrap, 'お使いのブラウザはQRコードの読み取りに対応していません。手動で貼り付けてください。');
    } else {
      hasMediaDeviceOfKind('videoinput').then((hasCamera) => {
        if (!hasCamera) {
          appendUnavailableNote(wrap, 'カメラが見つからないため、QR読み取りは利用できません。手動で貼り付けてください。');
          return;
        }
        const qrBtn = document.createElement('button');
        qrBtn.type = 'button';
        qrBtn.className = 'small-button';
        qrBtn.textContent = 'QRを読み取る';
        qrBtn.addEventListener('click', () => showQrScanModal(el));
        wrap.insertBefore(qrBtn, wrap.firstChild);
      });
    }

    const audioSupported = !!(window.AudioContext || window.webkitAudioContext);
    if (!audioSupported) {
      appendUnavailableNote(wrap, 'お使いのブラウザは音波での受信に対応していません。手動で貼り付けてください。');
    } else {
      hasMediaDeviceOfKind('audioinput').then((hasMic) => {
        if (!hasMic) {
          appendUnavailableNote(wrap, 'マイクが見つからないため、音波での受信は利用できません。手動で貼り付けてください。');
          return;
        }
        const audioBtn = document.createElement('button');
        audioBtn.type = 'button';
        audioBtn.className = 'small-button';
        audioBtn.textContent = '音波で受信';
        audioBtn.addEventListener('click', () => showAudioReceiveModal(el));
        wrap.appendChild(audioBtn);
      });
    }
  }

  function init() {
    document.querySelectorAll('[data-pchat-invite-code]').forEach(attachOutputButtons);
    document.querySelectorAll('[data-pchat-invite-input]').forEach(attachInputButtons);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
