import htmx from 'htmx.org';
import Alpine from 'alpinejs';
import Sortable from 'sortablejs';

window.htmx = htmx;
window.Alpine = Alpine;

function panelStorageKey(id) {
  if (!id) return null;
  return `aqto.ui.panel.${id}.open`;
}

window.aqtoCollapsible = function aqtoCollapsible(id, defaultOpen = true) {
  return {
    open: Boolean(defaultOpen),
    init() {
      const key = panelStorageKey(id);
      if (!key) return;
      try {
        const raw = localStorage.getItem(key);
        if (raw === null) return;
        if (raw === '1' || raw === 'true') this.open = true;
        if (raw === '0' || raw === 'false') this.open = false;
      } catch {
        // ignore storage failures
      }
    },
    setOpen(next) {
      this.open = Boolean(next);
      const key = panelStorageKey(id);
      if (!key) return;
      try {
        localStorage.setItem(key, this.open ? '1' : '0');
      } catch {
        // ignore storage failures
      }
    },
    toggle() {
      this.setOpen(!this.open);
    },
  };
};

Alpine.start();

function applyHtmlToConfigList(html) {
  const el = document.getElementById('configList');
  if (!el) return;
  el.innerHTML = html;

  // Re-init HTMX bindings on newly inserted content
  if (window.htmx && typeof window.htmx.process === 'function') {
    window.htmx.process(el);
  }

  // Re-init Alpine on the new subtree
  if (window.Alpine && typeof window.Alpine.initTree === 'function') {
    window.Alpine.initTree(el);
  }

  // Re-init syntax highlighting
  if (window.Prism && typeof window.Prism.highlightAllUnder === 'function') {
    window.Prism.highlightAllUnder(el);
  }

  // Re-init drag/drop
  if (typeof window.aqtoInitConfigTreeDragDrop === 'function') {
    window.aqtoInitConfigTreeDragDrop(el);
  }
}

function serializeConfigTree(root) {
  const container = root || document;
  const lists = Array.from(container.querySelectorAll('ul[data-aqto-sortable="1"]'));
  const items = [];

  for (const ul of lists) {
    const parentIdRaw = ul.dataset.folderId;
    const parentId = parentIdRaw && parentIdRaw.trim() !== '' ? parentIdRaw.trim() : null;

    const children = Array.from(ul.children).filter((li) => li && li.dataset && li.dataset.aqtoItem === '1');
    let sort = 10;
    for (const li of children) {
      const type = li.dataset.type || '';
      const id = li.dataset.id || '';
      if (!type || !id) continue;
      items.push({ type, id, parentId, sort });
      sort += 10;
    }
  }

  return items;
}

async function postTreeUpdate(items) {
  const resp = await fetch('/folders/tree-update', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'text/html' },
    body: JSON.stringify(items),
  });

  const html = await resp.text();
  if (!resp.ok) {
    throw new Error(html || `Tree update failed (${resp.status})`);
  }

  applyHtmlToConfigList(html);
}

window.aqtoInitConfigTreeDragDrop = function aqtoInitConfigTreeDragDrop(root) {
  const container = root || document;
  const lists = Array.from(container.querySelectorAll('ul[data-aqto-sortable="1"]'));
  if (lists.length === 0) return;

  for (const ul of lists) {
    if (ul.__aqtoSortable) continue;

    ul.__aqtoSortable = new Sortable(ul, {
      group: { name: 'aqto-config-tree', pull: true, put: true },
      animation: 120,
      handle: '.aqto-drag-handle',
      draggable: '[data-aqto-item="1"]',
      fallbackOnBody: true,
      swapThreshold: 0.65,
      onEnd: async () => {
        try {
          const items = serializeConfigTree(document.getElementById('configList') || document);
          await postTreeUpdate(items);
        } catch (e) {
          // Best-effort: alert then refresh to reflect server state
          // eslint-disable-next-line no-alert
          alert(e?.message || 'Unable to save order');

          if (window.htmx) {
            window.htmx.ajax('GET', '/configs/list', { target: '#configList', swap: 'innerHTML' });
          }
        }
      },
    });
  }
};

async function postFolderForm(url, params) {
  const resp = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'text/html' },
    body: new URLSearchParams(params),
  });

  const html = await resp.text();
  if (!resp.ok) {
    throw new Error(html || `Request failed (${resp.status})`);
  }

  applyHtmlToConfigList(html);
}

window.aqtoFolderCreate = async function aqtoFolderCreate(parentId) {
  const name = window.prompt('Folder name:');
  if (!name) return;
  const params = { name: name.trim() };
  if (parentId) params.parentId = parentId;
  try {
    await postFolderForm('/folders', params);
  } catch (e) {
    // eslint-disable-next-line no-alert
    alert(e?.message || 'Unable to create folder');
  }
};

window.aqtoFolderRename = async function aqtoFolderRename(id, currentName) {
  const name = window.prompt('Rename folder:', currentName || '');
  if (!name) return;
  try {
    await postFolderForm(`/folders/${encodeURIComponent(id)}/rename`, { name: name.trim() });
  } catch (e) {
    // eslint-disable-next-line no-alert
    alert(e?.message || 'Unable to rename folder');
  }
};

window.aqtoFolderDelete = async function aqtoFolderDelete(id) {
  const ok = window.confirm('Delete this folder? Items inside will be moved up one level.');
  if (!ok) return;
  try {
    await postFolderForm(`/folders/${encodeURIComponent(id)}/delete`, {});
  } catch (e) {
    // eslint-disable-next-line no-alert
    alert(e?.message || 'Unable to delete folder');
  }
};

// Re-run syntax highlighting after HTMX swaps.
document.body.addEventListener('htmx:afterSwap', (event) => {
  const target = event?.detail?.target || document.body;

  if (window.Alpine && typeof window.Alpine.initTree === 'function') {
    window.Alpine.initTree(target);
  }

  if (window.Prism && typeof window.Prism.highlightAllUnder === 'function') {
    window.Prism.highlightAllUnder(document.body);
  }

  if (typeof window.aqtoInitConfigTreeDragDrop === 'function') {
    window.aqtoInitConfigTreeDragDrop(target);
  }
});

// By default, HTMX will not swap content on non-2xx responses.
// For our modal endpoints, we often return an HTML fragment describing the error.
document.body.addEventListener('htmx:responseError', (event) => {
  const xhr = event?.detail?.xhr;
  const target = event?.detail?.target;
  if (!xhr || !target) return;

  const contentType = (xhr.getResponseHeader('content-type') || '').toLowerCase();
  if (!contentType.includes('text/html')) return;

  const html = xhr.responseText;
  if (typeof html !== 'string' || html.trim() === '') return;

  target.innerHTML = html;
});

function oauthStatusKey(configId, env) {
  if (!configId || !env) return null;
  return `aqto.oauth.status.${configId}.${env}`;
}

function setOAuthStatus({ configId, applyEnv, expiresIn, accessToken }) {
  const env = applyEnv || 'dev';
  const key = oauthStatusKey(configId, env);
  if (!key) return;

  const payload = {
    updatedAt: new Date().toISOString(),
  };

  const hint = tokenHint(accessToken);
  if (hint) {
    payload.tokenHint = hint;
  }

  const seconds = Number(expiresIn);
  if (Number.isFinite(seconds) && seconds > 0) {
    payload.expiresAt = new Date(Date.now() + seconds * 1000).toISOString();
  }

  try {
    localStorage.setItem(key, JSON.stringify(payload));
  } catch {
    // ignore storage failures
  }

  window.dispatchEvent(
    new CustomEvent('aqto:oauth-status-updated', {
      detail: { configId, env },
    }),
  );
}

function tokenHint(accessToken) {
  if (typeof accessToken !== 'string') return '';
  const t = accessToken.trim();
  if (t.length <= 10) return t;
  return `${t.slice(0, 4)}…${t.slice(-4)}`;
}

async function applyBearerToConfig(configId, { accessToken, tokenType, refreshToken, expiresIn } = {}) {
  if (!configId || !accessToken) return;

  const body = new URLSearchParams();
  body.set('accessToken', accessToken);
  if (tokenType) body.set('tokenType', tokenType);
  if (refreshToken) body.set('refreshToken', refreshToken);
  if (expiresIn !== undefined && expiresIn !== null) body.set('expiresIn', String(expiresIn));

  try {
    await fetch(`/configs/${encodeURIComponent(configId)}/apply-bearer`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });
  } catch {
    // ignore apply failures; refresh toast still reports token refresh result
  }
}

function dispatchToast(kind, title, message) {
  document.dispatchEvent(
    new CustomEvent('aqto-toast', {
      detail: { kind, title, message },
    }),
  );
}

document.body.addEventListener('oauth-token-received', (event) => {
  const d = event?.detail || {};
  if (d?.configId) {
    setOAuthStatus({
      configId: d.configId,
      applyEnv: d.applyEnv,
      expiresIn: d.expiresIn,
      accessToken: d.accessToken,
    });
  }

  if (d?.origin === 'list' && d?.accessToken && d?.configId && !d?.persisted) {
    applyBearerToConfig(d.configId, {
      accessToken: d.accessToken,
      tokenType: d.tokenType,
      refreshToken: d.refreshToken,
      expiresIn: d.expiresIn,
    });
  }

  if (d?.origin === 'list' && d?.mode === 'refresh') {
    const name = d.configName ? ` for ${d.configName}` : '';
    const env = d.applyEnv ? ` (${d.applyEnv})` : '';
    dispatchToast('success', 'Refresh success', `Token refreshed${name}${env}.`);
  }
});

document.body.addEventListener('oauth-token-error', (event) => {
  const d = event?.detail || {};

  if (d?.origin === 'list' && d?.mode === 'refresh') {
    const name = d.configName ? ` for ${d.configName}` : '';
    const env = d.applyEnv ? ` (${d.applyEnv})` : '';
    dispatchToast('error', 'Failed refresh', `${d.message || 'Token refresh failed'}${name}${env}.`);
  }
});

// When auth profiles change, refresh the config list so auth summaries/OAuth meta stay in sync.
window.addEventListener('auth-profiles-changed', () => {
  if (!window.htmx) return;
  const el = document.getElementById('configList');
  if (!el) return;
  window.htmx.ajax('GET', '/configs/list', { target: '#configList', swap: 'innerHTML' });
});

// Initial drag/drop init on first render (script is loaded with defer).
if (typeof window.aqtoInitConfigTreeDragDrop === 'function') {
  window.aqtoInitConfigTreeDragDrop(document);
}
