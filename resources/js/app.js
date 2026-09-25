import Alpine from 'alpinejs';
import focus from '@alpinejs/focus';

Alpine.plugin(focus);

/* ---------------------------------------------------------------------
   Small helpers shared across the public site and the dashboards.
   --------------------------------------------------------------------- */
Alpine.data('disclosure', (initial = false) => ({
    open: initial,
    toggle() {
        this.open = !this.open;
    },
}));

Alpine.data('copyToClipboard', (text = '') => ({
    copied: false,
    async copy(value) {
        const payload = value ?? text;

        try {
            await navigator.clipboard.writeText(payload);
        } catch {
            // Clipboard API blocked (insecure context) — fall back to a temp node.
            const node = document.createElement('textarea');
            node.value = payload;
            node.setAttribute('readonly', '');
            node.style.position = 'fixed';
            node.style.opacity = '0';
            document.body.appendChild(node);
            node.select();
            document.execCommand('copy');
            document.body.removeChild(node);
        }

        this.copied = true;
        setTimeout(() => (this.copied = false), 2000);
    },
}));

window.Alpine = Alpine;

Alpine.start();
