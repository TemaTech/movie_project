<?php

namespace Tests\Unit\Series;

use App\Services\Series\SeriesSlug;
use Tests\TestCase;

class SeriesSlugTest extends TestCase
{
    public function test_cjk_names_with_english_collection_keep_the_cjk_part(): void
    {
        $this->assertSame('戦狼-Collection', SeriesSlug::fromName('戦狼 Collection', 86837));
        $this->assertNotSame('collection', SeriesSlug::fromName('战狼 Collection', 86837));
        $this->assertSame('harry-potter-collection', SeriesSlug::fromName('Harry Potter Collection'));
        $this->assertSame('ハリーポッター-コレクション', SeriesSlug::fromName('ハリー・ポッター コレクション'));
    }

    public function test_generic_names_fall_back_to_the_collection_id(): void
    {
        $this->assertSame('tmdb-collection-86837', SeriesSlug::fromName('Collection', 86837));
        $this->assertSame('tmdb-collection-12', SeriesSlug::fromName('シリーズ', 12));
        $this->assertSame('tmdb-collection-1', SeriesSlug::unique('Collection', [], 1));
    }

    public function test_colliding_names_use_the_collection_id(): void
    {
        $used = ['戦狼-Collection'];
        $this->assertSame(
            'tmdb-collection-99',
            SeriesSlug::unique('戦狼 Collection', $used, 99)
        );
    }

    public function test_unique_slugs_are_never_blank_or_generic(): void
    {
        $used = [];
        foreach ([
            ['戦狼 Collection', 86837],
            ['Collection', 1],
            ['Collection', 2],
            ['Wolf Warrior Collection', 3],
            ['', 4],
        ] as [$name, $id]) {
            $slug = SeriesSlug::unique($name, $used, $id);
            $used[] = $slug;
            $this->assertNotSame('', $slug);
            $this->assertFalse(SeriesSlug::isGeneric($slug));
        }
        $this->assertCount(5, array_unique($used));
    }
}
