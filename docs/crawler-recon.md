# 兩站爬取偵察報告(2026-07-11 晚)

目標:7/12 開抓、7/16 前完成,匯入 books staging 供 7/17 大會展示。

## 校園書房 shop.campus.org.tw

- 平台:自建 ASP.NET WebForms;無 WAF/Cloudflare;robots.txt 允許分類頁與商品頁(禁 SearchResults.aspx、購物車等);無 sitemap
- 分類頁:`/ProductsList.aspx?CategoryID={2/4/6碼}`(三層樹,首頁選單可取完整分類樹)
- 商品頁:`/ProductDetails.aspx?ProductID={9碼}`;電子書 `/EBookDetails.aspx`
- 欄位抽取:`meta keywords`=「商品ID,書名,英文書名,出版社,作者,ISBN,1」一行五欄;og:title/description/image(封面 `Images/thumbs/{ProductID}_01_500_500.jpg`);「詳細資料」區塊 regex:原書號/ISBN/出版日期/頁數/尺寸/語言/裝訂/分類/適用對象
- 規模:單大類「生命造就」3,603 件、151 頁(24/頁);全站書籍估 2.5-4 萬種
- **難點:分頁是 WebForms postback(__VIEWSTATE)**;先試探是否有 GET 分頁參數,否則模擬 POST
- 節流:1 req/3-5 秒、單執行緒、UA 表明身分聯絡方式

## 基道 BookFinder www.logos.com.hk

- 平台:Classic ASP(ACMS);無 WAF;robots.txt 空(無限制);無 sitemap;主站 302 → `/bf/acms/?site=logosbf`
- 列表/檢索:`/bf/acms/content.asp?site=logosbf&op=search&type=product&field={year|author|publisher}&text=...&match=like&sort=...`
- 商品頁:`op=show&type=product&code={書碼}`(書碼=SKU 或 ISBN13);短網址 `/link/?code=`
- 欄位抽取:meta-author/description/keywords + og:image(封面 `acms/upload/logos/images/book/{code}.jpg`);列表頁即含書名/作者/出版社/現價原價;商品頁補英文書名/目錄/庫存
- 規模:逐年檢索「找到 N 項」可精確加總;估數萬種。`field=year&text=2021` 回 3,795 筆(需驗證是否嚴格限年)
- **難點:分頁參數未確認**(列表用 JS SortBy();探 `&page=`/`&start=`)
- 節流:1 req/2-3 秒,公益小站保守抓

## 策略

1. 入口:兩站皆分類/檢索頁遍歷(無 sitemap)
2. 校園以「最小分類」列舉(直接得分類歸屬 → 映射 CategoryV11);基道逐年或逐出版社列舉
3. 列表頁先收基本欄位 → 商品頁補齊 → 寫入 CSV(00_快速匯入表格式)+ 續跑 checkpoint
4. 時間預算:4 萬頁 × 3 秒 ≈ 33 小時 → 7/12 一早開跑、兩站並行(各自單執行緒),7/14 前抓完,留兩天清理匯入
5. 合規:禮貌抓取、UA 註明 CCNDA 與聯絡方式、快取已抓頁、簡介/封面標注來源
