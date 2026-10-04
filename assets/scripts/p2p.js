(() => {
  'use strict';

  // =====================================================================
  // シークレット会話モードのWebRTC接続(アカウント有無を問わず、既存の会話
  // (room)内のトグルから使う)。p-reversiのP2Pモード(assets/game.js)と
  // 同じ構成: WebRTCのoffer/answerをbase64文字列に変換し、コピー&ペーストで
  // 手動シグナリングする。接続後はDataChannelで直接テキストメッセージを
  // やり取りする。このサーバーは一切経由せず、メッセージも保存しない
  // (ページ内メモリにのみ存在し、リロードで消える)。
  //
  // このモードはJavaScript無しでは原理的に成立しない(WebRTCはJSでしか
  // 操作できない)ため、サーバー側の初期HTMLには何も置かず、必要なDOMは
  // すべてこのファイルの中で#pchat-secret-root配下に組み立てる。
  // =====================================================================

  const root = document.getElementById('pchat-secret-root');
  if (!root) { return; }

  if (typeof RTCPeerConnection === 'undefined') {
    root.innerHTML = '<p class="form-message error">お使いのブラウザはWebRTCに対応していないため、この機能は利用できません。</p>';
    return;
  }

  const ICE_SERVERS = [{ urls: ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'] }];

  // ---- DOM構築 ----
  function el(tag, attrs, children) {
    const node = document.createElement(tag);
    if (attrs) {
      for (const key in attrs) {
        if (key === 'text') { node.textContent = attrs[key]; }
        else if (key === 'html') { node.innerHTML = attrs[key]; }
        else { node.setAttribute(key, attrs[key]); }
      }
    }
    (children || []).forEach((c) => { if (c) { node.appendChild(c); } });
    return node;
  }

  const roleChoice = el('div', { id: 'p2p-role-choice' }, [
    el('button', { type: 'button', id: 'p2p-create', class: 'button' }, [document.createTextNode('会話を作成する')]),
    el('button', { type: 'button', id: 'p2p-join', class: 'button secondary' }, [document.createTextNode('招待を受けて参加する')]),
  ]);

  const offerOutput = el('textarea', { id: 'p2p-offer-output', readonly: 'readonly', rows: '4' });
  const offerCopy = el('button', { type: 'button', id: 'p2p-offer-copy', class: 'small-button' }, [document.createTextNode('コピーする')]);
  const answerInput = el('textarea', { id: 'p2p-answer-input', rows: '4', required: 'required' });
  const hostMessage = el('p', { id: 'p2p-host-message', class: 'form-message error', role: 'status' });
  const hostAnswerForm = el('form', { id: 'p2p-host-answer-form' }, [
    answerInput,
    el('button', { type: 'submit' }, [document.createTextNode('接続する')]),
    hostMessage,
  ]);
  const hostBlock = el('div', { id: 'p2p-host-block', hidden: 'hidden' }, [
    el('h2', { text: '① この文字列を相手に送ってください' }),
    offerOutput,
    offerCopy,
    el('h2', { text: '② 相手から返ってきた文字列を貼り付けてください' }),
    hostAnswerForm,
    el('button', { type: 'button', class: 'small-button ghost p2p-cancel' }, [document.createTextNode('キャンセル')]),
  ]);

  const offerInput = el('textarea', { id: 'p2p-offer-input', rows: '4', required: 'required' });
  const guestMessage = el('p', { id: 'p2p-guest-message', class: 'form-message error', role: 'status' });
  const guestOfferForm = el('form', { id: 'p2p-guest-offer-form' }, [
    offerInput,
    el('button', { type: 'submit' }, [document.createTextNode('接続情報を作る')]),
    guestMessage,
  ]);
  const answerOutput = el('textarea', { id: 'p2p-answer-output', readonly: 'readonly', rows: '4' });
  const answerCopy = el('button', { type: 'button', id: 'p2p-answer-copy', class: 'small-button' }, [document.createTextNode('コピーする')]);
  const answerOutputBlock = el('div', { id: 'p2p-answer-output-block', hidden: 'hidden' }, [
    el('h2', { text: '② この文字列を相手に送り返してください' }),
    answerOutput,
    answerCopy,
    el('p', { class: 'note', text: '相手が貼り付けると自動的に会話が始まります。' }),
  ]);
  const guestBlock = el('div', { id: 'p2p-guest-block', hidden: 'hidden' }, [
    el('h2', { text: '① 相手から受け取った文字列を貼り付けてください' }),
    guestOfferForm,
    answerOutputBlock,
    el('button', { type: 'button', class: 'small-button ghost p2p-cancel' }, [document.createTextNode('キャンセル')]),
  ]);

  const setupBlock = el('div', { id: 'p2p-setup' }, [roleChoice, hostBlock, guestBlock]);

  const statusLabel = el('p', { id: 'p2p-status', class: 'status-label', role: 'status' });
  const messagesBox = el('div', { id: 'p2p-messages', class: 'chat-messages' });
  const bodyInput = el('textarea', { id: 'p2p-body', rows: '2', required: 'required' });
  const sendForm = el('form', { id: 'p2p-send-form', class: 'chat-send-form' }, [
    bodyInput,
    el('button', { type: 'submit' }, [document.createTextNode('送信')]),
  ]);
  const backButton = el('button', { type: 'button', id: 'p2p-back', class: 'small-button ghost' }, [document.createTextNode('← 最初からやり直す')]);
  const chatSection = el('div', { id: 'p2p-chat-section', hidden: 'hidden' }, [backButton, statusLabel, messagesBox, sendForm]);

  root.appendChild(setupBlock);
  root.appendChild(chatSection);

  const els = {
    createButton: roleChoice.querySelector('#p2p-create'),
    joinButton: roleChoice.querySelector('#p2p-join'),
    hostBlock,
    offerOutput,
    offerCopy,
    hostAnswerForm,
    answerInput,
    hostMessage,
    guestBlock,
    guestOfferForm,
    offerInput,
    guestMessage,
    answerOutput: answerOutputBlock,
    answerOutputField: answerOutput,
    answerCopy,
    backButtons: root.querySelectorAll('.p2p-cancel'),
    chatSection,
    messages: messagesBox,
    sendForm,
    body: bodyInput,
    status: statusLabel,
    backButton,
    setupBlock,
    roleChoice,
  };

  // ---- WebRTCシグナリング・DataChannel ----
  let pc = null;
  let channel = null;

  function encodeDesc(desc) { return btoa(JSON.stringify(desc)); }
  function decodeDesc(str) { return JSON.parse(atob(str.trim())); }

  function waitIceGatheringComplete(peer) {
    return new Promise((resolve) => {
      if (peer.iceGatheringState === 'complete') { resolve(); return; }
      function check() {
        if (peer.iceGatheringState === 'complete') {
          peer.removeEventListener('icegatheringstatechange', check);
          resolve();
        }
      }
      peer.addEventListener('icegatheringstatechange', check);
    });
  }

  function resetPeer() {
    if (pc) { try { pc.close(); } catch (e) {} }
    pc = null;
    channel = null;
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
  }

  function appendMessage(body, mine) {
    const row = document.createElement('div');
    row.className = 'chat-message' + (mine ? ' mine' : '');
    const bodyEl = document.createElement('div');
    bodyEl.className = 'chat-message-body';
    bodyEl.textContent = body;
    row.appendChild(bodyEl);
    els.messages.appendChild(row);
    els.messages.scrollTop = els.messages.scrollHeight;
  }

  function setupChannel() {
    channel.addEventListener('open', () => {
      els.setupBlock.hidden = true;
      els.chatSection.hidden = false;
      els.status.textContent = '接続しました。';
    });
    channel.addEventListener('close', () => {
      els.status.textContent = '接続が切断されました。';
    });
    channel.addEventListener('message', (e) => {
      let msg;
      try { msg = JSON.parse(e.data); } catch (err) { return; }
      if (msg && msg.type === 'text' && typeof msg.body === 'string') {
        appendMessage(msg.body, false);
      }
    });
  }

  function startHost() {
    resetPeer();
    pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
    channel = pc.createDataChannel('pchat');
    setupChannel();
    els.hostBlock.hidden = false;
    els.guestBlock.hidden = true;
    els.roleChoice.hidden = true;
    els.offerOutput.value = '接続情報を作成しています...';
    pc.createOffer()
      .then((offer) => pc.setLocalDescription(offer))
      .then(() => waitIceGatheringComplete(pc))
      .then(() => { els.offerOutput.value = encodeDesc(pc.localDescription); });
  }

  function startGuest() {
    resetPeer();
    els.hostBlock.hidden = true;
    els.guestBlock.hidden = false;
    els.roleChoice.hidden = true;
  }

  function guestSubmitOffer(e) {
    e.preventDefault();
    let offerDesc;
    try { offerDesc = decodeDesc(els.offerInput.value); } catch (err) {
      els.guestMessage.textContent = '接続文字列を解釈できませんでした。コピーミスがないか確認してください。';
      return;
    }
    els.guestMessage.textContent = '';
    pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
    pc.ondatachannel = (e2) => { channel = e2.channel; setupChannel(); };
    pc.setRemoteDescription(offerDesc)
      .then(() => pc.createAnswer())
      .then((answer) => pc.setLocalDescription(answer))
      .then(() => waitIceGatheringComplete(pc))
      .then(() => { els.answerOutput.hidden = false; els.answerOutputField.value = encodeDesc(pc.localDescription); })
      .catch(() => { els.guestMessage.textContent = '接続情報の作成に失敗しました。もう一度やり直してください。'; });
  }

  function hostSubmitAnswer(e) {
    e.preventDefault();
    let answerDesc;
    try { answerDesc = decodeDesc(els.answerInput.value); } catch (err) {
      els.hostMessage.textContent = '接続文字列を解釈できませんでした。コピーミスがないか確認してください。';
      return;
    }
    els.hostMessage.textContent = '';
    pc.setRemoteDescription(answerDesc).catch(() => {
      els.hostMessage.textContent = '接続に失敗しました。もう一度最初からやり直してください。';
    });
  }

  function backToStart() {
    resetPeer();
    els.messages.innerHTML = '';
    els.chatSection.hidden = true;
    els.setupBlock.hidden = false;
    els.roleChoice.hidden = false;
    els.hostBlock.hidden = true;
    els.guestBlock.hidden = true;
    els.answerOutput.hidden = true;
  }

  function copyField(field) {
    field.select();
    field.setSelectionRange(0, field.value.length);
    try { document.execCommand('copy'); } catch (e) {}
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(field.value).catch(() => {});
    }
  }

  function submitSend(e) {
    e.preventDefault();
    if (!channel || channel.readyState !== 'open') { return; }
    const body = els.body.value.trim();
    if (body === '') { return; }
    channel.send(JSON.stringify({ type: 'text', body, ts: Date.now() }));
    appendMessage(body, true);
    els.body.value = '';
  }

  els.createButton.addEventListener('click', startHost);
  els.joinButton.addEventListener('click', startGuest);
  els.offerCopy.addEventListener('click', () => copyField(els.offerOutput));
  els.answerCopy.addEventListener('click', () => copyField(els.answerOutputField));
  els.hostAnswerForm.addEventListener('submit', hostSubmitAnswer);
  els.guestOfferForm.addEventListener('submit', guestSubmitOffer);
  els.backButton.addEventListener('click', backToStart);
  els.backButtons.forEach((btn) => btn.addEventListener('click', backToStart));
  els.sendForm.addEventListener('submit', submitSend);
})();
