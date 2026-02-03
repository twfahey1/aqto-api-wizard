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
