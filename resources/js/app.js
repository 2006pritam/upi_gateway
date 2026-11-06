import './bootstrap';

/**
 * data-copy="<text>" — copies the text to the clipboard and briefly shows "Copied!".
 * Used on payment link copy buttons throughout the dashboard.
 */
document.addEventListener('click', async (e) => {
    const el = e.target.closest('[data-copy]');
    if (!el) return;
    e.preventDefault();
    try {
        await navigator.clipboard.writeText(el.dataset.copy);
    } catch (_) {
        return;
    }
    const label = el.querySelector('[data-copy-label]') || el;
    const original = label.textContent;
    label.textContent = 'Copied!';
    setTimeout(() => label.textContent = original, 1500);
});
