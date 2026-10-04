/** Dev-only. Screenshots frame pages (from framePage) to PNGs with headless Chrome. */
import { rm, writeFile } from "node:fs/promises";
import puppeteer from "puppeteer-core";

const MARGIN = 16;

export async function renderPng(pages, outPaths, { cols, rows }) {
    const browser = await puppeteer.launch({
        executablePath: "/usr/bin/google-chrome",
        args: ["--no-sandbox", "--allow-file-access-from-files"],
    });
    try {
        const tab = await browser.newPage();
        await tab.setViewport({
            width: Math.ceil(cols * 8.5) + 2 * MARGIN,
            height: Math.ceil(rows * 17) + 2 * MARGIN,
            deviceScaleFactor: 2,
        });
        for (let i = 0; i < pages.length; i += 1) {
            const file = `${outPaths[i]}.html`;
            await writeFile(file, pages[i]);
            try {
                await tab.goto(`file://${file}`);
                await tab.waitForFunction("window.ready === true");
                const box = await (await tab.$(".xterm-screen")).boundingBox();
                await tab.screenshot({
                    path: outPaths[i],
                    clip: {
                        x: box.x - MARGIN,
                        y: box.y - MARGIN,
                        width: box.width + 2 * MARGIN,
                        height: box.height + 2 * MARGIN,
                    },
                });
            } finally {
                await rm(file, { force: true });
            }
        }
    } finally {
        await browser.close();
    }
}
