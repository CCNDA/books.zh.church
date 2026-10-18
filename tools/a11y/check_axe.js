// 書目網無障礙自動檢查：axe-core（WCAG 2.0／2.1 A＋AA）× 桌機 1280／手機 390
// 用法（開發者電腦，要有 Chrome 或 Edge）：
//   cd tools/a11y && npm install
//   node check_axe.js                              # 測正式站
//   node check_axe.js http://127.0.0.1:8081        # 測開發站
//   CHROME="C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe" node check_axe.js
// 有任何違規 → exit 1。「需人工看」不算失敗，但會列出來（常見是圖片還在載入時色彩對比判斷不了，重跑一次再看）。
// 2026-10-17 起每次發版前跑一次（Asana 1219388780461074）。
const fs = require("fs");
const puppeteer = require("puppeteer-core");
const axeSrc = fs.readFileSync(require.resolve("axe-core/axe.min.js"), "utf8");

const base = (process.argv[2] || "https://books.zh.church").replace(/\/$/, "");
const pages = ["/", "/about.html", "/book.php?id=45997", "/changelog.php"];
const chrome = process.env.CHROME ||
  ["C:/Program Files/Google/Chrome/Application/chrome.exe",
   "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
   "/usr/bin/google-chrome", "/usr/bin/chromium"].find(p => fs.existsSync(p));
if (!chrome) { console.error("找不到 Chrome／Edge，請用 CHROME= 指定路徑"); process.exit(2); }

(async () => {
  const browser = await puppeteer.launch({ executablePath: chrome, headless: "new" });
  let bad = 0;
  for (const [width, label] of [[1280, "桌機"], [390, "手機"]]) {
    for (const path of pages) {
      const page = await browser.newPage();
      await page.setViewport({ width, height: 900, isMobile: width < 500 });
      await page.goto(base + path, { waitUntil: "networkidle0", timeout: 60000 });
      await page.evaluate(axeSrc);
      const r = await page.evaluate(() => axe.run(document, {
        runOnly: { type: "tag", values: ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"] } }));
      bad += r.violations.length;
      console.log(`${label} ${path}：違規 ${r.violations.length}、通過 ${r.passes.length}、需人工看 ${r.incomplete.length}`);
      for (const v of r.violations)
        console.log(`   ✗ ${v.impact} ${v.id}（${v.nodes.length} 處）${v.help}｜例 ${v.nodes[0].target.join(" ")}`);
      for (const v of r.incomplete) console.log(`   ? ${v.id}（${v.nodes.length} 處）${v.help}`);
      await page.close();
    }
  }
  await browser.close();
  console.log(bad ? `\n共 ${bad} 項違規 → 失敗` : "\n全部 0 違規 → 通過");
  process.exit(bad ? 1 : 0);
})().catch(e => { console.error("執行失敗：", e.message); process.exit(2); });
