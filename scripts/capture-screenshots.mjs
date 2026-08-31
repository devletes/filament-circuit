#!/usr/bin/env node

/**
 * Regenerates docs/images/*.png from the workbench.
 *
 *   composer serve                 # http://127.0.0.1:8765
 *   node scripts/capture-screenshots.mjs
 *
 * Every shot is taken twice — once in each colour mode — so the README can put
 * light and dark side by side. Demo sections are clipped with a little padding
 * around them; overlays are clipped flush, so no neighbouring section is ever
 * in frame.
 */

import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const projectRoot = resolve(__dirname, '..');
const outDir = resolve(projectRoot, 'docs/images');

const baseUrl = process.env.WORKBENCH_URL ?? 'http://127.0.0.1:8765';
const email = process.env.WORKBENCH_EMAIL ?? 'aria@example.com';
const password = process.env.WORKBENCH_PASSWORD ?? 'password';

const PADDING = 16;
const VIEWPORT = { width: 1280, height: 1200 };
const MODES = ['light', 'dark'];

/** Sections that need nothing but a clip. */
const plainSections = [
    'canvas',
    'bodies',
    'edges',
    'validation',
    'horizontal',
    'minimal',
    'theming',
    'entry',
];

/** `data-demo` id => steps that set the section up before each shot. */
const interactions = {
    palette: {
        demo: 'canvas',
        before: async (page, section) => {
            await section.locator('.fi-circuit-toolbar button', { hasText: 'Add node' }).first().click();
            await page.waitForTimeout(250);
        },
        after: closeOverlays,
    },
    'node-actions': {
        demo: 'actions',
        before: async (page, section) => {
            await section.locator('[data-node-id="n2"]').first().hover();
            await page.waitForTimeout(250);
        },
        after: closeOverlays,
    },
    'node-actions-group': {
        demo: 'actions',
        before: async (page, section) => {
            await section.locator('[data-node-id="n2"]').first().hover();
            await page.waitForTimeout(150);
            await section.locator('[data-node-id="n2"] .fi-dropdown-trigger button').first().click({ force: true });
            await page.waitForTimeout(300);
        },
        after: closeOverlays,
    },
    minimap: {
        demo: 'minimap',
        before: async (page, section) => {
            await zoomIn(page, section, 1.35);
        },
    },
    connecting: {
        demo: 'canvas',
        before: async (page, section) => {
            const handle = await section.locator('[data-node-id="n3"] .fi-circuit-handle-source').first().boundingBox();
            const target = await section.locator('[data-node-id="n4"]').first().boundingBox();

            await page.mouse.move(handle.x + handle.width / 2, handle.y + handle.height / 2);
            await page.mouse.down();
            await page.mouse.move(target.x + target.width / 2, target.y + 12, { steps: 14 });
            await page.waitForTimeout(300);
        },
        // Release back over the source node: a self-link is refused, so the
        // graph is exactly as it was for the next shot.
        after: async (page, section) => {
            const source = await section.locator('[data-node-id="n3"]').first().boundingBox();
            await page.mouse.move(source.x + source.width / 2, source.y + source.height / 2, { steps: 8 });
            await page.mouse.up();
            await page.waitForTimeout(500);
        },
    },
};

/**
 * Shots clipped to an overlay rather than to the demo section. A modal is a
 * self-contained card sitting over a dimmed page, so these are clipped flush —
 * padding here would frame whatever happens to be behind it, which is exactly
 * what the section crops are careful to keep out.
 *
 * `viewport` shortens the window first: a slide-over is as tall as the window,
 * and a 1200px-tall one is unreadable beside a light/dark twin in the README.
 */
const overlays = {
    'node-config': {
        demo: 'canvas',
        selector: '.fi-modal-window',
        open: async (page, section) => {
            await section.locator('[data-node-id="n2"]').first().dblclick({ force: true });
        },
    },
    // The one shot that has to keep the page in frame: a slide-over is defined
    // by sitting against the edge of the window, and cropped flush it is
    // indistinguishable from a modal. So the other demos are hidden first and
    // the whole (short) window is taken — one section behind it, dimmed.
    'node-config-slideover': {
        demo: 'slideover',
        viewport: { width: 1280, height: 760 },
        isolate: true,
        open: async (page, section) => {
            await section.locator('[data-node-id="n2"]').first().dblclick({ force: true });
        },
    },
    'edge-config': {
        demo: 'edges',
        selector: '.fi-modal-window',
        open: async (page, section) => {
            // The one edge carrying an outcome AND a condition, so the
            // slide-over shows both halves of what edge config offers.
            await section.locator('[aria-label*="Approved"]').first().click({ force: true });
        },
    },
};

/** Whole-page integration shots. */
const pages = [
    { path: '/admin/workflows/2/edit', file: 'resource-form', selector: '.fi-page-content' },
    { path: '/admin/workflows/1', file: 'resource-view', selector: '.fi-page-content' },
];

async function closeOverlays(page) {
    await page.keyboard.press('Escape');
    await page.mouse.move(4, 4);
    await page.locator('.fi-modal-window').first().waitFor({ state: 'detached', timeout: 10000 }).catch(() => {});
    await page.waitForTimeout(400);
}

/** Leave one demo section on the page, so a viewport shot frames only it. */
async function isolateSection(page, demo) {
    await page.evaluate((only) => {
        const style = document.createElement('style');
        style.id = 'capture-isolate';
        style.textContent = '[data-capture-hidden] { display: none !important; }';
        document.head.append(style);

        document.querySelectorAll('[data-demo]').forEach((el) => {
            if (el.dataset.demo !== only) {
                el.setAttribute('data-capture-hidden', '');
            }
        });

        window.scrollTo(0, 0);
    }, demo);

    await page.waitForTimeout(300);
}

async function releaseSections(page) {
    await page.evaluate(() => {
        document.getElementById('capture-isolate')?.remove();
        document.querySelectorAll('[data-capture-hidden]').forEach((el) => el.removeAttribute('data-capture-hidden'));
    });

    await page.waitForTimeout(300);
}

/** Fit first so repeated runs (one per colour mode) land on the same framing. */
async function zoomIn(page, section, factor) {
    await section.evaluate((el, f) => {
        const data = window.Alpine?.$data(el.querySelector('.fi-circuit'));
        data?.fitView?.();
        data?.zoomBy?.(f);
    }, factor);
    await page.waitForTimeout(300);
}

async function setColorMode(page, mode) {
    await page.evaluate((m) => {
        const root = document.documentElement;
        root.classList.toggle('dark', m === 'dark');
        localStorage.setItem('theme', m);
    }, mode);
    await page.waitForTimeout(200);
}

/** Clip `selector` out of the full page with even padding on every side. */
async function shoot(page, { file, selector, extraHeight = 0, padding = PADDING }) {
    const locator = page.locator(selector).first();
    await locator.scrollIntoViewIfNeeded();
    await page.waitForTimeout(150);

    const box = await locator.boundingBox();
    const scrollY = await page.evaluate(() => window.scrollY);
    const outPath = resolve(outDir, file);

    await page.screenshot({
        path: outPath,
        fullPage: true,
        clip: {
            x: Math.max(0, box.x - padding),
            y: Math.max(0, box.y + scrollY - padding),
            width: box.width + padding * 2,
            height: box.height + padding * 2 + extraHeight,
        },
    });

    console.log(`saved ${file}`);
}

/** The whole window, for overlays that only make sense against the page. */
async function shootViewport(page, file) {
    await page.screenshot({ path: resolve(outDir, file) });

    console.log(`saved ${file}`);
}

/**
 * The canvases lay themselves out on first paint, but the node bodies arrive
 * on the round-trip after that — so a type with an `infolist()` grows once the
 * first layout is already on screen. Tidy again with the real card sizes.
 */
async function settle(page) {
    await page.waitForSelector('.fi-circuit-node', { timeout: 20000 });
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(3000);

    await page.evaluate(() => {
        document.querySelectorAll('.fi-circuit').forEach((el) => {
            const data = window.Alpine?.$data(el);
            data?.measure?.();
            data?.autoLayout?.();
        });
    });

    await page.waitForTimeout(2500);
}

async function main() {
    await mkdir(outDir, { recursive: true });

    const browser = await chromium.launch();
    const context = await browser.newContext({
        viewport: VIEWPORT,
        deviceScaleFactor: 2,
    });
    const page = await context.newPage();

    await page.goto(`${baseUrl}/admin/login`);
    await page.fill('input[type=email]', email);
    await page.fill('input[type=password]', password);
    await page.click('button[type=submit]');
    await page.waitForURL((url) => !url.toString().includes('/login'), { timeout: 20000 });
    await page.waitForLoadState('networkidle');

    await page.goto(`${baseUrl}/admin/showcase`);
    await settle(page);

    for (const mode of MODES) {
        await setColorMode(page, mode);

        for (const demo of plainSections) {
            await shoot(page, { file: `${demo}-${mode}.png`, selector: `[data-demo="${demo}"]` });
        }

        for (const [file, step] of Object.entries(interactions)) {
            const section = page.locator(`[data-demo="${step.demo}"]`);
            await section.scrollIntoViewIfNeeded();
            await step.before(page, section);
            await shoot(page, {
                file: `${file}-${mode}.png`,
                selector: `[data-demo="${step.demo}"]`,
                extraHeight: step.extraHeight ?? 0,
            });
            await step.after?.(page, section);
        }

        for (const [file, step] of Object.entries(overlays)) {
            if (step.viewport) {
                await page.setViewportSize(step.viewport);
                await page.waitForTimeout(400);
            }

            if (step.isolate) {
                await isolateSection(page, step.demo);
            }

            const section = page.locator(`[data-demo="${step.demo}"]`);
            await section.scrollIntoViewIfNeeded();
            await step.open(page, section);
            await page.waitForSelector('.fi-modal-window', { timeout: 15000 });
            await page.waitForTimeout(700);

            step.isolate
                ? await shootViewport(page, `${file}-${mode}.png`)
                : await shoot(page, { file: `${file}-${mode}.png`, selector: step.selector, padding: 0 });

            await closeOverlays(page);

            if (step.isolate) {
                await releaseSections(page);
            }

            if (step.viewport) {
                await page.setViewportSize(VIEWPORT);
                await page.waitForTimeout(400);
            }
        }
    }

    for (const target of pages) {
        await page.goto(`${baseUrl}${target.path}`);
        await settle(page);

        for (const mode of MODES) {
            await setColorMode(page, mode);
            await shoot(page, { file: `${target.file}-${mode}.png`, selector: target.selector });
        }
    }

    await browser.close();
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
