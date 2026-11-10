<?php
// "Smart" date input: one text field that understands how exact a date is.
//   21 Sep 1937 / 21.9.1937 / 1937-09-21 / Sep 21, 1937  -> day
//   Sep 1937 / 1937-09 / 9/1937                            -> month
//   1937                                                   -> year
//   1930s                                                  -> decade
//   c. 1930 / ca. 1930 / circa 1930 / ~1930                -> circa
//   Summer 1937 / Winter 1942/43 / Herbst 1937             -> season (winter 1943 = winter 1942/43)
// Stored as a full date (first day of the month/year/decade/season) plus the precision, like the rest of the data.

declare(strict_types=1);

const MONTH_NAMES = [
    'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3, 'apr' => 4, 'april' => 4,
    'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7, 'aug' => 8, 'august' => 8,
    'sep' => 9, 'sept' => 9, 'september' => 9, 'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11,
    'dec' => 12, 'december' => 12,
    // German month names, as the data is often typed from German sources
    'januar' => 1, 'jän' => 1, 'februar' => 2, 'märz' => 3, 'mär' => 3, 'mai' => 5, 'juni' => 6, 'juli' => 7,
    'okt' => 10, 'oktober' => 10, 'dez' => 12, 'dezember' => 12,
];

/** Season words -> first month of the (meteorological) season. */
const SEASON_NAMES = [
    'spring' => 3, 'summer' => 6, 'autumn' => 9, 'fall' => 9, 'winter' => 12,
    'frühling' => 3, 'frühjahr' => 3, 'sommer' => 6, 'herbst' => 9,
];

/**
 * Parses a date as typed by a person. Returns ['date' => 'YYYY-MM-DD', 'precision' => ..., 'label' => '21 Sep 1937']
 * or null if the text is not understood (or not a real calendar date).
 */
function parseSmartDate(string $text): ?array
{
    $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
    if ($t === '') {
        return null;
    }
    $month = static fn(string $m): ?int => MONTH_NAMES[rtrim($m, '.')] ?? null;
    $ok = static function (int $y, int $m, int $d, string $prec): ?array {
        if ($y < 1 || $y > 9999 || !checkdate($m, $d, $y)) {
            return null;
        }
        $date = sprintf('%04d-%02d-%02d', $y, $m, $d);
        return ['date' => $date, 'precision' => $prec, 'label' => formatDate($date, $prec)];
    };

    // circa
    if (preg_match('/^(?:c\.?|ca\.?|circa|approx\.?|~)\s*(\d{1,4})$/', $t, $m)) {
        return $ok((int) $m[1], 1, 1, 'circa');
    }
    // season: "Summer 1937", "summer of 1937", "Winter 1942/43", "Winter 1942/1943", "Herbst 1937".
    // A winter with a single year is the one at the start of that year: "Winter 1943" = Dec 1942 – Feb 1943.
    if (preg_match('/^([a-zäöü]+),?(?: of)? (\d{1,4})(?:\s*[\/–-]\s*(\d{2}|\d{4}))?$/u', $t, $m) && isset(SEASON_NAMES[$m[1]])) {
        $start = SEASON_NAMES[$m[1]];
        $y = (int) $m[2];
        if (isset($m[3])) {
            // "1942/43" or "1942/1943": the second year must follow the first, and only winter spans two years
            $second = strlen($m[3]) === 2 ? intdiv($y, 100) * 100 + (int) $m[3] : (int) $m[3];
            if (strlen($m[3]) === 2 && $second < $y) {
                $second += 100;   // 1999/00
            }
            if ($start !== 12 || $second !== $y + 1) {
                return null;
            }
        } elseif ($start === 12) {
            $y--;
        }
        return $ok($y, $start, 1, 'season');
    }
    // decade: 1930s, 1930's
    if (preg_match('/^(\d{3})0\'?s$/', $t, $m)) {
        return $ok((int) ($m[1] . '0'), 1, 1, 'decade');
    }
    // year
    if (preg_match('/^\d{1,4}$/', $t)) {
        return $ok((int) $t, 1, 1, 'year');
    }
    // ISO: 1937-09-21 / 1937-9-21 / 1937-09
    if (preg_match('/^(\d{4})-(\d{1,2})(?:-(\d{1,2}))?$/', $t, $m)) {
        return isset($m[3]) ? $ok((int) $m[1], (int) $m[2], (int) $m[3], 'day') : $ok((int) $m[1], (int) $m[2], 1, 'month');
    }
    // European numeric: 21.9.1937 / 21/9/1937 / 21-9-1937 (day first)
    if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/', $t, $m)) {
        return $ok((int) $m[3], (int) $m[2], (int) $m[1], 'day');
    }
    // month/year: 9/1937 / 09.1937
    if (preg_match('/^(\d{1,2})[.\/](\d{4})$/', $t, $m)) {
        return $ok((int) $m[2], (int) $m[1], 1, 'month');
    }
    // 21 Sep 1937 / 21. September 1937
    if (preg_match('/^(\d{1,2})\.? ([a-zä]+\.?),? (\d{1,4})$/u', $t, $m) && ($mo = $month($m[2]))) {
        return $ok((int) $m[3], $mo, (int) $m[1], 'day');
    }
    // Sep 21, 1937 / September 21 1937
    if (preg_match('/^([a-zä]+\.?) (\d{1,2})(?:st|nd|rd|th)?,? (\d{3,4})$/u', $t, $m) && ($mo = $month($m[1]))) {
        return $ok((int) $m[3], $mo, (int) $m[2], 'day');
    }
    // Sep 1937 / September, 1937
    if (preg_match('/^([a-zä]+\.?),? (\d{1,4})$/u', $t, $m) && ($mo = $month($m[1]))) {
        return $ok((int) $m[2], $mo, 1, 'month');
    }
    return null;
}

/** Text for the input field from a stored date + precision (the viewer's format, which parseSmartDate reads back). */
function smartDateText(?string $date, ?string $precision): string
{
    return $date ? formatDate(substr($date, 0, 10), $precision ?: 'day') : '';
}

const PRECISION_LABELS = ['day' => 'exact day', 'month' => 'month', 'season' => 'season', 'year' => 'year', 'decade' => 'decade', 'circa' => 'approximate year'];
