<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\SegurancaOperacionalRepository;

require_once __DIR__ . '/../includes/bootstrap.php';
requireOperacaoPermissao('usuarios.gerenciar');

$pageTitle = 'Usuários';
$pageSubtitle = 'Cadastros, perfis e escopo de credores.';
$activePage = 'usuarios';

$pdo = operacaoPdo();
$erro = null;
$mensagemSucesso = null;
// A tela de Segurança deixa configurar isso (min_tamanho_senha), mas essa
// pagina validava com um "< 6" fixo, ignorando o valor configurado.
$minTamanhoSenha = max(1, (int) ((new SegurancaOperacionalRepository($pdo))->configuracoes()['min_tamanho_senha'] ?? 8));

/** @return list<string> */
function usuariosCamposTabela(PDO $pdo, string $tabela): array
{
    try {
        $rows = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $tabela) . '`')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $rows)));
    } catch (Throwable) {
        return [];
    }
}

function usuariosTabelaExiste(PDO $pdo, string $tabela): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :tabela LIMIT 1');
    $stmt->bindValue('tabela', $tabela);
    $stmt->execute();
    return (bool) $stmt->fetchColumn();
}

/** @param list<string> $campos */
function usuariosPrimeiroCampo(array $campos, array $candidatos): ?string
{
    foreach ($candidatos as $campo) {
        if (in_array($campo, $campos, true)) {
            return $campo;
        }
    }
    return null;
}

function usuariosTipoCampo(PDO $pdo, string $tabela, string $campo): string
{
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $tabela) . '` LIKE ' . $pdo->quote($campo));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return strtolower((string) ($row['Type'] ?? ''));
    } catch (Throwable) {
        return '';
    }
}

function usuariosCampoNumerico(PDO $pdo, string $tabela, string $campo): bool
{
    return preg_match('/\b(tinyint|smallint|mediumint|int|bigint|decimal|numeric)\b/i', usuariosTipoCampo($pdo, $tabela, $campo)) === 1;
}

/** @return array<string,array{codigo:string,nome:string,descricao:string,valor_texto:string,valor_num:int,nivel:int,ativo:bool}> */
function usuariosCatalogoPerfis(PDO $pdo, bool $somenteAtivos = true): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . ($somenteAtivos ? 'ativos' : 'todos');
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $fallback = [
        'admin' => ['codigo' => 'admin', 'nome' => 'Administrador', 'descricao' => 'Acesso total ao Pactua.', 'valor_texto' => 'admin', 'valor_num' => 1, 'nivel' => 100, 'ativo' => true],
        'supervisor' => ['codigo' => 'supervisor', 'nome' => 'Supervisor', 'descricao' => 'Gestão operacional e acompanhamento da equipe.', 'valor_texto' => 'supervisor', 'valor_num' => 2, 'nivel' => 80, 'ativo' => true],
        'lider' => ['codigo' => 'lider', 'nome' => 'Líder', 'descricao' => 'Apoio operacional com visão de equipe.', 'valor_texto' => 'lider', 'valor_num' => 3, 'nivel' => 60, 'ativo' => true],
        'planejamento' => ['codigo' => 'planejamento', 'nome' => 'Planejamento / Estratégia', 'descricao' => 'Relatórios, CSV e integrações read-only para análise.', 'valor_texto' => 'planejamento', 'valor_num' => 6, 'nivel' => 50, 'ativo' => true],
        'preventivo' => ['codigo' => 'preventivo', 'nome' => 'Preventivo', 'descricao' => 'Acompanhamento de acordos, vencimentos e contatos preventivos.', 'valor_texto' => 'preventivo', 'valor_num' => 4, 'nivel' => 45, 'ativo' => true],
        'operador' => ['codigo' => 'operador', 'nome' => 'Operador', 'descricao' => 'Atendimento diário conforme o escopo de credores.', 'valor_texto' => 'operador', 'valor_num' => 5, 'nivel' => 20, 'ativo' => true],
    ];

    if (!usuariosTabelaExiste($pdo, 'perfis_acesso')) {
        return $cache[$cacheKey] = $fallback;
    }

    $campos = usuariosCamposTabela($pdo, 'perfis_acesso');
    if (!in_array('id', $campos, true) || !in_array('codigo', $campos, true)) {
        return $cache[$cacheKey] = $fallback;
    }

    try {
        $colunas = ['id', 'codigo'];
        foreach (['nome', 'descricao', 'nivel', 'ativo'] as $campo) {
            if (in_array($campo, $campos, true)) {
                $colunas[] = $campo;
            }
        }
        $sql = 'SELECT `' . implode('`,`', $colunas) . '` FROM perfis_acesso';
        if ($somenteAtivos && in_array('ativo', $campos, true)) {
            $sql .= ' WHERE ativo = 1';
        }
        $sql .= ' ORDER BY ' . (in_array('nivel', $campos, true) ? 'nivel DESC, ' : '') . (in_array('nome', $campos, true) ? 'nome ASC, ' : '') . 'id ASC';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $catalogo = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $codigo = trim((string) ($row['codigo'] ?? ''));
            if ($id <= 0 || $codigo === '') {
                continue;
            }
            $base = $fallback[$codigo] ?? null;
            $catalogo[$codigo] = [
                'codigo' => $codigo,
                'nome' => trim((string) ($row['nome'] ?? '')) ?: (string) ($base['nome'] ?? ucfirst($codigo)),
                'descricao' => trim((string) ($row['descricao'] ?? '')) ?: (string) ($base['descricao'] ?? 'Perfil cadastrado no CRM.'),
                'valor_texto' => $codigo,
                'valor_num' => $id,
                'nivel' => (int) ($row['nivel'] ?? ($base['nivel'] ?? 0)),
                'ativo' => !array_key_exists('ativo', $row) || (int) $row['ativo'] === 1,
            ];
        }
        if ($catalogo !== []) {
            return $cache[$cacheKey] = $catalogo;
        }
    } catch (Throwable) {
        // Mantém compatibilidade com instalações legadas.
    }

    return $cache[$cacheKey] = $fallback;
}

/** @return array<string,mixed> */
function usuariosSchema(PDO $pdo): array
{
    if (!usuariosTabelaExiste($pdo, 'usuarios')) {
        throw new RuntimeException('Tabela usuarios não encontrada.');
    }

    $campos = usuariosCamposTabela($pdo, 'usuarios');
    if (!in_array('id', $campos, true)) {
        throw new RuntimeException('A tabela usuarios não possui a coluna id esperada.');
    }

    $nome = usuariosPrimeiroCampo($campos, ['nome', 'name', 'nome_usuario']);
    $login = usuariosPrimeiroCampo($campos, ['login', 'usuario', 'username', 'user_name']);
    $email = usuariosPrimeiroCampo($campos, ['email', 'e_mail']);
    $senha = usuariosPrimeiroCampo($campos, ['senha_hash', 'password_hash', 'senha', 'password']);
    $status = usuariosPrimeiroCampo($campos, ['status', 'situacao']);
    $ativo = usuariosPrimeiroCampo($campos, ['ativo', 'is_active']);
    $perfil = usuariosPrimeiroCampo($campos, ['perfil_codigo', 'perfil_id', 'id_perfil', 'perfil_usuario_id', 'perfil', 'role', 'papel', 'tipo_usuario']);
    $adminTotal = usuariosPrimeiroCampo($campos, ['admin_total', 'is_admin', 'administrador']);
    $criadoEm = usuariosPrimeiroCampo($campos, ['criado_em', 'created_at', 'data_criacao']);
    $atualizadoEm = usuariosPrimeiroCampo($campos, ['atualizado_em', 'updated_at', 'data_atualizacao']);
    $forcarTroca = usuariosPrimeiroCampo($campos, ['forcar_troca_senha']);

    if ($nome === null) {
        throw new RuntimeException('Não foi possível identificar a coluna de nome na tabela usuarios.');
    }
    if ($login === null && $email === null) {
        throw new RuntimeException('Não foi possível identificar login ou e-mail na tabela usuarios.');
    }

    return [
        'campos' => $campos,
        'nome' => $nome,
        'login' => $login,
        'email' => $email,
        'senha' => $senha,
        'status' => $status,
        'ativo' => $ativo,
        'perfil' => $perfil,
        'admin_total' => $adminTotal,
        'criado_em' => $criadoEm,
        'atualizado_em' => $atualizadoEm,
        'forcar_troca' => $forcarTroca,
    ];
}

/** @return array{tabela:string,usuario_col:string,credor_col:string}|null */
function usuariosLinkCredores(PDO $pdo): ?array
{
    $prioridades = [
        'usuario_credores', 'usuarios_credores', 'usuario_credor', 'usuario_escopo_credores',
        'usuarios_escopo_credores', 'escopo_usuario_credores', 'usuario_credor_escopo',
    ];

    $tables = [];
    try {
        $rows = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND (table_name LIKE '%usuario%' OR table_name LIKE '%escopo%') ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($prioridades as $tabela) {
            if (in_array($tabela, $rows, true)) {
                $tables[] = $tabela;
            }
        }
        foreach ($rows as $tabela) {
            $tabela = (string) $tabela;
            if (!in_array($tabela, $tables, true)) {
                $tables[] = $tabela;
            }
        }
    } catch (Throwable) {
        $tables = $prioridades;
    }

    foreach ($tables as $tabela) {
        $campos = usuariosCamposTabela($pdo, $tabela);
        $usuarioCol = usuariosPrimeiroCampo($campos, ['usuario_id', 'id_usuario', 'user_id']);
        $credorCol = usuariosPrimeiroCampo($campos, ['credor_id', 'id_credor']);
        if ($usuarioCol !== null && $credorCol !== null) {
            return ['tabela' => $tabela, 'usuario_col' => $usuarioCol, 'credor_col' => $credorCol];
        }
    }
    return null;
}

/** @return array{tabela:string,usuario_col:string,perfil_col:string}|null */
function usuariosLinkPerfis(PDO $pdo): ?array
{
    $prioridades = ['perfil_usuarios', 'usuarios_perfis', 'usuario_perfis', 'perfil_usuario_vinculo', 'usuario_perfil'];
    $tables = [];
    try {
        $rows = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_name LIKE '%perfil%' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($prioridades as $tabela) {
            if (in_array($tabela, $rows, true)) {
                $tables[] = $tabela;
            }
        }
        foreach ($rows as $tabela) {
            $tabela = (string) $tabela;
            if (!in_array($tabela, $tables, true)) {
                $tables[] = $tabela;
            }
        }
    } catch (Throwable) {
        $tables = $prioridades;
    }

    foreach ($tables as $tabela) {
        $campos = usuariosCamposTabela($pdo, $tabela);
        $usuarioCol = usuariosPrimeiroCampo($campos, ['usuario_id', 'id_usuario', 'user_id']);
        $perfilCol = usuariosPrimeiroCampo($campos, ['perfil_id', 'id_perfil', 'perfil_usuario_id', 'perfil_codigo', 'codigo_perfil', 'perfil']);
        if ($usuarioCol !== null && $perfilCol !== null) {
            return ['tabela' => $tabela, 'usuario_col' => $usuarioCol, 'perfil_col' => $perfilCol];
        }
    }
    return null;
}

function usuariosCodigoPerfil(PDO $pdo, array $schema, ?array $linkPerfil, array $row): string
{
    $catalogo = usuariosCatalogoPerfis($pdo, false);
    $valor = null;

    if (($schema['perfil'] ?? null) !== null) {
        $valor = $row['perfil_raw'] ?? null;
    } elseif ($linkPerfil !== null) {
        try {
            $sql = 'SELECT `' . $linkPerfil['perfil_col'] . '` FROM `' . $linkPerfil['tabela'] . '` WHERE `' . $linkPerfil['usuario_col'] . '` = :usuario_id LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue('usuario_id', (int) ($row['id'] ?? 0), PDO::PARAM_INT);
            $stmt->execute();
            $valor = $stmt->fetchColumn();
        } catch (Throwable) {
            $valor = null;
        }
    }

    $texto = trim((string) ($valor ?? ''));
    foreach ($catalogo as $codigo => $perfil) {
        if ($texto === (string) $perfil['valor_num'] || mb_strtolower($texto) === mb_strtolower($perfil['valor_texto'])) {
            return $codigo;
        }
    }

    if ((int) ($row['admin_total'] ?? 0) === 1) {
        return 'admin';
    }
    return $texto !== '' ? $texto : 'operador';
}

function usuariosNomePerfil(PDO $pdo, string $codigo): string
{
    $catalogo = usuariosCatalogoPerfis($pdo, false);
    return $catalogo[$codigo]['nome'] ?? ($codigo !== '' ? ucfirst($codigo) : 'Não definido');
}

/** @return list<array{id:int,codigo:string,nome:string}> */
function usuariosListarCredores(PDO $pdo): array
{
    if (!usuariosTabelaExiste($pdo, 'credores')) {
        return [];
    }
    $campos = usuariosCamposTabela($pdo, 'credores');
    if (!in_array('id', $campos, true)) {
        return [];
    }
    $codigo = usuariosPrimeiroCampo($campos, ['codigo', 'sigla', 'slug']);
    $nome = usuariosPrimeiroCampo($campos, ['nome', 'descricao']);
    if ($nome === null) {
        return [];
    }
    $sql = 'SELECT id, ' . ($codigo !== null ? '`' . $codigo . '`' : "''") . ' AS codigo, `' . $nome . '` AS nome FROM credores ORDER BY `' . $nome . '` ASC';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $out[] = ['id' => $id, 'codigo' => trim((string) ($row['codigo'] ?? '')), 'nome' => trim((string) ($row['nome'] ?? ''))];
    }
    return $out;
}

/** @return array{todos:bool,credor_ids:list<int>} */
function usuariosEscopoAtual(int $usuarioId, ?array $linkCredores): array
{
    try {
        $escopo = authRepository()->escopoUsuario($usuarioId);
        return [
            'todos' => (bool) ($escopo['todos'] ?? false),
            'credor_ids' => array_values(array_unique(array_map('intval', is_array($escopo['credor_ids'] ?? null) ? $escopo['credor_ids'] : []))),
        ];
    } catch (Throwable) {
        // fallback abaixo
    }

    if ($linkCredores === null) {
        return ['todos' => true, 'credor_ids' => []];
    }

    try {
        $pdo = operacaoPdo();
        $sql = 'SELECT `' . $linkCredores['credor_col'] . '` FROM `' . $linkCredores['tabela'] . '` WHERE `' . $linkCredores['usuario_col'] . '` = :usuario_id';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
        $stmt->execute();
        return ['todos' => false, 'credor_ids' => array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])))];
    } catch (Throwable) {
        return ['todos' => true, 'credor_ids' => []];
    }
}

/** @param list<int> $credorIds */
function usuariosSalvarEscopoCredores(PDO $pdo, int $usuarioId, array $credorIds, ?array $linkCredores): void
{
    if ($linkCredores === null) {
        if ($credorIds !== []) {
            throw new RuntimeException('Não foi possível identificar a tabela que vincula usuários aos credores.');
        }
        return;
    }

    $credorIds = array_values(array_unique(array_filter(array_map('intval', $credorIds), static fn (int $id): bool => $id > 0)));
    $table = str_replace('`', '', $linkCredores['tabela']);
    $usuarioCol = str_replace('`', '', $linkCredores['usuario_col']);
    $credorCol = str_replace('`', '', $linkCredores['credor_col']);

    $delete = $pdo->prepare('DELETE FROM `' . $table . '` WHERE `' . $usuarioCol . '` = :usuario_id');
    $delete->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
    $delete->execute();

    if ($credorIds === []) {
        return;
    }

    $insert = $pdo->prepare('INSERT INTO `' . $table . '` (`' . $usuarioCol . '`, `' . $credorCol . '`) VALUES (:usuario_id, :credor_id)');
    foreach ($credorIds as $credorId) {
        $insert->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
        $insert->bindValue('credor_id', $credorId, PDO::PARAM_INT);
        $insert->execute();
    }
}

function usuariosSalvarPerfil(PDO $pdo, int $usuarioId, string $codigoPerfil, array $schema, ?array $linkPerfil): void
{
    $catalogo = usuariosCatalogoPerfis($pdo);
    if (!isset($catalogo[$codigoPerfil])) {
        throw new RuntimeException('Perfil informado não é válido.');
    }
    $perfil = $catalogo[$codigoPerfil];

    if (($schema['perfil'] ?? null) !== null) {
        $campo = (string) $schema['perfil'];
        $valor = usuariosCampoNumerico($pdo, 'usuarios', $campo) ? $perfil['valor_num'] : $perfil['valor_texto'];
        $stmt = $pdo->prepare('UPDATE usuarios SET `' . str_replace('`', '', $campo) . '` = :perfil WHERE id = :id');
        if (is_int($valor)) {
            $stmt->bindValue('perfil', $valor, PDO::PARAM_INT);
        } else {
            $stmt->bindValue('perfil', $valor);
        }
        $stmt->bindValue('id', $usuarioId, PDO::PARAM_INT);
        $stmt->execute();
        return;
    }

    if ($linkPerfil === null) {
        throw new RuntimeException('Não foi possível identificar onde o perfil do usuário é armazenado.');
    }

    $table = str_replace('`', '', $linkPerfil['tabela']);
    $usuarioCol = str_replace('`', '', $linkPerfil['usuario_col']);
    $perfilCol = str_replace('`', '', $linkPerfil['perfil_col']);
    $valor = usuariosCampoNumerico($pdo, $table, $perfilCol) ? $perfil['valor_num'] : $perfil['valor_texto'];

    $delete = $pdo->prepare('DELETE FROM `' . $table . '` WHERE `' . $usuarioCol . '` = :usuario_id');
    $delete->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
    $delete->execute();

    $insert = $pdo->prepare('INSERT INTO `' . $table . '` (`' . $usuarioCol . '`, `' . $perfilCol . '`) VALUES (:usuario_id, :perfil)');
    $insert->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
    if (is_int($valor)) {
        $insert->bindValue('perfil', $valor, PDO::PARAM_INT);
    } else {
        $insert->bindValue('perfil', $valor);
    }
    $insert->execute();
}

function usuariosMetaDisponivel(PDO $pdo): bool
{
    return usuariosTabelaExiste($pdo, 'usuarios_edicoes_meta');
}

function usuariosRegistrarUltimaEdicao(PDO $pdo, int $usuarioId, string $tipo): void
{
    if (!usuariosMetaDisponivel($pdo)) {
        return;
    }
    $sql = 'INSERT INTO usuarios_edicoes_meta (usuario_id, editado_por_usuario_id, editado_por_nome, editado_em, tipo_ultima_alteracao) '
        . 'VALUES (:usuario_id, :editor_id, :editor_nome, NOW(), :tipo) '
        . 'ON DUPLICATE KEY UPDATE editado_por_usuario_id = VALUES(editado_por_usuario_id), editado_por_nome = VALUES(editado_por_nome), editado_em = NOW(), tipo_ultima_alteracao = VALUES(tipo_ultima_alteracao)';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
    $editorId = usuarioAtualId();
    $stmt->bindValue('editor_id', $editorId, $editorId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue('editor_nome', usuarioAtualNome() ?: 'Sistema');
    $stmt->bindValue('tipo', $tipo);
    $stmt->execute();
}

/** @return array<int,array<string,mixed>> */
function usuariosMetasEdicao(PDO $pdo): array
{
    if (!usuariosMetaDisponivel($pdo)) {
        return [];
    }
    $rows = $pdo->query('SELECT usuario_id, editado_por_usuario_id, editado_por_nome, editado_em, tipo_ultima_alteracao FROM usuarios_edicoes_meta')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    foreach ($rows as $row) {
        $map[(int) ($row['usuario_id'] ?? 0)] = $row;
    }
    return $map;
}

function usuariosStatusDoRow(array $schema, array $row): string
{
    if (($schema['status'] ?? null) !== null) {
        $status = mb_strtolower(trim((string) ($row['status_raw'] ?? '')));
        return in_array($status, ['ativo', 'active', '1', 'sim'], true) ? 'ativo' : 'inativo';
    }
    if (($schema['ativo'] ?? null) !== null) {
        return (int) ($row['ativo_raw'] ?? 0) === 1 ? 'ativo' : 'inativo';
    }
    return 'ativo';
}

/** @return list<array<string,mixed>> */
function usuariosListar(PDO $pdo, array $schema, ?array $linkPerfil, ?array $linkCredores, array $metas, array $credores): array
{
    $select = ['id'];
    $select[] = '`' . $schema['nome'] . '` AS nome';
    $select[] = ($schema['login'] !== null ? '`' . $schema['login'] . '`' : "''") . ' AS login';
    $select[] = ($schema['email'] !== null ? '`' . $schema['email'] . '`' : "''") . ' AS email';
    $select[] = ($schema['perfil'] !== null ? '`' . $schema['perfil'] . '`' : 'NULL') . ' AS perfil_raw';
    $select[] = ($schema['status'] !== null ? '`' . $schema['status'] . '`' : 'NULL') . ' AS status_raw';
    $select[] = ($schema['ativo'] !== null ? '`' . $schema['ativo'] . '`' : 'NULL') . ' AS ativo_raw';
    $select[] = ($schema['admin_total'] !== null ? '`' . $schema['admin_total'] . '`' : '0') . ' AS admin_total';
    $select[] = ($schema['criado_em'] !== null ? '`' . $schema['criado_em'] . '`' : 'NULL') . ' AS criado_em';
    $select[] = ($schema['atualizado_em'] !== null ? '`' . $schema['atualizado_em'] . '`' : 'NULL') . ' AS atualizado_em';
    $select[] = ($schema['forcar_troca'] !== null ? '`' . $schema['forcar_troca'] . '`' : '0') . ' AS forcar_troca_senha';

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM usuarios ORDER BY `' . $schema['nome'] . '` ASC, id ASC';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $credorMap = [];
    foreach ($credores as $credor) {
        $credorMap[(int) $credor['id']] = $credor;
    }

    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $perfilCodigo = usuariosCodigoPerfil($pdo, $schema, $linkPerfil, $row);
        $escopo = usuariosEscopoAtual($id, $linkCredores);
        $credorIds = $escopo['credor_ids'];
        if ($escopo['todos'] && $credorIds === []) {
            $credorIds = array_map(static fn (array $credor): int => (int) $credor['id'], $credores);
        }
        $credorNomes = [];
        foreach ($credorIds as $credorId) {
            if (isset($credorMap[$credorId])) {
                $credorNomes[] = $credorMap[$credorId]['codigo'] !== '' ? $credorMap[$credorId]['codigo'] : $credorMap[$credorId]['nome'];
            }
        }
        $meta = $metas[$id] ?? null;
        $ultimaEdicaoEm = is_array($meta) ? ($meta['editado_em'] ?? null) : ($row['atualizado_em'] ?? null);
        $ultimoEditor = is_array($meta) ? trim((string) ($meta['editado_por_nome'] ?? '')) : '';

        $out[] = [
            'id' => $id,
            'nome' => trim((string) ($row['nome'] ?? '')),
            'login' => trim((string) ($row['login'] ?? '')),
            'email' => trim((string) ($row['email'] ?? '')),
            'perfil_codigo' => $perfilCodigo,
            'perfil_nome' => usuariosNomePerfil($pdo, $perfilCodigo),
            'status' => usuariosStatusDoRow($schema, $row),
            'admin_total' => (int) ($row['admin_total'] ?? 0),
            'criado_em' => $row['criado_em'] ?? null,
            'atualizado_em' => $row['atualizado_em'] ?? null,
            'forcar_troca_senha' => (int) ($row['forcar_troca_senha'] ?? 0),
            'escopo_todos' => (bool) $escopo['todos'],
            'credor_ids' => array_values(array_unique(array_map('intval', $credorIds))),
            'credor_nomes' => $credorNomes,
            'ultimo_editor' => $ultimoEditor,
            'ultima_edicao_em' => $ultimaEdicaoEm,
            'meta_disponivel' => is_array($meta),
        ];
    }
    return $out;
}

function usuariosValorPerfilParaBanco(PDO $pdo, string $codigoPerfil, array $schema): int|string
{
    $perfil = usuariosCatalogoPerfis($pdo)[$codigoPerfil] ?? null;
    if ($perfil === null) {
        throw new RuntimeException('Perfil inválido.');
    }
    if (($schema['perfil'] ?? null) !== null && usuariosCampoNumerico($pdo, 'usuarios', (string) $schema['perfil'])) {
        return $perfil['valor_num'];
    }
    return $perfil['valor_texto'];
}

function usuariosValorStatusParaBanco(PDO $pdo, array $schema, string $status): int|string
{
    $status = $status === 'inativo' ? 'inativo' : 'ativo';
    if (($schema['status'] ?? null) !== null && usuariosCampoNumerico($pdo, 'usuarios', (string) $schema['status'])) {
        return $status === 'ativo' ? 1 : 0;
    }
    return $status;
}

function usuariosValidarDuplicidade(PDO $pdo, array $schema, string $login, string $email, int $ignorarId = 0): void
{
    $conds = [];
    $params = [];
    if ($schema['login'] !== null && $login !== '') {
        $conds[] = 'LOWER(TRIM(`' . $schema['login'] . '`)) = LOWER(TRIM(:login))';
        $params['login'] = $login;
    }
    if ($schema['email'] !== null && $email !== '') {
        $conds[] = 'LOWER(TRIM(`' . $schema['email'] . '`)) = LOWER(TRIM(:email))';
        $params['email'] = $email;
    }
    if ($conds === []) {
        return;
    }
    $sql = 'SELECT id FROM usuarios WHERE (' . implode(' OR ', $conds) . ')' . ($ignorarId > 0 ? ' AND id <> :ignorar_id' : '') . ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    if ($ignorarId > 0) {
        $stmt->bindValue('ignorar_id', $ignorarId, PDO::PARAM_INT);
    }
    $stmt->execute();
    if ($stmt->fetchColumn()) {
        throw new RuntimeException('Já existe outro usuário com o mesmo login ou e-mail.');
    }
}

$schema = usuariosSchema($pdo);
$linkCredores = usuariosLinkCredores($pdo);
$linkPerfil = usuariosLinkPerfis($pdo);
$credores = usuariosListarCredores($pdo);
$catalogoPerfis = usuariosCatalogoPerfis($pdo);
$metaDisponivel = usuariosMetaDisponivel($pdo);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validarCsrf();
        $acao = trim((string) ($_POST['acao'] ?? ''));

        if (!in_array($acao, ['criar_usuario', 'editar_usuario'], true)) {
            throw new RuntimeException('Ação inválida.');
        }

        $usuarioId = $acao === 'editar_usuario' && ctype_digit((string) ($_POST['usuario_id'] ?? '')) ? (int) $_POST['usuario_id'] : 0;
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $login = trim((string) ($_POST['login'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $senha = (string) ($_POST['senha'] ?? '');
        $perfilCodigo = trim((string) ($_POST['perfil_codigo'] ?? 'operador'));
        $statusNovo = trim((string) ($_POST['status'] ?? 'ativo')) === 'inativo' ? 'inativo' : 'ativo';
        $todosCredores = isset($_POST['todos_credores']);
        // Requisito (2026-10-06): cria com senha padrão e o operador
        // define a dele no primeiro acesso (requireOperacaoLogin obriga).
        $forcarTroca = isset($_POST['forcar_troca_senha']) ? 1 : 0;
        $credorIds = array_values(array_unique(array_filter(array_map('intval', is_array($_POST['credor_ids'] ?? null) ? $_POST['credor_ids'] : []), static fn (int $id): bool => $id > 0)));

        if ($nome === '') {
            throw new RuntimeException('Informe o nome do usuário.');
        }
        if ($schema['login'] !== null && $login === '') {
            throw new RuntimeException('Informe o login do usuário.');
        }
        if ($schema['email'] !== null && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Informe um e-mail válido.');
        }
        if (!isset($catalogoPerfis[$perfilCodigo])) {
            throw new RuntimeException('Selecione um perfil válido.');
        }
        if ($acao === 'criar_usuario' && trim($senha) === '') {
            throw new RuntimeException('Informe a senha inicial do novo usuário.');
        }
        if (trim($senha) !== '' && mb_strlen($senha) < $minTamanhoSenha) {
            throw new RuntimeException('A senha deve ter pelo menos ' . $minTamanhoSenha . ' caracteres.');
        }
        if ($schema['senha'] === null && trim($senha) !== '') {
            throw new RuntimeException('Não foi possível identificar a coluna de senha na tabela usuarios.');
        }
        if ($acao === 'editar_usuario' && $usuarioId <= 0) {
            throw new RuntimeException('Usuário inválido para edição.');
        }
        if ($acao === 'editar_usuario' && $usuarioId === usuarioAtualId() && $statusNovo !== 'ativo') {
            throw new RuntimeException('Você não pode desativar o próprio usuário enquanto está conectado.');
        }

        if ($todosCredores) {
            $credorIds = array_map(static fn (array $credor): int => (int) $credor['id'], $credores);
        }
        if ($credores !== [] && $credorIds === []) {
            throw new RuntimeException('Selecione pelo menos um credor para o usuário.');
        }

        usuariosValidarDuplicidade($pdo, $schema, $login, $email, $usuarioId);
        $pdo->beginTransaction();

        if ($acao === 'criar_usuario') {
            $campos = [];
            $valores = [];
            $binds = [];

            $campos[] = '`' . $schema['nome'] . '`';
            $valores[] = ':nome';
            $binds['nome'] = $nome;

            if ($schema['login'] !== null) {
                $campos[] = '`' . $schema['login'] . '`';
                $valores[] = ':login';
                $binds['login'] = $login;
            }
            if ($schema['email'] !== null) {
                $campos[] = '`' . $schema['email'] . '`';
                $valores[] = ':email';
                $binds['email'] = $email !== '' ? $email : null;
            }
            if ($schema['senha'] !== null) {
                $campos[] = '`' . $schema['senha'] . '`';
                $valores[] = ':senha';
                $binds['senha'] = password_hash($senha, PASSWORD_DEFAULT);
            }
            if ($schema['status'] !== null) {
                $campos[] = '`' . $schema['status'] . '`';
                $valores[] = ':status';
                $binds['status'] = usuariosValorStatusParaBanco($pdo, $schema, $statusNovo);
            } elseif ($schema['ativo'] !== null) {
                $campos[] = '`' . $schema['ativo'] . '`';
                $valores[] = ':ativo';
                $binds['ativo'] = $statusNovo === 'ativo' ? 1 : 0;
            }
            if ($schema['perfil'] !== null) {
                $campos[] = '`' . $schema['perfil'] . '`';
                $valores[] = ':perfil';
                $binds['perfil'] = usuariosValorPerfilParaBanco($pdo, $perfilCodigo, $schema);
            }
            if ($schema['admin_total'] !== null) {
                $campos[] = '`' . $schema['admin_total'] . '`';
                $valores[] = ':admin_total';
                $binds['admin_total'] = $perfilCodigo === 'admin' ? 1 : 0;
            }
            if ($schema['forcar_troca'] !== null) {
                $campos[] = '`' . $schema['forcar_troca'] . '`';
                $valores[] = ':forcar_troca';
                $binds['forcar_troca'] = $forcarTroca;
            }
            if ($schema['criado_em'] !== null) {
                $campos[] = '`' . $schema['criado_em'] . '`';
                $valores[] = 'NOW()';
            }
            if ($schema['atualizado_em'] !== null) {
                $campos[] = '`' . $schema['atualizado_em'] . '`';
                $valores[] = 'NOW()';
            }

            $sql = 'INSERT INTO usuarios (' . implode(', ', $campos) . ') VALUES (' . implode(', ', $valores) . ')';
            $stmt = $pdo->prepare($sql);
            foreach ($binds as $key => $value) {
                $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
            }
            $stmt->execute();
            $usuarioId = (int) $pdo->lastInsertId();
            if ($usuarioId <= 0) {
                throw new RuntimeException('Não foi possível obter o ID do usuário criado.');
            }
            if ($schema['perfil'] === null) {
                usuariosSalvarPerfil($pdo, $usuarioId, $perfilCodigo, $schema, $linkPerfil);
            }
            usuariosSalvarEscopoCredores($pdo, $usuarioId, $credorIds, $linkCredores);
            usuariosRegistrarUltimaEdicao($pdo, $usuarioId, 'criacao');
            registrarAuditoriaSistema('usuario.criado', 'usuario', $usuarioId, 'Usuário criado pela administração.', null, ['nome' => $nome, 'login' => $login, 'email' => $email, 'perfil' => $perfilCodigo, 'credor_ids' => $credorIds, 'forcar_troca_senha' => $forcarTroca]);
            $pdo->commit();
            header('Location: /operacao/configuracoes/usuarios.php?ok=criado');
            exit;
        }

        $antesStmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = :id LIMIT 1');
        $antesStmt->bindValue('id', $usuarioId, PDO::PARAM_INT);
        $antesStmt->execute();
        $antes = $antesStmt->fetch(PDO::FETCH_ASSOC);
        if (!$antes) {
            throw new RuntimeException('Usuário não encontrado.');
        }

        $sets = ['`' . $schema['nome'] . '` = :nome'];
        $binds = ['nome' => $nome, 'id' => $usuarioId];
        if ($schema['login'] !== null) {
            $sets[] = '`' . $schema['login'] . '` = :login';
            $binds['login'] = $login;
        }
        if ($schema['email'] !== null) {
            $sets[] = '`' . $schema['email'] . '` = :email';
            $binds['email'] = $email !== '' ? $email : null;
        }
        if ($schema['senha'] !== null && trim($senha) !== '') {
            $sets[] = '`' . $schema['senha'] . '` = :senha';
            $binds['senha'] = password_hash($senha, PASSWORD_DEFAULT);
        }
        if ($schema['status'] !== null) {
            $sets[] = '`' . $schema['status'] . '` = :status';
            $binds['status'] = usuariosValorStatusParaBanco($pdo, $schema, $statusNovo);
        } elseif ($schema['ativo'] !== null) {
            $sets[] = '`' . $schema['ativo'] . '` = :ativo';
            $binds['ativo'] = $statusNovo === 'ativo' ? 1 : 0;
        }
        if ($schema['perfil'] !== null) {
            $sets[] = '`' . $schema['perfil'] . '` = :perfil';
            $binds['perfil'] = usuariosValorPerfilParaBanco($pdo, $perfilCodigo, $schema);
        }
        if ($schema['admin_total'] !== null) {
            $sets[] = '`' . $schema['admin_total'] . '` = :admin_total';
            $binds['admin_total'] = $perfilCodigo === 'admin' ? 1 : 0;
        }
        if ($schema['forcar_troca'] !== null) {
            $sets[] = '`' . $schema['forcar_troca'] . '` = :forcar_troca';
            $binds['forcar_troca'] = $forcarTroca;
        }
        if ($schema['atualizado_em'] !== null) {
            $sets[] = '`' . $schema['atualizado_em'] . '` = NOW()';
        }

        $update = $pdo->prepare('UPDATE usuarios SET ' . implode(', ', $sets) . ' WHERE id = :id LIMIT 1');
        foreach ($binds as $key => $value) {
            $update->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        }
        $update->execute();

        if ($schema['perfil'] === null) {
            usuariosSalvarPerfil($pdo, $usuarioId, $perfilCodigo, $schema, $linkPerfil);
        }
        usuariosSalvarEscopoCredores($pdo, $usuarioId, $credorIds, $linkCredores);
        usuariosRegistrarUltimaEdicao($pdo, $usuarioId, 'edicao');
        registrarAuditoriaSistema('usuario.atualizado', 'usuario', $usuarioId, 'Usuário atualizado pela administração.', is_array($antes) ? $antes : null, ['nome' => $nome, 'login' => $login, 'email' => $email, 'perfil' => $perfilCodigo, 'status' => $statusNovo, 'credor_ids' => $credorIds, 'forcar_troca_senha' => $forcarTroca, 'senha_redefinida' => trim($senha) !== '']);
        $pdo->commit();
        header('Location: /operacao/configuracoes/usuarios.php?ok=editado');
        exit;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $erro = $e->getMessage();
}

$metas = usuariosMetasEdicao($pdo);
$usuarios = usuariosListar($pdo, $schema, $linkPerfil, $linkCredores, $metas, $credores);

$q = trim((string) ($_GET['q'] ?? ''));
$credorFiltro = isset($_GET['credor_id']) && ctype_digit((string) $_GET['credor_id']) ? (int) $_GET['credor_id'] : 0;
$statusFiltro = in_array((string) ($_GET['status'] ?? 'todos'), ['todos', 'ativo', 'inativo'], true) ? (string) ($_GET['status'] ?? 'todos') : 'todos';
$perfilFiltro = trim((string) ($_GET['perfil'] ?? 'todos'));
if ($perfilFiltro !== 'todos' && !isset($catalogoPerfis[$perfilFiltro])) {
    $perfilFiltro = 'todos';
}

$usuariosFiltrados = array_values(array_filter($usuarios, static function (array $usuario) use ($q, $credorFiltro, $statusFiltro, $perfilFiltro): bool {
    if ($q !== '') {
        $agulha = mb_strtolower($q);
        $texto = mb_strtolower(trim((string) ($usuario['nome'] ?? '')) . ' ' . trim((string) ($usuario['login'] ?? '')) . ' ' . trim((string) ($usuario['email'] ?? '')));
        if (!str_contains($texto, $agulha)) {
            return false;
        }
    }
    if ($credorFiltro > 0 && !in_array($credorFiltro, $usuario['credor_ids'] ?? [], true)) {
        return false;
    }
    if ($statusFiltro !== 'todos' && (string) ($usuario['status'] ?? '') !== $statusFiltro) {
        return false;
    }
    if ($perfilFiltro !== 'todos' && (string) ($usuario['perfil_codigo'] ?? '') !== $perfilFiltro) {
        return false;
    }
    return true;
}));

$totalUsuarios = count($usuarios);
$totalAtivos = count(array_filter($usuarios, static fn (array $u): bool => ($u['status'] ?? '') === 'ativo'));
$totalInativos = max(0, $totalUsuarios - $totalAtivos);
$totalPerfisAtivos = count($catalogoPerfis);

$ok = trim((string) ($_GET['ok'] ?? ''));
$mensagemSucesso = match ($ok) {
    'criado' => 'Usuário criado com sucesso.',
    'editado' => 'Usuário atualizado com sucesso.',
    default => null,
};

require __DIR__ . '/../includes/header.php';
?>
<main class="container access-users-page">
    <?php require __DIR__ . '/../includes/config_nav.php'; ?>
    <?php if ($mensagemSucesso !== null): ?><section class="alert success"><?= h($mensagemSucesso) ?></section><?php endif; ?>
    <?php if ($erro !== null): ?><section class="alert error"><strong>Não foi possível salvar o usuário.</strong> <?= h($erro) ?></section><?php endif; ?>
    <?php if (!$metaDisponivel): ?><section class="alert warning"><strong>Último editor ainda não habilitado.</strong> Execute a migration <code>077_usuarios_ultima_edicao.sql</code>. A gestão de usuários continua funcionando sem ela.</section><?php endif; ?>

    <header class="access-admin-head">
        <div>
            <span>Administração de acesso</span>
            <h2>Usuários e equipe</h2>
            <p>Cadastre a pessoa, escolha o perfil e determine em quais credores ela poderá trabalhar.</p>
        </div>
        <div>
            <a class="button secondary" href="/operacao/configuracoes/perfis.php">Configurar perfis</a>
            <button class="button" type="button" data-user-modal-open="new">+ Novo usuário</button>
        </div>
    </header>

    <section class="access-users-guide" aria-label="Estrutura do acesso">
        <div><span>1</span><p><strong>Usuário</strong><small>Identidade e dados de login</small></p></div>
        <div><span>2</span><p><strong>Perfil</strong><small>O que poderá fazer no CRM</small></p></div>
        <div><span>3</span><p><strong>Credores</strong><small>Onde poderá consultar e operar</small></p></div>
    </section>

    <section class="access-users-summary" aria-label="Resumo de usuários">
        <article><i>EQ</i><span><small>Equipe cadastrada</small><strong><?= $totalUsuarios ?></strong></span></article>
        <article class="is-success"><i>AT</i><span><small>Usuários ativos</small><strong><?= $totalAtivos ?></strong></span></article>
        <article class="is-muted"><i>IN</i><span><small>Usuários inativos</small><strong><?= $totalInativos ?></strong></span></article>
        <article class="is-info"><i>PF</i><span><small>Perfis disponíveis</small><strong><?= $totalPerfisAtivos ?></strong></span></article>
    </section>

    <section class="card access-users-filters">
        <form method="get" action="/operacao/configuracoes/usuarios.php">
            <label class="is-search">
                <span>Pesquisar</span>
                <input type="search" name="q" value="<?= h($q) ?>" placeholder="Nome, login ou e-mail" autocomplete="off">
            </label>
            <label>
                <span>Perfil</span>
                <select name="perfil" onchange="this.form.submit()">
                    <option value="todos">Todos os perfis</option>
                    <?php foreach ($catalogoPerfis as $codigo => $perfil): ?>
                        <option value="<?= h($codigo) ?>"<?= $perfilFiltro === $codigo ? ' selected' : '' ?>><?= h((string) $perfil['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Credor</span>
                <select name="credor_id" onchange="this.form.submit()">
                    <option value="0">Todos os credores</option>
                    <?php foreach ($credores as $credor): ?>
                        <option value="<?= (int) $credor['id'] ?>"<?= $credorFiltro === (int) $credor['id'] ? ' selected' : '' ?>><?= h(($credor['codigo'] !== '' ? $credor['codigo'] . ' · ' : '') . $credor['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Status</span>
                <select name="status" onchange="this.form.submit()">
                    <option value="todos"<?= $statusFiltro === 'todos' ? ' selected' : '' ?>>Todos</option>
                    <option value="ativo"<?= $statusFiltro === 'ativo' ? ' selected' : '' ?>>Ativos</option>
                    <option value="inativo"<?= $statusFiltro === 'inativo' ? ' selected' : '' ?>>Inativos</option>
                </select>
            </label>
            <div>
                <button class="button secondary" type="submit">Aplicar filtros</button>
                <?php if ($q !== '' || $credorFiltro > 0 || $statusFiltro !== 'todos' || $perfilFiltro !== 'todos'): ?><a class="button ghost" href="/operacao/configuracoes/usuarios.php">Limpar</a><?php endif; ?>
            </div>
        </form>
    </section>

    <section class="access-users-results-head">
        <div><strong><?= count($usuariosFiltrados) ?> usuário(s)</strong><span>encontrados com os filtros atuais</span></div>
        <small>Para alterar permissões de todos de uma vez, edite o perfil.</small>
    </section>

    <?php if ($usuariosFiltrados === []): ?>
        <div class="card empty access-users-empty">Nenhum usuário encontrado para os filtros informados.</div>
    <?php else: ?>
        <section class="access-user-grid">
        <?php foreach ($usuariosFiltrados as $usuario): ?>
            <?php
            $editorTexto = trim((string) ($usuario['ultimo_editor'] ?? ''));
            $dataEdicao = $usuario['ultima_edicao_em'] ?? null;
            $modalPayload = [
                'id' => (int) $usuario['id'],
                'nome' => (string) $usuario['nome'],
                'login' => (string) $usuario['login'],
                'email' => (string) $usuario['email'],
                'perfil_codigo' => (string) $usuario['perfil_codigo'],
                'status' => (string) $usuario['status'],
                'credor_ids' => array_values(array_map('intval', $usuario['credor_ids'] ?? [])),
                'escopo_todos' => (bool) ($usuario['escopo_todos'] ?? false),
                'forcar_troca_senha' => (int) ($usuario['forcar_troca_senha'] ?? 0),
                'ultimo_editor' => $editorTexto,
                'ultima_edicao_em' => $dataEdicao !== null && $dataEdicao !== '' ? dataHoraBr($dataEdicao) : '',
            ];
            ?>
            <article class="card access-user-card">
                <header>
                    <span class="access-user-avatar"><?= h(mb_strtoupper(mb_substr((string) $usuario['nome'], 0, 1))) ?></span>
                    <div><strong><?= h((string) $usuario['nome']) ?></strong><small><?= h((string) ($usuario['login'] !== '' ? $usuario['login'] : $usuario['email'])) ?></small></div>
                    <span class="access-user-status <?= $usuario['status'] === 'ativo' ? 'is-active' : 'is-inactive' ?>"><?= $usuario['status'] === 'ativo' ? 'Ativo' : 'Inativo' ?></span>
                </header>
                <div class="access-user-details">
                    <div><span>Perfil</span><strong><?= h((string) $usuario['perfil_nome']) ?></strong></div>
                    <div><span>E-mail</span><strong><?= $usuario['email'] !== '' ? h((string) $usuario['email']) : 'Não informado' ?></strong></div>
                </div>
                <div class="access-user-scope">
                    <span>Escopo de credores</span>
                    <div>
                        <?php if (($usuario['credor_nomes'] ?? []) === []): ?>
                            <small>Sem credor configurado</small>
                        <?php elseif ((bool) ($usuario['escopo_todos'] ?? false)): ?>
                            <b>Todos os credores</b>
                        <?php else: ?>
                            <?php foreach (array_slice($usuario['credor_nomes'], 0, 4) as $credorNome): ?><b><?= h((string) $credorNome) ?></b><?php endforeach; ?>
                            <?php if (count($usuario['credor_nomes']) > 4): ?><b>+<?= count($usuario['credor_nomes']) - 4 ?></b><?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <footer>
                    <span><small>Última edição</small><strong><?= $dataEdicao ? h(dataHoraBr($dataEdicao)) : 'Não identificada' ?></strong><?= $editorTexto !== '' ? '<small>por ' . h($editorTexto) . '</small>' : '' ?></span>
                    <button class="button secondary" type="button" data-user-edit='<?= h(json_encode($modalPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>Editar usuário</button>
                </footer>
            </article>
        <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>

<div class="works-modal-backdrop" data-user-modal hidden>
    <section class="works-modal works-user-modal" role="dialog" aria-modal="true" aria-labelledby="works-user-modal-title">
        <header class="works-user-modal-head">
            <div>
                <span class="works-users-kicker" data-user-modal-kicker>Novo cadastro</span>
                <h2 id="works-user-modal-title" data-user-modal-title>Novo usuário</h2>
                <p data-user-modal-subtitle>Informe os dados de acesso, perfil e credores.</p>
            </div>
            <button class="works-modal-close" type="button" data-user-modal-close aria-label="Fechar">×</button>
        </header>

        <form method="post" action="/operacao/configuracoes/usuarios.php" class="access-user-modal-form" autocomplete="off">
            <?= authCsrfInput() ?>
            <input type="hidden" name="acao" value="criar_usuario" data-user-action>
            <input type="hidden" name="usuario_id" value="" data-user-id>

            <section class="access-form-section">
                <header><b>1</b><span><strong>Dados do usuário</strong><small>Informações usadas para identificação e entrada no sistema.</small></span></header>
                <div class="access-form-grid">
                    <label class="is-wide"><span>Nome completo</span><input type="text" name="nome" maxlength="180" required data-user-name placeholder="Nome completo"></label>
                    <?php if ($schema['login'] !== null): ?>
                        <label><span>Login</span><input type="text" name="login" maxlength="180" required data-user-login placeholder="Login de acesso" autocomplete="off"></label>
                    <?php else: ?><input type="hidden" name="login" value="" data-user-login><?php endif; ?>
                    <?php if ($schema['email'] !== null): ?>
                        <label><span>E-mail</span><input type="email" name="email" maxlength="220" data-user-email placeholder="nome@empresa.com.br" autocomplete="off"></label>
                    <?php else: ?><input type="hidden" name="email" value="" data-user-email><?php endif; ?>
                    <label class="is-wide"><span data-user-password-label>Senha inicial</span><input type="password" name="senha" minlength="<?= $minTamanhoSenha ?>" data-user-password autocomplete="new-password" placeholder="Mínimo de <?= $minTamanhoSenha ?> caracteres"><small data-user-password-help>Obrigatória no cadastro. Depois pode ser alterada sem mostrar a senha atual.</small></label>
                    <?php if ($schema['forcar_troca'] !== null): ?>
                        <label class="switch-card is-wide"><input type="checkbox" name="forcar_troca_senha" value="1" data-user-force-change checked><span><strong>Trocar a senha no próximo acesso</strong><small>O usuário entra com esta senha provisória e é obrigado a criar a dele antes de usar o CRM.</small></span></label>
                    <?php endif; ?>
                </div>
            </section>

            <section class="access-form-section">
                <header><b>2</b><span><strong>Nível de acesso</strong><small>O perfil determina as telas e ações disponíveis.</small></span><a href="/operacao/configuracoes/perfis.php">Configurar perfis</a></header>
                <div class="access-form-grid">
                    <label><span>Perfil</span><select name="perfil_codigo" data-user-profile required><?php foreach ($catalogoPerfis as $codigo => $perfil): ?><option value="<?= h($codigo) ?>" data-description="<?= h((string) $perfil['descricao']) ?>"><?= h($perfil['nome']) ?></option><?php endforeach; ?></select><small data-user-profile-help>O perfil define o que o usuário poderá fazer.</small></label>
                    <label><span>Status</span><select name="status" data-user-status><option value="ativo">Ativo</option><option value="inativo">Inativo</option></select><small>Usuários inativos não conseguem entrar no CRM.</small></label>
                </div>
            </section>

            <section class="access-form-section access-user-scope-section">
                <header><b>3</b><span><strong>Escopo de credores</strong><small>Escolha onde este usuário poderá consultar e trabalhar.</small></span><label><input type="checkbox" name="todos_credores" value="1" data-user-all-creditors> Todos os credores</label></header>
                <div class="access-user-creditor-grid">
                    <?php if ($credores === []): ?><span class="muted">Nenhum credor cadastrado.</span><?php endif; ?>
                    <?php foreach ($credores as $credor): ?>
                        <label><input type="checkbox" name="credor_ids[]" value="<?= (int) $credor['id'] ?>" data-user-creditor> <span><strong><?= h((string) ($credor['codigo'] !== '' ? $credor['codigo'] : $credor['nome'])) ?></strong><small><?= h((string) $credor['nome']) ?></small></span></label>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="works-user-last-edit-panel" data-user-last-edit-panel hidden>
                <span>Última edição</span>
                <strong data-user-last-edit>—</strong>
                <small>Esta informação identifica a última alteração feita pela Administração.</small>
            </section>

            <footer class="access-user-modal-actions">
                <button class="button ghost" type="button" data-user-modal-close>Cancelar</button>
                <button class="button" type="submit" data-user-save>Salvar usuário</button>
            </footer>
        </form>
    </section>
</div>

<script>
(() => {
    const modal = document.querySelector('[data-user-modal]');
    if (!modal) return;
    const form = modal.querySelector('form');
    const action = modal.querySelector('[data-user-action]');
    const id = modal.querySelector('[data-user-id]');
    const name = modal.querySelector('[data-user-name]');
    const login = modal.querySelector('[data-user-login]');
    const email = modal.querySelector('[data-user-email]');
    const profile = modal.querySelector('[data-user-profile]');
    const profileHelp = modal.querySelector('[data-user-profile-help]');
    const status = modal.querySelector('[data-user-status]');
    const password = modal.querySelector('[data-user-password]');
    const passwordLabel = modal.querySelector('[data-user-password-label]');
    const passwordHelp = modal.querySelector('[data-user-password-help]');
    const forceChange = modal.querySelector('[data-user-force-change]');
    // Admin digitou uma senha nova pra alguém: por padrão ela é provisória.
    password?.addEventListener('input', () => {
        if (forceChange && action.value === 'editar_usuario' && password.value !== '') forceChange.checked = true;
    });
    const title = modal.querySelector('[data-user-modal-title]');
    const kicker = modal.querySelector('[data-user-modal-kicker]');
    const subtitle = modal.querySelector('[data-user-modal-subtitle]');
    const save = modal.querySelector('[data-user-save]');
    const lastEditPanel = modal.querySelector('[data-user-last-edit-panel]');
    const lastEdit = modal.querySelector('[data-user-last-edit]');
    const allCreditors = modal.querySelector('[data-user-all-creditors]');
    const creditors = [...modal.querySelectorAll('[data-user-creditor]')];

    const syncProfileHelp = () => {
        if (!profileHelp || !profile) return;
        const selected = profile.options[profile.selectedIndex];
        profileHelp.textContent = selected?.dataset.description || 'O perfil define o que o usuário poderá fazer.';
    };
    profile?.addEventListener('change', syncProfileHelp);

    const syncAllCreditors = () => {
        if (!allCreditors) return;
        if (allCreditors.checked) creditors.forEach((item) => { item.checked = true; });
    };
    allCreditors?.addEventListener('change', syncAllCreditors);
    creditors.forEach((item) => item.addEventListener('change', () => {
        if (!allCreditors) return;
        allCreditors.checked = creditors.length > 0 && creditors.every((box) => box.checked);
    }));

    const openModal = (payload = null) => {
        form?.reset();
        creditors.forEach((item) => { item.checked = false; });
        if (payload) {
            action.value = 'editar_usuario';
            id.value = String(payload.id || '');
            name.value = payload.nome || '';
            login.value = payload.login || '';
            email.value = payload.email || '';
            profile.value = payload.perfil_codigo || 'operador';
            syncProfileHelp();
            status.value = payload.status || 'ativo';
            password.value = '';
            password.removeAttribute('required');
            passwordLabel.textContent = 'Nova senha';
            passwordHelp.textContent = 'Deixe em branco para manter a senha atual.';
            if (forceChange) forceChange.checked = Number(payload.forcar_troca_senha || 0) === 1;
            title.textContent = 'Editar usuário';
            kicker.textContent = 'Cadastro existente';
            subtitle.textContent = 'Atualize dados, perfil, status ou escopo de credores.';
            save.textContent = 'Salvar alterações';
            const ids = new Set((payload.credor_ids || []).map(Number));
            creditors.forEach((box) => { box.checked = ids.has(Number(box.value)); });
            if (allCreditors) allCreditors.checked = Boolean(payload.escopo_todos) || (creditors.length > 0 && creditors.every((box) => box.checked));
            const lastText = [payload.ultimo_editor ? `por ${payload.ultimo_editor}` : '', payload.ultima_edicao_em || ''].filter(Boolean).join(' · ');
            lastEdit.textContent = lastText || 'Sem edição identificada';
            lastEditPanel.hidden = false;
        } else {
            action.value = 'criar_usuario';
            id.value = '';
            if (profile) profile.value = 'operador';
            syncProfileHelp();
            if (status) status.value = 'ativo';
            password.value = '';
            password.setAttribute('required', 'required');
            passwordLabel.textContent = 'Senha inicial';
            passwordHelp.textContent = 'Obrigatória no cadastro. Depois pode ser alterada sem mostrar a senha atual.';
            if (forceChange) forceChange.checked = true;
            title.textContent = 'Novo usuário';
            kicker.textContent = 'Novo cadastro';
            subtitle.textContent = 'Informe os dados de acesso, perfil e credores.';
            save.textContent = 'Criar usuário';
            if (allCreditors) allCreditors.checked = false;
            lastEditPanel.hidden = true;
        }
        modal.hidden = false;
        document.documentElement.classList.add('works-modal-open');
        setTimeout(() => name?.focus(), 40);
    };

    const closeModal = () => {
        modal.hidden = true;
        document.documentElement.classList.remove('works-modal-open');
    };

    document.querySelectorAll('[data-user-modal-open="new"]').forEach((button) => button.addEventListener('click', () => openModal()));
    document.querySelectorAll('[data-user-edit]').forEach((button) => button.addEventListener('click', () => {
        try { openModal(JSON.parse(button.getAttribute('data-user-edit') || '{}')); }
        catch (_) { openModal(); }
    }));
    modal.querySelectorAll('[data-user-modal-close]').forEach((button) => button.addEventListener('click', closeModal));
    modal.addEventListener('click', (event) => { if (event.target === modal) closeModal(); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !modal.hidden) closeModal(); });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
