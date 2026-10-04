(() => {
  'use strict';

  // .pchat-window(:target)が開いている間、背後のbody側がスクロールしてしまうのを
  // 防ぐ(フルスクリーン表示のつもりでも、指を離した拍子に背後のトップページが
  // 動いて見えたり、iOSでラバーバンドスクロールが起きたりするため)。
  // ウィンドウ自身の内部スクロール(.pchat-window:target { overflow-y: auto })は
  // CSSだけで元々JS無しでも機能しているので、そちらには触れない。ここはあくまで
  // 背後のbody側だけをJSで止める追加の強化機能(JS無しでも開閉自体は今まで通り
  // 動く)。

  function isWindowOpen() {
    const id = location.hash.slice(1);
    if (!id) { return false; }
    const el = document.getElementById(id);
    return !!el && el.classList.contains('pchat-window');
  }

  function sync() {
    document.body.classList.toggle('pchat-no-scroll', isWindowOpen());
  }

  sync();
  window.addEventListener('hashchange', sync);
})();
