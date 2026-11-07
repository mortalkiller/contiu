{{--
    Full-screen loader between pages. Shown when leaving for another page of the app (a link or a
    plain form), only if the next page takes longer than the delay; never for Livewire requests or
    downloads. Hides itself after 10 s in case the browser never leaves the page.

    The symbol's elements with class `fsl-loader-piece` come in one after the other.
--}}
<div id="fsl-page-loader" class="fsl-page-loader" role="status" aria-live="polite" aria-hidden="true" style="--fsl-loader-delay: {{ $delay }}ms">
    <div class="fsl-loader-symbol" aria-hidden="true">
        @if (filled($symbol))
            {{ $symbol }}
        @else
            <svg viewBox="0 0 120 96" width="64" height="52" fill="currentColor" focusable="false">
                <rect class="fsl-loader-piece" x="0" y="0" width="120" height="20" rx="10"/>
                <rect class="fsl-loader-piece" x="0" y="38" width="88" height="20" rx="10"/>
                <rect class="fsl-loader-piece" x="0" y="76" width="56" height="20" rx="10"/>
            </svg>
        @endif
    </div>
    <span class="fsl-loader-label">{{ $label }}</span>
</div>

<script>
    (() => {
        const loader = document.getElementById('fsl-page-loader');

        if (! loader || loader.dataset.ready) {
            return;
        }

        loader.dataset.ready = '1';
        let safety;

        const hide = () => {
            loader.classList.remove('is-visible');
            loader.setAttribute('aria-hidden', 'true');
            clearTimeout(safety);
        };

        const show = () => {
            loader.classList.add('is-visible');
            loader.setAttribute('aria-hidden', 'false');
            clearTimeout(safety);
            safety = setTimeout(hide, 10000);
        };

        const leavesThePage = (url) => url.origin === location.origin
            && ! (url.pathname === location.pathname && url.search === location.search);

        document.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            const link = event.target.closest('a[href]');

            if (! link || (link.target && link.target !== '_self') || link.hasAttribute('download') || link.hasAttribute('wire:navigate')) {
                return;
            }

            if (leavesThePage(new URL(link.href, location.href))) {
                show();
            }
        });

        // Plain forms only (logout and the like): Livewire forms prevent the default submit.
        document.addEventListener('submit', (event) => {
            const form = event.target;

            if (event.defaultPrevented || (form.target && form.target !== '_self')) {
                return;
            }

            show();
        });

        // Back/forward cache: the page comes back with the loader still on.
        window.addEventListener('pageshow', hide);
    })();
</script>
