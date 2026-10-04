window.PchatSettingsUI = (() => {
  'use strict';

  // プッシュ通知/デスクトップ通知/通知音の設定項目は、JSでしか成立しない機能
  // (購読・許可要求・ミュート切り替えの操作そのものがJS前提)なので、no-JS向けの
  // 「非表示プレースホルダーをJSが表示する」形にせず、この共通ヘルパーが項目
  // (見出し・説明・トグル)をまるごと生成して差し込む。JSが動かない環境では
  // #pchat-notification-settings の中身は最初から空のまま。

  function mount(selector) {
    return document.querySelector(selector);
  }

  function appendItem(mountEl, heading, noteText) {
    const item = document.createElement('div');
    item.className = 'pchat-settings-item';

    const h2 = document.createElement('h2');
    h2.textContent = heading;
    item.appendChild(h2);

    if (noteText) {
      const note = document.createElement('p');
      note.className = 'note';
      note.textContent = noteText;
      item.appendChild(note);
    }

    mountEl.appendChild(item);
    return item;
  }

  // 有効/無効を表すtrue/false反転のトグルボタン(aria-pressedで状態を持つ)。
  function appendToggle(item, label, onToggle) {
    const row = document.createElement('div');
    row.className = 'pchat-toggle-row';

    const labelSpan = document.createElement('span');
    labelSpan.className = 'pchat-toggle-label';
    labelSpan.textContent = label;
    row.appendChild(labelSpan);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pchat-toggle';
    btn.setAttribute('aria-pressed', 'false');
    btn.setAttribute('aria-label', label);
    row.appendChild(btn);

    item.appendChild(row);

    btn.addEventListener('click', () => {
      const next = btn.getAttribute('aria-pressed') !== 'true';
      onToggle(next, btn);
    });

    return btn;
  }

  function setToggle(btn, on) {
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
  }

  function appendStatus(item) {
    const status = document.createElement('p');
    status.className = 'note pchat-settings-status';
    item.appendChild(status);
    return status;
  }

  return { mount, appendItem, appendToggle, setToggle, appendStatus };
})();
