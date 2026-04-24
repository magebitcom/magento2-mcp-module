(() => {
    const header = document.querySelector('.site-header');
    if (header) {
        const onScroll = () => {
            header.classList.toggle('is-scrolled', window.scrollY > 4);
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    document.querySelectorAll('[data-copy-target]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const targetId = btn.getAttribute('data-copy-target');
            const el = document.getElementById(targetId);
            if (!el) return;
            const text = el.textContent.replace(/^\$\s*/, '').trim();
            try {
                await navigator.clipboard.writeText(text);
            } catch {
                const range = document.createRange();
                range.selectNodeContents(el);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
                document.execCommand('copy');
                sel.removeAllRanges();
            }
            const original = btn.textContent;
            btn.textContent = 'Copied';
            btn.classList.add('is-copied');
            setTimeout(() => {
                btn.textContent = original;
                btn.classList.remove('is-copied');
            }, 1600);
        });
    });

    const yearEl = document.getElementById('footer-year');
    if (yearEl) yearEl.textContent = new Date().getFullYear();
})();
