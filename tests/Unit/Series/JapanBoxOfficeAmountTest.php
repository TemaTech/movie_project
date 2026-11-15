<?php

namespace Tests\Unit\Series;

use App\Services\Series\JapanBoxOfficeAmount;
use Tests\TestCase;

class JapanBoxOfficeAmountTest extends TestCase
{
    public function test_parses_oku_yen(): void
    {
        $parsed = JapanBoxOfficeAmount::parse('11億円');
        $this->assertSame(1_100_000_000, $parsed['yen']);
        $this->assertSame(JapanBoxOfficeAmount::METRIC_BOX_OFFICE, $parsed['metric']);
    }

    public function test_parses_oku_and_man(): void
    {
        $parsed = JapanBoxOfficeAmount::parse('97億8000万円');
        $this->assertSame(9_780_000_000, $parsed['yen']);
    }

    public function test_parses_decimal_oku(): void
    {
        $parsed = JapanBoxOfficeAmount::parse('1494.6');
        $this->assertNull($parsed);

        $parsed = JapanBoxOfficeAmount::parse('1494.6億円');
        $this->assertSame(149_460_000_000, $parsed['yen']);
    }

    public function test_parses_bare_number_when_column_unit_is_oku(): void
    {
        $this->assertSame('oku', JapanBoxOfficeAmount::unitFromHeader('興行収入 (億円)'));
        $parsed = JapanBoxOfficeAmount::parse('75.0', JapanBoxOfficeAmount::METRIC_BOX_OFFICE, 'oku');
        $this->assertSame(7_500_000_000, $parsed['yen']);
        $this->assertNull(JapanBoxOfficeAmount::parse('$403,706,375', JapanBoxOfficeAmount::METRIC_BOX_OFFICE, 'oku'));
    }

    public function test_labels_distributor_rentals(): void
    {
        $parsed = JapanBoxOfficeAmount::parse('配給収入 6.1億円');
        $this->assertSame(610_000_000, $parsed['yen']);
        $this->assertSame(JapanBoxOfficeAmount::METRIC_DISTRIBUTOR_RENTALS, $parsed['metric']);
    }

    public function test_does_not_parse_dollar_amounts(): void
    {
        $this->assertNull(JapanBoxOfficeAmount::parse('$7,723,431,572'));
        $this->assertNull(JapanBoxOfficeAmount::parse('29.4億ドル'));
        $this->assertNull(JapanBoxOfficeAmount::parse('3億5,646万1,711ドル'));
        $this->assertNull(JapanBoxOfficeAmount::parse('6億5,298万0,194ドル'));
        $this->assertNull(JapanBoxOfficeAmount::parse('3億5646万ドル'));
    }

    public function test_missing_values_are_null(): void
    {
        $this->assertNull(JapanBoxOfficeAmount::parse('-'));
        $this->assertNull(JapanBoxOfficeAmount::parse('未発表'));
        $this->assertNull(JapanBoxOfficeAmount::parse(''));
    }
}
