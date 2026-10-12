<?php

namespace Tests\Unit\News;

use App\Services\News\YahooNewsPageParser;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class YahooNewsPageParserTest extends TestCase
{
    public function test_it_reads_headline_source_and_article_url(): void
    {
        $html = <<<'HTML'
        <ol class="newsFeed_list">
          <li>
            <a href="https://news.yahoo.co.jp/articles/abc123?source=rss">
              <div>
                <div>『バイオハザード』公開3日間で興収6.2億円</div>
                <div>世界興収もシリーズ最高を更新した。</div>
                <span>オリコン</span>
                <span>エンタメ総合</span>
                <time>10/12(月) 19:56</time>
              </div>
            </a>
          </li>
          <li>
            <a href="https://example.com/not-yahoo">
              <div>関係ないリンク</div>
            </a>
          </li>
        </ol>
        HTML;

        $articles = (new YahooNewsPageParser())->parse(
            $html,
            new DateTimeImmutable('2026-10-12T12:00:00+09:00'),
        );

        $this->assertCount(1, $articles);
        $this->assertSame('『バイオハザード』公開3日間で興収6.2億円', $articles[0]['title']);
        $this->assertSame('https://news.yahoo.co.jp/articles/abc123', $articles[0]['url']);
        $this->assertSame('オリコン', $articles[0]['source']);
        $this->assertSame('世界興収もシリーズ最高を更新した。', $articles[0]['snippet']);
        $this->assertSame('2026-10-12T19:56:00+09:00', $articles[0]['publishedAt']);
    }

    public function test_it_rolls_the_year_back_when_the_label_is_far_in_the_future(): void
    {
        $html = <<<'HTML'
        <ol class="newsFeed_list">
          <li>
            <a href="https://news.yahoo.co.jp/articles/year1">
              <div>
                <div>年末公開作の興行収入</div>
                <span>朝日新聞</span>
                <time>12/28(日) 10:00</time>
              </div>
            </a>
          </li>
        </ol>
        HTML;

        $articles = (new YahooNewsPageParser())->parse(
            $html,
            new DateTimeImmutable('2026-01-05T12:00:00+09:00'),
        );

        $this->assertSame('2025-12-28T10:00:00+09:00', $articles[0]['publishedAt']);
    }
}
