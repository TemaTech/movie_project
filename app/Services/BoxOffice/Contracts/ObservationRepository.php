<?php

namespace App\Services\BoxOffice\Contracts;

interface ObservationRepository
{
    /**
     * @param  array<string, mixed>  $observation
     */
    public function append(string $region, array $observation): bool;

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function loadByKey(string $region): array;

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function lastForKey(array $rows): ?array;

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $previous
     */
    public function shouldRecord(array $current, ?array $previous): bool;
}
