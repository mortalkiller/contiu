/*
 * The public site's few behaviours (resources/views/site): the top bar turning indigo once the
 * page scrolls, the "Recursos" menu, the price section's switches (every price is already in the
 * page), the help page's search, the article's reading bar and table of contents following the
 * reading, and the share buttons. No money is worked out here.
 */

const nav = document.querySelector('[data-site-nav]');

if (nav) {
    const update = () => nav.classList.toggle('is-scrolled', window.scrollY > 24);
    update();
    window.addEventListener('scroll', update, { passive: true });

    // The bar covers the top of the page by its own height (taller on a phone, where the menu
    // drops below the logo); site.css reads it from --st-nav-h, the 1px border left out.
    const root = nav.closest('.c-root');
    const measure = () => root.style.setProperty('--st-nav-h', `${nav.offsetHeight - 1}px`);
    measure();
    window.addEventListener('resize', measure);
    document.fonts?.ready.then(measure);
}

document.querySelectorAll('[data-site-drop]').forEach((drop) => {
    const button = drop.querySelector('button');
    const menu = drop.querySelector('.st-drop-menu');
    const setOpen = (open) => {
        button.setAttribute('aria-expanded', String(open));
        menu.hidden = !open;
    };

    button.addEventListener('click', () => setOpen(menu.hidden));
    document.addEventListener('click', (event) => {
        if (!drop.contains(event.target)) {
            setOpen(false);
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !menu.hidden) {
            setOpen(false);
            button.focus();
        }
    });
});

// The price section (home and Preços): monthly or yearly, with or without the accountant, and the regime.
// Every price is worked out on the server (data-prices); this only picks which one to show.
document.querySelectorAll('[data-site-pricing]').forEach((box) => {
    const prices = JSON.parse(box.dataset.prices);
    const state = { billing: 'monthly', plan: 'contiu', regime: 'simplified' };

    const render = () => {
        box.querySelectorAll('[data-billing]').forEach((button) => {
            const on = button.dataset.billing === state.billing;
            button.classList.toggle('is-on', on);
            button.setAttribute('aria-pressed', String(on));
        });
        box.querySelectorAll('.pr-segs [data-plan-choice]').forEach((button) => {
            const on = button.dataset.planChoice === state.plan;
            button.classList.toggle('is-on', on);
            button.classList.toggle('is-green', on && state.plan === 'contab');
            button.setAttribute('aria-pressed', String(on));
        });
        box.querySelectorAll('[data-regime]').forEach((button) => {
            const on = button.dataset.regime === state.regime;
            button.classList.toggle('is-on', on);
            button.setAttribute('aria-checked', String(on));
        });
        box.querySelectorAll('[data-only-plan]').forEach((element) => {
            element.hidden = element.dataset.onlyPlan !== state.plan;
        });
        box.querySelectorAll('[data-only-billing]').forEach((element) => {
            element.hidden = element.dataset.onlyBilling !== state.billing;
        });
        box.querySelector('[data-price]').textContent = prices[state.billing][state.plan === 'contiu' ? 'contiu' : state.regime];
    };

    box.addEventListener('click', (event) => {
        const control = event.target.closest('[data-billing], [data-plan-choice], [data-regime]');

        if (!control) {
            return;
        }

        state.billing = control.dataset.billing ?? state.billing;
        state.plan = control.dataset.planChoice ?? state.plan;
        state.regime = control.dataset.regime ?? state.regime;
        render();
    });

    render();
});

const toc = document.querySelector('[data-site-toc]');

if (toc) {
    const links = [...toc.querySelectorAll('a[href^="#"]')];
    const headings = links.map((link) => document.getElementById(link.hash.slice(1)));

    // The section being read: the last heading above the top third of the window, else the first.
    const update = () => {
        const line = window.innerHeight * 0.3;
        const index = Math.max(0, headings.findLastIndex((heading) => heading && heading.getBoundingClientRect().top < line && window.scrollY > 0));
        links.forEach((link, linkIndex) => link.setAttribute('aria-current', String(linkIndex === index)));
    };

    update();
    window.addEventListener('scroll', update, { passive: true });
}

document.querySelectorAll('[data-site-share]').forEach((share) => {
    const { url, title } = share.dataset;

    share.querySelectorAll('[data-share]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (button.dataset.share === 'copy') {
                await navigator.clipboard?.writeText(url);
                button.setAttribute('aria-label', 'Ligação copiada');
            } else if (button.dataset.share === 'linkedin') {
                window.open('https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(url), '_blank', 'noopener');
            } else {
                window.location.href = 'mailto:?subject=' + encodeURIComponent(title) + '&body=' + encodeURIComponent(url);
            }
        });
    });
});

const openLinkedQuestion = () => {
    const target = window.location.hash ? document.getElementById(decodeURIComponent(window.location.hash.slice(1))) : null;

    if (target instanceof HTMLDetailsElement) {
        target.hidden = false;
        target.open = true;
    }
};

openLinkedQuestion();
window.addEventListener('hashchange', openLinkedQuestion);

// The article's reading bar under the top bar: how much of the text has been read.
const readingBar = document.querySelector('[data-site-progress] i');
const article = document.querySelector('.st-prose');

if (readingBar && article) {
    const update = () => {
        const box = article.getBoundingClientRect();
        const length = box.height - window.innerHeight;
        const read = length > 0 ? Math.min(Math.max(-box.top / length, 0), 1) : 1;
        readingBar.style.width = `${(read * 100).toFixed(1)}%`;
    };

    update();
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
}

// The home page's app window (resources/views/site/partials/demo.blade.php): a cursor issues an
// invoice-receipt, opens the taxes and the fiscal calendar, and starts again. Every figure is
// already in the page (data-demo-swap holds the value after the invoice); this only moves the
// cursor and swaps classes and texts. It runs only while the window is on screen and the tab is
// visible, and not at all for reduced motion.
const demo = document.querySelector('[data-site-demo]');
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

if (demo && !reducedMotion) {
    const cursor = demo.querySelector('[data-demo-cursor]');
    const ring = cursor.querySelector('.dm-click');
    const find = (name) => demo.querySelector(`[data-demo="${name}"]`);
    const all = (selector) => demo.querySelectorAll(selector);

    all('[data-demo-swap]').forEach((element) => {
        element.dataset.demoBefore = element.textContent;
    });

    const bar = demo.querySelector('[data-demo-bar]');
    bar.dataset.demoBefore = bar.style.height;

    const typing = demo.querySelector('[data-demo-typing]');
    const caret = typing.nextElementSibling;
    const emit = find('emitir');
    emit.dataset.demoLabel = emit.textContent;

    const swap = (after) => {
        all('[data-demo-swap]').forEach((element) => {
            element.textContent = after ? element.dataset.demoSwap : element.dataset.demoBefore;
        });
        bar.style.height = after ? bar.dataset.demoBar : bar.dataset.demoBefore;
    };
    const swapOne = (selector, after) => {
        const element = demo.querySelector(selector);
        element.textContent = after ? element.dataset.demoSwap : element.dataset.demoBefore;
    };

    const moveTo = (name) => {
        const target = find(name);
        const box = target?.getBoundingClientRect();

        if (!box?.width) {
            return;
        }

        const frame = demo.getBoundingClientRect();
        const x = box.left - frame.left + Math.min(box.width / 2, 60);
        const y = box.top - frame.top + box.height * 0.55;
        cursor.style.transform = `translate(${x}px, ${y}px)`;
    };

    let clicks = 0;
    const click = () => {
        clicks += 1;
        ring.classList.remove('is-a', 'is-b');
        ring.classList.add(clicks % 2 ? 'is-a' : 'is-b');
    };
    const press = (element, on) => element.classList.toggle('is-press', on);
    const show = (view) => {
        all('[data-demo-view]').forEach((panel) => panel.classList.toggle('is-on', panel.dataset.demoView === view));
        all('.dm-nav').forEach((item) => item.classList.toggle('is-on', item.dataset.demo === `nav-${view}`));
    };
    const highlight = (row) => all('.dm-due-row').forEach((item) => item.classList.toggle('is-hi', item.dataset.demo === row));
    const toggle = (selector, on) => demo.querySelector(selector).classList.toggle('is-on', on);
    const focus = (name, on) => find(name).classList.toggle('is-focus', on);

    const reset = () => {
        show('hoje');
        swap(false);
        toggle('[data-demo-modal]', false);
        toggle('[data-demo-toast]', false);
        toggle('[data-demo-explain]', false);
        highlight(null);
        typing.textContent = '0,00';
        caret.hidden = true;
        emit.textContent = emit.dataset.demoLabel;
    };

    const steps = [
        [0, reset],
        [700, () => moveTo('nova')],
        [900, () => { click(); press(find('nova'), true); }],
        [250, () => { press(find('nova'), false); toggle('[data-demo-modal]', true); }],
        [600, () => moveTo('cliente')],
        [900, () => { click(); focus('cliente', true); }],
        [350, () => swapOne('[data-demo="cliente"] [data-demo-swap]', true)],
        [500, () => { focus('cliente', false); moveTo('valor'); }],
        [900, () => { click(); focus('valor', true); caret.hidden = false; }],
        ...['1', '10', '100', '1 000'].map((text) => [140, () => { typing.textContent = text; }]),
        [220, () => {
            typing.textContent = '1 000,00';
            caret.hidden = true;
            focus('valor', false);
            all('.dm-total [data-demo-swap]').forEach((element) => { element.textContent = element.dataset.demoSwap; });
        }],
        [500, () => moveTo('emitir')],
        [900, () => { click(); press(emit, true); emit.textContent = emit.dataset.demoBusy; }],
        [900, () => {
            press(emit, false);
            toggle('[data-demo-modal]', false);
            swap(true);
            all('[data-demo-flash]').forEach((element) => element.classList.add('is-flash'));
            toggle('[data-demo-toast]', true);
        }],
        [1300, () => all('[data-demo-flash]').forEach((element) => element.classList.remove('is-flash'))],
        [600, () => moveTo('nav-impostos')],
        [900, () => { click(); show('impostos'); toggle('[data-demo-toast]', false); }],
        [700, () => moveTo('conta')],
        [900, () => { click(); press(find('conta'), true); toggle('[data-demo-explain]', true); }],
        [300, () => press(find('conta'), false)],
        [1900, () => moveTo('nav-calendario')],
        [900, () => { click(); show('calendario'); toggle('[data-demo-explain]', false); highlight('prazo-1'); }],
        [900, () => moveTo('prazo-2')],
        [700, () => highlight('prazo-2')],
        [700, () => moveTo('prazo-3')],
        [700, () => highlight('prazo-3')],
        [1500, () => moveTo('nav-hoje')],
        [900, () => { click(); show('hoje'); highlight(null); }],
        [2200, () => {}],
    ];

    let step = 0;
    let timer = null;
    let onScreen = false;

    const next = () => {
        const [wait, action] = steps[step];
        timer = window.setTimeout(() => {
            action();
            step = (step + 1) % steps.length;
            next();
        }, wait);
    };
    const update = () => {
        window.clearTimeout(timer);
        timer = null;

        if (onScreen && !document.hidden) {
            next();
        }
    };

    cursor.style.transform = `translate(${Math.round(demo.clientWidth * 0.55)}px, 360px)`;
    new IntersectionObserver(([entry]) => {
        onScreen = entry.isIntersecting;
        update();
    }, { threshold: 0.2 }).observe(demo);
    document.addEventListener('visibilitychange', update);
}

// Sections with looping CSS animations (data-site-anim, e.g. «Porque somos a opção certa») pause
// them while off screen, so a page scrolled past them does no work (site.css, .is-paused).
document.querySelectorAll('[data-site-anim]').forEach((section) => {
    new IntersectionObserver(([entry]) => section.classList.toggle('is-paused', !entry.isIntersecting)).observe(section);
});

// The home page's top background (canvas SiteHeroPontos): a grid of small dots, blue to green,
// with waves of light and a band sweeping across. Every dot's fixed numbers are worked out once
// (typed arrays), the waves come from a sine table, and the canvas is drawn at half size and
// enlarged without smoothing (site.css), so each frame copies a quarter of the pixels; 24 frames a
// second, quieter under the headline, only while on screen and the tab is visible; one still
// frame for reduced motion.
const dotsCanvas = document.querySelector('[data-site-dots]');

if (dotsCanvas) {
    const scale = 2;
    const gap = 5;
    const frameTime = 1000 / 24;
    const context = dotsCanvas.getContext('2d');
    const style = getComputedStyle(dotsCanvas);
    const readRgb = (name, fallback) => (style.getPropertyValue(name).trim() || fallback).split(',').map(Number);
    const blue = readRgb('--accent-hi-rgb', '100,150,255');
    const green = [25, 245, 160];
    const tableSize = 1024;
    const toTable = tableSize / (Math.PI * 2);
    const sine = new Float32Array(tableSize).map((_, index) => Math.sin(index / toTable));
    const sin = (angle) => sine[(Math.round(angle * toTable) % tableSize + tableSize) % tableSize];
    const start = performance.now();
    let width = 0;
    let image = null;
    let pixels = null;
    let count = 0;
    let at = null;
    let rgb = null;
    let glow = null;
    let calm = null;
    let phaseA = null;
    let phaseB = null;
    let diagonal = null;
    let frame = 0;
    let lastDrawn = 0;
    let onScreen = true;

    const layout = () => {
        width = Math.ceil(dotsCanvas.clientWidth / scale);
        const height = Math.ceil(dotsCanvas.clientHeight / scale);

        if (!width || !height) {
            return;
        }

        dotsCanvas.width = width;
        dotsCanvas.height = height;
        image = context.createImageData(width, height);
        pixels = new Uint32Array(image.data.buffer);

        const columns = Math.floor((width - 2 - 2) / gap) + 1;
        const rows = Math.floor((height - 2 - 2) / gap) + 1;
        count = columns * rows;
        at = new Uint32Array(count);
        rgb = new Uint32Array(count);
        glow = new Float32Array(count);
        calm = new Float32Array(count);
        phaseA = new Float32Array(count);
        phaseB = new Float32Array(count);
        diagonal = new Float32Array(count);

        let n = 0;

        for (let row = 0; row < rows; row++) {
            const y = 2 + row * gap;

            for (let column = 0; column < columns; column++, n++) {
                const x = 2 + column * gap;
                const u = x / width;
                const v = y / height;
                const mix = Math.min(1, Math.max(0, u * 0.9 + (1 - v) * 0.35 - 0.2));
                const red = Math.round(blue[0] + (green[0] - blue[0]) * mix);
                const greenPart = Math.round(blue[1] + (green[1] - blue[1]) * mix);
                const bluePart = Math.round(blue[2] + (green[2] - blue[2]) * mix);
                const glowLeft = Math.exp(-(((u - 0.12) / 0.38) ** 2 + ((v - 0.95) / 0.55) ** 2));
                const glowRight = Math.exp(-(((u - 0.9) / 0.35) ** 2 + ((v - 0.15) / 0.5) ** 2));

                at[n] = Math.floor(y) * width + Math.floor(x);
                rgb[n] = (bluePart << 16) | (greenPart << 8) | red;
                glow[n] = Math.min(1, glowLeft + glowRight * 0.9);
                calm[n] = 1 - 0.65 * Math.exp(-(((u - 0.5) / 0.3) ** 2 + ((v - 0.42) / 0.3) ** 2));
                phaseA[n] = u * 11 + v * 6;
                phaseB[n] = u * 5 - v * 8;
                diagonal[n] = u * 0.85 + (1 - v) * 0.45;
            }
        }
    };

    const draw = (now) => {
        if (!pixels) {
            return;
        }

        const time = (now - start) / 1000;
        const sweep = ((time % 6) / 6) * 2.4 - 0.7;
        const shiftA = time * 1.6;
        const shiftB = time * 1.15;

        for (let n = 0; n < count; n++) {
            const wave = 0.5 + 0.5 * sin(phaseA[n] - shiftA) * sin(phaseB[n] + shiftB);
            const distance = (diagonal[n] - sweep) / 0.13;
            const band = distance > 3 || distance < -3 ? 0 : Math.exp(-distance * distance);
            const light = (glow[n] * (0.12 + 0.88 * wave * wave) + band * (0.55 + 0.45 * glow[n])) * calm[n];
            const alpha = Math.min(255, (0.08 + 0.92 * (light > 1 ? 1 : light)) * 255) | 0;
            const colour = ((alpha << 24) | rgb[n]) >>> 0;
            const big = alpha > 170 ? colour : 0;
            const i = at[n];

            pixels[i] = colour;
            pixels[i + 1] = big;
            pixels[i + width] = big;
            pixels[i + width + 1] = big;
        }

        context.putImageData(image, 0, 0);
    };

    const loop = (now) => {
        frame = 0;

        if (!onScreen || document.hidden) {
            return;
        }

        if (now - lastDrawn >= frameTime) {
            lastDrawn = now;
            draw(now);
        }

        frame = requestAnimationFrame(loop);
    };

    const play = () => {
        if (!frame && !reducedMotion && onScreen && !document.hidden) {
            frame = requestAnimationFrame(loop);
        }
    };

    layout();
    draw(performance.now());
    window.addEventListener('resize', () => {
        layout();
        draw(performance.now());
    });
    new IntersectionObserver(([entry]) => {
        onScreen = entry.isIntersecting;
        play();
    }).observe(dotsCanvas);
    document.addEventListener('visibilitychange', play);
}
