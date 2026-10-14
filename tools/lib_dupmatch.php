<?php
declare(strict_types=1);

/**
 * 「書名＋出版社」重複判斷的共用規則(2026-10-14,Asana 1219483419251976)。
 * tools/find_title_publisher_duplicates.php(找既有重複)與 tools/import.php(匯入時就併)共用,
 * 規則只寫在這裡,兩邊才不會各說各話。
 *
 * 起因:沒有 ISBN 的書只靠「書名＋第一作者」比對,各站作者寫法不同
 * (「賴特」/「賴特（N. T. Wright）」/「湯姆．賴特」)就長出三筆同一本書。
 */

/** 正規化:去空白標點符號、轉小寫(與 merge_duplicate_books.php norm()、import.php fuzzy_key() 相同) */
function dm_norm(?string $s): string
{
    return mb_strtolower(preg_replace('/[\s\p{P}\p{S}]+/u', '', (string) $s), 'UTF-8');
}

/** 第一作者(分隔符與 merge_duplicate_books.php first_author() 相同) */
function dm_first_author(?string $raw): string
{
    $p = preg_split('/[;' . "\u{FF1B}\u{3001}" . '\/]/u', (string) $raw);
    return dm_norm(preg_replace('/\s*等$/u', '', trim($p[0] ?? '')));
}

/** 第一作者去掉括號內容(多半是英文原名:「賴特（N. T. Wright）」→「賴特」) */
function dm_author_core(?string $raw): string
{
    $p = preg_split('/[;' . "\u{FF1B}\u{3001}" . '\/]/u', (string) $raw);
    return dm_norm(preg_replace(['/[（(][^（）()]*[）)]/u', '/\s*等$/u'], '', trim($p[0] ?? '')));
}

/** 一邊空白,或一邊包含另一邊(較短的至少 2 字,避免單字誤中) */
function dm_compatible(string $a, string $b): bool
{
    if ($a === '' || $b === '') return true;
    if ($a === $b) return true;
    [$s, $l] = mb_strlen($a) <= mb_strlen($b) ? [$a, $b] : [$b, $a];
    return mb_strlen($s) >= 2 && str_contains($l, $s);
}

/** 作者相容:原寫法或去括號後,任一種相容就算 */
function dm_authors_match(?string $rawA, ?string $rawB): bool
{
    return dm_compatible(dm_first_author($rawA), dm_first_author($rawB))
        || dm_compatible(dm_author_core($rawA), dm_author_core($rawB));
}

/**
 * 判一對書的等級:'高'(可以合併)/'待判'(留給人)/'不併'。
 * - 兩邊都沒作者 → 待判(《聖經》這類書誤併就是災難)
 * - 作者不相容 → 不併
 * - 同一家書房各上一筆 → 待判(多半是平裝/精裝、套書分冊、電子書等不同商品)
 * @param string[] $srcsA @param string[] $srcsB 兩本書各自的版本來源
 * @return array{0: string, 1: string} [等級, 理由]
 */
function dm_judge(?string $authorA, ?string $authorB, array $srcsA, array $srcsB): array
{
    $a = dm_first_author($authorA);
    $b = dm_first_author($authorB);
    if ($a === '' && $b === '') return ['待判', '兩邊都沒有作者'];
    if (!dm_authors_match($authorA, $authorB)) return ['不併', '作者不同'];
    if (array_intersect($srcsA, $srcsB)) return ['待判', '同一家書房上了兩筆(可能是不同版本或商品)'];
    return ['高', $a === $b ? '作者相同' : ($a === '' || $b === '' ? '一邊沒有作者' : '作者寫法不同但互相包含')];
}
