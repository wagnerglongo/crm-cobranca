<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PdoNamedPlaceholderTest extends TestCase
{
    public function testNaoHaPlaceholderNomeadoDuplicadoEmPrepareEstatico(): void
    {
        $root = realpath(__DIR__ . '/../../src');
        self::assertNotFalse($root);
        $violations = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (!is_string($source)) {
                continue;
            }
            if (!preg_match_all('/->prepare\\((.*?)\\);/s', $source, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[1] as [$sqlExpression, $offset]) {
                preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $sqlExpression, $placeholderMatches);
                $counts = array_count_values($placeholderMatches[1] ?? []);
                $duplicated = array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));
                if ($duplicated !== []) {
                    $line = substr_count(substr($source, 0, (int) $offset), "\n") + 1;
                    $violations[] = basename($file->getPathname()) . ':' . $line . ' => ' . implode(', ', $duplicated);
                }
            }
        }
        self::assertSame([], $violations, "Placeholders PDO nomeados repetidos podem causar HY093:\n" . implode("\n", $violations));
    }
}
