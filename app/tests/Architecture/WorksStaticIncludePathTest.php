<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WorksStaticIncludePathTest extends TestCase
{
    public function testIncludesERequiresEstaticosDasViewsWorksExistem(): void
    {
        $viewsRoot = realpath(__DIR__ . '/../../resources/views/works');
        self::assertNotFalse($viewsRoot);

        $missing = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsRoot));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            if (!is_string($source)) {
                continue;
            }

            if (!preg_match_all(
                '/\b(?:require|require_once|include|include_once)\s+__DIR__\s*\.\s*([\'\"])([^\'\"]+)\1\s*;/',
                $source,
                $matches,
                PREG_OFFSET_CAPTURE
            )) {
                continue;
            }

            foreach ($matches[2] as [$relativePath, $offset]) {
                $resolved = dirname($file->getPathname()) . $relativePath;
                if (!is_file($resolved)) {
                    $line = substr_count(substr($source, 0, (int) $offset), "\n") + 1;
                    $missing[] = $file->getFilename() . ':' . $line . ' => ' . $relativePath;
                }
            }
        }

        self::assertSame([], $missing, "Includes/requires estaticos inexistentes nas views do Pactua:\n" . implode("\n", $missing));
    }
}
