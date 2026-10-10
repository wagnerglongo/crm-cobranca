<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use CRM\Infrastructure\Redis\RedisCache;
use InvalidArgumentException;
use PDO;
use Throwable;

final class CredorConfiguracaoRepository
{
    private ?bool $moduloDisponivel = null;
    private RedisCache $cache;
    private int $cacheTtl;

    public function __construct(private readonly PDO $pdo)
    {
        $this->cache = RedisCache::default();
        $this->cacheTtl = max(15, min(3600, (int) \CRM\Config\Database::env('REDIS_CREDOR_CONFIG_TTL', '300')));
    }

    public function moduloDisponivel(): bool
    {
        if ($this->moduloDisponivel !== null) {
            return $this->moduloDisponivel;
        }

        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'credor_configuracoes'");
            $this->moduloDisponivel = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->moduloDisponivel = false;
        }

        return $this->moduloDisponivel;
    }

    /** @return list<array<string,mixed>> */
    public function listarCredores(): array
    {
        $stmt = $this->pdo->query('SELECT id, codigo, nome FROM credores ORDER BY nome ASC, id ASC');
        $rows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        if (!$this->moduloDisponivel() || $rows === []) {
            foreach ($rows as &$row) {
                $row['configuracoes_total'] = 0;
                $row['habilitado'] = true;
            }
            unset($row);
            return $rows;
        }

        $contagens = [];
        $stmtCfg = $this->pdo->query("SELECT credor_id,
                COUNT(*) AS configuracoes_total,
                MAX(CASE WHEN produto = '' AND chave = 'credor.ativo' AND ativo = 1 THEN valor END) AS credor_ativo
            FROM credor_configuracoes
            GROUP BY credor_id");
        foreach ($stmtCfg !== false ? ($stmtCfg->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
            $contagens[(int) ($row['credor_id'] ?? 0)] = $row;
        }

        foreach ($rows as &$row) {
            $id = (int) ($row['id'] ?? 0);
            $cfg = $contagens[$id] ?? [];
            $row['configuracoes_total'] = (int) ($cfg['configuracoes_total'] ?? 0);
            $ativoRaw = $cfg['credor_ativo'] ?? null;
            $row['habilitado'] = $ativoRaw === null ? true : $this->boolFromMixed($ativoRaw);
        }
        unset($row);

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function credorPorId(int $credorId): ?array
    {
        if ($credorId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id, codigo, nome FROM credores WHERE id = :id LIMIT 1');
        $stmt->bindValue('id', $credorId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function criarCredor(string $codigo, string $nome): int
    {
        [$codigo, $nome] = $this->normalizarCredor($codigo, $nome);
        $this->garantirCodigoDisponivel($codigo, null);

        $stmt = $this->pdo->prepare('INSERT INTO credores (codigo, nome) VALUES (:codigo, :nome)');
        $stmt->bindValue('codigo', $codigo);
        $stmt->bindValue('nome', $nome);
        $stmt->execute();
        $id = (int) $this->pdo->lastInsertId();
        if ($id <= 0) {
            throw new InvalidArgumentException('Não foi possível obter o ID do novo credor.');
        }

        if ($this->moduloDisponivel()) {
            $this->salvarConfiguracao($id, '', 'credor.ativo', '1', 'booleano', 'Define se o cadastro do credor está habilitado para uso operacional.', true);
        }
        $this->invalidarCacheCredor($id);

        return $id;
    }

    public function atualizarCredor(int $credorId, string $codigo, string $nome): void
    {
        if ($this->credorPorId($credorId) === null) {
            throw new InvalidArgumentException('Credor não encontrado.');
        }
        [$codigo, $nome] = $this->normalizarCredor($codigo, $nome);
        $this->garantirCodigoDisponivel($codigo, $credorId);

        $stmt = $this->pdo->prepare('UPDATE credores SET codigo = :codigo, nome = :nome WHERE id = :id LIMIT 1');
        $stmt->bindValue('codigo', $codigo);
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('id', $credorId, PDO::PARAM_INT);
        $stmt->execute();
        $this->invalidarCacheCredor($credorId);
    }

    /** @return list<array<string,mixed>> */
    public function listarConfiguracoes(int $credorId): array
    {
        if (!$this->moduloDisponivel() || $credorId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("SELECT *
            FROM credor_configuracoes
            WHERE credor_id = :credor_id
            ORDER BY CASE WHEN produto = '' THEN 0 ELSE 1 END, produto ASC, chave ASC");
        $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function salvarConfiguracao(
        int $credorId,
        ?string $produto,
        string $chave,
        mixed $valor,
        string $tipo = 'texto',
        ?string $descricao = null,
        bool $ativo = true
    ): void {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Configurações de credor indisponíveis. Execute a migration 076_configuracoes_credores.sql.');
        }
        if ($this->credorPorId($credorId) === null) {
            throw new InvalidArgumentException('Credor não encontrado para configuração.');
        }

        $produto = mb_substr(trim((string) $produto), 0, 180);
        $chave = mb_strtolower(trim($chave));
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{1,119}$/', $chave)) {
            throw new InvalidArgumentException('A chave deve usar apenas letras minúsculas, números, ponto, hífen ou sublinhado.');
        }

        $tipo = mb_strtolower(trim($tipo));
        if (!in_array($tipo, ['texto', 'inteiro', 'decimal', 'booleano', 'json', 'url'], true)) {
            throw new InvalidArgumentException('Tipo de configuração inválido.');
        }

        $valorNormalizado = $this->normalizarValor($valor, $tipo);
        $descricao = trim((string) $descricao);
        $descricao = $descricao !== '' ? mb_substr($descricao, 0, 500) : null;

        $stmt = $this->pdo->prepare('INSERT INTO credor_configuracoes (
                credor_id, produto, chave, valor, tipo, descricao, ativo
            ) VALUES (
                :credor_id, :produto, :chave, :valor, :tipo, :descricao, :ativo
            ) ON DUPLICATE KEY UPDATE
                valor = VALUES(valor),
                tipo = VALUES(tipo),
                descricao = VALUES(descricao),
                ativo = VALUES(ativo),
                atualizado_em = NOW()');
        $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
        $stmt->bindValue('produto', $produto);
        $stmt->bindValue('chave', $chave);
        $stmt->bindValue('valor', $valorNormalizado);
        $stmt->bindValue('tipo', $tipo);
        $stmt->bindValue('descricao', $descricao, $descricao === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue('ativo', $ativo ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();
        $this->invalidarCacheCredor($credorId);
    }

    public function removerConfiguracao(int $credorId, int $configuracaoId): void
    {
        if (!$this->moduloDisponivel() || $credorId <= 0 || $configuracaoId <= 0) {
            throw new InvalidArgumentException('Configuração inválida.');
        }
        $stmt = $this->pdo->prepare('DELETE FROM credor_configuracoes WHERE id = :id AND credor_id = :credor_id LIMIT 1');
        $stmt->bindValue('id', $configuracaoId, PDO::PARAM_INT);
        $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
        $stmt->execute();
        $this->invalidarCacheCredor($credorId);
    }

    public function valor(int $credorId, string $chave, ?string $produto = null): ?string
    {
        if (!$this->moduloDisponivel() || $credorId <= 0) {
            return null;
        }
        $produto = mb_substr(trim((string) $produto), 0, 180);
        $chave = mb_strtolower(trim($chave));

        $version = $this->cacheVersion($credorId);
        $cacheKey = 'credor-config:value:' . $credorId . ':' . $version . ':' . sha1($produto) . ':' . sha1($chave);
        $cached = $this->cache->getJson($cacheKey);
        if ($cached !== null && array_key_exists('found', $cached)) {
            return !empty($cached['found']) ? (string) ($cached['value'] ?? '') : null;
        }

        if ($produto !== '') {
            $stmt = $this->pdo->prepare("SELECT valor
                FROM credor_configuracoes
                WHERE credor_id = :credor_id
                  AND chave = :chave
                  AND ativo = 1
                  AND produto IN (:produto, '')
                ORDER BY CASE WHEN produto = :produto_ordem THEN 0 ELSE 1 END
                LIMIT 1");
            $stmt->bindValue('produto', $produto);
            $stmt->bindValue('produto_ordem', $produto);
        } else {
            $stmt = $this->pdo->prepare("SELECT valor
                FROM credor_configuracoes
                WHERE credor_id = :credor_id
                  AND chave = :chave
                  AND ativo = 1
                  AND produto = ''
                LIMIT 1");
        }
        $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
        $stmt->bindValue('chave', $chave);
        $stmt->execute();
        $valor = $stmt->fetchColumn();
        if ($valor === false) {
            $this->cache->setJson($cacheKey, ['found' => false], $this->cacheTtl);
            return null;
        }

        $valor = (string) $valor;
        $this->cache->setJson($cacheKey, ['found' => true, 'value' => $valor], $this->cacheTtl);
        return $valor;
    }

    private function cacheVersion(int $credorId): string
    {
        return $this->cache->get('credor-config:version:' . $credorId) ?? '0';
    }

    private function invalidarCacheCredor(int $credorId): void
    {
        if ($credorId > 0) {
            $this->cache->increment('credor-config:version:' . $credorId);
        }
    }

    public function booleano(int $credorId, string $chave, bool $padrao = false, ?string $produto = null): bool
    {
        $valor = $this->valor($credorId, $chave, $produto);
        return $valor === null ? $padrao : $this->boolFromMixed($valor);
    }

    /** @return array{0:string,1:string} */
    private function normalizarCredor(string $codigo, string $nome): array
    {
        $codigo = strtoupper(trim($codigo));
        $codigo = preg_replace('/[^A-Z0-9_-]+/', '_', $codigo) ?? '';
        $codigo = trim($codigo, '_-');
        $codigo = mb_substr($codigo, 0, 60);
        $nome = mb_substr(trim($nome), 0, 150);
        if ($codigo === '' || $nome === '') {
            throw new InvalidArgumentException('Informe código e nome do credor.');
        }
        return [$codigo, $nome];
    }

    private function garantirCodigoDisponivel(string $codigo, ?int $ignorarId): void
    {
        $sql = 'SELECT id FROM credores WHERE UPPER(codigo) = UPPER(:codigo)';
        if ($ignorarId !== null) {
            $sql .= ' AND id <> :ignorar_id';
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('codigo', $codigo);
        if ($ignorarId !== null) {
            $stmt->bindValue('ignorar_id', $ignorarId, PDO::PARAM_INT);
        }
        $stmt->execute();
        if ($stmt->fetchColumn() !== false) {
            throw new InvalidArgumentException('Já existe um credor com este código.');
        }
    }

    private function normalizarValor(mixed $valor, string $tipo): string
    {
        $texto = trim((string) $valor);
        return match ($tipo) {
            'booleano' => $this->boolFromMixed($texto) ? '1' : '0',
            'inteiro' => is_numeric($texto) ? (string) (int) $texto : throw new InvalidArgumentException('Valor inteiro inválido.'),
            'decimal' => is_numeric(str_replace(',', '.', $texto)) ? (string) (float) str_replace(',', '.', $texto) : throw new InvalidArgumentException('Valor decimal inválido.'),
            'json' => $this->normalizarJson($texto),
            'url' => filter_var($texto, FILTER_VALIDATE_URL) !== false ? $texto : throw new InvalidArgumentException('URL inválida.'),
            default => mb_substr($texto, 0, 20000),
        };
    }

    private function normalizarJson(string $texto): string
    {
        if ($texto === '') {
            return '{}';
        }
        $json = json_decode($texto, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('JSON inválido: ' . json_last_error_msg());
        }
        return json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function boolFromMixed(mixed $valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }
        $normalizado = mb_strtolower(trim((string) $valor));
        return in_array($normalizado, ['1', 'true', 'sim', 'yes', 'on', 'ativo'], true);
    }
}
