<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use PDO;
use Throwable;

final class SegurancaOperacionalRepository
{
    public const PADRAO = [
        'max_tentativas_login' => '5',
        'minutos_bloqueio_login' => '15',
        'min_tamanho_senha' => '8',
        'avisar_admin_padrao' => '1',
        'sessao_expira_horas' => '10',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function moduloDisponivel(): bool
    {
        return $this->tabelaExiste('seguranca_configuracoes');
    }

    /** @return array<string,string> */
    public function configuracoes(): array
    {
        $cfg = self::PADRAO;
        if (!$this->moduloDisponivel()) {
            return $cfg;
        }
        $stmt = $this->pdo->query('SELECT codigo, valor FROM seguranca_configuracoes');
        if ($stmt === false) {
            return $cfg;
        }
        foreach ($stmt->fetchAll() as $row) {
            $codigo = (string) ($row['codigo'] ?? '');
            if ($codigo !== '') {
                $cfg[$codigo] = (string) ($row['valor'] ?? '');
            }
        }
        return $cfg;
    }

    /** @param array<string,mixed> $dados */
    public function salvar(array $dados, ?int $usuarioId): void
    {
        if (!$this->moduloDisponivel()) {
            return;
        }
        $valores = [
            'max_tentativas_login' => (string) max(1, min(20, (int) ($dados['max_tentativas_login'] ?? 5))),
            'minutos_bloqueio_login' => (string) max(1, min(1440, (int) ($dados['minutos_bloqueio_login'] ?? 15))),
            'min_tamanho_senha' => (string) max(6, min(64, (int) ($dados['min_tamanho_senha'] ?? 8))),
            'avisar_admin_padrao' => ((string) ($dados['avisar_admin_padrao'] ?? '') === '1') ? '1' : '0',
            'sessao_expira_horas' => (string) max(1, min(24, (int) ($dados['sessao_expira_horas'] ?? 10))),
        ];
        $descricoes = [
            'max_tentativas_login' => 'Quantidade de tentativas invalidas antes de bloqueio temporario.',
            'minutos_bloqueio_login' => 'Tempo de bloqueio apos excesso de tentativas invalidas.',
            'min_tamanho_senha' => 'Tamanho minimo recomendado para novas senhas.',
            'avisar_admin_padrao' => 'Exibe alerta quando o usuario admin inicial ainda existe com login padrao.',
            'sessao_expira_horas' => 'Duracao maxima absoluta da sessao operacional, em horas. A sessao tambem exige novo login na virada do dia.',
        ];
        $stmt = $this->pdo->prepare('INSERT INTO seguranca_configuracoes (codigo, valor, descricao, atualizado_por_usuario_id)
            VALUES (:codigo, :valor, :descricao, :usuario_id)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), descricao = VALUES(descricao), atualizado_por_usuario_id = VALUES(atualizado_por_usuario_id)');
        foreach ($valores as $codigo => $valor) {
            $stmt->bindValue('codigo', $codigo);
            $stmt->bindValue('valor', $valor);
            $stmt->bindValue('descricao', $descricoes[$codigo] ?? null);
            if ($usuarioId === null) {
                $stmt->bindValue('usuario_id', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
            }
            $stmt->execute();
        }
    }

    /** @return array<string,mixed> */
    public function resumo(): array
    {
        $cfg = $this->configuracoes();
        return [
            'usuarios_ativos' => $this->countWhere("usuarios", "status = 'ativo'"),
            'usuarios_bloqueados' => $this->colunaExiste('usuarios', 'bloqueado_ate') ? $this->countWhere('usuarios', 'bloqueado_ate IS NOT NULL AND bloqueado_ate > NOW()') : 0,
            'usuarios_forcar_troca' => $this->colunaExiste('usuarios', 'forcar_troca_senha') ? $this->countWhere('usuarios', 'forcar_troca_senha = 1') : 0,
            'falhas_login_24h' => $this->tabelaExiste('auditoria_sistema') ? $this->countWhere('auditoria_sistema', "evento = 'auth.login_falha' AND criado_em >= DATE_SUB(NOW(), INTERVAL 24 HOUR)") : 0,
            'sucessos_login_24h' => $this->tabelaExiste('auditoria_sistema') ? $this->countWhere('auditoria_sistema', "evento = 'auth.login_sucesso' AND criado_em >= DATE_SUB(NOW(), INTERVAL 24 HOUR)") : 0,
            'admin_padrao_existe' => $this->adminPadraoExiste(),
            'configuracoes' => $cfg,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function ultimosEventosSeguranca(int $limite = 60): array
    {
        if (!$this->tabelaExiste('auditoria_sistema')) {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT a.*, u.nome AS usuario_nome
            FROM auditoria_sistema a
            LEFT JOIN usuarios u ON u.id = a.usuario_id
            WHERE a.evento LIKE 'auth.%' OR a.evento LIKE 'seguranca.%' OR a.evento LIKE 'usuario.%' OR a.evento LIKE 'perfil.%'
            ORDER BY a.id DESC LIMIT :limite");
        $stmt->bindValue('limite', max(1, min(200, $limite)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function countWhere(string $table, string $where): int
    {
        try {
            if (!$this->tabelaExiste($table)) { return 0; }
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where);
            return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function adminPadraoExiste(): bool
    {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM usuarios WHERE login = 'admin' AND status = 'ativo'");
            return $stmt !== false && (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function tabelaExiste(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
            $stmt->bindValue('table', $table);
            $stmt->execute();
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function colunaExiste(string $table, string $column): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1');
            $stmt->bindValue('table', $table);
            $stmt->bindValue('column', $column);
            $stmt->execute();
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
