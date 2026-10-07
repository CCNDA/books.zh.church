# 00_快速匯入表 → MVP books 欄位映射

建立:2026-07-11。匯入工具(D5 起)依此實作;階段三遷移正規化時 extra JSON 可完整還原。

## 設計原理:多重呈現(來自整合模板,不可簡化掉)

正規化 14 表的存在理由是同一資料會有**多重呈現**,平面欄位無法正確表達:

- 一作品多版本(Work/Edition):同書不同 ISBN、修訂版、頁數裝幀各異 → 02_Editions
- 一版本多識別碼:ISBN13 + ISBN10 + 條碼並存 → 03_Identifiers
- 一版本多格式多價格:平裝/精裝/電子/有聲,各有定價幣別 → 11_Formats_Prices
- 一書多人多角色:作者/編者/譯者/插畫/序,同角色多人有排序,封面署名需保留原文 → 05/06
- 一書多系列:出版社系列 + 主題系列並存 → 07/08
- 多套分類系統混用:CategoryV11/中圖/BISAC 可並列 → 09/10(scheme 欄位)
- 連結、媒體可掛作品層或版本層(封面通常綁版本)→ 12/13

依「書籍資料.docx」的複數可能清單,一對多關聯的完整範圍還包括:語言、出版日期(不同版本)、讀者群、書介(長/短/多語)、目錄/前言/附錄/索引、出版地/版權/授權代理、定價(地區×版本)、內頁圖與媒體素材、宣傳資源(書摘卡片/影片/社群貼文)、翻譯版本、衍生作品(影視/漫畫/舞台劇)、學術引用、相關課程/讀書會。這些在階段三之後依 12_Links/13_Media/14_Reviews 或增表承接;MVP 期間若匯入資料含這些內容,一律存 extra JSON。

**資料流(模板既定)**:00_快速匯入表(分隔符號平面格式)→ 程式拆分 → 正規化 14 表。

**架構定案(7/11 晚,熊哥指示)**:正規化關聯結構提前於 migration 004 建立(persons/book_persons、publishers、editions、identifiers、series、subjects、formats_prices、links、media、reviews),**匯入器直接寫入關聯表**,不再走平面 staging:

- 多作者/譯者/插畫 → persons 去重(按 name)+ book_persons(role、role_order、credit_text 保留署名原文)
- 出版社 → publishers 去重(name_zh);版本資訊(出版日期/頁數/裝幀/來源)→ editions(帶 source、source_url 標注出處)
- ISBN13/10/條碼 → identifiers(一版多碼)
- 來源站分類 → subjects(scheme='campus'/'logos')+ book_subjects;CategoryV11 對照後另建 scheme='categoryv11' 主題(對不上者留待內容專案人工編碼)
- 價格/庫存 → formats_prices;購書連結 → links(platform+url);封面 → media(source_url 記原圖出處,R2 轉存後更新 url_or_path)
- books 表平面欄位(author/publisher/isbn13...)保留作為過渡後備:v_book_list 檢視表 COALESCE(關聯優先→平面欄位),12 筆種子資料照常顯示;API 改讀 v_book_list

同一本書在兩站都出現時:以 ISBN13 合併為同一 book(Work),各建一個 edition;無 ISBN 者以「書名+主要作者」模糊比對,存疑不合併(寧可重複待人工併)。

| 快速匯入表欄位 | MVP books 欄位 | 多值處理 |
|---|---|---|
| title_zh | title | — |
| subtitle | subtitle | — |
| authors | author | 保留 ; 分隔原樣 |
| translators | translator | 同上 |
| editors | editors (003) | 同上 |
| contributors | extra.contributors | JSON 陣列 [{role,name}] |
| publisher | publisher | 保留 ; 分隔原樣 |
| publish_date | publish_date | 取 YYYY-MM |
| edition_statement | edition_statement (003) | — |
| isbn13 | isbn13 | 首筆入欄(搜尋用);完整原值存 extra.isbn13_raw |
| ean_upc | extra.ean_upc | JSON 陣列 |
| series | series (003) | 原樣「名稱#冊次;...」 |
| page_count | page_count (003) | — |
| binding | binding (003) | — |
| language | language (003) | ; 分隔原樣 |
| subjects | category_id + extra.subjects | 對得上 CategoryV11 者填 category_id;全部原值存 extra.subjects |
| keywords | keywords (003) | ; 分隔原樣 |
| summary_short | summary_short (003) | — |
| summary_long | summary | — |
| buy_links | buy_links | 轉 JSON [{platform,url}] |
| cover_url | cover_url | 首筆入欄(轉存 R2);其餘 extra.cover_urls |

## 原則

1. **不丟資料**:任何映射不了的值一律進 extra(JSON),階段三拆表時據此還原
2. 多值欄位 MVP 先保留分隔字串或 JSON,不強拆
3. subjects 對照 CategoryV11(docs/CategoryV11.xls):比對「類別/次類別」名稱,對不上留 extra 待人工編碼(內容專案負責)
4. 封面:匯入時下載 → 上傳 R2(bucket oursweb,prefix books/)→ cover_url 存 https://imgr2.oursweb.net/books/{book_id}.jpg
