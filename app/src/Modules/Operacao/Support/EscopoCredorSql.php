<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Support;

use PDO;
use PDOStatement;

final class EscopoCredorSql
{
    /**
     * @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopo
     * @return array{sql:string,params:array<string,int>}
     */
    public static function contrato(?array $escopo, string $alias = 'c', string $prefix = 'escopo'): array
    {
        if ($escopo === null || (bool) ($escopo['todos'] ?? false)) {
            return ['sql' => '1=1', 'params' => []];
        }

        $credorIds = self::normalizarIds($escopo['credor_ids'] ?? []);
        $carteiraIds = self::normalizarIds($escopo['carteira_ids'] ?? []);
        if ($credorIds === [] && $carteiraIds === []) {
            return ['sql' => '0=1', 'params' => []];
        }

        $params = [];
        $partes = [];
        if ($credorIds !== []) {
            $placeholders = [];
            foreach ($credorIds as $i => $id) {
                $key = $prefix . '_credor_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $id;
            }
            $partes[] = $alias . '.credor_id IN (' . implode(',', $placeholders) . ')';
        }

        if ($carteiraIds !== []) {
            $placeholders = [];
            foreach ($carteiraIds as $i => $id) {
                $key = $prefix . '_carteira_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $id;
            }
            $partes[] = $alias . '.carteira_id IN (' . implode(',', $placeholders) . ')';
        }

        return ['sql' => '(' . implode(' OR ', $partes) . ')', 'params' => $params];
    }

    /**
     * @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopo
     * @return array{sql:string,params:array<string,int>}
     */
    public static function acordo(?array $escopo, string $acordoAlias = 'ao', string $contratoAlias = 'c', string $prefix = 'escopo'): array
    {
        if ($escopo === null || (bool) ($escopo['todos'] ?? false)) {
            return ['sql' => '1=1', 'params' => []];
        }

        $credorIds = self::normalizarIds($escopo['credor_ids'] ?? []);
        $carteiraIds = self::normalizarIds($escopo['carteira_ids'] ?? []);
        if ($credorIds === [] && $carteiraIds === []) {
            return ['sql' => '0=1', 'params' => []];
        }

        $params = [];
        $partes = [];
        if ($credorIds !== []) {
            $placeholders = [];
            foreach ($credorIds as $i => $id) {
                $key = $prefix . '_credor_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $id;
            }
            $partes[] = 'COALESCE(' . $acordoAlias . '.credor_id, ' . $contratoAlias . '.credor_id) IN (' . implode(',', $placeholders) . ')';
        }

        if ($carteiraIds !== []) {
            $placeholders = [];
            foreach ($carteiraIds as $i => $id) {
                $key = $prefix . '_carteira_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $id;
            }
            $partes[] = $contratoAlias . '.carteira_id IN (' . implode(',', $placeholders) . ')';
        }

        return ['sql' => '(' . implode(' OR ', $partes) . ')', 'params' => $params];
    }

    /** @param array<string,mixed> $params */
    public static function bind(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    /** @param list<int|string> $ids @return list<int> */
    private static function normalizarIds(array $ids): array
    {
        $ret = [];
        foreach ($ids as $id) {
            $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $ret[(int) $id] = (int) $id;
            }
        }
        return array_values($ret);
    }
}
