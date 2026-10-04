(() => {
  'use strict';

  // =====================================================================
  // quiet.js(音波送受信)のプロファイル一覧・選択UIの共通部分。
  // invite-share.js(招待コードの一回きりの送受信)とsonic-chat.js(音波での
  // 継続的な会話)の両方から使われるため、選択肢の定義とlocalStorageの
  // 選択保存キーをここに一本化する(どちらの画面で選んでも同じ「音の方式」が
  // 引き継がれる)。
  //
  // 選択肢は生の技術名(GMSK/周波数[Hz]等)ではなく、周波数帯ごとに「何が
  // 得意か」を短い言葉と表で示す形にしている。ただし段階数を固定の3つに
  // 絞ってはいない: wideband-dsss(DSSSコーデックの実機フリーズ)や
  // ultrasonic(19kHz帯でのスマホの感度低下)のように、理屈の上では
  // 妥当でも実機でしか分からない問題が過去に何度もあったため、実機で
  // 送受信できることを確認済みの選択肢(audible・mosquito含む)は理論上の
  // 3分類に無理にまとめず、そのまま残してある。
  //
  // 到達距離の目安は、機種・環境ごとの実測値ではなく「低い周波数ほど空気中
  // での減衰・スピーカーの指向性が弱く遠くまで届く」という物理特性からの
  // 見積もりに過ぎない(目安)。balancedは追加したばかりで実機未検証。
  // 実機での送受信・距離比較テストを行ったら、ここの数値・並び順・
  // 「未検証」表記を実測結果に更新すること。
  //
  // いずれもouter_fec_scheme:noneでlibfec.js(別途動的リンクが必要な
  // Reed-Solomon等)を要らないプロファイルのみを選んでいる。
  //
  // wideband-dsss(dsss方式、17kHz)は以前のセッションで「送信側で実機が
  // フリーズする」と記録され選択肢から外していたが、その記録自体が前セッションの
  // 文脈詰め込みすぎによるハルシネーションだった可能性を指摘されたため、
  // 再検証のため選択肢に戻す。フリーズが実際に起きるかどうかまだ未確定なので、
  // すぐに気づけるよう短いメッセージ1通から試すこと。再度フリーズを確認したら
  // また外し、確認できなければこのコメントを実機確認済みの記録に更新すること。
  const AUDIO_PROFILES = [
    {
      value: 'bass',
      priority: '飛びやすさ優先',
      freqLabel: '1kHz 〜 3kHz',
      audibilityLabel: 'はっきり聴こえる',
      rangeLabel: '数m 〜 10m超(目安)',
    },
    {
      value: 'audible',
      priority: '確実・推奨',
      freqLabel: '4kHz 前後',
      audibilityLabel: 'はっきり聴こえる',
      rangeLabel: '未実測(目安なし)',
    },
    {
      value: 'balanced',
      priority: 'バランス型(実機未検証)',
      freqLabel: '8kHz 〜 12kHz',
      audibilityLabel: '高い、耳につきやすい音',
      rangeLabel: '1m 〜 3m 程度(目安)',
    },
    {
      value: 'mosquito',
      priority: '中間・実績あり',
      freqLabel: '17kHz 前後',
      audibilityLabel: 'ほとんど聴こえない',
      rangeLabel: '未実測(目安なし)',
    },
    {
      value: 'wideband-dsss',
      priority: '要再検証(過去にクラッシュ報告あり)',
      freqLabel: '17kHz 前後(DSSS方式)',
      audibilityLabel: 'ほとんど聴こえない',
      rangeLabel: '未実測(目安なし)',
      // quiet.js(vendor/quiet/quiet.js)側のバグ: transmitter()がencoderの
      // frame_len(quiet_encoder_clamp_frame_lenの戻り値)を検証せずtransmit()の
      // ループに使っており、DSSSのように拡散率が高くsampleBufferSize 1個に
      // シンボル1個も収まらない設定だとこれが0を返し、transmit()内のfor
      // ループがi+=0で前進せず無限ループ→メモリ食い潰しでクラッシュすることを
      // 実機で確認した(quiet.js側にframe_len<=0の防御を追加済み。以後は
      // クラッシュせず例外で失敗するようになったはず)。
      // clampFrame:falseにすると quiet_encoder_get_frame_len (profileの
      // frame_length: 8バイトにより近い、sampleBufferSizeへのクランプをしない
      // 生の値)を使う別経路に回避できるため、そちらで実際に送受信できるか
      // 再検証中。
      clampFrame: false,
    },
    {
      value: 'ultrasonic',
      priority: '静音性優先',
      freqLabel: '18.5kHz 〜 19.5kHz',
      audibilityLabel: 'ほぼ無音(非可聴)',
      rangeLabel: '10cm 〜 1m 程度(目安)',
    },
  ];
  const AUDIO_PROFILE_STORAGE_KEY = 'pchatAudioProfile';

  function loadAudioProfile() {
    try {
      const v = window.localStorage.getItem(AUDIO_PROFILE_STORAGE_KEY);
      if (AUDIO_PROFILES.some((p) => p.value === v)) { return v; }
    } catch (e) { /* noop */ }
    return AUDIO_PROFILES[0].value;
  }

  function saveAudioProfile(v) {
    try { window.localStorage.setItem(AUDIO_PROFILE_STORAGE_KEY, v); } catch (e) { /* noop */ }
  }

  /**
   * Quiet.transmitter()へそのまま渡せる追加オプションを、選ばれたプロファイル
   * ごとに返す(通常は{}で、quiet.js側のデフォルト挙動のまま)。wideband-dsssの
   * clampFrame:falseのように、特定プロファイルの既知の問題を回避するための
   * 例外的な指定だけをここに持たせ、呼び出し元(invite-share.js/sonic-chat.js)
   * がプロファイルごとの事情を個別に知らなくて済むようにする。
   */
  function transmitOptsFor(value) {
    const p = AUDIO_PROFILES.find((x) => x.value === value);
    return (p && p.clampFrame !== undefined) ? { clampFrame: p.clampFrame } : {};
  }

  /** 選択肢の一覧表(優先度/周波数/聴こえるか/到達距離の目安)。参考として選択UIの下に添える。 */
  function buildAudioProfileTable() {
    const table = document.createElement('table');
    table.className = 'pchat-audio-profile-table';
    const thead = document.createElement('thead');
    const headRow = document.createElement('tr');
    ['重視すること', '周波数', '聴こえるか?', '到達距離の目安'].forEach((h) => {
      const th = document.createElement('th');
      th.textContent = h;
      headRow.appendChild(th);
    });
    thead.appendChild(headRow);
    table.appendChild(thead);

    const tbody = document.createElement('tbody');
    AUDIO_PROFILES.forEach((p) => {
      const row = document.createElement('tr');
      row.dataset.profileValue = p.value;
      [p.priority, p.freqLabel, p.audibilityLabel, p.rangeLabel].forEach((cell) => {
        const td = document.createElement('td');
        td.textContent = cell;
        row.appendChild(td);
      });
      tbody.appendChild(row);
    });
    table.appendChild(tbody);
    return table;
  }

  /**
   * 呼び出し元(送信側・受信側・音波チャット)すべてに同じ選択肢を出す。通信する
   * 双方は同じ方式を選ぶ必要がある。selectの下に一覧表(buildAudioProfileTable)も
   * 添え、選んでいる行がひと目で分かるよう選択に応じてハイライトする。
   */
  function buildAudioProfileSelect() {
    const wrap = document.createElement('label');
    wrap.className = 'pchat-audio-profile-select';
    const span = document.createElement('span');
    span.textContent = '音の方式: ';
    wrap.appendChild(span);
    const select = document.createElement('select');
    AUDIO_PROFILES.forEach((p) => {
      const opt = document.createElement('option');
      opt.value = p.value;
      opt.textContent = p.priority + '(' + p.freqLabel + ')';
      select.appendChild(opt);
    });
    select.value = loadAudioProfile();
    wrap.appendChild(select);

    const table = buildAudioProfileTable();
    wrap.appendChild(table);

    function syncHighlight() {
      table.querySelectorAll('tr[data-profile-value]').forEach((row) => {
        row.classList.toggle('pchat-audio-profile-row-selected', row.dataset.profileValue === select.value);
      });
    }
    syncHighlight();
    select.addEventListener('change', () => {
      saveAudioProfile(select.value);
      syncHighlight();
    });

    return { wrap, select };
  }

  window.PchatAudioProfiles = {
    PROFILES: AUDIO_PROFILES,
    load: loadAudioProfile,
    save: saveAudioProfile,
    buildSelect: buildAudioProfileSelect,
    transmitOptsFor: transmitOptsFor,
  };
})();
