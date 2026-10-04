(() => {
  'use strict';

  // 通知音の設定項目。実際に鳴らす側はchat.jsのplayNotifySound()(会話を開いて
  // いる時だけ読み込まれる)が担うが、ミュート状態自体はwindow.PusyuuNotifySound
  // (notify-sound.js)側で保持されるため、このトグルは会話を開いていなくても
  // 独立して動く。

  if (!window.PchatSettingsUI || !window.PchatNotifyConfig || !window.PusyuuNotifySound) { return; }
  const ui = window.PchatSettingsUI;
  const config = window.PchatNotifyConfig;

  const mountEl = ui.mount(config.mountSelector);
  if (!mountEl) { return; }

  const item = ui.appendItem(mountEl, '🔈 通知音', null);
  const btn = ui.appendToggle(item, '通知音', handleToggle);
  sync();

  function sync() {
    ui.setToggle(btn, !window.PusyuuNotifySound.isMuted());
  }

  function handleToggle(next) {
    window.PusyuuNotifySound.setMuted(!next);
    sync();
  }
})();
