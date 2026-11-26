// Records the admin: signs in, plays the scenes and writes raw.mp4 and marks.json.
// Usage: node record.js <scenes.js> <output directory>, with RECORD_URL set to the served slot. With RECORD_REHEARSE=1
// the scenes are asked to stop short of anything that costs money, such as sending a request to a model.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const [scenesFile, out] = process.argv.slice(2);
const scenes = require(path.resolve(scenesFile));
const url = process.env.RECORD_URL;
const rehearse = process.env.RECORD_REHEARSE === '1';

// Headless Chromium draws no pointer, so the page gets one that follows the mouse.
function showPointer() {
    document.addEventListener('DOMContentLoaded', () => {
        const style = document.createElement('style');
        style.textContent = '.sf-toolbar, .sf-minitoolbar { display: none !important; }'
            + '#rec-pointer { position: fixed; z-index: 2147483647; width: 22px; height: 22px; margin: -11px 0 0 -11px;'
            + ' border-radius: 50%; background: rgba(30, 90, 67, .35); border: 2px solid #1e5a43; pointer-events: none;'
            + ' transition: transform .12s; left: -50px; top: -50px; }'
            + '#rec-pointer.down { transform: scale(.7); background: rgba(30, 90, 67, .6); }';
        document.head.appendChild(style);
        const pointer = document.createElement('div');
        pointer.id = 'rec-pointer';
        document.body.appendChild(pointer);
        document.addEventListener('mousemove', (e) => { pointer.style.left = e.clientX + 'px'; pointer.style.top = e.clientY + 'px'; }, true);
        document.addEventListener('mousedown', () => pointer.classList.add('down'), true);
        document.addEventListener('mouseup', () => pointer.classList.remove('down'), true);
    });
}

async function glideAndClick(page, locator) {
    const box = await locator.boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 25 });
    await page.waitForTimeout(250);
    await page.mouse.down();
    await page.waitForTimeout(80);
    await page.mouse.up();
}

async function signIn(page, user, password) {
    await page.goto(`${url}/admin/login`);
    await page.fill('input[name=username]', user);
    await page.fill('input[name=password]', password);
    await page.click('button[type=submit], input[type=submit]');
    await page.waitForFunction(() => window.opendxp && opendxp.helpers && opendxp.helpers.openObject, null, { timeout: 60000 });
    await page.waitForTimeout(4000);
    await page.mouse.move(760, 430);
}

// The Chrome screencast delivers frames in device pixels. A frame comes only when the page changes.
async function startFilming(context, page) {
    const frames = path.join(out, 'frames');
    fs.rmSync(frames, { recursive: true, force: true });
    fs.mkdirSync(frames, { recursive: true });

    const shots = [];
    const marks = {};
    let first = null;
    let stopped = false;
    const cdp = await context.newCDPSession(page);
    cdp.on('Page.screencastFrame', async ({ data, metadata, sessionId }) => {
        // A frame can still arrive after the recording stopped.
        if (stopped) {
            return;
        }

        const file = path.join(frames, `${String(shots.length).padStart(5, '0')}.jpg`);
        fs.writeFileSync(file, Buffer.from(data, 'base64'));
        first ??= metadata.timestamp;
        shots.push({ file, time: metadata.timestamp });
        await cdp.send('Page.screencastFrameAck', { sessionId }).catch(() => {});
    });
    await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 92, maxWidth: 1920, maxHeight: 1080 });
    const started = Date.now() / 1000;

    return {
        // Marks count from the first frame, so a mark is a second of the video.
        mark(label) {
            marks[label] = Date.now() / 1000 - (first ?? started);
        },
        async stop() {
            stopped = true;
            await cdp.send('Page.stopScreencast');
            // The last frame stays until the recording stops, so a still page at the end is in the video.
            const end = Math.max(shots[shots.length - 1].time + 1, Date.now() / 1000);
            const list = shots.map((shot, i) => {
                const next = i + 1 < shots.length ? shots[i + 1].time : end;
                return `file '${shot.file}'\nduration ${(next - shot.time).toFixed(3)}`;
            });
            list.push(`file '${shots[shots.length - 1].file}'`);
            const listFile = path.join(out, 'frames.txt');
            fs.writeFileSync(listFile, list.join('\n'));
            execFileSync('nice', ['-n', '19', 'ffmpeg', '-threads', '2', '-loglevel', 'error', '-y', '-f', 'concat', '-safe', '0',
                '-i', listFile, '-vf', 'scale=1920:1080:flags=lanczos,fps=30', '-c:v', 'libx264', '-threads', '2', '-crf', '18',
                '-pix_fmt', 'yuv420p', path.join(out, 'raw.mp4')]);
            fs.rmSync(frames, { recursive: true, force: true });
            fs.rmSync(listFile);
            fs.writeFileSync(path.join(out, 'marks.json'), JSON.stringify(marks, null, 1));
        },
    };
}

(async () => {
    const password = process.env[scenes.passwordVariable];

    if (!password) {
        throw new Error(`${scenes.passwordVariable} is not set. Declare it in extra.opendxp-test.environment of the checkout and set it in .ddev/testkit.yaml.`);
    }

    fs.mkdirSync(out, { recursive: true });
    fs.rmSync(path.join(out, 'error.png'), { force: true });
    // A page may play audio by itself, and the recording shows the player running.
    const browser = await chromium.launch({ args: ['--autoplay-policy=no-user-gesture-required'] });
    const context = await browser.newContext({ viewport: { width: 1536, height: 864 }, deviceScaleFactor: 1.25, ignoreHTTPSErrors: true });
    await context.addInitScript(showPointer);
    const page = await context.newPage();

    try {
        await signIn(page, scenes.user, password);
        const film = await startFilming(context, page);
        await page.waitForTimeout(800);
        try {
            await scenes.play({ page, mark: film.mark, click: (locator) => glideAndClick(page, locator), rehearse });
        } catch (error) {
            // The page as the scene failed on, to see why.
            await page.screenshot({ path: path.join(out, 'error.png') });
            throw error;
        } finally {
            await film.stop();
        }
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error.message);
    process.exit(1);
});
