import htmx from 'htmx.org';
import Alpine from 'alpinejs';

window.htmx = htmx;
window.Alpine = Alpine;

Alpine.start();

// Re-run syntax highlighting after HTMX swaps.
document.body.addEventListener('htmx:afterSwap', (event) => {
  const target = event?.detail?.target || document.body;

  if (window.Alpine && typeof window.Alpine.initTree === 'function') {
    window.Alpine.initTree(target);
  }

  if (window.Prism && typeof window.Prism.highlightAllUnder === 'function') {
    window.Prism.highlightAllUnder(document.body);
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

function setOAuthStatus({ configId, applyEnv, expiresIn }) {
  const env = applyEnv || 'dev';
  const key = oauthStatusKey(configId, env);
  if (!key) return;

  const payload = {
    updatedAt: new Date().toISOString(),
  };

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
    setOAuthStatus({ configId: d.configId, applyEnv: d.applyEnv, expiresIn: d.expiresIn });
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
