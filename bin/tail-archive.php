#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

main($argv);

/**
 * @param list<string> $argv
 */
function main(array $argv): void
{
    $command = $argv[1] ?? null;

    if (null === $command || in_array($command, ['-h', '--help'], true)) {
        fwrite(STDOUT, usage());

        return;
    }

    try {
        match ($command) {
            'extract' => extractArchive(
                archivePath: requireArg($argv, 2, 'Missing archive path for extract command.'),
                destinationRoot: $argv[3] ?? getcwd(),
            ),
            'create' => createArchive(
                manifestArchivePath: requireArg($argv, 2, 'Missing manifest archive path for create command.'),
                outputArchivePath: $argv[3] ?? null,
                sourceRoot: $argv[4] ?? getcwd(),
            ),
            default => throw new InvalidArgumentException(sprintf('Unknown command "%s".', $command)),
        };
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage()."\n");
        exit(1);
    }
}

function usage(): string
{
    return <<<TXT
Usage:
  php bin/tail-archive.php extract <archive> [destination]
  php bin/tail-archive.php create <manifest-archive> [output-archive] [source-root]

Commands:
  extract   Re-create files from a tail-style archive with headers like "==> path <==".
  create    Rebuild an archive using the file list stored in an existing archive.

Examples:
  php bin/tail-archive.php extract SlotFlow.php
  php bin/tail-archive.php extract SoftFlow.php /tmp/softflow
  php bin/tail-archive.php create SlotFlow.php /tmp/SlotFlow.rebuilt.php

TXT;
}

function requireArg(array $argv, int $index, string $message): string
{
    if (!isset($argv[$index]) || '' === $argv[$index]) {
        throw new InvalidArgumentException($message);
    }

    return $argv[$index];
}

function extractArchive(string $archivePath, string $destinationRoot): void
{
    $entries = parseArchive($archivePath);
    $destinationRoot = normalizeDirectory($destinationRoot);

    foreach ($entries as $entry) {
        $targetPath = joinPath($destinationRoot, $entry['path']);
        ensurePathStaysWithinRoot($destinationRoot, $targetPath, $entry['path']);

        $directory = dirname($targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $directory));
        }

        if (false === file_put_contents($targetPath, $entry['content'])) {
            throw new RuntimeException(sprintf('Unable to write "%s".', $targetPath));
        }

        fwrite(STDOUT, sprintf("wrote %s\n", $entry['path']));
    }
}

function createArchive(string $manifestArchivePath, ?string $outputArchivePath, string $sourceRoot): void
{
    $entries = parseArchive($manifestArchivePath);
    $sourceRoot = normalizeDirectory($sourceRoot);

    $buffer = '';
    foreach ($entries as $index => $entry) {
        $sourcePath = joinPath($sourceRoot, $entry['path']);
        ensurePathStaysWithinRoot($sourceRoot, $sourcePath, $entry['path']);

        if (!is_file($sourcePath)) {
            throw new RuntimeException(sprintf('Missing source file "%s".', $sourcePath));
        }

        $content = file_get_contents($sourcePath);
        if (false === $content) {
            throw new RuntimeException(sprintf('Unable to read "%s".', $sourcePath));
        }

        if ($index > 0 && '' !== $buffer && !str_ends_with($buffer, "\n")) {
            $buffer .= "\n";
        }

        $buffer .= sprintf("==> %s <==\n", $entry['path']);
        $buffer .= $content;
    }

    if (null === $outputArchivePath || '-' === $outputArchivePath) {
        fwrite(STDOUT, $buffer);

        return;
    }

    $outputDirectory = dirname($outputArchivePath);
    if ('.' !== $outputDirectory && !is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException(sprintf('Unable to create directory "%s".', $outputDirectory));
    }

    if (false === file_put_contents($outputArchivePath, $buffer)) {
        throw new RuntimeException(sprintf('Unable to write archive "%s".', $outputArchivePath));
    }

    fwrite(STDOUT, sprintf("created %s\n", $outputArchivePath));
}

/**
 * @return list<array{path: string, content: string}>
 */
function parseArchive(string $archivePath): array
{
    if (!is_file($archivePath)) {
        throw new RuntimeException(sprintf('Archive "%s" does not exist.', $archivePath));
    }

    $archive = file_get_contents($archivePath);
    if (false === $archive) {
        throw new RuntimeException(sprintf('Unable to read archive "%s".', $archivePath));
    }

    if (!preg_match_all('/^==> (.+) <==$/m', $archive, $matches, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException('No tail-style file markers were found in the archive.');
    }

    $entries = [];
    $headerMatches = $matches[0];
    $pathMatches = $matches[1];

    foreach ($headerMatches as $index => [$headerLine, $headerOffset]) {
        $path = trim($pathMatches[$index][0]);
        $contentOffset = $headerOffset + strlen($headerLine);

        if (isset($archive[$contentOffset]) && "\n" === $archive[$contentOffset]) {
            ++$contentOffset;
        }

        $contentLength = isset($headerMatches[$index + 1])
            ? $headerMatches[$index + 1][1] - $contentOffset
            : null;

        $content = null === $contentLength
            ? substr($archive, $contentOffset)
            : substr($archive, $contentOffset, $contentLength);

        if (false === $content) {
            throw new RuntimeException(sprintf('Unable to parse content for "%s".', $path));
        }

        $entries[] = [
            'path'    => $path,
            'content' => $content,
        ];
    }

    return $entries;
}

function normalizeDirectory(string $path): string
{
    $trimmed = rtrim($path, DIRECTORY_SEPARATOR);

    return '' === $trimmed ? DIRECTORY_SEPARATOR : $trimmed;
}

function joinPath(string $root, string $relativePath): string
{
    return $root.DIRECTORY_SEPARATOR.ltrim($relativePath, DIRECTORY_SEPARATOR);
}

function ensurePathStaysWithinRoot(string $root, string $fullPath, string $relativePath): void
{
    $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    $normalizedFullPath = normalizePath($fullPath);

    if (!str_starts_with($normalizedFullPath, $rootPrefix)) {
        throw new RuntimeException(sprintf('Refusing to write outside root: "%s".', $relativePath));
    }
}

function normalizePath(string $path): string
{
    $parts = [];
    foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
        if ('' === $part || '.' === $part) {
            continue;
        }

        if ('..' === $part) {
            array_pop($parts);
            continue;
        }

        $parts[] = $part;
    }

    return DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts);
}
