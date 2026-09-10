const { chromium } = require('/opt/minipanel-snapshots/node_modules/playwright');
const [domain, scheme] = process.argv.slice(2);
if (!/^[a-z0-9.-]+$/.test(domain || '') || !['http', 'https'].includes(scheme)) process.exit(64);

(async () => {
    const browser = await chromium.launch({
        chromiumSandbox: false,
        args: [`--host-resolver-rules=MAP ${domain} 127.0.0.1, EXCLUDE localhost`, '--no-proxy-server'],
    });
    try {
        const context = await browser.newContext({viewport: {width: 1280, height: 800}, serviceWorkers: 'block', acceptDownloads: false});
        await context.route('**/*', route => {
            const url = new URL(route.request().url());
            const isReadRequest = ['GET', 'HEAD'].includes(route.request().method());
            const isSiteResource = url.hostname === domain && ['http:', 'https:'].includes(url.protocol) && !url.port;
            const isUnsplashImage = ['images.unsplash.com', 'plus.unsplash.com'].includes(url.hostname) && url.protocol === 'https:' && !url.port;
            const allowed = isReadRequest && (isSiteResource || isUnsplashImage);
            return allowed ? route.continue() : route.abort();
        });
        await context.routeWebSocket(/.*/, socket => socket.close());
        const page = await context.newPage();
        const response = await page.goto(`${scheme}://${domain}/`, {waitUntil: 'domcontentloaded', timeout: 25000});
        if (!response || response.status() >= 400) throw new Error('Site unavailable');
        await page.waitForLoadState('load', {timeout: 10000});
        await page.waitForLoadState('networkidle', {timeout: 5000}).catch(() => undefined);
        await page.evaluate(async () => { await document.fonts?.ready; });
        await page.waitForTimeout(500);
        const screenshot = await page.screenshot({type: 'jpeg', quality: 65, animations: 'disabled', timeout: 10000});
        process.stdout.write(screenshot.toString('base64'));
    } finally {
        await browser.close();
    }
})().catch(() => { process.stderr.write('Snapshot failed.\n'); process.exit(1); });
