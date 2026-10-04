(() => {
  'use strict';

  // =====================================================================
  // 下ナビゲーション「会話」アイコンの未読バッジを、会話一覧を開いていない
  // 画面(設定画面・会話中等)にいる間も定期的に更新する(任意の強化層)。
  // no-JS/簡易表示では、これまで通り次回のページ読み込み時にしか更新されない。
  // =====================================================================

  const link = document.querySelector('.pchat-arcade-nav a[href$="#pchat-window-rooms"]');
  if (!link) { return; }

  function render(total) {
    let badge = link.querySelector('.pchat-nav-badge');
    if (total > 0) {
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'pchat-unread-badge pchat-nav-badge';
        link.appendChild(badge);
      }
      badge.textContent = total > 99 ? '99+' : String(total);
    } else if (badge) {
      badge.remove();
    }
  }

  function poll() {
    fetch('./?unread_count=1', { credentials: 'same-origin' })
      .then((r) => r.json())
      .then((data) => render(data.total || 0))
      .catch(() => { /* 次回のポーリングでまた試す */ });
  }

  // 会話中のchat.js(3秒間隔)ほどシビアである必要はないので間隔は緩め。
  setInterval(poll, 10000);
  window.addEventListener('focus', poll);
})();
