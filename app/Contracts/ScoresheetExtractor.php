<?php

namespace App\Contracts;

use App\Enums\ImportDriver;

/**
 * Reads a raw scoresheet and returns one array per line it can make sense of.
 *
 * Implementations must NEVER write to the database. They only describe what
 * they read; `ScoreImportService` stores it and a human approves it.
 */
interface ScoresheetExtractor
{
    /** Can this extractor handle the given upload? */
    public function supports(string $mimeType, string $extension): bool;

    public function driver(): ImportDriver;

    /**
     * @param  string  $absolutePath  Path to the stored upload.
     * @param  array<string,mixed>  $context  e.g. ['subject' => 'Mathematics', 'total_marks' => 100]
     * @return array<int,array{
     *     row:int,
     *     identifier:?string,
     *     name:?string,
     *     subject:?string,
     *     score:?float,
     *     confidence:?float,
     *     raw:array<string,mixed>
     * }>
     */
    public function extract(string $absolutePath, array $context = []): array;

    /** Human-readable description of how the file was read. */
    public function describe(): string;
}
