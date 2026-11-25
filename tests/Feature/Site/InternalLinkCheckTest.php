<?php

namespace Tests\Feature\Site;

use App\Services\Site\InternalLinkChecker;
use Tests\TestCase;

class InternalLinkCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/mubiran-links-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/movies/tmdb-1', 0777, true);
        mkdir($this->dir.'/series/名探偵コナン', 0777, true);
        mkdir($this->dir.'/build/assets', 0777, true);
        file_put_contents($this->dir.'/index.html', '<a href="/movies/tmdb-1/">ok</a><a href="/missing/">broken</a>');
        file_put_contents($this->dir.'/movies/tmdb-1/index.html', '<link href="/build/assets/app.css"><a href="/series/名探偵コナン/">series</a><a href="/series/global/collection/">old</a>');
        file_put_contents($this->dir.'/series/名探偵コナン/index.html', '<a href="/">home</a><a href="https://example.com/out">ext</a><a href="//cdn.example/x">proto</a>');
        file_put_contents($this->dir.'/build/assets/app.css', 'body{}');
        file_put_contents($this->dir.'/robots.txt', 'User-agent: *');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
        parent::tearDown();
    }

    public function test_scan_reports_missing_internal_hrefs_and_ignores_external_urls(): void
    {
        $result = InternalLinkChecker::scan($this->dir);

        $this->assertSame(3, $result['scanned']);
        $this->assertGreaterThanOrEqual(5, $result['links']);
        $missing = array_column($result['missing'], 'href');
        $this->assertContains('/missing/', $missing);
        $this->assertContains('/series/global/collection/', $missing);
        $this->assertCount(2, $result['missing']);
        $this->assertFalse(InternalLinkChecker::targetExists($this->dir, '/missing/'));
        $this->assertTrue(InternalLinkChecker::targetExists($this->dir, '/movies/tmdb-1/'));
        $this->assertTrue(InternalLinkChecker::targetExists($this->dir, '/series/名探偵コナン/'));
        $this->assertTrue(InternalLinkChecker::targetExists($this->dir, '/'));
        $this->assertTrue(InternalLinkChecker::targetExists($this->dir, '/build/assets/app.css'));
        $this->assertContains('/series/名探偵コナン/', InternalLinkChecker::internalHrefs('<a href="/series/%E5%90%8D%E6%8E%A2%E5%81%B5%E3%82%B3%E3%83%8A%E3%83%B3/">'));
        $this->assertTrue(InternalLinkChecker::targetExists($this->dir, '/series/%E5%90%8D%E6%8E%A2%E5%81%B5%E3%82%B3%E3%83%8A%E3%83%B3/'));
    }

    public function test_command_warns_without_failing(): void
    {
        $this->artisan('site:check-internal-links', ['--output' => $this->dir])
            ->expectsOutputToContain('2 broken internal link(s)')
            ->expectsOutputToContain('/missing/')
            ->expectsOutputToContain('/series/global/collection/')
            ->assertExitCode(0);

        $this->artisan('site:check-internal-links', ['--output' => $this->dir.'/does-not-exist'])
            ->expectsOutputToContain('Output directory not found')
            ->assertExitCode(0);
    }

    public function test_command_reports_clean_output(): void
    {
        $clean = $this->dir.'-clean';
        mkdir($clean, 0777, true);
        file_put_contents($clean.'/index.html', '<a href="/">home</a>');
        try {
            $this->artisan('site:check-internal-links', ['--output' => $clean])
                ->expectsOutputToContain('No broken internal links')
                ->assertExitCode(0);
        } finally {
            $this->removeDirectory($clean);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }
}
