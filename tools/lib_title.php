<?php
declare(strict_types=1);
/**
 * 書名促銷詞剝除規則 —— import.php 與 tools/check_title_promo.php **共用同一份**。
 *
 * ════════ 為什麼要獨立成一支 lib ════════
 * 這條規則有兩個使用者:
 *   (1) tools/check_title_promo.php —— 產 dry-run 對照表給人勾(它是規則的驗證者)
 *   (2) tools/import.php            —— 新抓進來的書套用同一條規則
 * 兩邊若各寫一份,**驗過的規則與實際跑的規則會悄悄分家** ——
 * 對照表說「這樣剝」、匯入卻剝成另一樣,而且不會有人發現。
 * 所以規則只有這裡一份,兩邊都 require。(先例:lib_isbn.php)
 *
 * ════════ 白名單(熊哥 2026-09-29 裁示) ════════
 * `NN折`、`特價`、`免運(費)`、`瑕疵`、`優惠`、`限時`。
 *
 * ★★ **「限量」不在白名單裡,不要加。** 它同時是三種東西:
 *   (a) 庫存狀態(elim 式「(限量)」前綴 32 本,意思是快賣完了);
 *   (b) **版本標示,是書名真的有的字** ——《一九八四（限量精裝版）》
 *       《賈伯斯傳（限量硬殼精裝）》《生命中不能承受之輕（出版30週年限量紀念）》。
 *       剝掉會讓限量精裝版與平裝本同名,**反而製造錯誤合併**;
 *   (c) 非書商品的禮盒/組合包。
 *   另有《無可限量：神手中的教會》這種本來就叫這個名字的。
 * ★ 「預購」也不在白名單(熊哥 9/29 裁示維持現狀):它是時效狀態,
 *   且括號裡常夾出貨日(「8/10出貨」),要另外寫規則。
 *
 * ════════ 兩個踩過的坑,改這支之前先看 ════════
 * 1. **《聖潔沒有瑕疵》(44157、80677)差點被剝成《聖潔沒有》。**
 *    「瑕疵」在白名單裡,而「字尾直接剝」那條規則會吃到它。
 *    → 字尾另立更窄的 TAIL_PROMO,**瑕疵不在裡面**。不要順手補齊。
 * 2. **字尾促銷詞前面常黏著半截修飾語**:
 *      「…原價2000元,網路特價1500元」→ 只剝促銷詞會變成「…原價2000元,網路」
 *      「…雙日曆（…）最後特價490元」 → 變成「…最後」
 *      「…全套50本合購優惠」          → 變成「…合購」
 *    → 剝完殘尾若落在 DANGLING,判 hold,**不自作聰明多吃幾個字**。
 *
 * ════════ 全形標點怎麼寫(陷阱 11) ════════
 * 括號一律用 \x{FF08} 這種 unicode escape,**不寫字面全形字元**:
 * 字面全形括號會在生成/複製/存檔時退化成半形重複(「[（(]」→「[((]」),
 * 退化後 regex 照樣編譯得過、只是靜默少比對一半,看不出來。
 *
 * ════════ 原值怎麼保留 ════════
 * **不必另外寫 title_raw。** import.php 的 `$extraRec = $raw`(整筆爬蟲原始紀錄)
 * 會存進 `books.extra[來源]`,裡面本來就有站方原始 title;
 * 合併時 extra 是「保留其他鍵、只換本來源那一鍵」,所以原值一直在。
 * ★ 多寫一份 title_raw 只是多一個要同步的地方 —— 本專案已經吃過「兩份資料要同步」的虧。
 *
 * 相關:Asana 1218961224853655
 */

// ── 括號(unicode escape,理由見檔頭) ────────────────────────────────────
const TP_B_OPEN  = '\x{FF08}\x{3010}\x{FF3B}\(\[';   // （ 【 ［ ( [
const TP_B_CLOSE = '\x{FF09}\x{3011}\x{FF3D}\)\]';   // ） 】 ］ ) ]

/** 白名單。**「限量」「預購」不在裡面。** */
const TP_PROMO = '(?:[0-9０-９]+\s*折|特價|免運費?|瑕疵|優惠|限時)';

/** 一律不剝、且要降人工判的詞。 */
const TP_KEEP = '(?:限量)';

/**
 * 剝完促銷詞後,段落裡還允許殘留的東西。超出這些 → 這段還有內容 → 人工判。
 * ★ 只放「純粹是促銷詞修飾語或分隔符」的字串。
 *   不要放「套」「組」「首刷」「同工版」這類**帶意義**的詞 ——
 *   「(首刷優惠套組)」是組合包,剝掉會讓人以為買的是單本。
 */
const TP_NOISE = '(?:[0-9０-９\s,，、;；:：\.。\-－—~～\/＋\+]|元|起|新書|預購|商品|品|活動|網路)';

/**
 * 只有這幾個詞可以在「沒有括號、直接掛在書名尾巴」時剝掉。
 * ★★ 「瑕疵」**絕對不可放進來**,理由見檔頭第 1 點。
 */
const TP_TAIL_PROMO = '(?:[0-9０-９]+\s*折|特價|優惠|免運費?|限時)';

/** 剝掉字尾促銷詞後,結果以這些結尾表示前面還黏著半截修飾語 → 人工判。 */
const TP_DANGLING = '(?:[，,、;；:：\-－—\/＋\+]|元|價|購|後|路|原)';

/**
 * 剝除書名裡的促銷詞。
 *
 * @return array{clean:string, stripped:string[], hold:string[]}
 *   clean    剝除後的書名(沒得剝或判 hold 時等於原值)
 *   stripped 剝掉了哪些片段(空陣列 = 沒動)
 *   hold     不敢自動剝的理由(非空 = 要人工看,呼叫端**不可**採用 clean)
 *
 * ★ 呼叫端的約定:`hold` 非空就當作「這本不處理」,照原書名走。
 *   規則只負責分辨「確定可以剝」與「不確定」,不確定一律交給人。
 */
function strip_title_promo(?string $title): array
{
    $title = (string) $title;
    $out   = ['clean' => $title, 'stripped' => [], 'hold' => []];
    if ($title === '' || !preg_match('/' . TP_PROMO . '/u', $title)) {
        return $out;                    // 沒有白名單詞 → 不動(含只有「限量」「預購」的)
    }

    $clean    = $title;
    $stripped = [];
    $hold     = [];

    // ── 1. 括號段落 ──────────────────────────────────────────────────
    $reSeg = '/([' . TP_B_OPEN . '])([^' . TP_B_CLOSE . ']*)([' . TP_B_CLOSE . '])/u';
    if (preg_match_all($reSeg, $title, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $m) {
            $whole = $m[0];
            $inner = $m[2];
            if (!preg_match('/' . TP_PROMO . '/u', $inner)) {
                continue;
            }
            if (preg_match('/' . TP_KEEP . '/u', $inner)) {
                $hold[] = $whole . ' → 含「限量」,不自動剝';
                continue;
            }
            $rest = preg_replace('/' . TP_PROMO . '/u', '', $inner);
            $rest = trim((string) preg_replace('/' . TP_NOISE . '/u', '', (string) $rest));
            if ($rest === '') {
                $clean      = str_replace($whole, '', $clean);
                $stripped[] = $whole;
            } else {
                $hold[] = $whole . ' → 段落還有其他內容「' . $rest . '」';
            }
        }
    }

    // ── 2. 沒有括號、直接掛在字尾的促銷詞 ──────────────────────────────
    $reTail = '/\s*' . TP_TAIL_PROMO . '(?:\s*[0-9０-９]+\s*元)?\s*$/u';
    if (!preg_match('/' . TP_KEEP . '/u', $clean) && preg_match($reTail, $clean, $mt)) {
        $cand = trim((string) preg_replace($reTail, '', $clean));
        if (mb_strlen($cand, 'UTF-8') < 4) {
            $hold[] = trim($mt[0]) . ' → 剝完只剩 ' . mb_strlen($cand, 'UTF-8') . ' 字,不敢剝';
        } elseif (preg_match('/' . TP_DANGLING . '$/u', $cand)) {
            $hold[] = trim($mt[0]) . ' → 剝完殘尾「' . mb_substr($cand, -4, 4, 'UTF-8')
                    . '」不像書名結尾,要人工看';
        } else {
            $clean      = $cand;
            $stripped[] = trim($mt[0]);
        }
    }

    $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
    $clean = trim((string) preg_replace('/[' . TP_B_OPEN . ']\s*$/u', '', $clean));

    // ── 3. 收尾防線 ──────────────────────────────────────────────────
    // 命中白名單詞、但兩條規則都沒吃到(詞在句中且無括號)。
    // ★ 這種**不可以當成沒事** —— 規則沒涵蓋到,正是要人看的那一類。
    if (!$stripped && !$hold) {
        $hold[] = '命中促銷詞但規則沒吃到(詞在句中且無括號),要人工看';
    }
    if ($clean === '' || mb_strlen($clean, 'UTF-8') < 2) {
        $hold[] = '剝完剩不到 2 字,一定是規則出錯';
    }

    return [
        'clean'    => $hold ? $title : $clean,   // ★ 有 hold 就退回原值,絕不半套
        'stripped' => $hold ? [] : $stripped,
        'hold'     => $hold,
    ];
}
