<?php

namespace App\Services\News;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 作品名と興行収入の語が見出し側にあり、出典が報道機関・映画情報サイトのものだけを残す。
 */
class BoxOfficeNewsMatcher
{
    private const BOX_OFFICE = '/興行|興収|動員|億(?:円|ドル)|box office|gross/ui';

    /** @var list<string> */
    private const TRUSTED_SOURCES = [
        'Yahoo!ニュース',
        'Yahooニュース',
        'オリコン',
        'oricon',
        'シネマトゥデイ',
        '映画.com',
        '映画ドットコム',
        '映画ナタリー',
        'ナタリー',
        'シネマカフェ',
        'クランクイン',
        'MANTAN',
        'まんたん',
        '日刊スポーツ',
        'スポニチ',
        'スポーツ報知',
        '報知新聞',
        '東スポ',
        '東京スポーツ',
        'デイリースポーツ',
        'サンスポ',
        '朝日新聞',
        '毎日新聞',
        '読売',
        '日本経済新聞',
        '日経',
        '産経',
        '東洋経済',
        'NHK',
        '共同通信',
        '時事通信',
        'ロイター',
        'Reuters',
        'Forbes',
        'フォーブス',
        'Hollywood Reporter',
        'Variety',
        'Deadline',
        'リアルサウンド',
        'MOVIE WALKER',
        'ムービーウォーカー',
        'キネマ旬報',
        'ぴあ',
        'BBC',
        'CNN',
        'Bloomberg',
        'ブルームバーグ',
        'Associated Press',
        'IndieWire',
        'Screen Daily',
        '文春',
    ];

    /** @var list<string> */
    private const BLOCKED_SOURCES = [
        '週刊女性',
        '女性自身',
        'SmartFLASH',
        'FRIDAY',
        'アサヒ芸能',
        'アサ芸',
        'ねとらぼ',
        'Game Spark',
        'アニメ！アニメ',
    ];

    public static function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[\s　・･·「」『』【】（）()\[\]"\'“”]/u', '', $value) ?? $value;

        return $value;
    }

    /**
     * @param  list<array{title?: string, url?: string, source?: string, snippet?: string, publishedAt?: ?string}>  $articles
     * @return list<array{title: string, url: string, source: string, publishedAt: ?string}>
     */
    public function match(string $title, ?string $originalTitle, array $articles, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
        $needles = $this->needles($title, $originalTitle);
        if ($needles === []) {
            return [];
        }

        $cutoff = $now->modify('-45 days');
        $kept = [];
        foreach ($articles as $article) {
            $headline = trim((string) ($article['title'] ?? ''));
            $url = (string) ($article['url'] ?? '');
            $source = trim((string) ($article['source'] ?? ''));
            $snippet = (string) ($article['snippet'] ?? '');
            $publishedAt = $this->publishedAt($article['publishedAt'] ?? null);
            if ($headline === '' || $source === '' || ! $this->isYahooArticleUrl($url)) {
                continue;
            }
            if (! $this->isTrusted($source) || ! $this->headlineMatches($headline, $needles)) {
                continue;
            }
            if (preg_match(self::BOX_OFFICE, $headline.' '.$snippet) !== 1) {
                continue;
            }
            if ($publishedAt !== null) {
                try {
                    if (new DateTimeImmutable($publishedAt) < $cutoff) {
                        continue;
                    }
                } catch (\Throwable) {
                    $publishedAt = null;
                }
            }

            $kept[$url] = [
                'title' => $headline,
                'url' => $url,
                'source' => $source,
                'publishedAt' => $publishedAt,
            ];
        }

        $items = array_values($kept);
        usort($items, function (array $left, array $right): int {
            return strcmp((string) $right['publishedAt'], (string) $left['publishedAt']);
        });

        return array_slice($items, 0, 5);
    }

    /**
     * @return list<string>
     */
    private function needles(string $title, ?string $originalTitle): array
    {
        $needles = [];
        foreach ([$title, $originalTitle] as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            $this->addNeedle($needles, self::normalize($raw));
            if (preg_match('/^(.{4,}?)[\s　]*[:：\/／|｜～〜]/u', $raw, $match) === 1) {
                $this->addNeedle($needles, self::normalize($match[1]));
            }
            if (preg_match_all('/[「『]([^」』]{2,40})[」』]/u', $raw, $quoted) > 0) {
                foreach ($quoted[1] as $inner) {
                    $this->addNeedle($needles, self::normalize($inner));
                }
            }
            $withoutPrefix = preg_replace('/^劇場版/u', '', self::normalize($raw));
            if (is_string($withoutPrefix)) {
                $this->addNeedle($needles, $withoutPrefix);
            }
        }

        return array_values(array_unique($needles));
    }

    /**
     * @param  list<string>  $needles
     */
    private function addNeedle(array &$needles, string $needle): void
    {
        if ($this->isUsableNeedle($needle)) {
            $needles[] = $needle;
        }
    }

    private function isUsableNeedle(string $needle): bool
    {
        if (preg_match('/\p{Han}|\p{Hiragana}|\p{Katakana}/u', $needle) === 1) {
            return mb_strlen($needle) >= 2;
        }

        return mb_strlen($needle) >= 4;
    }

    /**
     * @param  list<string>  $needles
     */
    private function headlineMatches(string $headline, array $needles): bool
    {
        $normalized = self::normalize($headline);
        foreach ($needles as $needle) {
            if ($needle !== '' && mb_stripos($normalized, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isTrusted(string $source): bool
    {
        foreach (self::BLOCKED_SOURCES as $blocked) {
            if (mb_stripos($source, $blocked) !== false) {
                return false;
            }
        }
        foreach (self::TRUSTED_SOURCES as $trusted) {
            if (mb_stripos($source, $trusted) !== false) {
                return true;
            }
        }

        return false;
    }

    private function publishedAt(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function isYahooArticleUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        return ($parts['scheme'] ?? '') === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'news.yahoo.co.jp'
            && str_starts_with((string) ($parts['path'] ?? ''), '/articles/');
    }
}
