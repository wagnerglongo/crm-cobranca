<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use PDO;
use Throwable;

final class AuditoriaSistemaRepository
{
    private ?bool $moduloDisponivel = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function moduloDisponivel(): bool
    {
        if ($this->moduloDisponivel !== null) {
            return $this->moduloDisponivel;
        }

        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'auditoria_sistema'");
            $this->moduloDisponivel = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->moduloDisponivel = false;
        }

        return $this->moduloDisponivel;
    }

    /**
     * @param array<string,mixed>|null $payloadAntes
     * @param array<string,mixed>|null $payloadDepois
     */
    public function registrar(
        ?int $usuarioId,
        string $evento,
        ?string $entidadeTipo = null,
        int|string|null $entidadeId = null,
        ?string $descricao = null,
        ?array $payloadAntes = null,
        ?array $payloadDepois = null
    ): void {
        if (!$this->moduloDisponivel()) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare('INSERT INTO auditoria_sistema (
                    usuario_id,
                    evento,
                    entidade_tipo,
                    entidade_id,
                    descricao,
                    payload_antes,
                    payload_depois,
                    ip,
                    user_agent
                ) VALUES (
                    :usuario_id,
                    :evento,
                    :entidade_tipo,
                    :entidade_id,
                    :descricao,
                    :payload_antes,
                    :payload_depois,
                    :ip,
                    :user_agent
                )');

            $this->bindNullableInt($stmt, 'usuario_id', $usuarioId);
            $stmt->bindValue('evento', $this->limitarTexto($evento, 100));
            $this->bindNullableString($stmt, 'entidade_tipo', $this->limitarTexto($entidadeTipo, 80));
            $this->bindNullableInt($stmt, 'entidade_id', $this->normalizarEntidadeId($entidadeId));
            $this->bindNullableString($stmt, 'descricao', $this->limitarTexto($descricao, 2000));
            $this->bindNullableString($stmt, 'payload_antes', $this->jsonSeguro($payloadAntes));
            $this->bindNullableString($stmt, 'payload_depois', $this->jsonSeguro($payloadDepois));
            $this->bindNullableString($stmt, 'ip', $this->limitarTexto($this->ipOrigem(), 64));
            $this->bindNullableString($stmt, 'user_agent', $this->limitarTexto($_SERVER['HTTP_USER_AGENT'] ?? null, 255));
            $stmt->execute();
        } catch (Throwable) {
            // Auditoria geral nao deve interromper operacao critica do CRM.
            return;
        }
    }

    /** @return array{total:int,total_hoje:int,usuarios:int,eventos:int} */
    public function resumoGeral(): array
    {
        if (!$this->moduloDisponivel()) {
            return ['total' => 0, 'total_hoje' => 0, 'usuarios' => 0, 'eventos' => 0];
        }

        return [
            'total' => $this->contar('SELECT COUNT(*) FROM auditoria_sistema'),
            'total_hoje' => $this->contar('SELECT COUNT(*) FROM auditoria_sistema WHERE criado_em >= CURDATE()'),
            'usuarios' => $this->contar('SELECT COUNT(DISTINCT usuario_id) FROM auditoria_sistema WHERE usuario_id IS NOT NULL'),
            'eventos' => $this->contar('SELECT COUNT(DISTINCT evento) FROM auditoria_sistema'),
        ];
    }

    /**
     * @param array<string,string> $filtros
     * @return list<array<string,mixed>>
     */
    public function listar(array $filtros, int $limite = 100): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        [$where, $params] = $this->montarFiltros($filtros);
        $limite = max(10, min(300, $limite));

        $sql = 'SELECT a.id, a.usuario_id, a.evento, a.entidade_tipo, a.entidade_id, a.descricao,
                       a.payload_antes, a.payload_depois, a.ip, a.user_agent, a.criado_em,
                       u.nome AS usuario_nome, u.login AS usuario_login
                FROM auditoria_sistema a
                LEFT JOIN usuarios u ON u.id = a.usuario_id';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY a.id DESC LIMIT :limite';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @param array<string,string> $filtros
     * @return array{total:int}
     */
    public function resumoFiltros(array $filtros): array
    {
        if (!$this->moduloDisponivel()) {
            return ['total' => 0];
        }

        [$where, $params] = $this->montarFiltros($filtros);
        $sql = 'SELECT COUNT(*) FROM auditoria_sistema a LEFT JOIN usuarios u ON u.id = a.usuario_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return ['total' => (int) $stmt->fetchColumn()];
    }

    /** @return list<array{evento:string,total:int}> */
    public function eventosDisponiveis(): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $stmt = $this->pdo->query('SELECT evento, COUNT(*) AS total
            FROM auditoria_sistema
            GROUP BY evento
            ORDER BY evento ASC');

        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /** @return list<array{entidade_tipo:string,total:int}> */
    public function entidadesDisponiveis(): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $stmt = $this->pdo->query('SELECT entidade_tipo, COUNT(*) AS total
            FROM auditoria_sistema
            WHERE entidade_tipo IS NOT NULL AND entidade_tipo <> \'\'
            GROUP BY entidade_tipo
            ORDER BY entidade_tipo ASC');

        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /** @return list<array{id:int,nome:string,login:string,total:int}> */
    public function usuariosDisponiveis(): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $stmt = $this->pdo->query('SELECT u.id, u.nome, u.login, COUNT(*) AS total
            FROM auditoria_sistema a
            INNER JOIN usuarios u ON u.id = a.usuario_id
            GROUP BY u.id, u.nome, u.login
            ORDER BY u.nome ASC, u.login ASC');

        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /** @return array{0:list<string>,1:array<string,string>} */
    private function montarFiltros(array $filtros): array
    {
        $where = [];
        $params = [];

        $usuarioId = trim((string) ($filtros['usuario_id'] ?? ''));
        if ($usuarioId !== '' && ctype_digit($usuarioId) && (int) $usuarioId > 0) {
            $where[] = 'a.usuario_id = :usuario_id';
            $params['usuario_id'] = $usuarioId;
        }

        $evento = trim((string) ($filtros['evento'] ?? ''));
        if ($evento !== '') {
            $where[] = 'a.evento = :evento';
            $params['evento'] = mb_substr($evento, 0, 100);
        }

        $entidadeTipo = trim((string) ($filtros['entidade_tipo'] ?? ''));
        if ($entidadeTipo !== '') {
            $where[] = 'a.entidade_tipo = :entidade_tipo';
            $params['entidade_tipo'] = mb_substr($entidadeTipo, 0, 80);
        }

        $entidadeId = trim((string) ($filtros['entidade_id'] ?? ''));
        if ($entidadeId !== '' && ctype_digit($entidadeId) && (int) $entidadeId > 0) {
            $where[] = 'a.entidade_id = :entidade_id';
            $params['entidade_id'] = $entidadeId;
        }

        $ip = trim((string) ($filtros['ip'] ?? ''));
        if ($ip !== '') {
            $where[] = 'a.ip LIKE :ip';
            $params['ip'] = '%' . mb_substr($ip, 0, 64) . '%';
        }

        $dataInicio = $this->normalizarData($filtros['data_inicio'] ?? '');
        if ($dataInicio !== null) {
            $where[] = 'a.criado_em >= :data_inicio';
            $params['data_inicio'] = $dataInicio . ' 00:00:00';
        }

        $dataFim = $this->normalizarData($filtros['data_fim'] ?? '');
        if ($dataFim !== null) {
            $where[] = 'a.criado_em <= :data_fim';
            $params['data_fim'] = $dataFim . ' 23:59:59';
        }

        $q = trim((string) ($filtros['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(a.evento LIKE :q OR a.descricao LIKE :q OR a.entidade_tipo LIKE :q OR u.nome LIKE :q OR u.login LIKE :q)';
            $params['q'] = '%' . mb_substr($q, 0, 120) . '%';
        }

        return [$where, $params];
    }

    private function contar(string $sql): int
    {
        try {
            $stmt = $this->pdo->query($sql);
            return $stmt === false ? 0 : (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function normalizarData(mixed $value): ?string
    {
        $data = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            return null;
        }

        return $data;
    }

    private function normalizarEntidadeId(int|string|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return ctype_digit($value) ? (int) $value : null;
    }

    /** @param array<string,mixed>|null $payload */
    private function jsonSeguro(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $payload = $this->removerDadosSensiveis($payload);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function removerDadosSensiveis(array $payload): array
    {
        $bloqueados = [
            'senha', 'password', 'senha_hash', 'token', 'token_hash', 'access_token', 'refresh_token',
            'authorization', 'bearer', 'api_key', 'x_api_key', 'client_secret', 'csrf_token', 'operacao_csrf',
        ];
        $limpo = [];

        foreach ($payload as $key => $value) {
            $keyString = (string) $key;
            if (in_array(mb_strtolower($keyString), $bloqueados, true)) {
                continue;
            }

            if (is_array($value)) {
                /** @var array<string,mixed> $value */
                $limpo[$keyString] = $this->removerDadosSensiveis($value);
                continue;
            }

            $limpo[$keyString] = $value;
        }

        return $limpo;
    }

    private function ipOrigem(): ?string
    {
        // 2026-10-06: antes confiava no X-Forwarded-For de qualquer origem
        // (IP da auditoria forjavel em acesso direto a :8080).
        $ip = \CRM\Infrastructure\Http\ProxyConfiavel::ipCliente($_SERVER);
        return $ip === '0.0.0.0' ? null : $ip;
    }

    private function limitarTexto(mixed $value, int $limite): ?string
    {
        if ($value === null) {
            return null;
        }

        $texto = trim((string) $value);
        if ($texto === '') {
            return null;
        }

        return mb_substr($texto, 0, $limite);
    }

    private function bindNullableInt(\PDOStatement $stmt, string $key, ?int $value): void
    {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }

    private function bindNullableString(\PDOStatement $stmt, string $key, ?string $value): void
    {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($key, $value);
    }
}
