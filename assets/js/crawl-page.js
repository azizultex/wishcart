const puppeteer = require('puppeteer');

(async () => {
    const url = process.argv[2]; // Get URL from command line argument
    const includeSelectors = process.argv[3] ? process.argv[3].split(',') : [];
    const excludeSelectors = process.argv[4] ? process.argv[4].split(',') : [];

    if (!url) {
        console.error("No URL provided.");
        process.exit(1);
    }

    const browser = await puppeteer.launch({ headless: 'new' });
    const page = await browser.newPage();

    // Set a realistic user agent
    await page.setUserAgent("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36");

    await page.goto(url, { waitUntil: "networkidle2", timeout: 60000 });

    // Remove excluded elements
    if (excludeSelectors.length > 0) {
        await page.evaluate((selectors) => {
            selectors.forEach(selector => {
                document.querySelectorAll(selector).forEach(el => el.remove());
            });
        }, excludeSelectors);
    }

    let content = "";
    if (includeSelectors.length > 0) {
        // Extract only included elements
        content = await page.evaluate((selectors) => {
            return selectors.map(selector => {
                return [...document.querySelectorAll(selector)].map(el => el.innerText).join("\n");
            }).join("\n");
        }, includeSelectors);
    } else {
        // Get full body content if no includeSelectors provided
        content = await page.evaluate(() => document.body.innerText);
    }

    console.log(content.trim());
    await browser.close();
})();
