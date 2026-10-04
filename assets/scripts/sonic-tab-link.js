(() => {
  'use strict';

  // =====================================================================
  // 「音波」タブへのリンクをJSでだけ差し込む。
  //
  // 音波タブ(mode=sonic)はquiet.js等のJSが無いと丸ごと機能しないため、
  // JS無効環境ではそもそもリンク自体を見せない(押しても何も出来ない
  // タブを常設HTMLに置かない)。簡易表示(?lite=1)はJSを一切読み込まない
  // 設計なので、このファイルもそちらには読み込まれず、結果として簡易表示に
  // 音波タブは出ない(意図した挙動)。
  // =====================================================================

  function init() {
    const nav = document.querySelector('.mode-tabs');
    if (!nav) { return; }
    const a = document.createElement('a');
    a.href = './?mode=sonic';
    a.className = 'pchat-sonic-tab-link';
    a.textContent = '音波';
    nav.appendChild(a);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
