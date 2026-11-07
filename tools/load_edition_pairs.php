<?php
declare(strict_types=1);

/**
 * 找出「同一本書的簡體版和正體版」（ISBN 不同、站上是兩本），匯進 edition_pairs 等後台確認 —— 2026-11-07
 * Asana 1218475436576276（子票 1220314648553834）；熊哥 11/7 TG 選「甲 對照表＋後台確認」。
 *
 * 規則（11/7 盤點第二版，抽 15 組約 12～13 組對；第一版切掉副標會把不同卷／不同年配在一起）：
 *   1. 簡體的判斷：language 寫簡體，或書名有「簡體／简体」，或書名有 2 個以上簡化字（6 字以下的短書名 1 個就算）
 *   2. 書名只拿掉簡繁標記（（簡體）、(簡體版)、簡體字…），副標、卷冊、年份都留著
 *   3. 兩本書名字數一樣、逐字相同六成以上；書名裡的數字、第N卷／冊／年／期、上下冊要完全一樣
 *   4. 同出版社，或 ISBN 前 9 碼相同（同一家出版社的號段；麥種傳道會 vs 美國麥種出版社這種）
 *   5. ISBN 相同的不算（那種已經併成一本了）
 * ★ 只比對、不轉換任何文字（9/1 教訓：繁體來源套 s2tw 會把「后位→後位」改壞）。
 * 已經在表上的組合略過，不會蓋掉後台判過的結果 → 可以重跑（新書進來再跑一次就補上）。
 *
 * 用法：
 *   php tools/load_edition_pairs.php            # 試跑：印組數、分布、抽樣，不寫入
 *   php tools/load_edition_pairs.php --apply    # 寫入 edition_pairs
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/lib/db.php';

$apply = in_array('--apply', $argv, true);

// 常見「只在簡體出現」的字（簡化字）。只拿來判斷這本是不是簡體，不做轉換。
const SIMP_CHARS = '们这个来时会说过还进动经发种门问间实现学应读圣约罗马传义记录导写爱亲关与东业为乐习买两严举乡书乱亚产仅从众优伟伤体侠侣侧债倾儿党兰兴养内册农冲决况净减凤凭击刘则刚创删别制剂剑剧劝办务动励劳势区医华协单卖卢卫历压厌县参双变叙叶号叹吗听启员响团园围图圆场坏块坚坛声处备复够头夸夹夺奋奖妇妈学宁宝实宠审宪宫宽宾对寻导寿将尔尘尝层属岁岛岭币师帐带帮广庆库应庙废开异弃张弥弯弹强归当录彻忆忧怀态怜总恋恶恼惊惧惨惯愿戏战扑执扩扫扬扰护报担拟拥择挂挡挣挤挥损换据摄摆摇数斋斗断无旧时显晋晓暂术机杀杂权条杨极构枪柜标树样桥梦检楼横欢欧残毁毕气汇汉汤沟没泪泽洁浅测济浓涂涛润涨渐温游湾满灭灯灵灾烂烟烦烧热爱牵犹狮独献环现电画畅疗盐监盖盘着确础碍礼祷祸离种积称稳穷竞笔简粮紧纠红约级纪纯纲纳纵纷纸线练组细织终经结绕给络绝统继续维综绿缘罚罢罗职联肃肠肤肾胜脉脑脚脱脸舍艰艺节苏药获营萨蓝虑虚虽补装见观规视览觉触计订认讨让训议讯记讲许论设访证评识诉词译试诗诚话诞询该详语误说请诸读课谁调谈谊谋谜谢谦谨谱负责贤败货质贫购贯贵费贺资赏赖赐赞赶跃践踪轨转轮软轻载较辅辈输边达迁过运还这进远违连迟适选递遗邮邻释针钟钢钱铁银锁错镜长门闭问闲间闻阅队阳阴阵阶际陆陈险随隐难静韩页顶项顺须顾预领题颜风飞饥饭饮馆马驱验鱼鸟鸡麦黄齐龙';
const MARK_RE = '/[（(【\[，,\s]*(新譯本)?(簡體字?版?|简体字?版?|簡體中文|简体中文|繁體版?|繁体版?)[）)】\]]?/u';
const VOL_RE  = '/[0-9０-９]+|第[一二三四五六七八九十百○零〇0-9]+[卷冊册年期輯辑部集]|[上中下]冊|卷[一二三四五六七八九十]|[一二三四五六七八九十○〇]{2,}/u';

$simpSet = array_flip(mb_str_split(SIMP_CHARS, 1, 'UTF-8'));

function is_simp(string $title, string $lang, array $simpSet): bool
{
    if (preg_match('/簡|简/u', $lang) || preg_match('/簡體|简体/u', $title)) return true;
    $n = 0;
    foreach (mb_str_split($title, 1, 'UTF-8') as $ch) if (isset($simpSet[$ch])) $n++;
    return $n >= 2 || ($n >= 1 && mb_strlen($title, 'UTF-8') <= 6);
}
function main_title(string $t): string
{
    $t = preg_replace(MARK_RE, '', trim($t));
    return preg_replace('/[\s　]+/u', '', (string) $t);
}
function vol_tokens(string $t): array
{
    preg_match_all(VOL_RE, $t, $m);
    return $m[0];
}

$pdo = db();
$S = []; $T = [];
$q = $pdo->query("SELECT book_id, title, COALESCE(publisher,'') pub, COALESCE(isbn13,'') isbn, COALESCE(language,'') lang, source
                    FROM books WHERE is_published = 1");
foreach ($q as $r) {
    $mt = main_title((string) $r['title']);
    $len = mb_strlen($mt, 'UTF-8');
    if ($len < 2) continue;
    $row = ['id' => (int) $r['book_id'], 'title' => (string) $r['title'], 'pub' => trim((string) $r['pub']),
            'isbn' => trim((string) $r['isbn']), 'src' => (string) $r['source'],
            'mt' => $mt, 'ch' => mb_str_split($mt, 1, 'UTF-8'), 'vol' => vol_tokens($mt)];
    if (is_simp((string) $r['title'], (string) $r['lang'], $simpSet)) $S[] = $row;
    else $T[$len][] = $row;
}

$pairs = [];
foreach ($S as $s) {
    $best = null;
    $n = count($s['ch']);
    foreach ($T[$n] ?? [] as $t) {
        if ($s['isbn'] !== '' && $s['isbn'] === $t['isbn']) continue;
        $same = 0;
        for ($i = 0; $i < $n; $i++) if ($s['ch'][$i] === $t['ch'][$i]) $same++;
        $ratio = $same / $n;
        if ($ratio < 0.6 || $s['vol'] !== $t['vol']) continue;
        $samePub = $s['pub'] !== '' && $s['pub'] === $t['pub'];
        $sameIsbnPrefix = strlen($s['isbn']) === 13 && strlen($t['isbn']) === 13 && substr($s['isbn'], 0, 9) === substr($t['isbn'], 0, 9);
        if (!$samePub && !$sameIsbnPrefix) continue;
        $score = [$ratio, $samePub ? 1 : 0];
        if ($best === null || $score > $best['score']) $best = ['score' => $score, 't' => $t, 'ratio' => $ratio, 'pub' => $samePub ? '出版社相同' : 'ISBN 號段相同'];
    }
    if ($best !== null) $pairs[] = ['s' => $s, 't' => $best['t'], 'ratio' => $best['ratio'], 'pub' => $best['pub']];
}

$exact = count(array_filter($pairs, fn($p) => $p['ratio'] >= 1.0));
echo '已上架判成簡體 ' . count($S) . " 本；成對 " . count($pairs) . " 組（書名逐字相同 {$exact}）\n";
$bySrc = [];
foreach ($pairs as $p) $bySrc[$p['s']['src']] = ($bySrc[$p['s']['src']] ?? 0) + 1;
arsort($bySrc);
echo '依簡體那本的來源：' . json_encode($bySrc, JSON_UNESCAPED_UNICODE) . "\n";
foreach ([[65499, 65498], [64263, 81275], [69241, 69248], [64537, 66787]] as [$a, $b]) {
    $hit = array_values(array_filter($pairs, fn($p) => $p['s']['id'] === $a));
    echo "票上例子 {$a}/{$b} → " . ($hit ? $hit[0]['s']['id'] . '/' . $hit[0]['t']['id'] : '沒配到') . "\n";
}

$new = 0;
if ($apply) {
    // 試跑不碰 edition_pairs（還沒跑 migration 的庫也能試跑）
    $ins = $pdo->prepare('INSERT IGNORE INTO edition_pairs (simp_book_id, trad_book_id, match_how, same_ratio, pub_how)
                          VALUES (:s, :t, :h, :r, :p)');
    foreach ($pairs as $p) {
        $ins->execute([':s' => $p['s']['id'], ':t' => $p['t']['id'],
                       ':h' => $p['ratio'] >= 1.0 ? '書名逐字相同' : '書名' . (int) floor($p['ratio'] * 10) . '成相同',
                       ':r' => round($p['ratio'], 3), ':p' => $p['pub']]);
        $new += $ins->rowCount();
    }
    $tot = (int) $pdo->query('SELECT COUNT(*) FROM edition_pairs')->fetchColumn();
    echo "寫入：新增 {$new} 組（已在表上的略過）；表上共 {$tot} 組\n";
} else {
    echo "（試跑，沒有寫入；加 --apply 才寫）\n";
}
