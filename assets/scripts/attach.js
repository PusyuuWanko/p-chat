(() => {
  'use strict';

  // =====================================================================
  // JS有効時のみ有効になる、添付ファイルのチャンク分割アップロード
  // (最大5GB)。既存の素のフォーム(#pchat-attach-form)のsubmitを横取りし、
  // preventDefault()した上でfetch()による分割アップロードに切り替える。
  //
  // 【重要】このリスナーが発火した(=JSが機能している)時点で、以降の送信は
  // 完全にfetch任せにする。途中で失敗しても素のform.submit()へは絶対に
  // フォールバックしない(以前はフォールバックしていたが、5GB級の大きい
  // ファイルでチャンク処理が何らかの理由で失敗した際、その巨大ファイルを
  // まるごと1回のnative POSTで送ろうとしてPHPのpost_max_size/
  // upload_max_filesizeを突破し、エラー+ページリロードを引き起こす実害が
  // あったため撤廃した。失敗時はエラーを表示し、ユーザーが送信ボタンを
  // 押し直せば同じJSの経路で再試行できるようにする)。
  //
  // JS自体が動かない(スクリプトが読み込めない等)場合はこのリスナー自体が
  // 存在しないため、素のフォームがブラウザの既定動作でそのままPOSTされる
  // (=JS無しでも動く、という方針はここで保たれる。JS「が動いているのに」
  // 素のPOSTに戻ることは無い、という区別が今回のポイント)。
  //
  // 暗号化はここでは行わない(p-5second側(index.php?api=...)がサーバー自身の鍵で
  // 暗号化するため、WebCryptoは不要)。ブラウザは常にp-chat自身としか通信しない。
  //
  // 完了後はページ遷移(リロード)しない。attach_finalizeが返すurlを直接
  // 本文テキストエリアへ挿入するだけで完結させる。
  // =====================================================================

  const form = document.getElementById('pchat-attach-form');
  const input = document.getElementById('pchat-attach-input');
  const ttlSelect = document.getElementById('pchat-attach-ttl');
  const submitBtn = document.getElementById('pchat-attach-submit');
  const status = document.getElementById('pchat-attach-status');
  const progress = document.getElementById('pchat-attach-progress');
  const progressFill = document.getElementById('pchat-attach-progress-fill');
  const bodyInput = document.getElementById('pchat-body-input');
  if (!form || !input || !submitBtn || !status || !bodyInput) { return; }
  if (typeof fetch === 'undefined' || typeof FormData === 'undefined') { return; }

  function setStatus(text, isError) {
    status.textContent = text;
    status.style.color = isError ? '#ff5a5a' : '';
    if (isError) { console.error('[p-chat attach]', text); }
  }

  function showProgress(pct) {
    if (!progress || !progressFill) { return; }
    progress.hidden = false;
    progressFill.style.width = Math.max(0, Math.min(100, pct)) + '%';
  }

  function hideProgress() {
    if (!progress) { return; }
    progress.hidden = true;
    if (progressFill) { progressFill.style.width = '0%'; }
  }

  function randomHex(bytes) {
    if (window.crypto && crypto.getRandomValues) {
      const arr = new Uint8Array(bytes);
      crypto.getRandomValues(arr);
      return Array.from(arr).map((b) => b.toString(16).padStart(2, '0')).join('');
    }
    let out = '';
    for (let i = 0; i < bytes * 2; i++) { out += Math.floor(Math.random() * 16).toString(16); }
    return out;
  }

  function bytesLabel(n) {
    if (n >= 1024 * 1024 * 1024) { return (n / 1024 / 1024 / 1024).toFixed(2) + 'GB'; }
    if (n >= 1024 * 1024) { return (n / 1024 / 1024).toFixed(1) + 'MB'; }
    if (n >= 1024) { return (n / 1024).toFixed(1) + 'KB'; }
    return n + 'B';
  }

  function ttlLabel() {
    if (!ttlSelect) { return ''; }
    const opt = ttlSelect.options[ttlSelect.selectedIndex];
    return opt ? opt.textContent : '';
  }

  function postForm(fd) {
    // 【重要】form.action は使わない。このフォームには <input name="action"> があり、
    // ブラウザは名前付きフォームコントロールをform要素のプロパティとして自動公開する
    // ため、form.action は本来のURL文字列ではなくその<input>要素そのものを返して
    // しまう(HTMLの有名な罠)。文字列化されると "[object HTMLInputElement]" になり、
    // 存在しないURLへPOSTして404→HTMLエラーページ→JSONパース失敗、という不具合の
    // 直接の原因になっていた。getAttribute('action')なら生の属性値を確実に読める。
    //
    // X-Requested-Withは、PHP側が「$_POSTが空になるほどのサイズ超過」を検知した際に、
    // 通常のページ遷移アクションかfetch()専用アクションかを判別するために使う
    // (無いとエラー時にリダイレクト先のHTMLをそのまま受け取ってJSONパースに失敗する)。
    return fetch(form.getAttribute('action'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
    }).then((r) => r.json());
  }

  function uploadChunked(file, limits) {
    const chunkSize = limits.chunk_size;
    const uploadId = randomHex(16);
    const totalChunks = Math.max(Math.ceil(file.size / chunkSize), 1);
    const csrf = form.elements['csrf'].value;
    const room = form.elements['room'].value;
    const ttl = ttlSelect ? ttlSelect.value : '2592000';
    let uploaded = 0;

    function putChunk(index) {
      const start = index * chunkSize;
      const end = Math.min(start + chunkSize, file.size);
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('action', 'attach_chunk');
      fd.append('room', room);
      fd.append('uploadId', uploadId);
      fd.append('index', String(index));
      fd.append('chunk', file.slice(start, end), 'chunk');
      return postForm(fd).then((res) => {
        if (!res.ok) { throw new Error(res.message || res.error || 'chunk_failed'); }
        uploaded += (end - start);
        const pct = Math.round((uploaded / file.size) * 100);
        showProgress(pct);
        setStatus('アップロード中... ' + pct + '% (' + bytesLabel(uploaded) + ' / ' + bytesLabel(file.size) + ')');
      });
    }

    function finalize() {
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('action', 'attach_finalize');
      fd.append('room', room);
      fd.append('uploadId', uploadId);
      fd.append('totalChunks', String(totalChunks));
      fd.append('name', file.name);
      fd.append('mime', file.type || 'application/octet-stream');
      fd.append('ttl', ttl);
      return postForm(fd).then((res) => {
        if (!res.ok || !res.url) { throw new Error(res.message || res.error || 'finalize_failed'); }
        return res.url;
      });
    }

    showProgress(0);
    function step(i) {
      if (i >= totalChunks) { return finalize(); }
      return putChunk(i).then(() => step(i + 1));
    }

    return step(0);
  }

  form.addEventListener('submit', (e) => {
    const file = input.files[0];
    if (!file) { return; }
    e.preventDefault();
    submitBtn.disabled = true;
    setStatus('準備しています...');

    fetch(form.getAttribute('data-limits-url'), { credentials: 'same-origin' })
      .then((r) => r.json())
      .then((limits) => {
        if (!limits || !limits.chunk_size) { throw new Error((limits && limits.message) || 'limits_unavailable'); }
        if (file.size > limits.max_file_bytes) {
          setStatus('ファイルが大きすぎます(上限' + bytesLabel(limits.max_file_bytes) + ')。', true);
          submitBtn.disabled = false;
          return null;
        }
        return uploadChunked(file, limits);
      })
      .then((url) => {
        if (!url) { return; }
        const sep = bodyInput.value && !/\n$/.test(bodyInput.value) ? '\n' : '';
        bodyInput.value = bodyInput.value + sep + url;
        setStatus('添付しました(保存期間: ' + ttlLabel() + ')。');
        hideProgress();
        submitBtn.disabled = false;
        input.value = '';
      })
      .catch((err) => {
        // ここでform.submit()へフォールバックしない(理由は冒頭コメント参照)。
        // エラーを表示して送信ボタンを再度押せるようにするだけに留め、
        // 再試行も同じJSの経路(fetch)を通す。
        hideProgress();
        const detail = err && err.message ? err.message : String(err);
        setStatus('添付に失敗しました(' + detail + ')。もう一度お試しください。', true);
        submitBtn.disabled = false;
      });
  });
})();
