import htmx from 'htmx.org';
import Alpine from 'alpinejs';

window.htmx = htmx;
window.Alpine = Alpine;

Alpine.start();

// Re-run syntax highlighting after HTMX swaps.
document.body.addEventListener('htmx:afterSwap', () => {
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
