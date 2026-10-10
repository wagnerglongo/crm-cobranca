<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\DadosGeraisRepository;
use PHPUnit\Framework\TestCase;

final class DadosGeraisProjectionTest extends TestCase
{
    public function testSelectsMantemMesmaOrdemEAliasesDasColunasCsv(): void
    {
        $reflection = new ReflectionClass(DadosGeraisRepository::class);
        $repository = $reflection->newInstanceWithoutConstructor();

        foreach (['selectMailing', 'selectBase'] as $methodName) {
            $method = $reflection->getMethod($methodName);
            [$select, $columns] = $method->invoke($repository);

            self::assertCount(count($columns), $select, $methodName . ' deve possuir uma expressao SQL por coluna CSV.');
            $aliases = array_map([$this, 'aliasDaExpressao'], $select);
            self::assertSame(array_keys($columns), $aliases, $methodName . ' possui alias SQL diferente da chave esperada pelo CSV.');
        }
    }

    private function aliasDaExpressao(string $expression): string
    {
        if (preg_match('/\s+AS\s+`?([A-Za-z0-9_]+)`?$/i', $expression, $match) === 1) {
            return $match[1];
        }
        if (preg_match('/cb\.([A-Za-z0-9_]+)$/', $expression, $match) === 1) {
            return $match[1];
        }
        self::fail('Nao foi possivel determinar o alias da expressao: ' . $expression);
    }
}
