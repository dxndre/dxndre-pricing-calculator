const puppeteer = require('puppeteer');

(async () => {
  try {
    const payload = JSON.parse(process.argv[2]);

    const browser = await puppeteer.launch({
      headless: 'new',
      args: ['--no-sandbox', '--disable-setuid-sandbox']
    });

    const page = await browser.newPage();

    await page.goto(payload.url, {
      waitUntil: 'networkidle0'
    });

    await page.pdf({
      path: payload.output,
      format: 'A4',
      printBackground: true,
      margin: {
        top: '40px',
        bottom: '40px',
        left: '40px',
        right: '40px'
      }
    });

    await browser.close();
    process.exit(0);

  } catch (err) {
    console.error(err);
    process.exit(1);
  }
})();