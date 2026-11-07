{{--
    Contiu page loader ("01 · Sequência"): the four pieces of the symbol come in one after the
    other and leave in the same order. Shown when leaving for another page of the app (a link or a
    plain form), only if the next page takes longer than 0.3 s; never for Livewire requests (the
    dashboard polls every few seconds) or downloads. Hides itself after 10 s just in case.
--}}
<div id="contiu-page-loader" class="cpl" role="status" aria-live="polite" aria-hidden="true">
    <svg class="cpl-symbol" viewBox="250 180 391 336" width="72" height="62" fill="currentColor" aria-hidden="true" focusable="false">
        <path class="cpl-piece cpl-tl" d="M285.441 377.008 C276.382 375.362 269.97 369.385 267.063 359.875 L266.375 357.625 266.375 308 L266.375 258.375 266.944 254.631 C268.053 247.338 269.209 243.182 272.183 235.799 C276.965 223.929 288.928 211.154 301.375 204.626 C302.887 203.832 304.377 203.028 304.686 202.837 C308.576 200.434 315.795 198.257 324.625 196.822 L328.875 196.132 383.875 196.128 L438.875 196.125 441.25 196.832 C452.117 200.07 458.654 208.943 458.185 219.818 C458.026 223.508 457.555 225.151 455.455 229.34 C452.774 234.688 448.608 238.041 442.113 240.079 C438.063 241.349 439.355 241.314 389.75 241.489 C347.545 241.637 342.908 241.693 340.832 242.074 C328.04 244.425 318.458 253.342 314.651 266.438 C313.401 270.738 313.422 270.019 313.249 315 C313.159 338.306 312.999 357.712 312.892 358.125 C310.413 367.735 304.57 374.238 296.375 376.508 C294.16 377.121 287.695 377.417 285.441 377.008 Z"/>
        <path class="cpl-piece cpl-ub" d="M367.875 336.248 C356.647 333.801 350.019 326.247 349.323 315.103 C348.592 303.396 356.653 293.636 368.948 291.343 C370.929 290.973 377.979 290.94 447.441 290.968 L523.757 291 526.191 291.521 C537.064 293.848 544.75 302.998 544.75 313.614 C544.75 325.191 536.879 334.41 525.341 336.347 C521.67 336.963 370.723 336.868 367.875 336.248 Z"/>
        <path class="cpl-piece cpl-lb" d="M374.25 410.979 C367.772 410.81 365.937 410.457 362.125 408.645 C349.133 402.471 345.191 385.692 354.072 374.365 C357.61 369.853 363.092 366.746 368.839 365.996 C370.249 365.812 394.617 365.754 448.125 365.808 L525.375 365.885 527.411 366.432 C539.791 369.761 546.939 381.37 544.235 393.756 C542.176 403.194 533.273 410.557 523.5 410.905 C518.85 411.071 380.411 411.139 374.25 410.979 Z"/>
        <path class="cpl-piece cpl-br" d="M443.25 500.31 C426.459 499.206 417.036 481.451 425.373 466.625 C428.368 461.298 435.219 456.802 441.55 456.009 C442.909 455.838 460.716 455.75 493.754 455.75 C552.048 455.75 548.266 455.91 555.698 453.135 C561.917 450.813 570.387 442.922 573.687 436.375 C575.273 433.228 576.368 429.258 577.248 423.466 C577.408 422.418 577.514 408.434 577.536 385.466 C577.556 365.479 577.654 347.775 577.754 346.125 C578.282 337.389 583.068 330.262 591.066 326.3 C598.966 322.385 609.871 323.575 616.515 329.075 C619.812 331.804 622.86 336.483 624.204 340.875 L624.816 342.875 625.01 370.25 C625.284 409.1 624.989 438.251 624.287 441.625 C622.269 451.331 621.132 454.961 618.059 461.5 C614.997 468.018 606.28 478.694 600 483.617 C595.357 487.257 589.711 490.879 585.375 493 C583.126 494.1 581.194 495 581.08 495.001 C580.967 495.001 580.031 495.349 579 495.772 C573.472 498.044 568.902 499.112 561 499.977 C558.883 500.209 446.545 500.526 443.25 500.31 Z"/>
    </svg>
    <span class="cpl-label">A carregar…</span>
</div>

<style>
    #contiu-page-loader {
        position: fixed;
        inset: 0;
        z-index: 2147483000;
        display: flex;
        flex-direction: column;
        gap: 16px;
        align-items: center;
        justify-content: center;
        background: #ffffff;
        color: #415847;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: opacity 0.2s ease, visibility 0s linear 0.2s;
    }
    #contiu-page-loader.is-visible {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
        /* Fast pages never get to show it. */
        transition: opacity 0.2s ease 0.3s, visibility 0s linear 0.3s;
    }
    .dark #contiu-page-loader { background: #0a0a0a; color: #ffffff; }
    #contiu-page-loader .cpl-symbol { display: block; overflow: visible; }
    #contiu-page-loader .cpl-piece {
        transform-box: fill-box;
        transform-origin: center;
        animation: cpl-seq 2.4s cubic-bezier(0.4, 0, 0.2, 1) infinite both;
        animation-play-state: paused;
    }
    #contiu-page-loader.is-visible .cpl-piece { animation-play-state: running; }
    #contiu-page-loader .cpl-ub { animation-delay: 0.16s; }
    #contiu-page-loader .cpl-lb { animation-delay: 0.32s; }
    #contiu-page-loader .cpl-br { animation-delay: 0.48s; }
    @keyframes cpl-seq {
        0% { opacity: 0; transform: translateY(36px); }
        18%, 62% { opacity: 1; transform: none; }
        82%, 100% { opacity: 0; transform: none; }
    }
    #contiu-page-loader .cpl-label {
        font-size: 14px;
        font-weight: 500;
        letter-spacing: 0.02em;
        color: #525252;
        animation: cpl-label 1.6s ease-in-out infinite alternate;
        animation-play-state: paused;
    }
    #contiu-page-loader.is-visible .cpl-label { animation-play-state: running; }
    .dark #contiu-page-loader .cpl-label { color: #a3a3a3; }
    @keyframes cpl-label {
        from { opacity: 0.55; }
        to { opacity: 1; }
    }
    @media (prefers-reduced-motion: reduce) {
        #contiu-page-loader .cpl-piece,
        #contiu-page-loader .cpl-label { animation: none; }
    }
</style>

<script>
    (() => {
        const loader = document.getElementById('contiu-page-loader');

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
