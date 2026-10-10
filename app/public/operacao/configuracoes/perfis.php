<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
requireOperacaoPermissao('usuarios.gerenciar');

$pageTitle = 'Perfis e Permissões';
$pageSubtitle = 'Configure os perfis padrão do Pactua.';
$activePage = 'perfis';

$pdo = operacaoPdo();
$erro = null;
$mensagemSucesso = null;

/** @return list<array{Field:string,Type?:string}> */
function perfisColumns(PDO $pdo, string $table): array
{
    try {
        return $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

/** @return list<string> */
function perfisFields(PDO $pdo, string $table): array
{
    return array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), perfisColumns($pdo, $table));
}

function perfisTableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
              AND table_type = \'BASE TABLE\'
            LIMIT 1');
        $stmt->bindValue('table_name', $table);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    } catch (Throwable) {
        return false;
    }
}

function perfisFirstField(array $fields, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $fields, true)) {
            return $candidate;
        }
    }
    return null;
}

function perfisColumnType(PDO $pdo, string $table, string $field): string
{
    foreach (perfisColumns($pdo, $table) as $column) {
        if ((string) ($column['Field'] ?? '') === $field) {
            return strtolower((string) ($column['Type'] ?? ''));
        }
    }
    return '';
}

function perfisIsNumericColumn(PDO $pdo, string $table, string $field): bool
{
    $type = perfisColumnType($pdo, $table, $field);
    return preg_match('/\b(tinyint|smallint|mediumint|int|bigint|decimal|numeric)\b/i', $type) === 1;
}

/** @return array<string,array{codigo:string,nome:string,descricao:string,valor_texto:string,valor_num:int,padroes:list<string>}> */
function perfisPadroesCatalogo(): array
{
    return [
        'admin' => [
            'codigo' => 'admin',
            'nome' => 'Administrador',
            'descricao' => 'Acesso total ao Pactua, gestão, usuários, permissões, cargas e área técnica.',
            'valor_texto' => 'admin',
            'valor_num' => 1,
            'padroes' => ['*'],
        ],
        'supervisor' => [
            'codigo' => 'supervisor',
            'nome' => 'Supervisor',
            'descricao' => 'Gestão operacional, carteira de acordos, vencimentos, relatórios e atribuições.',
            'valor_texto' => 'supervisor',
            'valor_num' => 2,
            'padroes' => [
                'clientes.consultar', 'atendimentos.registrar', 'contatos.alterar', 'contatos.editar', 'financeiro.historico', 'boletos.copiar', 'boletos.baixar',
                'retornos.gerenciar',
                'acordos.visualizar', 'acordos.carteira.visualizar', 'acordos.carteira.editar', 'acordos.cadastro_manual', 'acordos.corrigir', 'acordos.desfazer',
                'acordos.criar', 'acordos.status', 'acordos.integracao', 'acordos.atribuir', 'acordos.gerenciar', 'acordos.configurar_carencia', 'acordos.sincronizar', 'contatos.excluir',
                'credores.visualizar',
                'relatorios.visualizar', 'dashboard.visualizar',
            ],
        ],
        'lider' => [
            'codigo' => 'lider',
            'nome' => 'Líder',
            'descricao' => 'Apoio operacional com visão de equipe e controle limitado da carteira de acordos.',
            'valor_texto' => 'lider',
            'valor_num' => 3,
            'padroes' => [
                'clientes.consultar', 'atendimentos.registrar', 'contatos.alterar', 'contatos.editar', 'financeiro.historico', 'boletos.copiar', 'boletos.baixar',
                'retornos.gerenciar',
                'acordos.visualizar', 'acordos.carteira.visualizar', 'acordos.carteira.editar', 'acordos.corrigir',
                'acordos.criar', 'acordos.status', 'acordos.sincronizar', 'contatos.excluir',
                'dashboard.visualizar', 'relatorios.visualizar',
            ],
        ],
        'planejamento' => [
            'codigo' => 'planejamento',
            'nome' => 'Planejamento / Estratégia',
            'descricao' => 'Perfil de leitura para relatórios CSV e integrações com Excel/Power Query.',
            'valor_texto' => 'planejamento',
            'valor_num' => 6,
            'padroes' => [
                'relatorios.visualizar', 'relatorios.api.gerenciar', 'credores.visualizar',
            ],
        ],
        'preventivo' => [
            'codigo' => 'preventivo',
            'nome' => 'Preventivo',
            'descricao' => 'Acompanhamento de acordos, vencimentos e contatos preventivos.',
            'valor_texto' => 'preventivo',
            'valor_num' => 4,
            'padroes' => [
                'clientes.consultar', 'atendimentos.registrar', 'contatos.alterar', 'contatos.editar', 'financeiro.historico', 'boletos.copiar', 'boletos.baixar',
                'retornos.gerenciar',
                'acordos.visualizar', 'acordos.carteira.visualizar', 'acordos.corrigir', 'acordos.criar', 'acordos.sincronizar',
            ],
        ],
        'operador' => [
            'codigo' => 'operador',
            'nome' => 'Operador',
            'descricao' => 'Atendimento diário no Pactua, localização e fechamento de promessa própria.',
            'valor_texto' => 'operador',
            'valor_num' => 5,
            'padroes' => [
                'clientes.consultar', 'atendimentos.registrar', 'contatos.alterar', 'contatos.editar', 'financeiro.historico', 'boletos.copiar', 'boletos.baixar',
                'retornos.gerenciar',
                'acordos.visualizar', 'acordos.carteira.visualizar', 'acordos.criar',
            ],
        ],
    ];
}

/** @return array<string,array{nome:string,grupo:string,descricao:string}> */
function perfisPermissoesCatalogo(): array
{
    return [
        'clientes.consultar' => ['nome' => 'Consultar clientes/contratos', 'grupo' => 'Atendimento', 'descricao' => 'Permite usar a busca e abrir contratos no Pactua.'],
        'atendimentos.registrar' => ['nome' => 'Registrar acionamento', 'grupo' => 'Atendimento', 'descricao' => 'Permite registrar eventos, tabulações e observações.'],
        'contatos.alterar' => ['nome' => 'Alterar localização', 'grupo' => 'Atendimento', 'descricao' => 'Permissão legada para adicionar/alterar telefones, e-mails e endereços.'],
        'contatos.editar' => ['nome' => 'Editar localização 2.0', 'grupo' => 'Atendimento', 'descricao' => 'Permite salvar categoria, situação, principal e novos contatos/endereços no Pactua.'],
        'contatos.excluir' => ['nome' => 'Excluir/inativar localização', 'grupo' => 'Atendimento', 'descricao' => 'Reserva permissão para futura exclusão/inativação de contatos e endereços.'],
        'financeiro.historico' => ['nome' => 'Ver histórico financeiro', 'grupo' => 'Acordos', 'descricao' => 'Permite consultar eventos financeiros do contrato, incluindo acordos, responsáveis e boletos.'],
        'boletos.copiar' => ['nome' => 'Copiar linha digitável', 'grupo' => 'Acordos', 'descricao' => 'Permite consultar/copiar linha digitável de boleto disponível.'],
        'boletos.baixar' => ['nome' => 'Baixar boleto', 'grupo' => 'Acordos', 'descricao' => 'Permite baixar PDF do boleto disponível no credor.'],
        'retornos.gerenciar' => ['nome' => 'Gerenciar retornos', 'grupo' => 'Atendimento', 'descricao' => 'Permite visualizar e tratar retornos.'],
        'acordos.visualizar' => ['nome' => 'Visualizar acordos', 'grupo' => 'Acordos', 'descricao' => 'Permissão legada para consultar acordos.'],
        'acordos.carteira.visualizar' => ['nome' => 'Visualizar carteira de acordos', 'grupo' => 'Acordos', 'descricao' => 'Permite acompanhar parcelas, situação, próxima ação e encerramento.'],
        'acordos.carteira.editar' => ['nome' => 'Editar carteira de acordos', 'grupo' => 'Acordos', 'descricao' => 'Permite alterar dados operacionais da carteira conforme as demais permissões.'],
        'acordos.cadastro_manual' => ['nome' => 'Cadastrar acordo manual', 'grupo' => 'Acordos', 'descricao' => 'Permite incluir acordo fechado fora da integração, com auditoria.'],
        'acordos.corrigir' => ['nome' => 'Corrigir parcelas/pagamentos', 'grupo' => 'Acordos', 'descricao' => 'Permite registrar ou corrigir pagamento manual e recalcular o acordo.'],
        'acordos.desfazer' => ['nome' => 'Desfazer correções manuais', 'grupo' => 'Acordos', 'descricao' => 'Permite reverter movimentos manuais auditados sem excluir histórico.'],
        'acordos.criar' => ['nome' => 'Fechar promessa/acordo', 'grupo' => 'Acordos', 'descricao' => 'Permite criar promessa/acordo no financeiro do Pactua.'],
        'acordos.status' => ['nome' => 'Alterar status de acordo', 'grupo' => 'Acordos', 'descricao' => 'Permite marcar acordo como cumprido, quebrado, cancelado ou formalizado.'],
        'acordos.integracao' => ['nome' => 'Integrar acordo com credor', 'grupo' => 'Acordos', 'descricao' => 'Permite enviar/preparar integração de acordo com credor quando aplicável.'],
        'acordos.atribuir' => ['nome' => 'Atribuir acordo a outro operador', 'grupo' => 'Acordos', 'descricao' => 'Permite alterar o responsável por um acordo para outro usuário.'],
        'acordos.gerenciar' => ['nome' => 'Gerenciar acordos', 'grupo' => 'Acordos', 'descricao' => 'Permissão ampla de gestão de acordos, incluindo atribuição.'],
        'acordos.configurar_carencia' => ['nome' => 'Configurar carência', 'grupo' => 'Credores', 'descricao' => 'Permite definir dias de carência por credor e, opcionalmente, por produto antes da quebra automática.'],
        'acordos.sincronizar' => ['nome' => 'Sincronizar acordo com credor', 'grupo' => 'Acordos', 'descricao' => 'Permite consultar manualmente o adapter do credor e registrar divergências sem alterar pagamento automaticamente.'],
        'credores.visualizar' => ['nome' => 'Visualizar credores', 'grupo' => 'Credores', 'descricao' => 'Permite consultar o cadastro e as configurações dos credores.'],
        'credores.editar' => ['nome' => 'Editar credores', 'grupo' => 'Credores', 'descricao' => 'Permite cadastrar credores e alterar parâmetros operacionais não sensíveis.'],
        'relatorios.visualizar' => ['nome' => 'Visualizar/exportar relatórios CSV', 'grupo' => 'Relatórios', 'descricao' => 'Permite acessar relatórios CSV respeitando o escopo de credores do usuário.'],
        'relatorios.api.gerenciar' => ['nome' => 'Gerenciar token Excel/API', 'grupo' => 'Relatórios', 'descricao' => 'Permite criar e revogar token read-only para Excel, Power Query e integrações de planejamento.'],
        'dashboard.visualizar' => ['nome' => 'Visualizar dashboard', 'grupo' => 'Gestão', 'descricao' => 'Permite abrir indicadores e resumo da operação.'],
        'tabulacoes.configurar' => ['nome' => 'Configurar tabulações', 'grupo' => 'Configurações', 'descricao' => 'Permite alterar resultados/eventos de atendimento.'],
        'usuarios.gerenciar' => ['nome' => 'Gerenciar usuários/perfis', 'grupo' => 'Configurações', 'descricao' => 'Permite alterar usuários, perfis e permissões.'],
        'cargas.visualizar' => ['nome' => 'Visualizar cargas', 'grupo' => 'Configurações', 'descricao' => 'Permite acessar status de cargas.'],
        'cargas.gerenciar_rotinas' => ['nome' => 'Gerenciar rotinas de carga (SFTP)', 'grupo' => 'Configurações', 'descricao' => 'Permite cadastrar, editar, ativar/pausar e executar rotinas de coleta automática de carga via SFTP, incluindo credenciais.'],
        'integracoes.visualizar' => ['nome' => 'Visualizar integrações', 'grupo' => 'Configurações', 'descricao' => 'Permite consultar saúde, latência e erros das integrações externas sem acesso a segredos.'],
        'seguranca.visualizar' => ['nome' => 'Visualizar segurança', 'grupo' => 'Configurações', 'descricao' => 'Permite acessar painéis de segurança/login.'],
        'auditoria.visualizar' => ['nome' => 'Visualizar auditoria', 'grupo' => 'Configurações', 'descricao' => 'Permite acessar auditoria de ações sensíveis.'],
    ];
}

/** @return list<string> */
function perfisTodosCodigosPermissao(): array
{
    return array_keys(perfisPermissoesCatalogo());
}

/** @return array{link_table:string,link_perfil:?string,link_perm_codigo:?string,link_perm_id:?string,link_fields:list<string>,perm_fields:list<string>,perfil_numeric:bool} */
function perfisPermissaoConfig(PDO $pdo): array
{
    $linkTable = perfisTableExists($pdo, 'perfil_permissoes') ? 'perfil_permissoes' : '';
    $linkFields = $linkTable !== '' ? perfisFields($pdo, $linkTable) : [];
    $permFields = perfisTableExists($pdo, 'permissoes') ? perfisFields($pdo, 'permissoes') : [];
    $linkPerfil = perfisFirstField($linkFields, ['perfil_id', 'id_perfil', 'perfil_usuario_id', 'funcao_id', 'perfil_codigo', 'codigo_perfil', 'perfil', 'perfil_nome', 'perfil_chave']);

    return [
        'link_table' => $linkTable,
        'link_perfil' => $linkPerfil,
        'link_perm_codigo' => perfisFirstField($linkFields, ['permissao_codigo', 'codigo_permissao', 'permissao', 'codigo', 'permissao_chave']),
        'link_perm_id' => perfisFirstField($linkFields, ['permissao_id', 'id_permissao']),
        'link_fields' => $linkFields,
        'perm_fields' => $permFields,
        'perfil_numeric' => $linkTable !== '' && $linkPerfil !== null ? perfisIsNumericColumn($pdo, $linkTable, $linkPerfil) : false,
    ];
}

/** @return array<string,array{codigo:string,nome:string,descricao:string,valor:string,padroes:list<string>}> */
function perfisListarPadroes(PDO $pdo, array $cfg): array
{
    $catalogo = perfisPadroesCatalogo();
    $numeric = (bool) ($cfg['perfil_numeric'] ?? false);
    $perfis = [];
    $perfisBanco = [];

    if ($numeric && perfisTableExists($pdo, 'perfis_acesso')) {
        $fields = perfisFields($pdo, 'perfis_acesso');
        if (in_array('id', $fields, true) && in_array('codigo', $fields, true)) {
            try {
                $cols = ['id', 'codigo'];
                foreach (['nome', 'descricao', 'ativo'] as $col) {
                    if (in_array($col, $fields, true)) {
                        $cols[] = $col;
                    }
                }
                $rows = $pdo->query('SELECT `' . implode('`,`', $cols) . '` FROM perfis_acesso ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $codigoBanco = trim((string) ($row['codigo'] ?? ''));
                    $idBanco = (int) ($row['id'] ?? 0);
                    if ($codigoBanco !== '' && $idBanco > 0) {
                        $perfisBanco[$codigoBanco] = $row;
                    }
                }
            } catch (Throwable) {
                $perfisBanco = [];
            }
        }
    }

    foreach ($catalogo as $codigo => $perfil) {
        $perfilBanco = $perfisBanco[$codigo] ?? null;
        if ($numeric && $perfilBanco === null) {
            // Nunca inventa um ID para uma chave estrangeira. A migration 080
            // cadastra os perfis canonicos que estiverem ausentes.
            continue;
        }
        $valor = $numeric
            ? (string) ((int) $perfilBanco['id'])
            : $perfil['valor_texto'];
        $padroes = $perfil['padroes'] === ['*'] ? perfisTodosCodigosPermissao() : $perfil['padroes'];
        $perfis[$valor] = [
            'codigo' => $codigo,
            'nome' => trim((string) ($perfilBanco['nome'] ?? '')) ?: $perfil['nome'],
            'descricao' => trim((string) ($perfilBanco['descricao'] ?? '')) ?: $perfil['descricao'],
            'valor' => $valor,
            'padroes' => $padroes,
        ];
    }

    foreach ($perfisBanco as $codigo => $perfilBanco) {
        $valor = (string) (int) ($perfilBanco['id'] ?? 0);
        if ($valor === '0' || isset($perfis[$valor])) {
            continue;
        }
        $perfis[$valor] = [
            'codigo' => $codigo,
            'nome' => trim((string) ($perfilBanco['nome'] ?? '')) ?: ucfirst($codigo),
            'descricao' => trim((string) ($perfilBanco['descricao'] ?? '')) ?: 'Perfil cadastrado no banco de dados.',
            'valor' => $valor,
            'padroes' => [],
        ];
    }

    // Acrescenta qualquer perfil já existente no vínculo de permissões, para não esconder legado real.
    if (($cfg['link_table'] ?? '') !== '' && ($cfg['link_perfil'] ?? null) !== null) {
        try {
            $table = str_replace('`', '', (string) $cfg['link_table']);
            $field = str_replace('`', '', (string) $cfg['link_perfil']);
            $rows = $pdo->query('SELECT DISTINCT TRIM(CAST(`' . $field . '` AS CHAR)) AS perfil_valor FROM `' . $table . '` WHERE `' . $field . '` IS NOT NULL AND TRIM(CAST(`' . $field . '` AS CHAR)) <> "" ORDER BY perfil_valor')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $valor = trim((string) ($row['perfil_valor'] ?? ''));
                if ($valor === '' || isset($perfis[$valor])) {
                    continue;
                }
                $nome = ctype_digit($valor) ? 'Perfil #' . $valor : ucfirst($valor);
                $perfis[$valor] = [
                    'codigo' => $valor,
                    'nome' => $nome,
                    'descricao' => 'Perfil legado encontrado nas permissões já gravadas.',
                    'valor' => $valor,
                    'padroes' => [],
                ];
            }
        } catch (Throwable) {
            // Não impede a tela de abrir.
        }
    }

    return $perfis;
}

/** @return array<string,array{usuarios:int,permissoes:int}> */
function perfisResumoUso(PDO $pdo): array
{
    if (!perfisTableExists($pdo, 'perfis_acesso')) {
        return [];
    }

    try {
        $temUsuarios = perfisTableExists($pdo, 'usuarios');
        $temVinculos = perfisTableExists($pdo, 'perfil_permissoes');
        $sql = 'SELECT pa.id'
            . ($temUsuarios ? ', (SELECT COUNT(*) FROM usuarios u WHERE u.perfil_id = pa.id) AS total_usuarios' : ', 0 AS total_usuarios')
            . ($temVinculos ? ', (SELECT COUNT(*) FROM perfil_permissoes pp WHERE pp.perfil_id = pa.id) AS total_permissoes' : ', 0 AS total_permissoes')
            . ' FROM perfis_acesso pa';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $resumo = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $resumo[$id] = [
                'usuarios' => (int) ($row['total_usuarios'] ?? 0),
                'permissoes' => (int) ($row['total_permissoes'] ?? 0),
            ];
        }
        return $resumo;
    } catch (Throwable) {
        return [];
    }
}

function perfisNormalizarCodigo(string $codigo): string
{
    $codigo = trim(mb_strtolower($codigo));
    if ($codigo === '') {
        return '';
    }
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $codigo) : false;
    if (is_string($ascii) && $ascii !== '') {
        $codigo = mb_strtolower($ascii);
    }
    $codigo = (string) preg_replace('/[^a-z0-9]+/', '_', $codigo);
    $codigo = trim($codigo, '_');
    if ($codigo !== '' && ctype_digit($codigo[0])) {
        $codigo = 'perfil_' . $codigo;
    }
    return mb_substr($codigo, 0, 60);
}

function perfisCriar(PDO $pdo, string $nome, string $codigoInformado, string $descricao, ?int $copiarPerfilId): int
{
    if (!perfisTableExists($pdo, 'perfis_acesso') || !perfisTableExists($pdo, 'perfil_permissoes')) {
        throw new RuntimeException('A estrutura de perfis não está disponível. Execute a migration 080.');
    }

    $nome = trim($nome);
    if (mb_strlen($nome) < 3 || mb_strlen($nome) > 120) {
        throw new RuntimeException('O nome do perfil deve ter entre 3 e 120 caracteres.');
    }
    $codigo = perfisNormalizarCodigo($codigoInformado !== '' ? $codigoInformado : $nome);
    if (!preg_match('/^[a-z][a-z0-9_]{2,59}$/', $codigo)) {
        throw new RuntimeException('O código do perfil deve começar com letra e usar apenas letras minúsculas, números e sublinhado.');
    }
    $descricao = trim($descricao);
    if (mb_strlen($descricao) > 500) {
        throw new RuntimeException('A descrição do perfil deve ter no máximo 500 caracteres.');
    }

    $duplicado = $pdo->prepare('SELECT id FROM perfis_acesso WHERE codigo = :codigo OR LOWER(TRIM(nome)) = LOWER(TRIM(:nome)) LIMIT 1');
    $duplicado->execute(['codigo' => $codigo, 'nome' => $nome]);
    if ($duplicado->fetchColumn() !== false) {
        throw new RuntimeException('Já existe um perfil com esse nome ou código.');
    }

    if ($copiarPerfilId !== null) {
        $origem = $pdo->prepare('SELECT id FROM perfis_acesso WHERE id = :id LIMIT 1');
        $origem->bindValue('id', $copiarPerfilId, PDO::PARAM_INT);
        $origem->execute();
        if ($origem->fetchColumn() === false) {
            throw new RuntimeException('O perfil usado como modelo não foi encontrado.');
        }
    }

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare('INSERT INTO perfis_acesso (codigo, nome, nivel, descricao, ativo) VALUES (:codigo, :nome, :nivel, :descricao, 1)');
        $insert->bindValue('codigo', $codigo);
        $insert->bindValue('nome', $nome);
        $insert->bindValue('nivel', 10, PDO::PARAM_INT);
        $insert->bindValue('descricao', $descricao !== '' ? $descricao : null, $descricao !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $insert->execute();
        $perfilId = (int) $pdo->lastInsertId();
        if ($perfilId <= 0) {
            throw new RuntimeException('Não foi possível obter o ID do novo perfil.');
        }

        if ($copiarPerfilId !== null) {
            $copiar = $pdo->prepare('INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id) SELECT :novo_perfil_id, permissao_id FROM perfil_permissoes WHERE perfil_id = :perfil_origem_id');
            $copiar->bindValue('novo_perfil_id', $perfilId, PDO::PARAM_INT);
            $copiar->bindValue('perfil_origem_id', $copiarPerfilId, PDO::PARAM_INT);
            $copiar->execute();
        }

        $pdo->commit();
        return $perfilId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @return array<string,array{codigo:string,nome:string,grupo:string,descricao:string,id:int|null}> */
function perfisListarPermissoes(PDO $pdo): array
{
    $ret = [];
    foreach (perfisPermissoesCatalogo() as $codigo => $dados) {
        $ret[$codigo] = ['codigo' => $codigo, 'nome' => $dados['nome'], 'grupo' => $dados['grupo'], 'descricao' => $dados['descricao'], 'id' => null];
    }

    if (!perfisTableExists($pdo, 'permissoes')) {
        return $ret;
    }

    $fields = perfisFields($pdo, 'permissoes');
    if (!in_array('codigo', $fields, true)) {
        return $ret;
    }

    try {
        $cols = ['codigo'];
        foreach (['id', 'nome', 'grupo', 'descricao'] as $col) {
            if (in_array($col, $fields, true)) {
                $cols[] = $col;
            }
        }
        $rows = $pdo->query('SELECT `' . implode('`,`', $cols) . '` FROM permissoes ORDER BY ' . (in_array('grupo', $fields, true) ? 'grupo, ' : '') . 'codigo')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $codigo = trim((string) ($row['codigo'] ?? ''));
            if ($codigo === '') {
                continue;
            }
            $base = perfisPermissoesCatalogo()[$codigo] ?? ['nome' => $codigo, 'grupo' => 'Outras', 'descricao' => ''];
            $ret[$codigo] = [
                'codigo' => $codigo,
                'nome' => trim((string) ($row['nome'] ?? $base['nome'])) ?: $codigo,
                'grupo' => trim((string) ($row['grupo'] ?? $base['grupo'])) ?: 'Outras',
                'descricao' => trim((string) ($row['descricao'] ?? $base['descricao'])),
                'id' => isset($row['id']) ? (int) $row['id'] : null,
            ];
        }
    } catch (Throwable) {
        // Usa catálogo padrão.
    }

    ksort($ret);
    return $ret;
}

function perfisUpsertPermissoes(PDO $pdo): void
{
    if (!perfisTableExists($pdo, 'permissoes')) {
        return;
    }

    $fields = perfisFields($pdo, 'permissoes');
    if (!in_array('codigo', $fields, true)) {
        return;
    }

    foreach (perfisPermissoesCatalogo() as $codigo => $dados) {
        try {
            $stmt = $pdo->prepare('SELECT ' . (in_array('id', $fields, true) ? 'id' : 'codigo') . ' FROM permissoes WHERE codigo = :codigo LIMIT 1');
            $stmt->bindValue('codigo', $codigo);
            $stmt->execute();
            $exists = $stmt->fetchColumn() !== false;

            if (!$exists) {
                $cols = ['codigo'];
                $params = ['codigo' => $codigo];
                if (in_array('nome', $fields, true)) { $cols[] = 'nome'; $params['nome'] = $dados['nome']; }
                if (in_array('descricao', $fields, true)) { $cols[] = 'descricao'; $params['descricao'] = $dados['descricao']; }
                if (in_array('grupo', $fields, true)) { $cols[] = 'grupo'; $params['grupo'] = $dados['grupo']; }
                if (in_array('ativo', $fields, true)) { $cols[] = 'ativo'; $params['ativo'] = 1; }
                if (in_array('criado_em', $fields, true)) { $cols[] = 'criado_em'; }
                $sql = 'INSERT INTO permissoes (`' . implode('`,`', $cols) . '`) VALUES ('
                    . implode(',', array_map(static fn (string $c): string => $c === 'criado_em' ? 'NOW()' : ':' . $c, $cols)) . ')';
                $insert = $pdo->prepare($sql);
                foreach ($params as $key => $value) {
                    $insert->bindValue($key, $value);
                }
                $insert->execute();
            }
        } catch (Throwable) {
            continue;
        }
    }
}

/** @return list<string> */
function perfisPermissoesDoPerfil(PDO $pdo, array $cfg, string $perfilValor, array $perfilPadrao): array
{
    $ret = [];
    $consultaExecutada = false;
    if (($cfg['link_table'] ?? '') !== '' && ($cfg['link_perfil'] ?? null) !== null) {
        try {
            $table = str_replace('`', '', (string) $cfg['link_table']);
            $perfilCol = str_replace('`', '', (string) $cfg['link_perfil']);
            if (($cfg['link_perm_codigo'] ?? null) !== null) {
                $permCol = str_replace('`', '', (string) $cfg['link_perm_codigo']);
                $sql = 'SELECT `' . $permCol . '` AS codigo FROM `' . $table . '` WHERE `' . $perfilCol . '` = :perfil_id';
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue('perfil_id', $perfilValor);
                $stmt->execute();
                $ret = array_values(array_filter(array_map(static fn (array $row): string => trim((string) ($row['codigo'] ?? '')), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [])));
                $consultaExecutada = true;
            } elseif (($cfg['link_perm_id'] ?? null) !== null && perfisTableExists($pdo, 'permissoes') && in_array('id', $cfg['perm_fields'], true) && in_array('codigo', $cfg['perm_fields'], true)) {
                $permCol = str_replace('`', '', (string) $cfg['link_perm_id']);
                $sql = 'SELECT p.codigo FROM `' . $table . '` pp INNER JOIN permissoes p ON p.id = pp.`' . $permCol . '` WHERE pp.`' . $perfilCol . '` = :perfil_id';
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue('perfil_id', $perfilValor);
                $stmt->execute();
                $ret = array_values(array_filter(array_map(static fn (array $row): string => trim((string) ($row['codigo'] ?? '')), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [])));
                $consultaExecutada = true;
            }
        } catch (Throwable) {
            $ret = [];
            $consultaExecutada = false;
        }
    }

    // Tabela válida e consulta executada: lista vazia significa que o perfil
    // realmente não possui permissões. Não restaura padrões removidos.
    if ($consultaExecutada) {
        return array_values(array_unique($ret));
    }

    // Fallback somente para instalações ainda sem a estrutura canônica.
    return $perfilPadrao;
}

/** @param list<string> $codigos */
function perfisSalvarPermissoes(PDO $pdo, array $cfg, string $perfilValor, array $codigos, array $permissoes): void
{
    if (($cfg['link_table'] ?? '') === '' || ($cfg['link_perfil'] ?? null) === null) {
        throw new RuntimeException('Estrutura perfil_permissoes indisponível. Execute a migration 080_repara_perfil_permissoes.sql.');
    }

    $table = str_replace('`', '', (string) $cfg['link_table']);
    $perfilCol = str_replace('`', '', (string) $cfg['link_perfil']);
    $codigos = array_values(array_unique(array_filter(array_map('strval', $codigos), static fn (string $v): bool => trim($v) !== '')));
    $idsPermissoes = [];

    // Resolve tudo antes do DELETE. Assim uma falha ao cadastrar o catalogo
    // nunca apaga os vinculos atuais do perfil.
    if (($cfg['link_perm_id'] ?? null) !== null) {
        $naoResolvidas = [];
        foreach ($codigos as $codigo) {
            $permissaoId = $permissoes[$codigo]['id'] ?? null;
            if ($permissaoId === null || (int) $permissaoId <= 0) {
                $naoResolvidas[] = $codigo;
                continue;
            }
            $idsPermissoes[$codigo] = (int) $permissaoId;
        }
        if ($naoResolvidas !== []) {
            throw new RuntimeException(
                'Não foi possível localizar no banco as permissões: ' . implode(', ', $naoResolvidas) . '. Nenhuma alteração foi feita.'
            );
        }
    }

    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM `' . $table . '` WHERE `' . $perfilCol . '` = :perfil_id');
        $delete->bindValue('perfil_id', $perfilValor);
        $delete->execute();

        $createdField = perfisFirstField($cfg['link_fields'], ['criado_em', 'created_at']);
        if (($cfg['link_perm_codigo'] ?? null) !== null) {
            $permCol = str_replace('`', '', (string) $cfg['link_perm_codigo']);
            $cols = [$perfilCol, $permCol];
            $values = [':perfil_id', ':codigo'];
            if ($createdField !== null) {
                $cols[] = $createdField;
                $values[] = 'NOW()';
            }
            $insert = $pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $values) . ')');
            foreach ($codigos as $codigo) {
                $insert->bindValue('perfil_id', $perfilValor);
                $insert->bindValue('codigo', $codigo);
                $insert->execute();
            }
        } elseif (($cfg['link_perm_id'] ?? null) !== null) {
            $permCol = str_replace('`', '', (string) $cfg['link_perm_id']);
            $cols = [$perfilCol, $permCol];
            $values = [':perfil_id', ':permissao_id'];
            if ($createdField !== null) {
                $cols[] = $createdField;
                $values[] = 'NOW()';
            }
            $insert = $pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $values) . ')');
            foreach ($codigos as $codigo) {
                $insert->bindValue('perfil_id', $perfilValor);
                $insert->bindValue('permissao_id', $idsPermissoes[$codigo], PDO::PARAM_INT);
                $insert->execute();
            }
        } else {
            throw new RuntimeException('Não foi possível identificar o campo de permissão no vínculo perfil_permissoes.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

try {
    $cfg = perfisPermissaoConfig($pdo);
    perfisUpsertPermissoes($pdo);
    $cfg = perfisPermissaoConfig($pdo);
    $perfis = perfisListarPadroes($pdo, $cfg);
    $permissoes = perfisListarPermissoes($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validarCsrf();
        $acao = (string) ($_POST['acao'] ?? '');
        if ($acao === 'criar_perfil') {
            $nomePerfil = trim((string) ($_POST['nome'] ?? ''));
            $codigoPerfil = trim((string) ($_POST['codigo'] ?? ''));
            $descricaoPerfil = trim((string) ($_POST['descricao'] ?? ''));
            $copiarPerfilId = ctype_digit((string) ($_POST['copiar_perfil_id'] ?? '')) && (int) $_POST['copiar_perfil_id'] > 0
                ? (int) $_POST['copiar_perfil_id']
                : null;
            $novoPerfilId = perfisCriar($pdo, $nomePerfil, $codigoPerfil, $descricaoPerfil, $copiarPerfilId);
            registrarAuditoriaSistema('perfil.criado', 'perfil_usuario', $novoPerfilId, 'Perfil criado pela administração.', null, [
                'nome' => $nomePerfil,
                'codigo' => perfisNormalizarCodigo($codigoPerfil !== '' ? $codigoPerfil : $nomePerfil),
                'copiado_de_perfil_id' => $copiarPerfilId,
            ]);
            header('Location: /operacao/configuracoes/perfis.php?perfil_id=' . $novoPerfilId . '&ok=perfil_criado');
            exit;
        }
        if ($acao === 'salvar_permissoes') {
            $perfilValor = trim((string) ($_POST['perfil_id'] ?? ''));
            if ($perfilValor === '' || !isset($perfis[$perfilValor])) {
                throw new RuntimeException('Perfil inválido.');
            }
            $codigos = isset($_POST['permissoes']) && is_array($_POST['permissoes']) ? array_map('strval', $_POST['permissoes']) : [];
            perfisSalvarPermissoes($pdo, $cfg, $perfilValor, $codigos, $permissoes);
            registrarAuditoriaSistema('perfil.permissoes_atualizadas', 'perfil_usuario', $perfilValor, 'Permissões do perfil atualizadas.', null, ['permissoes' => $codigos]);
            header('Location: /operacao/configuracoes/perfis.php?perfil_id=' . rawurlencode($perfilValor) . '&ok=1');
            exit;
        }
    }

    $perfilSelecionado = trim((string) ($_GET['perfil_id'] ?? ''));
    if ($perfilSelecionado === '' || !isset($perfis[$perfilSelecionado])) {
        $perfilSelecionado = (string) array_key_first($perfis);
    }
    $permissoesSelecionadas = perfisPermissoesDoPerfil($pdo, $cfg, $perfilSelecionado, $perfis[$perfilSelecionado]['padroes'] ?? []);
    if (isset($_GET['ok'])) {
        $mensagemSucesso = (string) $_GET['ok'] === 'perfil_criado'
            ? 'Perfil criado com sucesso. Agora revise e salve as permissões.'
            : 'Permissões do perfil atualizadas com sucesso.';
    }
} catch (Throwable $e) {
    $erro = $e->getMessage();
    $cfg = $cfg ?? [];
    $perfis = $perfis ?? [];
    $permissoes = $permissoes ?? [];
    $perfilSelecionado = $perfilSelecionado ?? '';
    $permissoesSelecionadas = $permissoesSelecionadas ?? [];
}

$grupos = [];
foreach ($permissoes as $codigo => $permissao) {
    $grupo = trim((string) ($permissao['grupo'] ?? 'Outras')) ?: 'Outras';
    $grupos[$grupo][$codigo] = $permissao;
}
$ordemGrupos = ['Gestão', 'Dashboard', 'Atendimento', 'Campanhas', 'Acordos', 'Preventiva', 'Credores', 'Relatórios', 'Configurações', 'Segurança', 'Outras'];
uksort($grupos, static function (string $a, string $b) use ($ordemGrupos): int {
    $posA = array_search($a, $ordemGrupos, true);
    $posB = array_search($b, $ordemGrupos, true);
    $posA = $posA === false ? PHP_INT_MAX : $posA;
    $posB = $posB === false ? PHP_INT_MAX : $posB;
    return $posA === $posB ? strcasecmp($a, $b) : $posA <=> $posB;
});

$descricaoGrupos = [
    'Gestão' => 'Indicadores e visão geral da operação.',
    'Dashboard' => 'Indicadores e visão geral da operação.',
    'Atendimento' => 'Clientes, acionamentos, localização e retornos.',
    'Campanhas' => 'Filas, campanhas e distribuição de trabalho.',
    'Acordos' => 'Financeiro, boletos, acordos, parcelas e sincronização.',
    'Preventiva' => 'Acompanhamento preventivo de vencimentos.',
    'Credores' => 'Consulta e configuração dos credores.',
    'Relatórios' => 'Relatórios CSV, Excel, Power Query e tokens.',
    'Configurações' => 'Cadastros administrativos e parâmetros do CRM.',
    'Segurança' => 'Auditoria, acesso e segurança operacional.',
    'Outras' => 'Permissões adicionais cadastradas no banco.',
];

$perfilAtual = $perfis[$perfilSelecionado] ?? null;
$resumoPerfis = perfisResumoUso($pdo);
$codigosPermissoes = array_keys($permissoes);
$permissoesSelecionadas = array_values(array_intersect($codigosPermissoes, $permissoesSelecionadas));
$totalPermissoes = count($codigosPermissoes);
$totalSelecionadas = count($permissoesSelecionadas);
$totalBloqueadas = max(0, $totalPermissoes - $totalSelecionadas);
$abrirModalNovoPerfil = $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['acao'] ?? '') === 'criar_perfil'
    && $erro !== null;

require __DIR__ . '/../includes/header.php';
?>
<main class="container access-admin-page">
    <?php require __DIR__ . '/../includes/config_nav.php'; ?>
    <?php if ($mensagemSucesso !== null): ?>
        <section class="alert success"><?= h($mensagemSucesso) ?></section>
    <?php endif; ?>

    <?php if ($erro !== null): ?>
        <section class="alert error"><strong>Não foi possível concluir.</strong> <?= h($erro) ?></section>
    <?php endif; ?>

    <header class="access-admin-head">
        <div>
            <span>Administração de acesso</span>
            <h2>Perfis e permissões</h2>
            <p>Escolha um perfil à esquerda e defina o que seus usuários poderão fazer no CRM.</p>
        </div>
        <div>
            <a class="button secondary" href="/operacao/configuracoes/usuarios.php">Ver usuários</a>
            <button class="button" type="button" data-profile-modal-open>+ Novo perfil</button>
        </div>
    </header>

    <section class="access-how-card" aria-label="Como o acesso funciona">
        <div><b>1</b><span><strong>Crie o perfil</strong><small>Ex.: Operador, Supervisão ou Planejamento.</small></span></div>
        <i>→</i>
        <div><b>2</b><span><strong>Escolha as permissões</strong><small>Defina claramente o que ele pode fazer.</small></span></div>
        <i>→</i>
        <div><b>3</b><span><strong>Atribua aos usuários</strong><small>Os credores continuam definindo onde podem atuar.</small></span></div>
    </section>

    <div class="access-workspace">
        <aside class="card access-profile-sidebar">
            <header>
                <div><strong>Perfis</strong><small><?= count($perfis) ?> cadastrados</small></div>
                <button type="button" data-profile-modal-open aria-label="Adicionar perfil">+</button>
            </header>
            <?php if ($perfis === []): ?>
                <div class="empty">Nenhum perfil cadastrado.</div>
            <?php else: ?>
                <nav aria-label="Perfis disponíveis">
                    <?php foreach ($perfis as $valor => $perfil): ?>
                        <?php
                        $selecionado = (string) $perfilSelecionado === (string) $valor;
                        $uso = $resumoPerfis[(string) $valor] ?? ['usuarios' => 0, 'permissoes' => 0];
                        ?>
                        <a class="access-profile-link<?= $selecionado ? ' is-selected' : '' ?>" href="/operacao/configuracoes/perfis.php?perfil_id=<?= rawurlencode((string) $valor) ?>"<?= $selecionado ? ' aria-current="page"' : '' ?>>
                            <span class="access-profile-avatar"><?= h(mb_strtoupper(mb_substr((string) ($perfil['nome'] ?? $valor), 0, 1))) ?></span>
                            <span>
                                <strong><?= h((string) ($perfil['nome'] ?? $valor)) ?></strong>
                                <small><?= (int) $uso['usuarios'] ?> usuário(s) · <?= (int) $uso['permissoes'] ?> permissões</small>
                            </span>
                            <i aria-hidden="true">›</i>
                        </a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
            <footer><span>Perfil</span> controla ações. <span>Credor</span> controla o escopo.</footer>
        </aside>

        <section class="access-profile-content">
            <?php if ($perfilAtual === null): ?>
                <div class="card empty">Selecione ou crie um perfil para configurar suas permissões.</div>
            <?php else: ?>
                <?php $usoAtual = $resumoPerfis[(string) $perfilSelecionado] ?? ['usuarios' => 0, 'permissoes' => $totalSelecionadas]; ?>
                <form method="post" action="/operacao/configuracoes/perfis.php?perfil_id=<?= rawurlencode((string) $perfilSelecionado) ?>" class="access-permission-form" data-permission-form>
                    <?= authCsrfInput() ?>
                    <input type="hidden" name="acao" value="salvar_permissoes">
                    <input type="hidden" name="perfil_id" value="<?= h($perfilSelecionado) ?>">

                    <section class="card access-profile-hero">
                        <div class="access-profile-main">
                            <span class="access-profile-avatar is-large"><?= h(mb_strtoupper(mb_substr((string) ($perfilAtual['nome'] ?? $perfilSelecionado), 0, 1))) ?></span>
                            <div>
                                <span>Perfil selecionado</span>
                                <h3><?= h((string) ($perfilAtual['nome'] ?? $perfilSelecionado)) ?></h3>
                                <p><?= h((string) ($perfilAtual['descricao'] ?? 'Perfil cadastrado no CRM.')) ?></p>
                            </div>
                        </div>
                        <div class="access-profile-facts">
                            <div><strong><?= (int) $usoAtual['usuarios'] ?></strong><span>Usuários</span></div>
                            <div><strong data-permission-selected><?= $totalSelecionadas ?></strong><span>Permitidas</span></div>
                            <div><strong data-permission-blocked><?= $totalBloqueadas ?></strong><span>Bloqueadas</span></div>
                        </div>
                    </section>

                    <?php if (($cfg['link_table'] ?? '') === '' || ($cfg['link_perfil'] ?? null) === null): ?>
                        <div class="alert warning">A estrutura de permissões não está disponível. Execute a migration <code>080_repara_perfil_permissoes.sql</code>.</div>
                    <?php endif; ?>

                    <section class="card access-permission-tools">
                        <label>
                            <span>Buscar permissão</span>
                            <input type="search" placeholder="Digite clientes, acordos, relatórios..." autocomplete="off" data-permission-search>
                        </label>
                        <div class="access-permission-actions">
                            <button type="button" data-permission-expand="all">Expandir módulos</button>
                            <button type="button" data-permission-expand="none">Recolher módulos</button>
                            <button type="button" data-permission-set="all">Permitir tudo</button>
                            <button class="is-danger" type="button" data-permission-set="none">Bloquear tudo</button>
                        </div>
                    </section>

                    <div class="access-permission-modules">
                        <?php foreach ($grupos as $indiceGrupo => $lista): ?>
                            <?php
                            $grupo = (string) $indiceGrupo;
                            $marcadasGrupo = count(array_intersect(array_keys($lista), $permissoesSelecionadas));
                            $grupoAberto = in_array($grupo, ['Gestão', 'Dashboard', 'Atendimento'], true);
                            ?>
                            <details class="card access-permission-module" data-permission-group<?= $grupoAberto ? ' open' : '' ?>>
                                <summary>
                                    <span class="access-module-icon"><?= h(mb_strtoupper(mb_substr($grupo, 0, 1))) ?></span>
                                    <span class="access-module-copy">
                                        <strong><?= h($grupo) ?></strong>
                                        <small><?= h($descricaoGrupos[$grupo] ?? 'Permissões relacionadas a este módulo.') ?></small>
                                    </span>
                                    <span class="access-module-count" data-permission-group-count><?= $marcadasGrupo ?>/<?= count($lista) ?> permitidas</span>
                                    <span class="access-module-arrow" aria-hidden="true">⌄</span>
                                </summary>
                                <div class="access-module-body">
                                    <div class="access-module-actions">
                                        <span>Permissões deste módulo</span>
                                        <button type="button" data-permission-group-toggle><?= $marcadasGrupo === count($lista) ? 'Bloquear módulo' : 'Permitir módulo' ?></button>
                                    </div>
                                    <?php foreach ($lista as $codigo => $permissao): ?>
                                        <?php $textoBusca = mb_strtolower($grupo . ' ' . $codigo . ' ' . (string) ($permissao['nome'] ?? '') . ' ' . (string) ($permissao['descricao'] ?? '')); ?>
                                        <label class="access-permission-row" data-permission-item data-permission-search-text="<?= h($textoBusca) ?>">
                                            <span class="access-permission-text">
                                                <strong><?= h((string) ($permissao['nome'] ?? $codigo)) ?></strong>
                                                <small><?= h((string) ($permissao['descricao'] ?? 'Controla o acesso a esta ação.')) ?></small>
                                            </span>
                                            <span class="access-permission-control">
                                                <span data-permission-state><?= in_array($codigo, $permissoesSelecionadas, true) ? 'Permitido' : 'Bloqueado' ?></span>
                                                <span class="access-switch">
                                                    <input type="checkbox" name="permissoes[]" value="<?= h($codigo) ?>" data-permission-checkbox<?= in_array($codigo, $permissoesSelecionadas, true) ? ' checked' : '' ?>>
                                                    <i aria-hidden="true"></i>
                                                </span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>

                    <div class="empty access-permission-empty" data-permission-empty hidden>Nenhuma permissão encontrada. Tente outro termo.</div>

                    <footer class="access-savebar">
                        <div><span>Alterando</span><strong><?= h((string) ($perfilAtual['nome'] ?? $perfilSelecionado)) ?></strong><small><b data-permission-selected><?= $totalSelecionadas ?></b> de <?= $totalPermissoes ?> permissões habilitadas</small></div>
                        <button class="button" type="submit" data-permission-save>Salvar permissões</button>
                    </footer>
                </form>
            <?php endif; ?>
        </section>
    </div>
</main>

<div class="works-modal-backdrop access-profile-modal-backdrop" data-profile-modal<?= $abrirModalNovoPerfil ? '' : ' hidden' ?>>
    <section class="works-modal access-profile-modal" role="dialog" aria-modal="true" aria-labelledby="access-profile-modal-title">
        <header>
            <div><span>Novo acesso</span><h2 id="access-profile-modal-title">Criar perfil</h2><p>Crie uma função para depois atribuí-la aos usuários.</p></div>
            <button type="button" data-profile-modal-close aria-label="Fechar">×</button>
        </header>
        <form method="post" action="/operacao/configuracoes/perfis.php" data-profile-create-form>
            <?= authCsrfInput() ?>
            <input type="hidden" name="acao" value="criar_perfil">

            <div class="access-profile-modal-tip"><b>Como funciona?</b><span>Você pode começar sem acesso ou copiar as permissões de um perfil existente e ajustar depois.</span></div>

            <div class="access-profile-modal-grid">
                <label>
                    <span>Nome do perfil</span>
                    <input type="text" name="nome" maxlength="120" required placeholder="Ex.: Qualidade" value="<?= h((string) ($_POST['nome'] ?? '')) ?>" data-profile-name>
                </label>
                <label>
                    <span>Código interno</span>
                    <input type="text" name="codigo" maxlength="60" required placeholder="qualidade" value="<?= h((string) ($_POST['codigo'] ?? '')) ?>" data-profile-code>
                    <small>Gerado automaticamente; use letras, números e sublinhado.</small>
                </label>
                <label class="is-wide">
                    <span>Descrição</span>
                    <textarea name="descricao" maxlength="500" rows="3" placeholder="Explique para quem este perfil será usado."><?= h((string) ($_POST['descricao'] ?? '')) ?></textarea>
                </label>
                <label class="is-wide">
                    <span>Permissões iniciais</span>
                    <select name="copiar_perfil_id">
                        <option value="">Começar sem nenhuma permissão</option>
                        <?php foreach ($perfis as $valor => $perfil): ?>
                            <?php if (!ctype_digit((string) $valor)) continue; ?>
                            <option value="<?= (int) $valor ?>"<?= (string) ($_POST['copiar_perfil_id'] ?? '') === (string) $valor ? ' selected' : '' ?>>Copiar de <?= h((string) ($perfil['nome'] ?? $valor)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Copiar não altera o perfil original.</small>
                </label>
            </div>

            <footer><button class="button ghost" type="button" data-profile-modal-close>Cancelar</button><button class="button" type="submit">Criar perfil</button></footer>
        </form>
    </section>
</div>
<script>
(() => {
    const form = document.querySelector('[data-permission-form]');
    const normalize = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

    if (form) {
        const checkboxes = [...form.querySelectorAll('[data-permission-checkbox]')];
        const groups = [...form.querySelectorAll('[data-permission-group]')];
        const selectedCounters = [...form.querySelectorAll('[data-permission-selected]')];
        const blockedCounter = form.querySelector('[data-permission-blocked]');
        const search = form.querySelector('[data-permission-search]');
        const empty = form.querySelector('[data-permission-empty]');

        const refresh = () => {
            const selected = checkboxes.filter((checkbox) => checkbox.checked).length;
            selectedCounters.forEach((counter) => { counter.textContent = String(selected); });
            if (blockedCounter) blockedCounter.textContent = String(Math.max(0, checkboxes.length - selected));
            checkboxes.forEach((checkbox) => {
                const row = checkbox.closest('[data-permission-item]');
                const state = row?.querySelector('[data-permission-state]');
                if (state) state.textContent = checkbox.checked ? 'Permitido' : 'Bloqueado';
            });
            groups.forEach((group) => {
                const groupCheckboxes = [...group.querySelectorAll('[data-permission-checkbox]')];
                const checked = groupCheckboxes.filter((checkbox) => checkbox.checked).length;
                const counter = group.querySelector('[data-permission-group-count]');
                const toggle = group.querySelector('[data-permission-group-toggle]');
                if (counter) counter.textContent = `${checked}/${groupCheckboxes.length} permitidas`;
                if (toggle) toggle.textContent = checked === groupCheckboxes.length ? 'Bloquear módulo' : 'Permitir módulo';
            });
        };

        checkboxes.forEach((checkbox) => checkbox.addEventListener('change', refresh));
        form.querySelectorAll('[data-permission-set]').forEach((button) => button.addEventListener('click', () => {
            const checked = button.dataset.permissionSet === 'all';
            checkboxes.forEach((checkbox) => { checkbox.checked = checked; });
            refresh();
        }));
        form.querySelectorAll('[data-permission-expand]').forEach((button) => button.addEventListener('click', () => {
            const open = button.dataset.permissionExpand === 'all';
            groups.forEach((group) => { group.open = open; });
        }));
        groups.forEach((group) => group.querySelector('[data-permission-group-toggle]')?.addEventListener('click', () => {
            const groupCheckboxes = [...group.querySelectorAll('[data-permission-checkbox]')];
            const shouldCheck = groupCheckboxes.some((checkbox) => !checkbox.checked);
            groupCheckboxes.forEach((checkbox) => { checkbox.checked = shouldCheck; });
            refresh();
        }));
        search?.addEventListener('input', () => {
            const term = normalize(search.value);
            let visibleItems = 0;
            groups.forEach((group) => {
                const items = [...group.querySelectorAll('[data-permission-item]')];
                let visibleInGroup = 0;
                items.forEach((item) => {
                    const visible = term === '' || normalize(item.dataset.permissionSearchText).includes(term);
                    item.hidden = !visible;
                    if (visible) visibleInGroup++;
                });
                group.hidden = visibleInGroup === 0;
                if (term !== '' && visibleInGroup > 0) group.open = true;
                visibleItems += visibleInGroup;
            });
            if (empty) empty.hidden = visibleItems > 0;
        });
        form.addEventListener('submit', () => {
            const save = form.querySelector('[data-permission-save]');
            if (save) { save.disabled = true; save.textContent = 'Salvando...'; }
        });
        refresh();
    }

    const modal = document.querySelector('[data-profile-modal]');
    const profileName = modal?.querySelector('[data-profile-name]');
    const profileCode = modal?.querySelector('[data-profile-code]');
    let codeEdited = Boolean(profileCode?.value);
    const slug = (value) => normalize(value).replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 60);
    const openModal = () => {
        if (!modal) return;
        modal.hidden = false;
        document.documentElement.classList.add('works-modal-open');
        window.setTimeout(() => profileName?.focus(), 30);
    };
    const closeModal = () => {
        if (!modal) return;
        modal.hidden = true;
        document.documentElement.classList.remove('works-modal-open');
    };
    document.querySelectorAll('[data-profile-modal-open]').forEach((button) => button.addEventListener('click', openModal));
    modal?.querySelectorAll('[data-profile-modal-close]').forEach((button) => button.addEventListener('click', closeModal));
    modal?.addEventListener('click', (event) => { if (event.target === modal) closeModal(); });
    profileCode?.addEventListener('input', () => { codeEdited = profileCode.value.trim() !== ''; });
    profileName?.addEventListener('input', () => { if (!codeEdited && profileCode) profileCode.value = slug(profileName.value); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && modal && !modal.hidden) closeModal(); });
    if (modal && !modal.hidden) document.documentElement.classList.add('works-modal-open');
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
