<?php

namespace App\Services\News;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Yahoo!ニュース検索結果の見出し・出典・リンクだけを取り出す。
 * 記事本文は保存しない。
 */
class YahooNewsPageParser
{
    /**
     * @return list<array{title: string, url: string, source: string, snippet: string, publishedAt: ?string}>
     */
    public function parse(string $html, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $items = $xpath->query("//ol[contains(concat(' ', normalize-space(@class), ' '), ' newsFeed_list ')]/li");
        if ($items === false) {
            return [];
        }

        $articles = [];
        foreach ($items as $item) {
            if (! $item instanceof DOMElement) {
                continue;
            }
            $article = $this->articleFromItem($xpath, $item, $now);
            if ($article === null) {
                continue;
            }
            $articles[$article['url']] = $article;
        }

        return array_values($articles);
    }

    /**
     * @return array{title: string, url: string, source: string, snippet: string, publishedAt: ?string}|null
     */
    private function articleFromItem(DOMXPath $xpath, DOMElement $item, DateTimeImmutable $now): ?array
    {
        $anchor = $xpath->query('.//a[contains(@href, "/articles/")]', $item)?->item(0);
        if (! $anchor instanceof DOMElement) {
            return null;
        }

        $url = $this->articleUrl($anchor->getAttribute('href'));
        if ($url === null) {
            return null;
        }

        $texts = [];
        $divs = $xpath->query('.//div[not(div)]', $item);
        if ($divs !== false) {
            foreach ($divs as $div) {
                $text = $this->clean($div->textContent ?? '');
                if (mb_strlen($text) >= 8) {
                    $texts[] = $text;
                }
            }
        }

        $title = $texts[0] ?? '';
        if ($title === '') {
            return null;
        }

        $sourceNode = $xpath->query('.//span', $item)?->item(0);
        $source = $sourceNode ? $this->clean($sourceNode->textContent ?? '') : '';
        if ($source === '') {
            return null;
        }

        $timeNode = $xpath->query('.//time', $item)?->item(0);
        $publishedAt = $timeNode ? $this->publishedAt($this->clean($timeNode->textContent ?? ''), $now) : null;

        return [
            'title' => mb_substr($title, 0, 180),
            'url' => $url,
            'source' => mb_substr($source, 0, 80),
            'snippet' => mb_substr($texts[1] ?? '', 0, 240),
            'publishedAt' => $publishedAt,
        ];
    }

    private function articleUrl(string $href): ?string
    {
        $parts = parse_url(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (! is_array($parts)) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($host !== 'news.yahoo.co.jp' || ! str_starts_with($path, '/articles/')) {
            return null;
        }

        return 'https://news.yahoo.co.jp'.$path;
    }

    private function publishedAt(string $label, DateTimeImmutable $now): ?string
    {
        try {
            if (preg_match('/(\d{4})\/(\d{1,2})\/(\d{1,2})(?:\s+(\d{1,2}):(\d{2}))?/u', $label, $match) === 1) {
                return $now
                    ->setDate((int) $match[1], (int) $match[2], (int) $match[3])
                    ->setTime((int) ($match[4] ?? 0), (int) ($match[5] ?? 0))
                    ->format('c');
            }

            if (preg_match('/(\d{1,2})\/(\d{1,2})\([^)]*\)\s*(\d{1,2}):(\d{2})/u', $label, $match) !== 1) {
                return null;
            }

            $published = $now
                ->setDate((int) $now->format('Y'), (int) $match[1], (int) $match[2])
                ->setTime((int) $match[3], (int) $match[4]);
            if ($published > $now->modify('+2 days')) {
                $published = $published->modify('-1 year');
            }

            return $published->format('c');
        } catch (\Throwable) {
            return null;
        }
    }

    private function clean(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
