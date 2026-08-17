<?php

namespace App\Services\BoxOffice\Contracts;

interface RegistryRepository
{
    public function resolveCanonicalKey(string $key): string;

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function movies(): array;

    /**
     * @param  array{
     *     region: string,
     *     title: string,
     *     tmdbId?: int|null,
     *     releaseYear?: int|null,
     *     releaseDate?: string|null,
     *     releaseDatePrecision?: string|null,
     *     legacyIds?: list<string>
     * }  $incoming
     * @return array<string, mixed>
     */
    public function resolve(array $incoming): array;

    public function rememberLegacyId(string $key, string $legacyId): void;

    /**
     * @return list<array{from: string, to: string}>
     */
    public function redirects(): array;

    public function save(): void;
}
