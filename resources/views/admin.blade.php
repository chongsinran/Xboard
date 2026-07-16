<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ $title }}</title>
  <script>
    window.settings = {
      base_url: "/",
      title: "{{ $title }}",
      version: "{{ $version }}",
      logo: "{{ $logo }}",
      secure_path: "{{ $secure_path }}",
    };
  </script>
  @php
    $manifestPath = public_path('assets/admin/manifest.json');
    $manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
    $entry = is_array($manifest) ? ($manifest['index.html'] ?? null) : null;
    $scripts = [];
    $styles = [];
    $locales = [];

    if (is_array($entry)) {
      $visited = [];
      $collectAssets = function ($chunkName) use (&$collectAssets, &$manifest, &$visited, &$scripts, &$styles) {
        if (isset($visited[$chunkName]) || !isset($manifest[$chunkName]) || !is_array($manifest[$chunkName])) {
          return;
        }

        $visited[$chunkName] = true;
        $chunk = $manifest[$chunkName];

        if (!empty($chunk['css']) && is_array($chunk['css'])) {
          foreach ($chunk['css'] as $cssFile) {
            $styles[$cssFile] = $cssFile;
          }
        }

        if (!empty($chunk['imports']) && is_array($chunk['imports'])) {
          foreach ($chunk['imports'] as $import) {
            $collectAssets($import);
          }
        }

        if (!empty($chunk['isEntry']) && !empty($chunk['file'])) {
          $scripts[$chunk['file']] = $chunk['file'];
        }
      };

      $collectAssets('index.html');
    }

    foreach (glob(public_path('assets/admin/locales/*.js')) ?: [] as $localeFile) {
      $locales[] = 'locales/' . basename($localeFile);
    }
    sort($locales);
  @endphp

  @if($entry && count($scripts) > 0)
    @foreach($styles as $css)
      <link rel="stylesheet" crossorigin href="/assets/admin/{{ $css }}" />
    @endforeach
    @foreach($locales as $locale)
      <script src="/assets/admin/{{ $locale }}"></script>
    @endforeach
    @foreach($scripts as $js)
      <script type="module" crossorigin src="/assets/admin/{{ $js }}"></script>
    @endforeach
  @else
    {{-- Fallback: hardcoded paths for backward compatibility --}}
    <script type="module" crossorigin src="/assets/admin/assets/index.js"></script>
    <link rel="stylesheet" crossorigin href="/assets/admin/assets/index.css" />
    <link rel="stylesheet" crossorigin href="/assets/admin/assets/vendor.css">
    <script src="/assets/admin/locales/en-US.js"></script>
    <script src="/assets/admin/locales/zh-CN.js"></script>
    <script src="/assets/admin/locales/ko-KR.js"></script>
  @endif
</head>

<body>
  <div id="root"></div>
  <div id="invite-reward-panel-root"></div>
  <div id="notice-tag-filter-root"></div>
  <div id="personal-notice-panel-root"></div>
  <div id="device-analytics-panel-root"></div>
  <script>
    (() => {
      const securePath = window.settings?.secure_path;
      if (!securePath) {
        return;
      }

      const labels = {
        en: {
          trigger: 'Invite Rewards',
          title: 'Invite Registration Rewards',
          description: 'Adjust extra traffic and time granted when a new user registers with an invite code.',
          enable: 'Enable invite registration rewards',
          refereeTraffic: 'Referee extra traffic (GB)',
          refereeHours: 'Referee extra time (hours)',
          referrerTraffic: 'Referrer extra traffic (GB)',
          referrerHours: 'Referrer extra time (hours)',
          levelsTitle: 'Promotion Levels',
          levelA: 'Level A · Starter',
          levelB: 'Level B · Bronze',
          levelC: 'Level C · Silver',
          levelD: 'Level D · Gold',
          levelEnable: 'Enable',
          levelRewardTraffic: 'Traffic reward (GB)',
          levelRewardHours: 'Time reward (hours)',
          levelValidTarget: 'Valid invite target',
          levelPaidTarget: 'Paid invite target',
          levelRewardType: 'Reward type',
          levelRewardValue: 'Reward value',
          cancel: 'Close',
          save: 'Save',
          saving: 'Saving...',
          loading: 'Loading...',
          saved: 'Saved successfully',
          failed: 'Failed to load settings',
          unauthorized: 'Please log into the admin panel first.',
        },
        zh: {
          trigger: '邀请奖励',
          title: '邀请注册奖励',
          description: '调整新用户使用邀请码注册时，邀请人和被邀请人获得的额外流量与时长。',
          enable: '启用邀请注册奖励',
          refereeTraffic: '被邀请人额外流量 (GB)',
          refereeHours: '被邀请人额外时长 (小时)',
          referrerTraffic: '邀请人额外流量 (GB)',
          referrerHours: '邀请人额外时长 (小时)',
          levelsTitle: '推广等级奖励',
          levelA: '等级 A · 普通推廣會員',
          levelB: '等级 B · 必连分享官',
          levelC: '等级 C · 必连推廣大使',
          levelD: '等级 D · 必连合夥人',
          levelEnable: '启用',
          levelRewardTraffic: '流量奖励 (GB)',
          levelRewardHours: '时长奖励 (小时)',
          levelValidTarget: '有效用户目标',
          levelPaidTarget: '付费用户目标',
          levelRewardType: '奖励类型',
          levelRewardValue: '奖励数值',
          cancel: '关闭',
          save: '保存',
          saving: '保存中...',
          loading: '加载中...',
          saved: '保存成功',
          failed: '加载设置失败',
          unauthorized: '请先登录管理后台。',
        }
      };

      const locale = (localStorage.getItem('i18nextLng') || 'zh-CN').toLowerCase().startsWith('en') ? 'en' : 'zh';
      const t = labels[locale];

      const root = document.getElementById('invite-reward-panel-root');
      if (!root) {
        return;
      }

      root.innerHTML = `
        <style>
          .invite-reward-inline-entry {
            margin-top: 14px;
            margin-bottom: calc(110px + env(safe-area-inset-bottom, 0px));
            padding-top: 14px;
            border-top: 1px dashed #d1d5db;
            display: grid;
            gap: 10px;
            scroll-margin-bottom: calc(110px + env(safe-area-inset-bottom, 0px));
          }
          .invite-reward-inline-entry h3 {
            margin: 0;
            font-size: 14px;
            line-height: 1.4;
            color: #111827;
            font-weight: 700;
          }
          .invite-reward-inline-entry p {
            margin: 0;
            color: #6b7280;
            font-size: 13px;
            line-height: 1.5;
          }
          .invite-reward-inline-entry button {
            justify-self: start;
            border: 0;
            border-radius: 10px;
            background: #111827;
            color: #fff;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 600;
            box-shadow: 0 10px 24px rgba(17, 24, 39, 0.14);
            cursor: pointer;
          }
          .invite-reward-backdrop {
            position: fixed;
            inset: 0;
            z-index: 1001;
            background: rgba(15, 23, 42, 0.42);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px 24px calc(110px + env(safe-area-inset-bottom, 0px));
            overflow: auto;
          }
          .invite-reward-backdrop.open {
            display: flex;
          }
          .invite-reward-card {
            width: min(520px, 100%);
            max-height: calc(100vh - 48px - 110px - env(safe-area-inset-bottom, 0px));
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.28);
            overflow: hidden;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            display: flex;
            flex-direction: column;
          }
          .invite-reward-form {
            min-height: 0;
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
          }
          .invite-reward-header {
            padding: 20px 22px 12px;
            border-bottom: 1px solid #e5e7eb;
            flex: 0 0 auto;
          }
          .invite-reward-header h2 {
            margin: 0;
            font-size: 20px;
            color: #111827;
          }
          .invite-reward-header p {
            margin: 8px 0 0;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.5;
          }
          .invite-reward-body {
            padding: 20px 22px;
            display: grid;
            gap: 14px;
            overflow-y: auto;
            overscroll-behavior: contain;
            flex: 1 1 auto;
            min-height: 0;
          }
          .invite-reward-switch {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: #111827;
            font-weight: 600;
          }
          .invite-reward-field {
            display: grid;
            gap: 6px;
          }
          .invite-reward-section {
            margin-top: 6px;
            padding-top: 14px;
            border-top: 1px dashed #d1d5db;
            display: grid;
            gap: 12px;
          }
          .invite-reward-section h3 {
            margin: 0;
            font-size: 14px;
            color: #111827;
            font-weight: 700;
          }
          .invite-reward-grid {
            display: grid;
            gap: 12px;
          }
          .invite-reward-level-card {
            padding: 14px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fafafa;
            display: grid;
            gap: 10px;
          }
          .invite-reward-level-card h4 {
            margin: 0;
            font-size: 13px;
            color: #111827;
            font-weight: 700;
          }
          .invite-reward-level-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
          }
          .invite-reward-field select {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 14px;
            outline: none;
            background: #fff;
          }
          .invite-reward-field label {
            font-size: 13px;
            color: #374151;
            font-weight: 600;
          }
          .invite-reward-field input {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 14px;
            outline: none;
          }
          .invite-reward-field input:focus {
            border-color: #111827;
            box-shadow: 0 0 0 3px rgba(17, 24, 39, 0.08);
          }
          .invite-reward-footer {
            padding: 16px 22px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border-top: 1px solid #e5e7eb;
            flex: 0 0 auto;
            background: #fff;
          }
          .invite-reward-status {
            min-height: 20px;
            font-size: 13px;
            color: #2563eb;
          }
          .invite-reward-actions {
            display: flex;
            gap: 10px;
          }
          .invite-reward-actions button {
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
          }
          .invite-reward-cancel {
            background: #fff;
            border: 1px solid #d1d5db;
            color: #111827;
          }
          .invite-reward-save {
            background: #111827;
            border: 1px solid #111827;
            color: #fff;
          }
        </style>
        <div class="invite-reward-backdrop" role="dialog" aria-modal="true" aria-label="${t.title}">
          <div class="invite-reward-card">
            <div class="invite-reward-header">
              <h2>${t.title}</h2>
              <p>${t.description}</p>
            </div>
            <form class="invite-reward-form">
              <div class="invite-reward-body">
                <label class="invite-reward-switch">
                  <input type="checkbox" name="invite_register_reward_enable" />
                  <span>${t.enable}</span>
                </label>
                <div class="invite-reward-field">
                  <label for="invite_register_referee_transfer_gb">${t.refereeTraffic}</label>
                  <input id="invite_register_referee_transfer_gb" name="invite_register_referee_transfer_gb" type="number" min="0" step="0.1" />
                </div>
                <div class="invite-reward-field">
                  <label for="invite_register_referee_hours">${t.refereeHours}</label>
                  <input id="invite_register_referee_hours" name="invite_register_referee_hours" type="number" min="0" step="1" />
                </div>
                <div class="invite-reward-field">
                  <label for="invite_register_referrer_transfer_gb">${t.referrerTraffic}</label>
                  <input id="invite_register_referrer_transfer_gb" name="invite_register_referrer_transfer_gb" type="number" min="0" step="0.1" />
                </div>
                <div class="invite-reward-field">
                  <label for="invite_register_referrer_hours">${t.referrerHours}</label>
                  <input id="invite_register_referrer_hours" name="invite_register_referrer_hours" type="number" min="0" step="1" />
                </div>
                <div class="invite-reward-section">
                  <h3>${t.levelsTitle}</h3>
                  <div class="invite-reward-grid">
                    <div class="invite-reward-level-card">
                      <h4>${t.levelA}</h4>
                      <label class="invite-reward-switch">
                        <input type="checkbox" name="invite_level_a_enable" />
                        <span>${t.levelEnable}</span>
                      </label>
                      <div class="invite-reward-level-row">
                        <div class="invite-reward-field">
                          <label for="invite_level_a_reward_transfer_gb">${t.levelRewardTraffic}</label>
                          <input id="invite_level_a_reward_transfer_gb" name="invite_level_a_reward_transfer_gb" type="number" min="0" step="0.1" />
                        </div>
                        <div class="invite-reward-field">
                          <label for="invite_level_a_reward_hours">${t.levelRewardHours}</label>
                          <input id="invite_level_a_reward_hours" name="invite_level_a_reward_hours" type="number" min="0" step="1" />
                        </div>
                      </div>
                    </div>
                    <div class="invite-reward-level-card">
                      <h4>${t.levelB}</h4>
                      <label class="invite-reward-switch">
                        <input type="checkbox" name="invite_level_b_enable" />
                        <span>${t.levelEnable}</span>
                      </label>
                      <div class="invite-reward-level-row">
                        <div class="invite-reward-field">
                          <label for="invite_level_b_valid_target">${t.levelValidTarget}</label>
                          <input id="invite_level_b_valid_target" name="invite_level_b_valid_target" type="number" min="0" step="1" />
                        </div>
                        <div class="invite-reward-field">
                          <label for="invite_level_b_paid_target">${t.levelPaidTarget}</label>
                          <input id="invite_level_b_paid_target" name="invite_level_b_paid_target" type="number" min="0" step="1" />
                        </div>
                      </div>
                      <div class="invite-reward-level-row">
                        <div class="invite-reward-field">
                          <label for="invite_level_b_reward_type">${t.levelRewardType}</label>
                          <select id="invite_level_b_reward_type" name="invite_level_b_reward_type">
                            <option value="hours">hours</option>
                            <option value="days">days</option>
                            <option value="months">months</option>
                            <option value="years">years</option>
                            <option value="lifetime">lifetime</option>
                          </select>
                        </div>
                        <div class="invite-reward-field">
                          <label for="invite_level_b_reward_value">${t.levelRewardValue}</label>
                          <input id="invite_level_b_reward_value" name="invite_level_b_reward_value" type="number" min="0" step="1" />
                        </div>
                      </div>
                    </div>
                    <div class="invite-reward-level-card">
                      <h4>${t.levelC}</h4>
                      <label class="invite-reward-switch">
                        <input type="checkbox" name="invite_level_c_enable" />
                        <span>${t.levelEnable}</span>
                      </label>
                      <div class="invite-reward-level-row">
                        <div class="invite-reward-field">
                          <label for="invite_level_c_valid_target">${t.levelValidTarget}</label>
                          <input id="invite_level_c_valid_target" name="invite_level_c_valid_target" type="number" min="0" step="1" />
                        </div>
                        <div class="invite-reward-field">
                          <label for="invite_level_c_paid_target">${t.levelPaidTarget}</label>
                          <input id="invite_level_c_paid_target" name="invite_level_c_paid_target" type="number" min="0" step="1" />
                        </div>
                      </div>
                      <div class="invite-reward-level-row">
                        <div class="invite-reward-field">
                          <label for="invite_level_c_reward_type">${t.levelRewardType}</label>
                          <select id="invite_level_c_reward_type" name="invite_level_c_reward_type">
                            <option value="hours">hours</option>
                            <option value="days">days</option>
                            <option value="months">months</option>
                            <option value="years">years</option>
                            <option value="lifetime">lifetime</option>
                          </select>
                        </div>
                        <div class="invite-reward-field">
                          <label for="invite_level_c_reward_value">${t.levelRewardValue}</label>
                          <input id="invite_level_c_reward_value" name="invite_level_c_reward_value" type="number" min="0" step="1" />
                        </div>
                      </div>
                    </div>
                    <div class="invite-reward-level-card">
                      <h4>${t.levelD}</h4>
                      <label class="invite-reward-switch">
                        <input type="checkbox" name="invite_level_d_enable" />
                        <span>${t.levelEnable}</span>
                      </label>
                      <div class="invite-reward-level-row">
                        <div class="invite-reward-field">
                          <label for="invite_level_d_valid_target">${t.levelValidTarget}</label>
                          <input id="invite_level_d_valid_target" name="invite_level_d_valid_target" type="number" min="0" step="1" />
                        </div>
                        <div class="invite-reward-field">
                          <label for="invite_level_d_paid_target">${t.levelPaidTarget}</label>
                          <input id="invite_level_d_paid_target" name="invite_level_d_paid_target" type="number" min="0" step="1" />
                        </div>
                      </div>
                      <div class="invite-reward-level-row">
                        <div class="invite-reward-field">
                          <label for="invite_level_d_reward_type">${t.levelRewardType}</label>
                          <select id="invite_level_d_reward_type" name="invite_level_d_reward_type">
                            <option value="hours">hours</option>
                            <option value="days">days</option>
                            <option value="months">months</option>
                            <option value="years">years</option>
                            <option value="lifetime">lifetime</option>
                          </select>
                        </div>
                        <div class="invite-reward-field">
                          <label for="invite_level_d_reward_value">${t.levelRewardValue}</label>
                          <input id="invite_level_d_reward_value" name="invite_level_d_reward_value" type="number" min="0" step="1" />
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="invite-reward-footer">
                <div class="invite-reward-status"></div>
                <div class="invite-reward-actions">
                  <button class="invite-reward-cancel" type="button">${t.cancel}</button>
                  <button class="invite-reward-save" type="submit">${t.save}</button>
                </div>
              </div>
            </form>
          </div>
        </div>
      `;

      const backdrop = root.querySelector('.invite-reward-backdrop');
      const form = root.querySelector('.invite-reward-form');
      const status = root.querySelector('.invite-reward-status');
      const saveButton = root.querySelector('.invite-reward-save');
      const cancelButton = root.querySelector('.invite-reward-cancel');
      let inlineButton = null;

      const getStoredToken = () => {
        const candidateKeys = ['XBOARD_ACCESS_TOKEN', 'Xboard_access_token', 'access_token'];
        const stores = [localStorage, sessionStorage];

        const unwrapToken = (raw) => {
          if (!raw) {
            return '';
          }

          try {
            const parsed = JSON.parse(raw);
            if (typeof parsed === 'string') {
              return parsed;
            }
            if (typeof parsed?.value === 'string') {
              return parsed.value;
            }
            if (typeof parsed?.value?.value === 'string') {
              return parsed.value.value;
            }
          } catch (_) {
            if (typeof raw === 'string') {
              return raw;
            }
          }

          return '';
        };

        for (const store of stores) {
          for (const key of candidateKeys) {
            const token = unwrapToken(store.getItem(key));
            if (token) {
              return token;
            }
          }

          for (let index = 0; index < store.length; index += 1) {
            const key = store.key(index);
            if (!key || !key.toLowerCase().endsWith('access_token')) {
              continue;
            }

            const token = unwrapToken(store.getItem(key));
            if (token) {
              return token;
            }
          }
        }

        return '';
      };

      const buildAuthHeaders = (extra = {}) => {
        const token = getStoredToken();
        const headers = {
          'Content-Language': localStorage.getItem('i18nextLng') || 'zh-CN',
          'X-Requested-With': 'XMLHttpRequest',
          ...extra,
        };

        if (token) {
          headers.Authorization = token.startsWith('Bearer ')
            ? token
            : `Bearer ${token}`;
        }

        return headers;
      };

      const setStatus = (message, color = '#2563eb') => {
        status.textContent = message || '';
        status.style.color = color;
      };

      const setLoading = (loading) => {
        saveButton.disabled = loading;
        cancelButton.disabled = loading;
        saveButton.textContent = loading ? t.saving : t.save;
      };

      const openPanel = async () => {
        backdrop.classList.add('open');
        setStatus(t.loading);
        const token = getStoredToken();
        if (!token) {
          setStatus(t.unauthorized, '#dc2626');
          return;
        }

        try {
          const response = await fetch(`/api/v2/${securePath}/config/fetch?key=invite`, {
            headers: buildAuthHeaders(),
            credentials: 'same-origin',
          });
          const payload = await response.json();
          const invite = payload?.data?.invite || {};
          form.elements.invite_register_reward_enable.checked = Boolean(invite.invite_register_reward_enable);
          form.elements.invite_register_referee_transfer_gb.value = invite.invite_register_referee_transfer_gb ?? 0;
          form.elements.invite_register_referee_hours.value = invite.invite_register_referee_hours ?? 0;
          form.elements.invite_register_referrer_transfer_gb.value = invite.invite_register_referrer_transfer_gb ?? 0;
          form.elements.invite_register_referrer_hours.value = invite.invite_register_referrer_hours ?? 0;
          form.elements.invite_level_a_enable.checked = Boolean(invite.invite_level_a_enable);
          form.elements.invite_level_a_reward_transfer_gb.value = invite.invite_level_a_reward_transfer_gb ?? 1;
          form.elements.invite_level_a_reward_hours.value = invite.invite_level_a_reward_hours ?? 24;
          form.elements.invite_level_b_enable.checked = Boolean(invite.invite_level_b_enable);
          form.elements.invite_level_b_valid_target.value = invite.invite_level_b_valid_target ?? 10;
          form.elements.invite_level_b_paid_target.value = invite.invite_level_b_paid_target ?? 1;
          form.elements.invite_level_b_reward_type.value = invite.invite_level_b_reward_type ?? 'months';
          form.elements.invite_level_b_reward_value.value = invite.invite_level_b_reward_value ?? 1;
          form.elements.invite_level_c_enable.checked = Boolean(invite.invite_level_c_enable);
          form.elements.invite_level_c_valid_target.value = invite.invite_level_c_valid_target ?? 50;
          form.elements.invite_level_c_paid_target.value = invite.invite_level_c_paid_target ?? 10;
          form.elements.invite_level_c_reward_type.value = invite.invite_level_c_reward_type ?? 'years';
          form.elements.invite_level_c_reward_value.value = invite.invite_level_c_reward_value ?? 1;
          form.elements.invite_level_d_enable.checked = Boolean(invite.invite_level_d_enable);
          form.elements.invite_level_d_valid_target.value = invite.invite_level_d_valid_target ?? 100;
          form.elements.invite_level_d_paid_target.value = invite.invite_level_d_paid_target ?? 30;
          form.elements.invite_level_d_reward_type.value = invite.invite_level_d_reward_type ?? 'lifetime';
          form.elements.invite_level_d_reward_value.value = invite.invite_level_d_reward_value ?? 1;
          setStatus('');
        } catch (_) {
          setStatus(t.failed, '#dc2626');
        }
      };

      const mountInlineEntry = () => {
        if (inlineButton && document.body.contains(inlineButton)) {
          return;
        }

        const candidates = Array.from(document.querySelectorAll(
          '.ant-form-item-label, .ant-form-item, .ant-card-body, .ant-space-item, .ant-typography, label, span, div',
        )).filter((node) => {
          const text = (node.textContent || '').trim();
          return text === '注册试用' || text === 'Trial Registration' || text === 'Try Out';
        });

        const target = candidates.find((node) => node.children.length === 0) || candidates[0];
        if (!target) {
          return;
        }

        const container = target.closest('.ant-form-item, .ant-card-body, .ant-space-item, .ant-col, .ant-row > div, [class*="form"], [class*="card"]') || target.parentElement;
        if (!container || container.querySelector('.invite-reward-inline-entry')) {
          return;
        }

        const entry = document.createElement('div');
        entry.className = 'invite-reward-inline-entry';
        entry.innerHTML = `
          <h3>${t.title}</h3>
          <p>${t.description}</p>
          <button type="button">${t.trigger}</button>
        `;

        inlineButton = entry.querySelector('button');
        inlineButton.addEventListener('click', openPanel);
        container.appendChild(entry);
      };

      mountInlineEntry();
      let inviteObserverTimer = null;
      const observer = new MutationObserver(() => {
        if (inviteObserverTimer) {
          clearTimeout(inviteObserverTimer);
        }
        inviteObserverTimer = setTimeout(() => {
          inviteObserverTimer = null;
          mountInlineEntry();
        }, 180);
      });
      observer.observe(document.body, { childList: true, subtree: true });

      cancelButton.addEventListener('click', () => {
        backdrop.classList.remove('open');
        setStatus('');
      });
      backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
          backdrop.classList.remove('open');
          setStatus('');
        }
      });

      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const token = getStoredToken();
        if (!token) {
          setStatus(t.unauthorized, '#dc2626');
          return;
        }

        setLoading(true);
        setStatus('');

        const body = {
          invite_register_reward_enable: form.elements.invite_register_reward_enable.checked ? 1 : 0,
          invite_register_referee_transfer_gb: form.elements.invite_register_referee_transfer_gb.value || 0,
          invite_register_referee_hours: form.elements.invite_register_referee_hours.value || 0,
          invite_register_referrer_transfer_gb: form.elements.invite_register_referrer_transfer_gb.value || 0,
          invite_register_referrer_hours: form.elements.invite_register_referrer_hours.value || 0,
          invite_level_a_enable: form.elements.invite_level_a_enable.checked ? 1 : 0,
          invite_level_a_reward_transfer_gb: form.elements.invite_level_a_reward_transfer_gb.value || 0,
          invite_level_a_reward_hours: form.elements.invite_level_a_reward_hours.value || 0,
          invite_level_b_enable: form.elements.invite_level_b_enable.checked ? 1 : 0,
          invite_level_b_valid_target: form.elements.invite_level_b_valid_target.value || 0,
          invite_level_b_paid_target: form.elements.invite_level_b_paid_target.value || 0,
          invite_level_b_reward_type: form.elements.invite_level_b_reward_type.value || 'months',
          invite_level_b_reward_value: form.elements.invite_level_b_reward_value.value || 0,
          invite_level_c_enable: form.elements.invite_level_c_enable.checked ? 1 : 0,
          invite_level_c_valid_target: form.elements.invite_level_c_valid_target.value || 0,
          invite_level_c_paid_target: form.elements.invite_level_c_paid_target.value || 0,
          invite_level_c_reward_type: form.elements.invite_level_c_reward_type.value || 'years',
          invite_level_c_reward_value: form.elements.invite_level_c_reward_value.value || 0,
          invite_level_d_enable: form.elements.invite_level_d_enable.checked ? 1 : 0,
          invite_level_d_valid_target: form.elements.invite_level_d_valid_target.value || 0,
          invite_level_d_paid_target: form.elements.invite_level_d_paid_target.value || 0,
          invite_level_d_reward_type: form.elements.invite_level_d_reward_type.value || 'lifetime',
          invite_level_d_reward_value: form.elements.invite_level_d_reward_value.value || 0,
        };

        try {
          const response = await fetch(`/api/v2/${securePath}/config/save`, {
            method: 'POST',
            headers: buildAuthHeaders({
              'Content-Type': 'application/json',
            }),
            credentials: 'same-origin',
            body: JSON.stringify(body),
          });
          const payload = await response.json();
          if (!response.ok || payload?.data === false) {
            throw new Error(payload?.message || 'save failed');
          }
          setStatus(t.saved, '#16a34a');
        } catch (_) {
          setStatus(t.failed, '#dc2626');
        } finally {
          setLoading(false);
        }
      });
    })();
  </script>
  <script>
    (() => {
      const securePath = window.settings?.secure_path;
      if (!securePath) {
        return;
      }

      const root = document.getElementById('notice-tag-filter-root');
      if (!root) {
        return;
      }

      const locale = (localStorage.getItem('i18nextLng') || 'zh-CN')
        .toLowerCase()
        .startsWith('en')
        ? 'en'
        : 'zh';
      const filterLabels = {
        en: {
          all: 'ALL',
          title: 'Tag Filter',
        },
        zh: {
          all: '全部',
          title: '标签筛选',
        },
      };
      const t = filterLabels[locale];

      root.innerHTML = `
        <style>
          .xboard-notice-tools-zone {
            display: grid;
            gap: 16px;
            margin: 18px 0 calc(140px + env(safe-area-inset-bottom, 0px));
          }
          .notice-tag-filter-shell {
            display: none;
            padding: 12px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
          }
          .notice-tag-filter-shell.open {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
          }
          .notice-tag-filter-main {
            min-width: 0;
            flex: 1 1 auto;
          }
          .notice-tag-filter-title {
            margin: 0 0 10px;
            font-size: 13px;
            color: #6b7280;
            font-weight: 600;
          }
          .notice-tag-filter-segments {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
          }
          .notice-tag-filter-chip {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #111827;
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all .16s ease;
          }
          .notice-tag-filter-chip.active {
            background: #111827;
            color: #fff;
            border-color: #111827;
          }
          .notice-tags-col-head,
          .notice-tags-col-cell {
            min-width: 180px;
          }
          .notice-tags-col-cell {
            vertical-align: top;
          }
          .notice-tags-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            max-width: 240px;
          }
          .notice-tag-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 9px;
            border: 1px solid #dbe2ea;
            background: #f8fafc;
            color: #334155;
            font-size: 12px;
            line-height: 1.2;
            font-weight: 600;
            white-space: nowrap;
          }
          .notice-tag-empty {
            color: #94a3b8;
            font-size: 12px;
          }
        </style>
        <div class="notice-tag-filter-shell">
          <div class="notice-tag-filter-main">
            <div class="notice-tag-filter-title">${t.title}</div>
            <div class="notice-tag-filter-segments"></div>
          </div>
        </div>
      `;

      const shell = root.querySelector('.notice-tag-filter-shell');
      const segmentContainer = root.querySelector('.notice-tag-filter-segments');
      const fixedTags = ['discovery', 'home', 'invite', 'referral', 'topup'];
      let notices = [];
      let fetchInFlight = null;
      let hydratedForPath = '';
      let hasFetchedForPath = false;
      let selectedTag = '__all__';

      const getStoredToken = () => {
        const candidateKeys = ['XBOARD_ACCESS_TOKEN', 'Xboard_access_token', 'access_token'];
        const stores = [localStorage, sessionStorage];

        const unwrapToken = (raw) => {
          if (!raw) return '';
          try {
            const parsed = JSON.parse(raw);
            if (typeof parsed === 'string') return parsed;
            if (typeof parsed?.value === 'string') return parsed.value;
            if (typeof parsed?.value?.value === 'string') return parsed.value.value;
          } catch (_) {
            if (typeof raw === 'string') return raw;
          }
          return '';
        };

        for (const store of stores) {
          for (const key of candidateKeys) {
            const token = unwrapToken(store.getItem(key));
            if (token) return token;
          }
          for (let index = 0; index < store.length; index += 1) {
            const key = store.key(index);
            if (!key || !key.toLowerCase().endsWith('access_token')) continue;
            const token = unwrapToken(store.getItem(key));
            if (token) return token;
          }
        }
        return '';
      };

      const extractTags = (rawTags) => {
        if (Array.isArray(rawTags)) {
          return rawTags.map((tag) => String(tag).trim()).filter(Boolean);
        }
        if (typeof rawTags === 'string') {
          try {
            const parsed = JSON.parse(rawTags);
            if (Array.isArray(parsed)) {
              return parsed.map((tag) => String(tag).trim()).filter(Boolean);
            }
          } catch (_) {
            return rawTags.split(',').map((tag) => tag.trim()).filter(Boolean);
          }
        }
        return [];
      };

      const onNoticePage = () => {
        const url = `${location.pathname}${location.hash}`.toLowerCase();
        if (url.includes('notice')) {
          return true;
        }

        const headings = Array.from(document.querySelectorAll('h1, h2, h3, .ant-page-header-heading-title, .ant-card-head-title'));
        return headings.some((node) => {
          const text = (node.textContent || '').trim();
          return text === '公告管理' || text === 'Notice Management';
        });
      };

      const findTableRows = () =>
        Array.from(document.querySelectorAll('tbody tr')).filter((row) => row.querySelectorAll('td').length > 1);

      const findNoticeHost = () => {
        const headings = Array.from(document.querySelectorAll('h1, h2, h3, .ant-page-header-heading-title, .ant-card-head-title'));
        const heading = headings.find((node) => {
          const text = (node.textContent || '').trim();
          return text === '公告管理' || text === 'Notice Management';
        });
        return heading?.closest('.ant-card, .ant-pro-card, .ant-layout-content, .ant-space, .ant-page-header, .ant-table-wrapper') || null;
      };

      const getNoticeFilterAnchor = (host, table) => {
        if (table) {
          return table.closest('.ant-table-wrapper') || table.parentElement;
        }
        return host;
      };

      const getNoticeToolsZone = (host) => {
        if (!host) return null;
        const parent = host.parentElement;
        if (!parent) return host;
        let zone = parent.querySelector(':scope > .xboard-notice-tools-zone');
        if (!zone) {
          zone = document.createElement('div');
          zone.className = 'xboard-notice-tools-zone';
          if (host.nextSibling) {
            parent.insertBefore(zone, host.nextSibling);
          } else {
            parent.appendChild(zone);
          }
        }
        return zone;
      };

      const getNoticeIdFromRow = (row) => {
        const firstCell = row.querySelector('td');
        const text = (firstCell?.textContent || '').trim();
        const match = text.match(/\d+/);
        return match ? Number(match[0]) : null;
      };

      const buildNoticeMap = () => {
        const map = new Map();
        notices.forEach((notice) => {
          map.set(Number(notice.id), extractTags(notice.tags));
        });
        return map;
      };

      const ensureHeader = (table, locale) => {
        const headerRow = table.querySelector('thead tr');
        if (!headerRow) {
          return;
        }

        if (headerRow.querySelector('.notice-tags-col-head')) {
          return;
        }

        const th = document.createElement('th');
        th.className = 'notice-tags-col-head';
        th.textContent = locale === 'en' ? 'Tags' : '标签';
        headerRow.appendChild(th);
      };

      const applyRowFilter = () => {
        const noticeMap = buildNoticeMap();
        findTableRows().forEach((row) => {
          if (selectedTag === '__all__') {
            row.style.display = '';
            return;
          }

          const noticeId = getNoticeIdFromRow(row);
          const tags = noticeId ? (noticeMap.get(noticeId) || []) : [];
          row.style.display = tags.includes(selectedTag) ? '' : 'none';
        });
      };

      const renderFilter = () => {
        segmentContainer.innerHTML = '';
        const options = ['__all__', ...fixedTags];

        options.forEach((option) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = `notice-tag-filter-chip${selectedTag === option ? ' active' : ''}`;
          button.textContent = option === '__all__' ? t.all : option;
          button.addEventListener('click', () => {
            selectedTag = option;
            renderFilter();
            applyRowFilter();
          });
          segmentContainer.appendChild(button);
        });
      };

      const hydrateRows = () => {
        const table = document.querySelector('.ant-table-wrapper table, table');
        if (!table) {
          return;
        }

        ensureHeader(table, locale);

        const noticeMap = buildNoticeMap();
        const rows = findTableRows();
        rows.forEach((row) => {
          let cell = row.querySelector('.notice-tags-col-cell');
          if (!cell) {
            cell = document.createElement('td');
            cell.className = 'notice-tags-col-cell';
            row.appendChild(cell);
          }

          const noticeId = getNoticeIdFromRow(row);
          const tags = noticeId ? (noticeMap.get(noticeId) || []) : [];

          if (!tags.length) {
            cell.innerHTML = `<span class="notice-tag-empty">-</span>`;
            return;
          }

          cell.innerHTML = `<div class="notice-tags-wrap">${tags.map((tag) => `<span class="notice-tag-pill">${tag}</span>`).join('')}</div>`;
        });

        renderFilter();
        applyRowFilter();
      };

      const fetchNotices = async () => {
        if (fetchInFlight) {
          return fetchInFlight;
        }

        const token = getStoredToken();

        fetchInFlight = (async () => {
          try {
            const headers = {
              'Content-Language': localStorage.getItem('i18nextLng') || 'zh-CN',
              'X-Requested-With': 'XMLHttpRequest',
            };
            if (token) {
              headers.Authorization = token.startsWith('Bearer ')
                ? token
                : `Bearer ${token}`;
            }

            const response = await fetch(`/api/v2/${securePath}/notice/fetch?_=${Date.now()}`, {
              headers,
              credentials: 'same-origin',
            });
            const payload = await response.json();
            notices = Array.isArray(payload?.data) ? payload.data : [];
          } catch (_) {
            notices = [];
          } finally {
            fetchInFlight = null;
          }
        })();

        return fetchInFlight;
      };

      const mountTagsColumn = async () => {
        if (!onNoticePage()) {
          hydratedForPath = '';
          shell.classList.remove('open');
          window.dispatchEvent(new CustomEvent('xboard-notice-shell', {
            detail: { open: false },
          }));
          return;
        }

        const table = document.querySelector('.ant-table-wrapper, table');
        if (!table) {
          return;
        }

        const host = findNoticeHost();
        const filterAnchor = getNoticeFilterAnchor(host, table);
        if (filterAnchor?.parentElement) {
          const shouldMove = shell.parentElement !== filterAnchor.parentElement
            || shell.nextElementSibling !== filterAnchor;
          if (shouldMove) {
            filterAnchor.parentElement.insertBefore(shell, filterAnchor);
          }
        }
        shell.classList.add('open');
        window.dispatchEvent(new CustomEvent('xboard-notice-shell', {
          detail: { open: true },
        }));

        const currentPath = `${location.pathname}${location.hash}`;
        if (hydratedForPath !== currentPath) {
          hydratedForPath = currentPath;
          notices = [];
          hasFetchedForPath = false;
        }

        if (!hasFetchedForPath) {
          hasFetchedForPath = true;
          await fetchNotices();
        }
        hydrateRows();
      };

      let mutationTimer = null;
      const observer = new MutationObserver(() => {
        if (mutationTimer) {
          clearTimeout(mutationTimer);
        }
        mutationTimer = setTimeout(() => {
          mutationTimer = null;
          mountTagsColumn();
        }, 120);
      });
      observer.observe(document.body, { childList: true, subtree: true });

      window.addEventListener('hashchange', mountTagsColumn);
      window.addEventListener('popstate', mountTagsColumn);
      mountTagsColumn();
    })();
  </script>
  <script>
    (() => {
      const securePath = window.settings?.secure_path;
      if (!securePath) {
        return;
      }

      const labels = {
        en: {
          title: 'Personal Notification',
          subtitle: 'Send markdown messages to selected users by UID or email.',
          trigger: 'Send Personal Notice',
          open: 'Open',
          close: 'Close',
          send: 'Send',
          sending: 'Sending...',
          titleLabel: 'Title',
          formatLabel: 'Content format',
          markdown: 'Markdown',
          plain: 'Plain text',
          imageLabel: 'Image URL',
          tagsLabel: 'Tags',
          tagsPlaceholder: 'Comma separated tags, e.g. vip,promo',
          contentLabel: 'Content',
          contentPlaceholder: 'Write markdown content here...',
          edit: 'Edit',
          preview: 'Preview',
          split: 'Split',
          markdownPreview: 'Markdown Preview',
          searchLabel: 'Search user',
          searchPlaceholder: 'Search by UID or email',
          searchButton: 'Search',
          selectedLabel: 'Selected users',
          selectedEmpty: 'No users selected yet',
          manualLabel: 'Manual recipients',
          manualHint: 'You can paste UIDs or emails separated by commas or new lines.',
          recentLabel: 'Recent personal notices',
          loadFailed: 'Failed to load',
          unauthorized: 'Please log into the admin panel first.',
          sent: 'Sent successfully',
          noRecipients: 'Select at least one user or provide UID/email.',
          noResults: 'No users found',
          searchFailed: 'User search failed',
        },
        zh: {
          title: '个人通知',
          subtitle: '向指定用户发送 Markdown 私信通知，可按 UID 或邮箱选择。',
          trigger: '发送个人通知',
          open: '打开',
          close: '关闭',
          send: '发送',
          sending: '发送中...',
          titleLabel: '标题',
          formatLabel: '内容格式',
          markdown: 'Markdown',
          plain: '纯文本',
          imageLabel: '图片 URL',
          tagsLabel: '标签',
          tagsPlaceholder: '逗号分隔标签，例如 vip,promo',
          contentLabel: '内容',
          contentPlaceholder: '在这里输入 Markdown 内容...',
          edit: '编辑',
          preview: '预览',
          split: '分栏',
          markdownPreview: 'Markdown 预览',
          searchLabel: '搜索用户',
          searchPlaceholder: '按 UID 或邮箱搜索',
          searchButton: '搜索',
          selectedLabel: '已选用户',
          selectedEmpty: '暂未选择用户',
          manualLabel: '手动接收人',
          manualHint: '可粘贴 UID 或邮箱，使用逗号或换行分隔。',
          recentLabel: '最近个人通知',
          loadFailed: '加载失败',
          unauthorized: '请先登录管理后台。',
          sent: '发送成功',
          noRecipients: '请至少选择一个用户，或填写 UID/邮箱。',
          noResults: '未找到用户',
          searchFailed: '搜索用户失败',
        }
      };

      const locale = (localStorage.getItem('i18nextLng') || 'zh-CN').toLowerCase().startsWith('en') ? 'en' : 'zh';
      const t = labels[locale];
      const root = document.getElementById('personal-notice-panel-root');
      if (!root) {
        return;
      }

      root.innerHTML = `
        <style>
          .personal-notice-entry {
            display: none;
            padding: 0;
            border: 0;
            background: transparent;
            box-shadow: none;
          }
          .personal-notice-entry.open {
            display: flex;
          }
          .personal-notice-entry-head {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            align-items: center;
          }
          .personal-notice-entry h3 {
            display: none;
          }
          .personal-notice-entry p {
            display: none;
          }
          .personal-notice-entry-trigger {
            border: 0;
            border-radius: 12px;
            background: #111827;
            color: #fff;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
          }
          .personal-notice-page-title {
            margin: 0 0 6px;
            font-size: 24px;
            line-height: 1.2;
            color: #111827;
            font-weight: 800;
          }
          .personal-notice-page-subtitle {
            margin: 0;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.55;
          }
          .personal-notice-backdrop {
            position: fixed;
            inset: 0;
            z-index: 1002;
            background: rgba(15, 23, 42, 0.46);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px 24px calc(110px + env(safe-area-inset-bottom, 0px));
            overflow: auto;
          }
          .personal-notice-backdrop.open {
            display: flex;
          }
          .personal-notice-card {
            width: min(880px, 100%);
            max-height: calc(100vh - 48px - 110px - env(safe-area-inset-bottom, 0px));
            overflow: auto;
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 28px 60px rgba(15, 23, 42, 0.28);
          }
          .personal-notice-header {
            padding: 20px 22px 12px;
            border-bottom: 1px solid #e5e7eb;
          }
          .personal-notice-header h2 {
            margin: 0;
            font-size: 22px;
            color: #111827;
          }
          .personal-notice-header p {
            margin: 8px 0 0;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.5;
          }
          .personal-notice-body {
            padding: 20px 22px;
            display: grid;
            gap: 16px;
            overflow-x: hidden;
          }
          .personal-notice-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
          }
          .personal-notice-field {
            display: grid;
            gap: 6px;
          }
          .personal-notice-field.full {
            grid-column: 1 / -1;
          }
          .personal-notice-field label {
            font-size: 13px;
            color: #374151;
            font-weight: 600;
          }
          .personal-notice-field input,
          .personal-notice-field textarea,
          .personal-notice-field select {
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 14px;
            outline: none;
            font-family: inherit;
          }
          .personal-notice-field textarea {
            min-height: 120px;
            resize: vertical;
          }
          .personal-notice-editor-shell {
            display: grid;
            gap: 10px;
          }
          .personal-notice-editor-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
          }
          .personal-notice-editor-modes {
            display: inline-flex;
            gap: 8px;
            padding: 4px;
            border-radius: 999px;
            background: #f3f4f6;
          }
          .personal-notice-editor-mode {
            border: 0;
            background: transparent;
            color: #4b5563;
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
          }
          .personal-notice-editor-mode.active {
            background: #111827;
            color: #fff;
          }
          .personal-notice-editor-tip {
            color: #6b7280;
            font-size: 12px;
          }
          .personal-notice-editor-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 12px;
          }
          .personal-notice-editor-layout.split {
            grid-template-columns: repeat(2, minmax(0, 1fr));
          }
          .personal-notice-editor-layout.preview-only .personal-notice-editor-pane-input {
            display: none;
          }
          .personal-notice-editor-layout.preview-only {
            grid-template-columns: minmax(0, 1fr);
          }
          .personal-notice-editor-layout.edit-only .personal-notice-editor-pane-preview {
            display: none;
          }
          .personal-notice-editor-pane {
            min-width: 0;
          }
          .personal-notice-editor-pane-preview {
            border: 1px solid #d1d5db;
            border-radius: 12px;
            background: #fbfdff;
            min-height: 240px;
            padding: 14px;
            overflow: auto;
          }
          .personal-notice-preview-body {
            color: #111827;
            font-size: 14px;
            line-height: 1.65;
            word-break: break-word;
          }
          .personal-notice-preview-body h1,
          .personal-notice-preview-body h2,
          .personal-notice-preview-body h3 {
            margin: 0 0 10px;
            line-height: 1.3;
          }
          .personal-notice-preview-body h1 {
            font-size: 22px;
          }
          .personal-notice-preview-body h2 {
            font-size: 18px;
          }
          .personal-notice-preview-body h3 {
            font-size: 16px;
          }
          .personal-notice-preview-body p,
          .personal-notice-preview-body ul,
          .personal-notice-preview-body ol,
          .personal-notice-preview-body pre,
          .personal-notice-preview-body blockquote {
            margin: 0 0 12px;
          }
          .personal-notice-preview-body code {
            background: #e5e7eb;
            border-radius: 6px;
            padding: 2px 5px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 12px;
          }
          .personal-notice-preview-body pre {
            background: #111827;
            color: #f9fafb;
            padding: 12px;
            border-radius: 10px;
            overflow: auto;
          }
          .personal-notice-preview-body pre code {
            background: transparent;
            color: inherit;
            padding: 0;
          }
          .personal-notice-preview-body blockquote {
            border-left: 3px solid #cbd5e1;
            padding-left: 12px;
            color: #475569;
          }
          .personal-notice-preview-placeholder {
            color: #94a3b8;
            font-size: 13px;
          }
          .personal-notice-search-row {
            display: flex;
            gap: 8px;
            align-items: stretch;
          }
          .personal-notice-search-row input {
            min-width: 0;
            flex: 1 1 auto;
          }
          .personal-notice-search-row button {
            border: 0;
            border-radius: 12px;
            background: #111827;
            color: #fff;
            padding: 0 14px;
            font-weight: 600;
            cursor: pointer;
          }
          .personal-notice-user-results,
          .personal-notice-selected,
          .personal-notice-recent {
            display: grid;
            gap: 8px;
          }
          .personal-notice-user-item,
          .personal-notice-recent-item {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 10px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
          }
          .personal-notice-user-item button,
          .personal-notice-chip button {
            border: 0;
            background: #111827;
            color: #fff;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 12px;
            cursor: pointer;
          }
          .personal-notice-chip-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
          }
          .personal-notice-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #eef2ff;
            color: #312e81;
            border-radius: 999px;
            padding: 7px 10px 7px 12px;
            font-size: 12px;
            font-weight: 600;
          }
          .personal-notice-chip button {
            background: #312e81;
            padding: 4px 8px;
          }
          .personal-notice-muted {
            color: #6b7280;
            font-size: 12px;
          }
          .personal-notice-footer {
            padding: 16px 22px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            border-top: 1px solid #e5e7eb;
          }
          .personal-notice-actions {
            display: flex;
            gap: 10px;
          }
          .personal-notice-actions button {
            border-radius: 12px;
            padding: 10px 14px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
          }
          .personal-notice-cancel {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #111827;
          }
          .personal-notice-send {
            border: 1px solid #111827;
            background: #111827;
            color: #fff;
          }
          .personal-notice-status {
            min-height: 20px;
            color: #2563eb;
            font-size: 13px;
          }
          @media (max-width: 720px) {
            .invite-reward-backdrop,
            .personal-notice-backdrop {
              align-items: flex-start;
              padding: 14px 14px calc(104px + env(safe-area-inset-bottom, 0px));
            }
            .invite-reward-card,
            .personal-notice-card {
              max-height: calc(100vh - 28px - 104px - env(safe-area-inset-bottom, 0px));
            }
            .personal-notice-grid {
              grid-template-columns: 1fr;
            }
            .personal-notice-editor-layout.split {
              grid-template-columns: 1fr;
            }
          }
        </style>
        <div class="personal-notice-entry">
          <div class="personal-notice-entry-head">
            <div>
              <h3 class="personal-notice-page-title">${t.title}</h3>
              <p>${t.subtitle}</p>
            </div>
            <button type="button" class="personal-notice-entry-trigger">${t.trigger}</button>
          </div>
        </div>
        <div class="personal-notice-backdrop" role="dialog" aria-modal="true" aria-label="${t.title}">
          <div class="personal-notice-card">
            <div class="personal-notice-header">
              <h2>${t.title}</h2>
              <p>${t.subtitle}</p>
            </div>
            <form class="personal-notice-form">
              <div class="personal-notice-body">
                <div class="personal-notice-grid">
                  <div class="personal-notice-field">
                    <label>${t.titleLabel}</label>
                    <input name="title" required />
                  </div>
                  <div class="personal-notice-field">
                    <label>${t.formatLabel}</label>
                    <select name="content_format">
                      <option value="markdown">${t.markdown}</option>
                      <option value="plain">${t.plain}</option>
                    </select>
                  </div>
                  <div class="personal-notice-field">
                    <label>${t.imageLabel}</label>
                    <input name="img_url" />
                  </div>
                  <div class="personal-notice-field">
                    <label>${t.tagsLabel}</label>
                    <input name="tags" placeholder="${t.tagsPlaceholder}" />
                  </div>
                  <div class="personal-notice-field full">
                    <label>${t.contentLabel}</label>
                    <div class="personal-notice-editor-shell">
                      <div class="personal-notice-editor-toolbar">
                        <div class="personal-notice-editor-modes">
                          <button type="button" class="personal-notice-editor-mode active" data-editor-mode="edit">${t.edit}</button>
                          <button type="button" class="personal-notice-editor-mode" data-editor-mode="preview">${t.preview}</button>
                          <button type="button" class="personal-notice-editor-mode" data-editor-mode="split">${t.split}</button>
                        </div>
                        <div class="personal-notice-editor-tip">${t.markdownPreview}</div>
                      </div>
                      <div class="personal-notice-editor-layout edit-only">
                        <div class="personal-notice-editor-pane personal-notice-editor-pane-input">
                          <textarea name="content" placeholder="${t.contentPlaceholder}" required></textarea>
                        </div>
                        <div class="personal-notice-editor-pane personal-notice-editor-pane-preview">
                          <div class="personal-notice-preview-body">
                            <div class="personal-notice-preview-placeholder">${t.markdownPreview}</div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="personal-notice-field full">
                    <label>${t.searchLabel}</label>
                    <div class="personal-notice-search-row">
                      <input name="user_search" placeholder="${t.searchPlaceholder}" />
                      <button type="button" class="personal-notice-search-button">${t.searchButton}</button>
                    </div>
                  </div>
                  <div class="personal-notice-field full">
                    <div class="personal-notice-user-results"></div>
                  </div>
                  <div class="personal-notice-field full">
                    <label>${t.selectedLabel}</label>
                    <div class="personal-notice-selected">
                      <div class="personal-notice-chip-wrap"></div>
                      <div class="personal-notice-muted personal-notice-selected-empty">${t.selectedEmpty}</div>
                    </div>
                  </div>
                  <div class="personal-notice-field full">
                    <label>${t.manualLabel}</label>
                    <textarea name="manual_recipients" placeholder="1001&#10;1002&#10;user@example.com"></textarea>
                    <div class="personal-notice-muted">${t.manualHint}</div>
                  </div>
                  <div class="personal-notice-field full">
                    <label>${t.recentLabel}</label>
                    <div class="personal-notice-recent"></div>
                  </div>
                </div>
              </div>
              <div class="personal-notice-footer">
                <div class="personal-notice-status"></div>
                <div class="personal-notice-actions">
                  <button type="button" class="personal-notice-cancel">${t.close}</button>
                  <button type="submit" class="personal-notice-send">${t.send}</button>
                </div>
              </div>
            </form>
          </div>
        </div>
      `;

      const entry = root.querySelector('.personal-notice-entry');
      const openButton = root.querySelector('.personal-notice-entry-trigger');
      const backdrop = root.querySelector('.personal-notice-backdrop');
      const form = root.querySelector('.personal-notice-form');
      const status = root.querySelector('.personal-notice-status');
      const cancelButton = root.querySelector('.personal-notice-cancel');
      const sendButton = root.querySelector('.personal-notice-send');
      const searchButton = root.querySelector('.personal-notice-search-button');
      const searchResults = root.querySelector('.personal-notice-user-results');
      const selectedWrap = root.querySelector('.personal-notice-chip-wrap');
      const selectedEmpty = root.querySelector('.personal-notice-selected-empty');
      const recentWrap = root.querySelector('.personal-notice-recent');
      const selectedUsers = new Map();
      const contentTextarea = form.elements.content;
      const contentFormatSelect = form.elements.content_format;
      const editorLayout = root.querySelector('.personal-notice-editor-layout');
      const editorModes = Array.from(root.querySelectorAll('[data-editor-mode]'));
      const previewBody = root.querySelector('.personal-notice-preview-body');
      let editorMode = 'edit';
      let navLink = null;
      let originalNoticeTitles = [];

      const getStoredToken = () => {
        const raw = localStorage.getItem('XBOARD_ACCESS_TOKEN')
          || localStorage.getItem('Xboard_access_token');
        if (!raw) {
          return '';
        }

        try {
          const parsed = JSON.parse(raw);
          if (typeof parsed?.value === 'string') {
            return parsed.value;
          }
        } catch (_) {
          return '';
        }

        return '';
      };

      const buildAuthHeaders = (extra = {}) => {
        const token = getStoredToken();
        const headers = {
          'Content-Language': localStorage.getItem('i18nextLng') || 'zh-CN',
          'X-Requested-With': 'XMLHttpRequest',
          ...extra,
        };
        if (token) {
          headers.Authorization = token.startsWith('Bearer ')
            ? token
            : `Bearer ${token}`;
        }
        return headers;
      };

      const setStatus = (message, color = '#2563eb') => {
        status.textContent = message || '';
        status.style.color = color;
      };

      const setSending = (sending) => {
        sendButton.disabled = sending;
        cancelButton.disabled = sending;
        sendButton.textContent = sending ? t.sending : t.send;
      };

      const escapeHtml = (value) =>
        String(value)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');

      const renderInlineMarkdown = (value) => {
        let html = escapeHtml(value);
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
        html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
        return html;
      };

      const renderMarkdownToHtml = (markdown) => {
        const source = String(markdown || '').replace(/\r\n/g, '\n').trim();
        if (!source) {
          return `<div class="personal-notice-preview-placeholder">${t.markdownPreview}</div>`;
        }

        const blocks = source.split(/\n{2,}/).map((block) => block.trim()).filter(Boolean);
        const html = blocks.map((block) => {
          if (block.startsWith('```') && block.endsWith('```')) {
            const code = block.replace(/^```[a-zA-Z0-9_-]*\n?/, '').replace(/\n?```$/, '');
            return `<pre><code>${escapeHtml(code)}</code></pre>`;
          }
          if (/^###\s+/.test(block)) {
            return `<h3>${renderInlineMarkdown(block.replace(/^###\s+/, ''))}</h3>`;
          }
          if (/^##\s+/.test(block)) {
            return `<h2>${renderInlineMarkdown(block.replace(/^##\s+/, ''))}</h2>`;
          }
          if (/^#\s+/.test(block)) {
            return `<h1>${renderInlineMarkdown(block.replace(/^#\s+/, ''))}</h1>`;
          }
          if (block.startsWith('>')) {
            const lines = block.split('\n').map((line) => line.replace(/^>\s?/, ''));
            return `<blockquote>${lines.map((line) => renderInlineMarkdown(line)).join('<br>')}</blockquote>`;
          }
          if (block.split('\n').every((line) => /^[-*]\s+/.test(line))) {
            const items = block.split('\n').map((line) => `<li>${renderInlineMarkdown(line.replace(/^[-*]\s+/, ''))}</li>`).join('');
            return `<ul>${items}</ul>`;
          }
          if (block.split('\n').every((line) => /^\d+\.\s+/.test(line))) {
            const items = block.split('\n').map((line) => `<li>${renderInlineMarkdown(line.replace(/^\d+\.\s+/, ''))}</li>`).join('');
            return `<ol>${items}</ol>`;
          }
          return `<p>${block.split('\n').map((line) => renderInlineMarkdown(line)).join('<br>')}</p>`;
        }).join('');

        return html || `<div class="personal-notice-preview-placeholder">${t.markdownPreview}</div>`;
      };

      const syncPreview = () => {
        if (!previewBody) {
          return;
        }

        if (contentFormatSelect.value === 'plain') {
          const plain = escapeHtml(contentTextarea.value || '').replace(/\n/g, '<br>');
          previewBody.innerHTML = plain || `<div class="personal-notice-preview-placeholder">${t.markdownPreview}</div>`;
          return;
        }

        previewBody.innerHTML = renderMarkdownToHtml(contentTextarea.value || '');
      };

      const applyEditorMode = () => {
        editorModes.forEach((button) => {
          button.classList.toggle('active', button.dataset.editorMode === editorMode);
        });

        editorLayout.classList.remove('edit-only', 'preview-only', 'split');
        if (editorMode === 'preview') {
          editorLayout.classList.add('preview-only');
        } else if (editorMode === 'split') {
          editorLayout.classList.add('split');
        } else {
          editorLayout.classList.add('edit-only');
        }
      };

      const isNoticePage = () => {
        const url = `${location.pathname}${location.hash}`.toLowerCase();
        if (url.includes('notice')) return true;
        return Array.from(document.querySelectorAll('h1, h2, h3, .ant-page-header-heading-title, .ant-card-head-title'))
          .some((node) => {
            const text = (node.textContent || '').trim();
            return text === '公告管理' || text === 'Notice Management';
          });
      };

      const mountEntry = () => {
        if (!isNoticePage()) {
          entry.classList.remove('open');
          return;
        }

        const filterShell = document.querySelector('.notice-tag-filter-shell');
        if (filterShell && entry.parentElement !== filterShell) {
          filterShell.appendChild(entry);
        }
        entry.classList.toggle('open', Boolean(filterShell?.classList.contains('open')));
      };

      const renderSelectedUsers = () => {
        selectedWrap.innerHTML = '';
        const values = Array.from(selectedUsers.values());
        selectedEmpty.style.display = values.length ? 'none' : '';

        values.forEach((user) => {
          const chip = document.createElement('div');
          chip.className = 'personal-notice-chip';
          chip.innerHTML = `<span>#${user.id} ${user.email}</span>`;
          const remove = document.createElement('button');
          remove.type = 'button';
          remove.textContent = '×';
          remove.addEventListener('click', () => {
            selectedUsers.delete(String(user.id));
            renderSelectedUsers();
          });
          chip.appendChild(remove);
          selectedWrap.appendChild(chip);
        });
      };

      const renderSearchResults = (users) => {
        searchResults.innerHTML = '';
        if (!users.length) {
          searchResults.innerHTML = `<div class="personal-notice-muted">${t.noResults}</div>`;
          return;
        }

        users.forEach((user) => {
          const item = document.createElement('div');
          item.className = 'personal-notice-user-item';
          item.innerHTML = `<div><strong>#${user.id}</strong><div class="personal-notice-muted">${user.email}</div></div>`;
          const button = document.createElement('button');
          button.type = 'button';
          button.textContent = '+';
          button.addEventListener('click', () => {
            selectedUsers.set(String(user.id), { id: user.id, email: user.email });
            renderSelectedUsers();
          });
          item.appendChild(button);
          searchResults.appendChild(item);
        });
      };

      const fetchRecent = async () => {
        try {
          const response = await fetch(`/api/v2/${securePath}/personal-notice/fetch`, {
            headers: buildAuthHeaders(),
            credentials: 'same-origin',
          });
          if (response.status === 401 || response.status === 403) {
            recentWrap.innerHTML = `<div class="personal-notice-muted">${t.unauthorized}</div>`;
            return;
          }
          const payload = await response.json();
          const items = Array.isArray(payload?.data) ? payload.data.slice(0, 6) : [];
          recentWrap.innerHTML = '';
          if (!items.length) {
            recentWrap.innerHTML = `<div class="personal-notice-muted">${t.noResults}</div>`;
            return;
          }
          items.forEach((item) => {
            const row = document.createElement('div');
            row.className = 'personal-notice-recent-item';
            row.innerHTML = `<div><strong>${item.title}</strong><div class="personal-notice-muted">#${item.user_id} ${item.user_email || ''}</div></div><div class="personal-notice-muted">${item.content_format || 'markdown'}</div>`;
            recentWrap.appendChild(row);
          });
        } catch (_) {
          recentWrap.innerHTML = `<div class="personal-notice-muted">${t.loadFailed}</div>`;
        }
      };

      const parseManualRecipients = (value) => {
        const ids = [];
        const emails = [];
        value
          .split(/[\n,]+/)
          .map((item) => item.trim())
          .filter(Boolean)
          .forEach((item) => {
            if (/^\d+$/.test(item)) {
              ids.push(Number(item));
            } else if (item.includes('@')) {
              emails.push(item.toLowerCase());
            }
          });
        return {
          ids: Array.from(new Set(ids)),
          emails: Array.from(new Set(emails)),
        };
      };

      openButton.addEventListener('click', async () => {
        backdrop.classList.add('open');
        setStatus('');
        syncPreview();
        await fetchRecent();
      });

      cancelButton.addEventListener('click', () => {
        backdrop.classList.remove('open');
        setStatus('');
      });

      backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
          backdrop.classList.remove('open');
          setStatus('');
        }
      });

      const performUserSearch = async () => {
        const keyword = form.elements.user_search.value.trim();
        setStatus('');

        try {
          const response = await fetch(`/api/v2/${securePath}/personal-notice/search-users`, {
            method: 'POST',
            headers: buildAuthHeaders({
              'Content-Type': 'application/json',
            }),
            credentials: 'same-origin',
            body: JSON.stringify({ keyword, limit: 20 }),
          });
          if (response.status === 401 || response.status === 403) {
            setStatus(t.unauthorized, '#dc2626');
            renderSearchResults([]);
            return;
          }
          if (!response.ok) {
            setStatus(`${t.searchFailed} (${response.status})`, '#dc2626');
            renderSearchResults([]);
            return;
          }
          const payload = await response.json();
          setStatus('');
          renderSearchResults(Array.isArray(payload?.data) ? payload.data : []);
        } catch (_) {
          setStatus(t.searchFailed, '#dc2626');
          renderSearchResults([]);
        }
      };

      searchButton.addEventListener('click', performUserSearch);
      editorModes.forEach((button) => {
        button.addEventListener('click', () => {
          editorMode = button.dataset.editorMode || 'edit';
          applyEditorMode();
        });
      });
      contentTextarea.addEventListener('input', syncPreview);
      contentFormatSelect.addEventListener('change', syncPreview);
      form.elements.user_search.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          performUserSearch();
        }
      });

      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const manual = parseManualRecipients(form.elements.manual_recipients.value || '');
        const recipient_ids = Array.from(selectedUsers.values()).map((user) => user.id).concat(manual.ids);
        const recipient_emails = manual.emails;

        if (!recipient_ids.length && !recipient_emails.length) {
          setStatus(t.noRecipients, '#dc2626');
          return;
        }

        setSending(true);
        setStatus('');

        const tags = (form.elements.tags.value || '')
          .split(',')
          .map((item) => item.trim())
          .filter(Boolean);

        try {
          const response = await fetch(`/api/v2/${securePath}/personal-notice/save`, {
            method: 'POST',
            headers: buildAuthHeaders({
              'Content-Type': 'application/json',
            }),
            credentials: 'same-origin',
            body: JSON.stringify({
              title: form.elements.title.value,
              content: form.elements.content.value,
              content_format: form.elements.content_format.value,
              img_url: form.elements.img_url.value || null,
              tags,
              show: 1,
              recipient_ids: Array.from(new Set(recipient_ids)),
              recipient_emails: Array.from(new Set(recipient_emails)),
            }),
          });
          if (response.status === 401 || response.status === 403) {
            setStatus(t.unauthorized, '#dc2626');
            return;
          }
          const payload = await response.json();
          if (!response.ok || payload?.data === false) {
            throw new Error(payload?.message || 'save failed');
          }

          setStatus(`${t.sent} (${payload?.data?.count || 0})`, '#16a34a');
          form.reset();
          selectedUsers.clear();
          renderSelectedUsers();
          renderSearchResults([]);
          syncPreview();
          await fetchRecent();
        } catch (_) {
          setStatus(t.loadFailed, '#dc2626');
        } finally {
          setSending(false);
        }
      });

      let personalNoticeObserverTimer = null;
      const observer = new MutationObserver(() => {
        if (personalNoticeObserverTimer) {
          clearTimeout(personalNoticeObserverTimer);
        }
        personalNoticeObserverTimer = setTimeout(() => {
          personalNoticeObserverTimer = null;
          mountEntry();
        }, 180);
      });
      observer.observe(document.body, { childList: true, subtree: true });
      window.addEventListener('hashchange', mountEntry);
      window.addEventListener('popstate', mountEntry);
      window.addEventListener('xboard-notice-shell', mountEntry);
      mountEntry();
      applyEditorMode();
      syncPreview();
      renderSelectedUsers();
    })();
  </script>
  <script>
    (() => {
      const securePath = window.settings?.secure_path;
      const root = document.getElementById('device-analytics-panel-root');
      if (!securePath || !root) return;

      const locale = (localStorage.getItem('i18nextLng') || 'zh-CN').toLowerCase().startsWith('en') ? 'en' : 'zh';
      const t = locale === 'en'
        ? {
            title: 'App Device Analytics',
            subtitle: 'Past 7 days registration source and daily active bundle IDs',
            activeUsers: 'Active Users',
            activeDevices: 'Active Devices',
            bundleIds: 'Bundle IDs',
            registrations: 'Registrations',
            bundleHeader: 'Bundle / Platform',
            dauHeader: '7-Day Daily Active',
            regHeader: '7-Day Registrations',
            channelHeader: 'Channel',
            empty: 'No analytics data yet',
          }
        : {
            title: '应用设备分析',
            subtitle: '查看近 7 天注册来源与每日活跃 Bundle ID',
            activeUsers: '活跃用户',
            activeDevices: '活跃设备',
            bundleIds: 'Bundle ID 数',
            registrations: '注册数',
            bundleHeader: 'Bundle / 平台',
            dauHeader: '7 日活跃',
            regHeader: '7 日注册',
            channelHeader: '渠道',
            empty: '暂无分析数据',
          };

      const getStoredToken = () => {
        const candidates = ['XBOARD_ACCESS_TOKEN', 'Xboard_access_token'];
        for (const key of candidates) {
          const raw = localStorage.getItem(key);
          if (!raw) continue;
          try {
            const parsed = JSON.parse(raw);
            if (parsed?.value) return String(parsed.value).replace(/^Bearer\s+/i, '');
          } catch (_) {
            if (raw) return String(raw).replace(/^Bearer\s+/i, '');
          }
        }
        return '';
      };

      const buildHeaders = () => {
        const token = getStoredToken();
        return token ? { Authorization: `Bearer ${token}` } : {};
      };

      let cache = null;
      let cacheAt = 0;
      let mounted = false;

      root.innerHTML = `
        <style>
          .device-analytics-shell {
            display: none;
            margin: 0 0 18px;
            padding: 18px 20px;
            border-radius: 18px;
            background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.98));
            box-shadow: 0 14px 40px rgba(15, 23, 42, 0.08);
            border: 1px solid rgba(226, 232, 240, 0.95);
          }
          .device-analytics-shell.open { display: block; }
          .device-analytics-title { margin: 0; font-size: 18px; font-weight: 700; color: #111827; }
          .device-analytics-subtitle { margin: 6px 0 0; font-size: 13px; color: #6b7280; }
          .device-analytics-summary {
            margin-top: 16px;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
          }
          .device-analytics-stat {
            padding: 14px;
            border-radius: 14px;
            background: #0f172a;
            color: #fff;
          }
          .device-analytics-stat label {
            display: block;
            font-size: 11px;
            color: rgba(255,255,255,0.72);
            margin-bottom: 6px;
          }
          .device-analytics-stat strong {
            font-size: 24px;
            line-height: 1;
          }
          .device-analytics-table-wrap {
            margin-top: 16px;
            overflow: auto;
            border-radius: 14px;
            border: 1px solid #e5e7eb;
            background: #fff;
          }
          .device-analytics-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px;
          }
          .device-analytics-table th,
          .device-analytics-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #eef2f7;
            text-align: left;
            font-size: 13px;
            color: #111827;
          }
          .device-analytics-table th {
            font-size: 12px;
            color: #6b7280;
            background: #f8fafc;
          }
          .device-analytics-empty {
            margin-top: 16px;
            padding: 14px;
            border-radius: 14px;
            background: #f8fafc;
            color: #64748b;
            font-size: 13px;
          }
          @media (max-width: 900px) {
            .device-analytics-summary {
              grid-template-columns: repeat(2, minmax(0, 1fr));
            }
          }
        </style>
        <section class="device-analytics-shell" aria-label="${t.title}">
          <h3 class="device-analytics-title">${t.title}</h3>
          <p class="device-analytics-subtitle">${t.subtitle}</p>
          <div class="device-analytics-summary"></div>
          <div class="device-analytics-table-wrap" hidden>
            <table class="device-analytics-table">
              <thead>
                <tr>
                  <th>${t.bundleHeader}</th>
                  <th>${t.channelHeader}</th>
                  <th>${t.dauHeader}</th>
                  <th>${t.regHeader}</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
          <div class="device-analytics-empty" hidden>${t.empty}</div>
        </section>
      `;

      const shell = root.querySelector('.device-analytics-shell');
      const summaryEl = root.querySelector('.device-analytics-summary');
      const tableWrap = root.querySelector('.device-analytics-table-wrap');
      const tbody = root.querySelector('tbody');
      const emptyEl = root.querySelector('.device-analytics-empty');

      const isDashboardPage = () => {
        const hash = `${location.hash || ''}`.toLowerCase();
        return hash.includes('dashboard') || document.body.innerText.includes('仪表盘');
      };

      const getDashboardAnchor = () =>
        document.querySelector('.ant-layout-content .ant-card') ||
        document.querySelector('.ant-layout-content');

      const mount = async () => {
        if (!isDashboardPage()) {
          shell.classList.remove('open');
          return;
        }
        const anchor = getDashboardAnchor();
        if (!anchor) return;
        if (root.parentElement !== anchor.parentElement || root.nextSibling !== anchor) {
          anchor.parentElement.insertBefore(root, anchor);
        }
        shell.classList.add('open');
        if (!mounted || (Date.now() - cacheAt) > 300000) {
          await fetchAndRender();
          mounted = true;
        }
      };

      const fetchAndRender = async () => {
        try {
          const response = await fetch(`/api/v2/${securePath}/stat/getDeviceAnalytics?days=7`, {
            headers: buildHeaders(),
            credentials: 'same-origin',
          });
          const payload = await response.json();
          cache = payload?.data || null;
          cacheAt = Date.now();
          render();
        } catch (_) {
          cache = null;
          render();
        }
      };

      const render = () => {
        const summary = cache?.summary || {};
        const daily = Array.isArray(cache?.daily) ? cache.daily : [];
        const registrations = Array.isArray(cache?.registrations) ? cache.registrations : [];

        summaryEl.innerHTML = [
          [t.activeUsers, summary.active_users_total || 0],
          [t.activeDevices, summary.active_devices_total || 0],
          [t.bundleIds, summary.bundle_ids_total || 0],
          [t.registrations, summary.registrations_total || 0],
        ].map(([label, value]) => `
          <div class="device-analytics-stat">
            <label>${label}</label>
            <strong>${value}</strong>
          </div>
        `).join('');

        const aggregate = new Map();
        daily.forEach((row) => {
          const key = [row.bundle_id, row.platform, row.distribution_channel].join('||');
          const existing = aggregate.get(key) || {
            bundle_id: row.bundle_id,
            platform: row.platform,
            distribution_channel: row.distribution_channel,
            active_users: 0,
            registrations: 0,
          };
          existing.active_users += Number(row.active_users || 0);
          aggregate.set(key, existing);
        });
        registrations.forEach((row) => {
          const key = [row.bundle_id, row.platform, row.distribution_channel].join('||');
          const existing = aggregate.get(key) || {
            bundle_id: row.bundle_id,
            platform: row.platform,
            distribution_channel: row.distribution_channel,
            active_users: 0,
            registrations: 0,
          };
          existing.registrations += Number(row.registrations || 0);
          aggregate.set(key, existing);
        });

        const rows = Array.from(aggregate.values()).sort((a, b) =>
          (b.active_users + b.registrations) - (a.active_users + a.registrations)
        );

        if (!rows.length) {
          tableWrap.hidden = true;
          emptyEl.hidden = false;
          tbody.innerHTML = '';
          return;
        }

        emptyEl.hidden = true;
        tableWrap.hidden = false;
        tbody.innerHTML = rows.map((row) => `
          <tr>
            <td><strong>${row.bundle_id || 'unknown'}</strong><br><span style="color:#64748b">${row.platform || 'unknown'}</span></td>
            <td>${row.distribution_channel || 'unknown'}</td>
            <td>${row.active_users}</td>
            <td>${row.registrations}</td>
          </tr>
        `).join('');
      };

      let timer = null;
      const observer = new MutationObserver(() => {
        clearTimeout(timer);
        timer = setTimeout(mount, 160);
      });
      observer.observe(document.body, { childList: true, subtree: true });
      window.addEventListener('hashchange', mount);
      window.addEventListener('popstate', mount);
      mount();
    })();
  </script>
</body>

</html>
