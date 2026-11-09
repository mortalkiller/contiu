(() => {
    if (window.fslPageLoaderReady) {
        return;
    }

    window.fslPageLoaderReady = true;

    let activeLoader;
    let showTimer;
    let safetyTimer;

    const hide = () => {
        clearTimeout(showTimer);
        clearTimeout(safetyTimer);

        activeLoader?.classList.remove('is-visible');
        activeLoader?.setAttribute('aria-hidden', 'true');
        activeLoader = undefined;
    };

    const schedule = () => {
        const loader = document.getElementById('fsl-page-loader');

        if (!loader || activeLoader === loader) {
            return;
        }

        hide();
        activeLoader = loader;

        const show = () => {
            if (!loader.isConnected) {
                hide();
                return;
            }

            loader.classList.add('is-visible');
            loader.setAttribute('aria-hidden', 'false');
            safetyTimer = setTimeout(hide, 10000);
        };

        const delay = Math.min(Number(loader.dataset.delay), 2147483647);

        if (delay > 0) {
            showTimer = setTimeout(show, delay);
        } else {
            show();
        }
    };

    const navigatesHere = (target) => {
        const effectiveTarget = target || document.querySelector('base[target]')?.target;

        return !effectiveTarget || effectiveTarget.toLowerCase() === '_self';
    };

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const link = event.target.closest('a[href]');

        if (!link || !navigatesHere(link.target) || link.hasAttribute('download') ||
            link.getAttributeNames().some((name) => name === 'wire:navigate' || name.startsWith('wire:navigate.'))) {
            return;
        }

        const url = new URL(link.href, location.href);

        if (url.origin === location.origin && (url.pathname !== location.pathname || url.search !== location.search)) {
            schedule();
        }
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        const submitter = event.submitter;
        const target = submitter?.hasAttribute('formtarget') ? submitter.formTarget : form.target;
        const method = submitter?.hasAttribute('formmethod') ? submitter.formMethod : form.method;

        if (!event.defaultPrevented && method !== 'dialog' && navigatesHere(target)) {
            schedule();
        }
    });

    window.addEventListener('pageshow', hide);
    document.addEventListener('livewire:navigating', hide);
    document.addEventListener('pointerdown', (event) => {
        if (activeLoader?.contains(event.target)) {
            hide();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            hide();
        }
    });
})();
