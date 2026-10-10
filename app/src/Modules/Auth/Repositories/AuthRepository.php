<?php

declare(strict_types=1);

namespace CRM\Modules\Auth\Repositories;

use InvalidArgumentException;
use PDO;
use Throwable;

final class AuthRepository
{
    private ?bool $moduloDisponivel = null;

    /** @var array<int,array<string,mixed>|null> */
    private array $usuarioSessaoCache = [];

    /** @var array<int,list<string>> */
    private array $permissoesCache = [];

    /** @var array<int,array{todos:bool,credor_ids:list<int>,carteira_ids:list<int>}> */
    private array $escopoCache = [];

    /** @var array<string,array{todos:bool,credor_ids:list<int>,carteira_ids:list<int>}> */
    private array $escopoAcaoCache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function moduloDisponivel(): bool
    {
        if ($this->moduloDisponivel !== null) {
            return $this->moduloDisponivel;
        }

        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'usuarios'");
            $this->moduloDisponivel = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->moduloDisponivel = false;
        }

        return $this->moduloDisponivel;
    }

    /** @return array<string,mixed> */
    public function autenticar(string $login, string $senha, bool $registrarSucesso = true): array
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Módulo de usuários indisponível. Execute a migration 019.');
        }

        $login = trim(mb_strtolower($login));
        if ($login === '' || $senha === '') {
            throw new InvalidArgumentException('Informe usuário e senha.');
        }

        $stmt = $this->pdo->prepare('SELECT u.*, p.codigo AS perfil_codigo, p.nome AS perfil_nome
            FROM usuarios u
            LEFT JOIN perfis_acesso p ON p.id = u.perfil_id
            WHERE (LOWER(u.login) = :login_login OR LOWER(u.email) = :login_email)
            LIMIT 1');
        $stmt->execute([
            'login_login' => $login,
            'login_email' => $login,
        ]);
        $user = $stmt->fetch();

        if ($user !== false && $this->usuarioBloqueadoTemporariamente($user)) {
            throw new InvalidArgumentException('Usuário temporariamente bloqueado por tentativas inválidas. Tente novamente mais tarde.');
        }

        if ($user === false || !password_verify($senha, (string) $user['senha_hash'])) {
            if ($user !== false) {
                $this->registrarLoginFalha((int) $user['id']);
            }
            throw new InvalidArgumentException('Usuário ou senha inválidos.');
        }

        if ((string) $user['status'] !== 'ativo') {
            throw new InvalidArgumentException('Usuário inativo ou bloqueado.');
        }

        // $registrarSucesso=false: verificacao de credencial sem abrir sessao (ex.: porteiro
        // do Dashboards, chamado a cada ~60s por usuario) - nao atualiza ultimo_login_em.
        // Falhas continuam contando pro bloqueio temporario.
        if ($registrarSucesso) {
            $this->registrarLogin((int) $user['id']);
        }

        return $this->buscarUsuarioSessao((int) $user['id']) ?? $user;
    }

    /** @return array<string,mixed>|null */
    public function buscarUsuarioSessao(int $usuarioId): ?array
    {
        if (!$this->moduloDisponivel()) {
            return null;
        }

        if (array_key_exists($usuarioId, $this->usuarioSessaoCache)) {
            return $this->usuarioSessaoCache[$usuarioId];
        }

        $stmt = $this->pdo->prepare('SELECT u.id, u.nome, u.login, u.email, u.status, u.admin_total, u.escopo_todos_credores,
                u.forcar_troca_senha, u.ultimo_login_em, u.tentativas_login_falha, u.bloqueado_ate, u.senha_alterada_em, p.codigo AS perfil_codigo, p.nome AS perfil_nome, p.id AS perfil_id
            FROM usuarios u
            LEFT JOIN perfis_acesso p ON p.id = u.perfil_id
            WHERE u.id = :id
            LIMIT 1');
        $stmt->execute(['id' => $usuarioId]);
        $row = $stmt->fetch();

        $this->usuarioSessaoCache[$usuarioId] = $row === false ? null : $row;
        return $this->usuarioSessaoCache[$usuarioId];
    }

    /**
     * O próprio usuário define a senha (requisito de 2026-10-06: o admin
     * cria com uma senha padrão e marca "trocar no próximo acesso"; o
     * operador define a dele, que ninguém mais conhece - bom também pra LGPD).
     * Zera forcar_troca_senha e grava senha_alterada_em (os tokens de API
     * criados antes deixam de valer - ApiTokenService).
     */
    public function alterarSenhaPropria(int $usuarioId, string $senhaAtual, string $novaSenha, string $confirmacao, int $minTamanho): void
    {
        $stmt = $this->pdo->prepare('SELECT id, senha_hash FROM usuarios WHERE id = :id AND status = \'ativo\' LIMIT 1');
        $stmt->execute(['id' => $usuarioId]);
        $user = $stmt->fetch();
        if ($user === false) {
            throw new InvalidArgumentException('Usuário não encontrado ou inativo.');
        }
        if (!password_verify($senhaAtual, (string) $user['senha_hash'])) {
            throw new InvalidArgumentException('A senha atual não confere.');
        }
        if (mb_strlen($novaSenha) < max(1, $minTamanho)) {
            throw new InvalidArgumentException('A nova senha deve ter pelo menos ' . max(1, $minTamanho) . ' caracteres.');
        }
        if ($novaSenha !== $confirmacao) {
            throw new InvalidArgumentException('A confirmação não é igual à nova senha.');
        }
        if (password_verify($novaSenha, (string) $user['senha_hash'])) {
            throw new InvalidArgumentException('A nova senha precisa ser diferente da atual.');
        }

        $update = $this->pdo->prepare('UPDATE usuarios
            SET senha_hash = :senha_hash, forcar_troca_senha = 0, senha_alterada_em = NOW()
            WHERE id = :id LIMIT 1');
        $update->execute(['senha_hash' => password_hash($novaSenha, PASSWORD_DEFAULT), 'id' => $usuarioId]);
        unset($this->usuarioSessaoCache[$usuarioId]);

        $this->registrarAuditoria($usuarioId, $usuarioId, 'usuario.senha_alterada', 'Senha alterada pelo próprio usuário.');
    }

    /** @return list<string> */
    public function permissoesUsuario(int $usuarioId): array
    {
        if (array_key_exists($usuarioId, $this->permissoesCache)) {
            return $this->permissoesCache[$usuarioId];
        }

        $usuario = $this->buscarUsuarioSessao($usuarioId);
        if ($usuario === null || (string) $usuario['status'] !== 'ativo') {
            $this->permissoesCache[$usuarioId] = [];
            return [];
        }

        if ((int) $usuario['admin_total'] === 1) {
            $this->permissoesCache[$usuarioId] = ['*'];
            return $this->permissoesCache[$usuarioId];
        }

        $stmt = $this->pdo->prepare('SELECT DISTINCT pe.codigo
            FROM permissoes pe
            INNER JOIN perfil_permissoes pp ON pp.permissao_id = pe.id
            INNER JOIN perfis_acesso pa ON pa.id = pp.perfil_id AND pa.ativo = 1
            INNER JOIN usuarios u ON u.perfil_id = pp.perfil_id
            WHERE u.id = :usuario_id
              AND pe.ativo = 1
            ORDER BY pe.codigo ASC');
        $stmt->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
        $stmt->execute();

        $permissoes = [];
        foreach ($stmt->fetchAll() as $row) {
            $permissoes[] = (string) $row['codigo'];
        }

        $this->permissoesCache[$usuarioId] = $permissoes;
        return $this->permissoesCache[$usuarioId];
    }

    public function usuarioTemPermissao(int $usuarioId, string $permissao): bool
    {
        $permissao = trim($permissao);
        if ($permissao === '') {
            return true;
        }

        $permissoes = $this->permissoesUsuario($usuarioId);
        return in_array('*', $permissoes, true) || in_array($permissao, $permissoes, true);
    }

    /** @return array{todos:bool,credor_ids:list<int>,carteira_ids:list<int>} */
    public function escopoUsuario(int $usuarioId): array
    {
        if (array_key_exists($usuarioId, $this->escopoCache)) {
            return $this->escopoCache[$usuarioId];
        }

        $usuario = $this->buscarUsuarioSessao($usuarioId);
        if ($usuario === null) {
            $this->escopoCache[$usuarioId] = ['todos' => false, 'credor_ids' => [], 'carteira_ids' => []];
            return $this->escopoCache[$usuarioId];
        }

        if ((int) $usuario['admin_total'] === 1 || (int) $usuario['escopo_todos_credores'] === 1) {
            $this->escopoCache[$usuarioId] = ['todos' => true, 'credor_ids' => [], 'carteira_ids' => []];
            return $this->escopoCache[$usuarioId];
        }

        $stmtCredores = $this->pdo->prepare('SELECT credor_id
            FROM usuario_credores
            WHERE usuario_id = :id
              AND (pode_consultar = 1 OR pode_operar = 1 OR pode_fechar_acordo = 1 OR pode_criar_campanha = 1)');
        $stmtCredores->execute(['id' => $usuarioId]);
        $credorIds = array_map('intval', array_column($stmtCredores->fetchAll(), 'credor_id'));

        $stmtCarteiras = $this->pdo->prepare('SELECT carteira_id FROM usuario_carteiras WHERE usuario_id = :id AND pode_consultar = 1');
        $stmtCarteiras->execute(['id' => $usuarioId]);
        $carteiraIds = array_map('intval', array_column($stmtCarteiras->fetchAll(), 'carteira_id'));

        $this->escopoCache[$usuarioId] = ['todos' => false, 'credor_ids' => $credorIds, 'carteira_ids' => $carteiraIds];
        return $this->escopoCache[$usuarioId];
    }

    /** @return array{todos:bool,credor_ids:list<int>,carteira_ids:list<int>} */
    public function escopoUsuarioPorAcao(int $usuarioId, string $acao = 'consultar'): array
    {
        // A ação específica é decidida pelas permissões do perfil/grupo.
        // O escopo do usuário agora define somente quais credores/carteiras ele pode enxergar/trabalhar.
        $cacheKey = $usuarioId . ':escopo_unificado';
        if (array_key_exists($cacheKey, $this->escopoAcaoCache)) {
            return $this->escopoAcaoCache[$cacheKey];
        }

        $this->escopoAcaoCache[$cacheKey] = $this->escopoUsuario($usuarioId);
        return $this->escopoAcaoCache[$cacheKey];
    }

    public function usuarioPodeContrato(int $usuarioId, int $contratoId, string $acao = 'consultar'): bool
    {
        if ($contratoId <= 0) {
            return false;
        }

        $escopo = $this->escopoUsuarioPorAcao($usuarioId, $acao);
        if ($escopo['todos']) {
            return true;
        }

        $credorIds = array_values(array_filter(array_map('intval', $escopo['credor_ids'])));
        $carteiraIds = array_values(array_filter(array_map('intval', $escopo['carteira_ids'])));
        if ($credorIds === [] && $carteiraIds === []) {
            return false;
        }

        $where = ['id = :contrato_id'];
        $params = ['contrato_id' => $contratoId];
        $scope = [];
        foreach ($credorIds as $i => $id) {
            $key = 'credor_' . $i;
            $scope[] = ':' . $key;
            $params[$key] = $id;
        }
        if ($scope !== []) {
            $whereScope = ['credor_id IN (' . implode(',', $scope) . ')'];
        } else {
            $whereScope = [];
        }

        $scopeCarteira = [];
        foreach ($carteiraIds as $i => $id) {
            $key = 'carteira_' . $i;
            $scopeCarteira[] = ':' . $key;
            $params[$key] = $id;
        }
        if ($scopeCarteira !== []) {
            $whereScope[] = 'carteira_id IN (' . implode(',', $scopeCarteira) . ')';
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM contratos WHERE ' . implode(' AND ', $where) . ' AND (' . implode(' OR ', $whereScope) . ') LIMIT 1');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    public function usuarioPodePessoa(int $usuarioId, int $pessoaId, string $acao = 'consultar'): bool
    {
        if ($pessoaId <= 0) {
            return false;
        }

        $escopo = $this->escopoUsuarioPorAcao($usuarioId, $acao);
        if ($escopo['todos']) {
            return true;
        }

        $credorIds = array_values(array_filter(array_map('intval', $escopo['credor_ids'])));
        $carteiraIds = array_values(array_filter(array_map('intval', $escopo['carteira_ids'])));
        if ($credorIds === [] && $carteiraIds === []) {
            return false;
        }

        $params = ['pessoa_id' => $pessoaId];
        $whereScope = [];
        $scopeCredor = [];
        foreach ($credorIds as $i => $id) {
            $key = 'credor_' . $i;
            $scopeCredor[] = ':' . $key;
            $params[$key] = $id;
        }
        if ($scopeCredor !== []) {
            $whereScope[] = 'credor_id IN (' . implode(',', $scopeCredor) . ')';
        }

        $scopeCarteira = [];
        foreach ($carteiraIds as $i => $id) {
            $key = 'carteira_' . $i;
            $scopeCarteira[] = ':' . $key;
            $params[$key] = $id;
        }
        if ($scopeCarteira !== []) {
            $whereScope[] = 'carteira_id IN (' . implode(',', $scopeCarteira) . ')';
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM contratos WHERE pessoa_id = :pessoa_id AND (' . implode(' OR ', $whereScope) . ') LIMIT 1');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    public function usuarioPodeAcordo(int $usuarioId, int $acordoId, string $acao = 'consultar'): bool
    {
        if ($acordoId <= 0) {
            return false;
        }

        $escopo = $this->escopoUsuarioPorAcao($usuarioId, $acao);
        if ($escopo['todos']) {
            return true;
        }

        $credorIds = array_values(array_filter(array_map('intval', $escopo['credor_ids'])));
        $carteiraIds = array_values(array_filter(array_map('intval', $escopo['carteira_ids'])));
        if ($credorIds === [] && $carteiraIds === []) {
            return false;
        }

        $params = ['acordo_id' => $acordoId];
        $whereScope = [];
        $scopeCredor = [];
        foreach ($credorIds as $i => $id) {
            $key = 'credor_' . $i;
            $scopeCredor[] = ':' . $key;
            $params[$key] = $id;
        }
        if ($scopeCredor !== []) {
            $whereScope[] = 'COALESCE(ao.credor_id, c.credor_id) IN (' . implode(',', $scopeCredor) . ')';
        }

        $scopeCarteira = [];
        foreach ($carteiraIds as $i => $id) {
            $key = 'carteira_' . $i;
            $scopeCarteira[] = ':' . $key;
            $params[$key] = $id;
        }
        if ($scopeCarteira !== []) {
            $whereScope[] = 'c.carteira_id IN (' . implode(',', $scopeCarteira) . ')';
        }

        $stmt = $this->pdo->prepare('SELECT 1
            FROM acordos_operacionais ao
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            WHERE ao.id = :acordo_id AND (' . implode(' OR ', $whereScope) . ')
            LIMIT 1');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    public function listarUsuarios(): array
    {
        try {
            $stmt = $this->pdo->query('SELECT u.id, u.nome, u.login, u.email, u.status, u.admin_total, u.escopo_todos_credores,
                    u.ultimo_login_em, u.criado_em, p.nome AS perfil_nome, p.codigo AS perfil_codigo,
                    (SELECT GROUP_CONCAT(cr.nome ORDER BY cr.nome SEPARATOR ', ')
                        FROM usuario_credores uc
                        INNER JOIN credores cr ON cr.id = uc.credor_id
                        WHERE uc.usuario_id = u.id
                          AND (uc.pode_consultar = 1 OR uc.pode_operar = 1 OR uc.pode_fechar_acordo = 1 OR uc.pode_criar_campanha = 1)
                    ) AS credores_escopo
                FROM usuarios u
                LEFT JOIN perfis_acesso p ON p.id = u.perfil_id
                ORDER BY u.nome ASC, u.id ASC');

            return $stmt !== false ? $stmt->fetchAll() : [];
        } catch (Throwable) {
            $stmt = $this->pdo->query('SELECT u.id, u.nome, u.login, u.email, u.status, u.admin_total, u.escopo_todos_credores,
                    u.ultimo_login_em, u.criado_em, p.nome AS perfil_nome, p.codigo AS perfil_codigo,
                    NULL AS credores_escopo
                FROM usuarios u
                LEFT JOIN perfis_acesso p ON p.id = u.perfil_id
                ORDER BY u.nome ASC, u.id ASC');

            return $stmt !== false ? $stmt->fetchAll() : [];
        }
    }

    /** @return array<string,mixed>|null */
    public function buscarUsuario(int $usuarioId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM usuarios WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $usuarioId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function listarPerfis(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM perfis_acesso WHERE ativo = 1 ORDER BY nivel DESC, nome ASC');
        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /** @return list<array<string,mixed>> */
    public function listarPerfisTodos(): array
    {
        $stmt = $this->pdo->query('SELECT
                pa.*,
                (SELECT COUNT(*) FROM usuarios u WHERE u.perfil_id = pa.id) AS usuarios_vinculados,
                (SELECT COUNT(*) FROM perfil_permissoes pp WHERE pp.perfil_id = pa.id) AS total_permissoes
            FROM perfis_acesso pa
            ORDER BY pa.ativo DESC, pa.nivel DESC, pa.nome ASC');

        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /** @return array<string,mixed>|null */
    public function buscarPerfil(int $perfilId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM perfis_acesso WHERE id = :id LIMIT 1');
        $stmt->bindValue('id', $perfilId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<string> */
    public function permissoesPerfil(int $perfilId): array
    {
        $stmt = $this->pdo->prepare('SELECT pe.codigo
            FROM perfil_permissoes pp
            INNER JOIN permissoes pe ON pe.id = pp.permissao_id
            WHERE pp.perfil_id = :perfil_id AND pe.ativo = 1
            ORDER BY pe.grupo ASC, pe.nome ASC');
        $stmt->bindValue('perfil_id', $perfilId, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('strval', array_column($stmt->fetchAll(), 'codigo'));
    }

    /** @return list<array<string,mixed>> */
    public function listarPermissoes(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM permissoes WHERE ativo = 1 ORDER BY grupo ASC, nome ASC');
        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /** @return list<array<string,mixed>> */
    public function listarCredores(): array
    {
        $stmt = $this->pdo->query('SELECT id, codigo, nome FROM credores WHERE ativo = 1 ORDER BY nome ASC');
        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /** @return list<array<string,mixed>> */
    public function listarCarteiras(): array
    {
        $stmt = $this->pdo->query('SELECT car.id, car.codigo, car.nome, car.credor_id, cr.nome AS credor_nome
            FROM carteiras car
            INNER JOIN credores cr ON cr.id = car.credor_id
            WHERE car.ativo = 1 AND cr.ativo = 1
            ORDER BY cr.nome ASC, car.nome ASC');
        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /** @return array<int,array{selecionado:int}> */
    public function credoresUsuario(int $usuarioId): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM usuario_credores WHERE usuario_id = :id');
            $stmt->execute(['id' => $usuarioId]);
            $ret = [];
            foreach ($stmt->fetchAll() as $row) {
                $selecionado = ((int) ($row['pode_consultar'] ?? 1) === 1
                    || (int) ($row['pode_operar'] ?? 0) === 1
                    || (int) ($row['pode_fechar_acordo'] ?? 0) === 1
                    || (int) ($row['pode_criar_campanha'] ?? 0) === 1) ? 1 : 0;
                $ret[(int) $row['credor_id']] = ['selecionado' => $selecionado];
            }
            return $ret;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    public function auditoriaUsuarios(int $limite = 80): array
    {
        try {
            $limite = max(1, min($limite, 200));
            $stmt = $this->pdo->prepare('SELECT a.*, u.nome AS ator_nome, alvo.nome AS alvo_nome
                FROM usuario_auditoria a
                LEFT JOIN usuarios u ON u.id = a.ator_usuario_id
                LEFT JOIN usuarios alvo ON alvo.id = a.usuario_id
                ORDER BY a.criado_em DESC, a.id DESC
                LIMIT :limite');
            $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $dados */
    public function salvarUsuario(array $dados, ?int $atorUsuarioId = null): int
    {
        $id = $this->normalizarId($dados['id'] ?? null);
        $nome = $this->normalizarTexto($dados['nome'] ?? null, 160, true, 'nome');
        $login = $this->normalizarLogin($dados['login'] ?? null);
        $email = $this->normalizarEmail($dados['email'] ?? null);
        $perfilId = $this->normalizarId($dados['perfil_id'] ?? null);
        $status = $this->normalizarStatus($dados['status'] ?? 'ativo');
        $adminTotal = (string) ($dados['admin_total'] ?? '0') === '1' ? 1 : 0;
        $escopoTodos = (string) ($dados['escopo_todos_credores'] ?? '0') === '1' ? 1 : 0;
        $senha = (string) ($dados['senha'] ?? '');

        if ($perfilId !== null && !$this->perfilExiste($perfilId)) {
            throw new InvalidArgumentException('Perfil de acesso inválido.');
        }

        if ($id === null && trim($senha) === '') {
            throw new InvalidArgumentException('Informe uma senha inicial para novo usuário.');
        }

        $this->pdo->beginTransaction();
        try {
            if ($id === null) {
                $stmt = $this->pdo->prepare('INSERT INTO usuarios (
                        nome, login, email, senha_hash, perfil_id, status, admin_total, escopo_todos_credores, criado_por_usuario_id
                    ) VALUES (
                        :nome, :login, :email, :senha_hash, :perfil_id, :status, :admin_total, :escopo_todos_credores, :criado_por_usuario_id
                    )');
                $stmt->bindValue('senha_hash', password_hash($senha, PASSWORD_DEFAULT));
                $evento = 'usuario_criado';
            } else {
                $sqlSenha = trim($senha) !== '' ? ', senha_hash = :senha_hash' : '';
                $stmt = $this->pdo->prepare('UPDATE usuarios SET
                        nome = :nome,
                        login = :login,
                        email = :email,
                        perfil_id = :perfil_id,
                        status = :status,
                        admin_total = :admin_total,
                        escopo_todos_credores = :escopo_todos_credores,
                        atualizado_por_usuario_id = :criado_por_usuario_id' . $sqlSenha . '
                    WHERE id = :id');
                $stmt->bindValue('id', $id, PDO::PARAM_INT);
                if (trim($senha) !== '') {
                    $stmt->bindValue('senha_hash', password_hash($senha, PASSWORD_DEFAULT));
                }
                $evento = 'usuario_atualizado';
            }

            $stmt->bindValue('nome', $nome);
            $stmt->bindValue('login', $login);
            $this->bindNullableString($stmt, 'email', $email);
            $this->bindNullableInt($stmt, 'perfil_id', $perfilId);
            $stmt->bindValue('status', $status);
            $stmt->bindValue('admin_total', $adminTotal, PDO::PARAM_INT);
            $stmt->bindValue('escopo_todos_credores', $escopoTodos, PDO::PARAM_INT);
            $this->bindNullableInt($stmt, 'criado_por_usuario_id', $atorUsuarioId);
            $stmt->execute();

            $usuarioId = $id ?? (int) $this->pdo->lastInsertId();
            // Permissões são administradas no perfil/grupo. Exceções diretas por usuário ficam desabilitadas nesta etapa.
            $this->pdo->prepare('DELETE FROM usuario_permissoes WHERE usuario_id = :id')->execute(['id' => $usuarioId]);
            $this->salvarEscopoCredores($usuarioId, $dados['credor_ids'] ?? []);
            $this->registrarAuditoria($usuarioId, $atorUsuarioId, $evento, 'Usuário salvo pela tela de administração.', [
                'login' => $login,
                'perfil_id' => $perfilId,
                'status' => $status,
                'admin_total' => $adminTotal,
                'escopo_todos_credores' => $escopoTodos,
                'credor_ids' => is_array($dados['credor_ids'] ?? null) ? array_values(array_map('intval', $dados['credor_ids'])) : [],
            ]);
            $this->pdo->commit();
            return $usuarioId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $dados */
    public function salvarPerfil(array $dados, ?int $atorUsuarioId = null): int
    {
        $id = $this->normalizarId($dados['id'] ?? null);
        $codigo = $this->normalizarCodigoPerfil($dados['codigo'] ?? null);
        $nome = $this->normalizarTexto($dados['nome'] ?? null, 120, true, 'nome do perfil');
        $nivel = $this->normalizarNivel($dados['nivel'] ?? 0);
        $descricao = $this->normalizarTexto($dados['descricao'] ?? null, 500, false, 'descrição');
        $ativo = (string) ($dados['ativo'] ?? '1') === '1' ? 1 : 0;
        $permissoes = is_array($dados['permissoes'] ?? null) ? array_values(array_filter(array_map('strval', $dados['permissoes']))) : [];

        $perfilAtual = $id !== null ? $this->buscarPerfil($id) : null;
        if ($id !== null && $perfilAtual === null) {
            throw new InvalidArgumentException('Perfil não encontrado.');
        }

        if ($perfilAtual !== null && (string) $perfilAtual['codigo'] === 'admin') {
            $codigo = 'admin';
            $ativo = 1;
            $nivel = max($nivel, 100);
        }

        $this->pdo->beginTransaction();
        try {
            if ($id === null) {
                $stmt = $this->pdo->prepare('INSERT INTO perfis_acesso (codigo, nome, nivel, descricao, ativo)
                    VALUES (:codigo, :nome, :nivel, :descricao, :ativo)');
                $evento = 'perfil_criado';
            } else {
                $stmt = $this->pdo->prepare('UPDATE perfis_acesso SET
                        codigo = :codigo,
                        nome = :nome,
                        nivel = :nivel,
                        descricao = :descricao,
                        ativo = :ativo
                    WHERE id = :id');
                $stmt->bindValue('id', $id, PDO::PARAM_INT);
                $evento = 'perfil_atualizado';
            }

            $stmt->bindValue('codigo', $codigo);
            $stmt->bindValue('nome', $nome);
            $stmt->bindValue('nivel', $nivel, PDO::PARAM_INT);
            $this->bindNullableString($stmt, 'descricao', $descricao);
            $stmt->bindValue('ativo', $ativo, PDO::PARAM_INT);
            $stmt->execute();

            $perfilId = $id ?? (int) $this->pdo->lastInsertId();
            $this->salvarPermissoesPerfil($perfilId, $permissoes);
            $this->registrarAuditoria(null, $atorUsuarioId, $evento, 'Perfil/grupo salvo pela tela de administração.', [
                'perfil_id' => $perfilId,
                'codigo' => $codigo,
                'nome' => $nome,
                'nivel' => $nivel,
                'ativo' => $ativo,
                'permissoes' => $permissoes,
            ]);
            $this->pdo->commit();
            return $perfilId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function desativarPerfil(int $perfilId, ?int $atorUsuarioId = null): void
    {
        $perfil = $this->buscarPerfil($perfilId);
        if ($perfil === null) {
            throw new InvalidArgumentException('Perfil não encontrado.');
        }
        if ((string) $perfil['codigo'] === 'admin') {
            throw new InvalidArgumentException('O perfil administrador não pode ser desativado.');
        }

        $stmt = $this->pdo->prepare('UPDATE perfis_acesso SET ativo = 0 WHERE id = :id LIMIT 1');
        $stmt->bindValue('id', $perfilId, PDO::PARAM_INT);
        $stmt->execute();
        $this->registrarAuditoria(null, $atorUsuarioId, 'perfil_desativado', 'Perfil/grupo desativado pela tela de administração.', [
            'perfil_id' => $perfilId,
            'codigo' => (string) $perfil['codigo'],
        ]);
    }

    /** @param list<string> $permissoes */
    private function salvarPermissoesPerfil(int $perfilId, array $permissoes): void
    {
        $permissoes = array_values(array_unique($permissoes));
        if ($permissoes === []) {
            $this->pdo->prepare('DELETE FROM perfil_permissoes WHERE perfil_id = :perfil_id')->execute(['perfil_id' => $perfilId]);
            return;
        }

        $stmtPerm = $this->pdo->prepare('SELECT id FROM permissoes WHERE codigo = :codigo AND ativo = 1 LIMIT 1');
        $idsPermissoes = [];
        $naoResolvidas = [];
        foreach ($permissoes as $codigo) {
            $stmtPerm->execute(['codigo' => $codigo]);
            $permissaoId = $stmtPerm->fetchColumn();
            if ($permissaoId === false) {
                $naoResolvidas[] = $codigo;
                continue;
            }
            $idsPermissoes[$codigo] = (int) $permissaoId;
        }
        if ($naoResolvidas !== []) {
            throw new InvalidArgumentException(
                'Permissões não encontradas ou inativas: ' . implode(', ', $naoResolvidas) . '. Nenhuma alteração foi feita.'
            );
        }

        $this->pdo->prepare('DELETE FROM perfil_permissoes WHERE perfil_id = :perfil_id')->execute(['perfil_id' => $perfilId]);
        $stmtIns = $this->pdo->prepare('INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id) VALUES (:perfil_id, :permissao_id)');
        foreach ($permissoes as $codigo) {
            $stmtIns->bindValue('perfil_id', $perfilId, PDO::PARAM_INT);
            $stmtIns->bindValue('permissao_id', $idsPermissoes[$codigo], PDO::PARAM_INT);
            $stmtIns->execute();
        }
    }

    private function registrarLogin(int $usuarioId): void
    {
        if ($this->colunaExiste('usuarios', 'tentativas_login_falha') && $this->colunaExiste('usuarios', 'bloqueado_ate')) {
            $stmt = $this->pdo->prepare('UPDATE usuarios SET ultimo_login_em = NOW(), tentativas_login_falha = 0, bloqueado_ate = NULL WHERE id = :id');
            $stmt->execute(['id' => $usuarioId]);
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE usuarios SET ultimo_login_em = NOW() WHERE id = :id');
        $stmt->execute(['id' => $usuarioId]);
    }

    private function registrarLoginFalha(int $usuarioId): void
    {
        if (!$this->colunaExiste('usuarios', 'tentativas_login_falha')) {
            return;
        }

        $maxTentativas = $this->segurancaConfigInt('max_tentativas_login', 5, 1, 20);
        $minutosBloqueio = $this->segurancaConfigInt('minutos_bloqueio_login', 15, 1, 1440);
        $temBloqueio = $this->colunaExiste('usuarios', 'bloqueado_ate');

        if ($temBloqueio) {
            $stmt = $this->pdo->prepare('UPDATE usuarios
                SET tentativas_login_falha = tentativas_login_falha + 1,
                    bloqueado_ate = CASE WHEN tentativas_login_falha + 1 >= :max_tentativas THEN DATE_ADD(NOW(), INTERVAL :minutos MINUTE) ELSE bloqueado_ate END
                WHERE id = :id');
            $stmt->bindValue('max_tentativas', $maxTentativas, PDO::PARAM_INT);
            $stmt->bindValue('minutos', $minutosBloqueio, PDO::PARAM_INT);
            $stmt->bindValue('id', $usuarioId, PDO::PARAM_INT);
            $stmt->execute();
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE usuarios SET tentativas_login_falha = tentativas_login_falha + 1 WHERE id = :id');
        $stmt->execute(['id' => $usuarioId]);
    }

    /** @param array<string,mixed> $user */
    private function usuarioBloqueadoTemporariamente(array $user): bool
    {
        if (!$this->colunaExiste('usuarios', 'bloqueado_ate') || empty($user['bloqueado_ate'])) {
            return false;
        }
        return strtotime((string) $user['bloqueado_ate']) > time();
    }

    private function segurancaConfigInt(string $codigo, int $padrao, int $min, int $max): int
    {
        try {
            if (!$this->tabelaExiste('seguranca_configuracoes')) {
                return $padrao;
            }
            $stmt = $this->pdo->prepare('SELECT valor FROM seguranca_configuracoes WHERE codigo = :codigo LIMIT 1');
            $stmt->bindValue('codigo', $codigo);
            $stmt->execute();
            $valor = $stmt->fetchColumn();
            if ($valor === false || !is_numeric($valor)) {
                return $padrao;
            }
            return max($min, min($max, (int) $valor));
        } catch (Throwable) {
            return $padrao;
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

    /** @param mixed $escopo */
    private function salvarEscopoCredores(int $usuarioId, mixed $escopo): void
    {
        $credorIds = is_array($escopo) ? array_values(array_unique(array_filter(array_map('intval', $escopo)))) : [];
        $this->pdo->prepare('DELETE FROM usuario_credores WHERE usuario_id = :id')->execute(['id' => $usuarioId]);
        if ($credorIds === []) {
            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO usuario_credores (
                usuario_id, credor_id, pode_consultar, pode_operar, pode_fechar_acordo, pode_criar_campanha
            ) VALUES (
                :usuario_id, :credor_id, 1, 1, 1, 1
            )');

        foreach ($credorIds as $credorId) {
            if ($credorId <= 0 || !$this->credorExiste($credorId)) {
                continue;
            }

            $stmt->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
            $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
            $stmt->execute();
        }
    }

    /** @param array<string,mixed> $payload */
    private function registrarAuditoria(?int $usuarioId, ?int $atorUsuarioId, string $evento, string $descricao, array $payload = []): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO usuario_auditoria (usuario_id, ator_usuario_id, evento, descricao, payload_extra)
            VALUES (:usuario_id, :ator_usuario_id, :evento, :descricao, :payload_extra)');
        $this->bindNullableInt($stmt, 'usuario_id', $usuarioId);
        $this->bindNullableInt($stmt, 'ator_usuario_id', $atorUsuarioId);
        $stmt->bindValue('evento', $evento);
        $stmt->bindValue('descricao', $descricao);
        $stmt->bindValue('payload_extra', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->execute();
    }

    private function perfilExiste(int $perfilId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM perfis_acesso WHERE id = :id AND ativo = 1 LIMIT 1');
        $stmt->execute(['id' => $perfilId]);
        return $stmt->fetchColumn() !== false;
    }

    private function credorExiste(int $credorId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM credores WHERE id = :id AND ativo = 1 LIMIT 1');
        $stmt->execute(['id' => $credorId]);
        return $stmt->fetchColumn() !== false;
    }

    private function normalizarCodigoPerfil(mixed $value): string
    {
        $codigo = trim(mb_strtolower((string) ($value ?? '')));
        $codigo = preg_replace('/[^a-z0-9._-]+/', '_', $codigo) ?? '';
        $codigo = trim($codigo, '._-');
        if ($codigo === '' || mb_strlen($codigo) < 3 || mb_strlen($codigo) > 60) {
            throw new InvalidArgumentException('Código do perfil inválido. Use ao menos 3 caracteres com letras, números, ponto, traço ou underline.');
        }
        return $codigo;
    }

    private function normalizarNivel(mixed $value): int
    {
        $nivel = is_numeric($value) ? (int) $value : 0;
        return max(0, min($nivel, 100));
    }

    private function normalizarId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function normalizarTexto(mixed $value, int $limite, bool $obrigatorio = false, string $campo = 'campo'): ?string
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            if ($obrigatorio) {
                throw new InvalidArgumentException('Informe ' . $campo . '.');
            }
            return null;
        }
        return mb_substr($texto, 0, $limite);
    }

    private function normalizarLogin(mixed $value): string
    {
        $login = trim(mb_strtolower((string) ($value ?? '')));
        if ($login === '' || !preg_match('/^[a-z0-9._-]{3,80}$/', $login)) {
            throw new InvalidArgumentException('Login inválido. Use letras, números, ponto, traço ou underline, com pelo menos 3 caracteres.');
        }
        return $login;
    }

    private function normalizarEmail(mixed $value): ?string
    {
        $email = trim(mb_strtolower((string) ($value ?? '')));
        if ($email === '') {
            return null;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('E-mail inválido.');
        }
        return $email;
    }

    private function normalizarStatus(mixed $value): string
    {
        $status = (string) ($value ?? 'ativo');
        return in_array($status, ['ativo', 'inativo', 'bloqueado'], true) ? $status : 'ativo';
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
        if ($value === null || $value === '') {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }
        $stmt->bindValue($key, $value);
    }
}
