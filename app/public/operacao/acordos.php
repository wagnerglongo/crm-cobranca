<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use CRM\Modules\Operacao\Repositories\CarteiraAcordosRepository;
use CRM\Modules\Operacao\Repositories\AcordoSincronizacaoRepository;
use CRM\Modules\Operacao\Repositories\CredorConfiguracaoRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Services\CredorAdapterFactory;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoAlgumaPermissao(['acordos.visualizar', 'acordos.carteira.visualizar', 'preventiva.visualizar']);

$pageTitle = 'Controle de Acordos';
$pageSubtitle = 'Supervisão de acordos, parcelas, vencimentos, integrações e ciclo de vida.';
$activePage = 'acordos_controle';
$erro = null;
$mensagemSucesso = null;
$moduloDisponivel = false;
$carteiraDisponivel = false;
$resumoCarteiraVisual = ['em_dia' => 0, 'vence_hoje' => 0, 'em_carencia' => 0, 'aguardando_proxima' => 0, 'quitado' => 0, 'quebrado' => 0];
$resumo = [
    'total' => 0,
    'pendentes' => 0,
    'vencidos' => 0,
    'hoje' => 0,
    'cumpridos' => 0,
    'quebrados' => 0,
    'cancelados' => 0,
    'formalizados' => 0,
    'pendentes_integracao' => 0,
    'erros_integracao' => 0,
    'valor_pendente' => 0.0,
];
$acordos = [];
$parcelasPorAcordo = [];
$acompanhamentosPorAcordo = [];
$sincronizacoesPorAcordo = [];
$usuariosAtribuiveisAcordo = [];
$podeAlterarStatus = usuarioEhAdminOperacao() || usuarioPode('acordos.status') || usuarioPode('acordos.gerenciar');
$podeIntegrar = usuarioEhAdminOperacao() || usuarioPode('acordos.integracao') || usuarioPode('acordos.gerenciar');
$podeAtribuirAcordo = usuarioEhAdminOperacao()
    || usuarioPode('acordos.atribuir')
    || usuarioPode('acordos.gerenciar')
    || usuarioPode('supervisao.visualizar');
$podeCadastrarManual = usuarioEhAdminOperacao()
    || usuarioPode('acordos.cadastro_manual')
    || usuarioPode('acordos.gerenciar');
$podeEditarCarteira = usuarioEhAdminOperacao()
    || usuarioPode('acordos.carteira.editar')
    || usuarioPode('acordos.corrigir')
    || usuarioPode('acordos.gerenciar')
    || usuarioPode('preventiva.editar');
$podeSincronizarCredor = usuarioEhAdminOperacao() || usuarioPode('acordos.sincronizar') || usuarioPode('acordos.gerenciar');
$podeDesfazer = usuarioEhAdminOperacao()
    || usuarioPode('acordos.desfazer')
    || usuarioPode('acordos.gerenciar');

/** @return list<array{id:int,nome:string,email:string}> */
function acordosUsuariosAtribuiveis(PDO $pdo): array
{
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM usuarios')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $fields = array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $cols);
        if (!in_array('id', $fields, true)) {
            return [];
        }

        $nomeCol = null;
        foreach (['nome', 'name', 'usuario', 'login', 'email'] as $candidate) {
            if (in_array($candidate, $fields, true)) {
                $nomeCol = $candidate;
                break;
            }
        }
        if ($nomeCol === null) {
            return [];
        }

        $emailCol = in_array('email', $fields, true) ? 'email' : null;
        $where = [];
        if (in_array('status', $fields, true)) {
            $where[] = "status = 'ativo'";
        } elseif (in_array('ativo', $fields, true)) {
            $where[] = 'ativo = 1';
        }

        $sql = 'SELECT id, `' . $nomeCol . '` AS nome'
            . ($emailCol !== null ? ', `' . $emailCol . '` AS email' : ', NULL AS email')
            . ' FROM usuarios'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY `' . $nomeCol . '` ASC LIMIT 500';

        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $usuarios = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $nome = trim((string) ($row['nome'] ?? ''));
            if ($id <= 0 || $nome === '') {
                continue;
            }
            $usuarios[] = ['id' => $id, 'nome' => $nome, 'email' => trim((string) ($row['email'] ?? ''))];
        }
        return $usuarios;
    } catch (Throwable) {
        return [];
    }
}

/** @param list<array{id:int,nome:string,email:string}> $usuarios */
function acordosResolverUsuario(array $usuarios, int $usuarioId): ?array
{
    foreach ($usuarios as $usuario) {
        if ((int) $usuario['id'] === $usuarioId) {
            return $usuario;
        }
    }
    return null;
}

/** @return array<string,mixed> */
function acordosPayload(array $acordo): array
{
    $payload = json_decode((string) ($acordo['payload_extra'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

function acordosNomeFechamento(array $acordo): string
{
    $payload = acordosPayload($acordo);
    $fechamento = is_array($payload['operador_fechamento'] ?? null) ? $payload['operador_fechamento'] : [];
    $nome = trim((string) ($fechamento['nome'] ?? ($acordo['operador_nome'] ?? '')));
    return $nome !== '' ? $nome : 'Não definido';
}

function acordosNomeResponsavel(array $acordo): string
{
    $payload = acordosPayload($acordo);
    $responsavel = is_array($payload['operador_responsavel'] ?? null) ? $payload['operador_responsavel'] : [];
    $nome = trim((string) ($responsavel['nome'] ?? ''));
    return $nome !== '' ? $nome : 'Não definido';
}

function acordosResponsavelId(array $acordo): int
{
    $payload = acordosPayload($acordo);
    $responsavel = is_array($payload['operador_responsavel'] ?? null) ? $payload['operador_responsavel'] : [];
    return (int) ($responsavel['usuario_id'] ?? 0);
}

function acordosOrigemOperacional(array $acordo): string
{
    // Lógica movida pra AcordoOperacionalRepository::classificarOrigemOperacional()
    // em 2026-09-23 pra ser reaproveitada pelo dashboard também - mantido
    // esse wrapper aqui só pra não precisar trocar as chamadas já existentes
    // nesta página.
    return AcordoOperacionalRepository::classificarOrigemOperacional($acordo);
}

function acordosOrigemOperacionalLabel(string $origem): string
{
    return match ($origem) {
        'works' => 'Pactua',
        'acme_works' => 'ACME via Pactua',
        'acme_externo' => 'ACME externo',
        'manual' => 'Manual',
        'indefinido' => 'Não definido',
        default => ucfirst(str_replace('_', ' ', $origem)),
    };
}

/** @param list<array<string,mixed>> $parcelas */
function acordosResumoParcelas(array $parcelas): array
{
    usort($parcelas, static fn (array $a, array $b): int => strcmp((string) ($a['data_vencimento'] ?? ''), (string) ($b['data_vencimento'] ?? '')));
    $pagas = 0;
    $valorPago = 0.0;
    $proxima = null;
    foreach ($parcelas as $parcela) {
        $status = (string) ($parcela['status'] ?? 'pendente');
        if ($status === 'paga') {
            $pagas++;
            $valorPago += (float) ($parcela['valor_pago'] ?? $parcela['valor_parcela'] ?? 0);
            continue;
        }
        if ($proxima === null && in_array($status, ['pendente', 'vencida'], true)) {
            $proxima = $parcela;
        }
    }

    return [
        'total' => count($parcelas),
        'pagas' => $pagas,
        'valor_pago' => $valorPago,
        'proxima' => $proxima,
        'parcelas' => $parcelas,
    ];
}

function acordosCombinaFiltroVencimento(?array $proximaParcela, string $filtro): bool
{
    if ($filtro === 'todos') {
        return true;
    }
    if ($proximaParcela === null || empty($proximaParcela['data_vencimento'])) {
        return $filtro === 'sem_vencimento';
    }

    try {
        $vencimento = new DateTimeImmutable((string) $proximaParcela['data_vencimento']);
    } catch (Throwable) {
        return $filtro === 'sem_vencimento';
    }

    $hoje = new DateTimeImmutable('today');
    $vencimento = $vencimento->setTime(0, 0);
    if ($filtro === 'vencidos') {
        return $vencimento < $hoje;
    }
    if ($filtro === 'hoje') {
        return $vencimento == $hoje;
    }

    $dias = match ($filtro) {
        'proximos_3' => 3,
        'proximos_7' => 7,
        'proximos_15' => 15,
        'proximos_30' => 30,
        default => 0,
    };
    if ($dias > 0) {
        return $vencimento > $hoje && $vencimento <= $hoje->modify('+' . $dias . ' days');
    }

    return false;
}

function acordosFiltrarLista(array $acordos, array $filtros): array
{
    $responsavel = trim((string) ($filtros['responsavel'] ?? 'todos'));
    $origem = trim((string) ($filtros['origem_operacional'] ?? 'todos'));

    return array_values(array_filter($acordos, static function (array $acordo) use ($responsavel, $origem): bool {
        if ($responsavel !== '' && $responsavel !== 'todos') {
            $responsavelId = acordosResponsavelId($acordo);
            if ($responsavel === 'sem_responsavel') {
                if ($responsavelId > 0 || acordosNomeResponsavel($acordo) !== 'Não definido') {
                    return false;
                }
            } elseif ((int) $responsavel !== $responsavelId) {
                return false;
            }
        }
        return $origem === '' || $origem === 'todos' || acordosOrigemOperacional($acordo) === $origem;
    }));
}

function acordosAtualizarResponsavel(PDO $pdo, int $acordoId, int $responsavelId, string $responsavelNome, ?string $observacao = null): void
{
    if ($acordoId <= 0 || $responsavelId <= 0 || trim($responsavelNome) === '') {
        throw new RuntimeException('Responsável inválido para atribuição do acordo.');
    }

    $stmt = $pdo->prepare('SELECT id, payload_extra, observacao FROM acordos_operacionais WHERE id = :id LIMIT 1');
    $stmt->bindValue('id', $acordoId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Acordo operacional não encontrado.');
    }

    $payload = json_decode((string) ($row['payload_extra'] ?? ''), true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $antes = is_array($payload['operador_responsavel'] ?? null) ? $payload['operador_responsavel'] : null;
    $payload['operador_responsavel'] = [
        'usuario_id' => $responsavelId,
        'nome' => $responsavelNome,
        'atribuido_por_usuario_id' => usuarioAtualId(),
        'atribuido_por_nome' => usuarioAtualNome() ?: 'Não definido',
        'atribuido_em' => date('c'),
        'origem' => 'operacao/acordos.php',
    ];
    $historico = is_array($payload['historico_responsavel'] ?? null) ? $payload['historico_responsavel'] : [];
    $historico[] = ['antes' => $antes, 'depois' => $payload['operador_responsavel'], 'observacao' => trim((string) $observacao)];
    $payload['historico_responsavel'] = array_slice($historico, -50);

    $observacaoNova = trim((string) $observacao);
    $observacaoFinal = (string) ($row['observacao'] ?? '');
    if ($observacaoNova !== '') {
        $observacaoFinal = trim($observacaoFinal . "\n[" . date('d/m/Y H:i') . '] Responsável atribuído para ' . $responsavelNome . ': ' . $observacaoNova);
    }

    $update = $pdo->prepare('UPDATE acordos_operacionais SET payload_extra = :payload_extra, observacao = :observacao WHERE id = :id LIMIT 1');
    $update->bindValue('payload_extra', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $update->bindValue('observacao', $observacaoFinal !== '' ? $observacaoFinal : null, $observacaoFinal !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $update->bindValue('id', $acordoId, PDO::PARAM_INT);
    $update->execute();
}

$vencimentoLegado = trim((string) ($_GET['situacao'] ?? ''));
$vencimentoInicial = trim((string) ($_GET['vencimento'] ?? ''));
if ($vencimentoInicial === '' && $vencimentoLegado !== '') {
    $vencimentoInicial = match ($vencimentoLegado) {
        'vencidos', 'vencidas' => 'vencidos',
        'hoje' => 'hoje',
        'futuros', 'proximos' => 'proximos_7',
        default => 'todos',
    };
}
if ($vencimentoInicial === '') {
    $vencimentoInicial = 'todos';
}

$filtros = [
    'status' => trim((string) ($_GET['status'] ?? 'ativos')),
    'integracao' => trim((string) ($_GET['integracao'] ?? 'todos')),
    'situacao' => 'todos',
    'vencimento' => $vencimentoInicial,
    'q' => trim((string) ($_GET['q'] ?? '')),
    'emissao_inicio' => trim((string) ($_GET['emissao_inicio'] ?? '')),
    'emissao_fim' => trim((string) ($_GET['emissao_fim'] ?? '')),
    'responsavel' => trim((string) ($_GET['responsavel'] ?? 'todos')),
    'origem_operacional' => trim((string) ($_GET['origem_operacional'] ?? 'todos')),
    'carteira' => trim((string) ($_GET['carteira'] ?? 'todos')),
    'fila' => mb_substr(trim((string) ($_GET['fila'] ?? 'todos')), 0, 190),
    'credor_id' => isset($_GET['credor_id']) && ctype_digit((string) $_GET['credor_id']) ? (int) $_GET['credor_id'] : 0,
];
if (!array_key_exists($filtros['status'], AcordoOperacionalRepository::STATUS_OPERACIONAL) && !in_array($filtros['status'], ['todos', 'ativos'], true)) {
    $filtros['status'] = 'ativos';
}
if (!array_key_exists($filtros['integracao'], AcordoOperacionalRepository::STATUS_INTEGRACAO) && $filtros['integracao'] !== 'todos') {
    $filtros['integracao'] = 'todos';
}
if (!in_array($filtros['vencimento'], ['todos', 'vencidos', 'hoje', 'proximos_3', 'proximos_7', 'proximos_15', 'proximos_30', 'sem_vencimento'], true)) {
    $filtros['vencimento'] = 'todos';
}
if (!in_array($filtros['origem_operacional'], ['todos', 'works', 'acme_works', 'acme_externo', 'manual', 'indefinido'], true)) {
    $filtros['origem_operacional'] = 'todos';
}
if ($filtros['carteira'] !== 'todos' && !array_key_exists($filtros['carteira'], CarteiraAcordosRepository::SITUACOES)) {
    $filtros['carteira'] = 'todos';
}
if ($filtros['responsavel'] !== 'todos' && $filtros['responsavel'] !== 'sem_responsavel' && !ctype_digit($filtros['responsavel'])) {
    $filtros['responsavel'] = 'todos';
}

try {
    $pdoOperacao = operacaoPdo();
    $repository = new AcordoOperacionalRepository($pdoOperacao);
    $carteiraRepository = new CarteiraAcordosRepository($pdoOperacao, $repository);
    $sincronizacaoRepository = new AcordoSincronizacaoRepository($pdoOperacao);
    $credorConfigRepository = new CredorConfiguracaoRepository($pdoOperacao);
    $adapterFactory = new CredorAdapterFactory($pdoOperacao, $credorConfigRepository);
    $usuariosAtribuiveisAcordo = $podeAtribuirAcordo ? acordosUsuariosAtribuiveis($pdoOperacao) : [];
    $escopoConsulta = usuarioEscopoCredoresAcao('consultar');
    $moduloDisponivel = $repository->moduloDisponivel();
    $carteiraDisponivel = $carteiraRepository->moduloDisponivel();
    $filasDisponiveisAcordo = $moduloDisponivel ? $repository->listarFilasDisponiveis($escopoConsulta) : [];
    if ($filtros['fila'] !== 'todos' && !in_array($filtros['fila'], $filasDisponiveisAcordo, true)) {
        $filtros['fila'] = 'todos';
    }
    $credorIdsVisiveisAcordo = usuarioEscopoCredorIdsAcao('consultar');
    $credoresDisponiveisAcordo = $credorConfigRepository->listarCredores();
    if ($credorIdsVisiveisAcordo !== null) {
        $credoresDisponiveisAcordo = array_values(array_filter(
            $credoresDisponiveisAcordo,
            static fn (array $credor): bool => in_array((int) ($credor['id'] ?? 0), $credorIdsVisiveisAcordo, true)
        ));
    }
    $credorIdsValidosAcordo = array_map(static fn (array $credor): int => (int) ($credor['id'] ?? 0), $credoresDisponiveisAcordo);
    if (!in_array($filtros['credor_id'], $credorIdsValidosAcordo, true)) {
        $filtros['credor_id'] = 0;
    }

    if ($moduloDisponivel) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            validarCsrf();
            $acao = (string) ($_POST['acao'] ?? '');
            $redirect = (string) ($_POST['redirect'] ?? '/operacao/acordos.php');
            if (!str_starts_with($redirect, '/operacao/acordos.php')) {
                $redirect = '/operacao/acordos.php';
            }

            if ($acao === 'cadastrar_acordo_manual') {
                if (!$podeCadastrarManual) {
                    throw new RuntimeException('Seu perfil não possui permissão para cadastrar acordo manual.');
                }
                if (!$carteiraDisponivel) {
                    throw new RuntimeException('Execute a migration 075_carteira_acordos.sql antes de cadastrar acordo manual.');
                }
                $contratoManual = $carteiraRepository->resolverContratoManual(
                    preg_replace('/\D+/', '', (string) ($_POST['documento'] ?? '')) ?: '',
                    trim((string) ($_POST['codigo_contrato'] ?? ''))
                );
                if ($contratoManual === null) {
                    throw new RuntimeException('Contrato não encontrado para o CPF/CNPJ informado.');
                }
                requireContratoNoEscopo((int) $contratoManual['contrato_id'], 'fechar_acordo');

                $novoAcordoId = $carteiraRepository->registrarManual([
                    'documento' => $_POST['documento'] ?? '',
                    'codigo_contrato' => $_POST['codigo_contrato'] ?? '',
                    'valor_acordo' => $_POST['valor_acordo'] ?? null,
                    'valor_entrada' => $_POST['valor_entrada'] ?? null,
                    'data_entrada' => $_POST['data_entrada'] ?? null,
                    'quantidade_parcelas' => $_POST['quantidade_parcelas'] ?? 1,
                    'data_primeira_parcela' => $_POST['data_primeira_parcela'] ?? null,
                    'periodicidade' => $_POST['periodicidade'] ?? 'mensal',
                    'forma_pagamento' => $_POST['forma_pagamento'] ?? 'nao_informado',
                    'acordo_observacao' => $_POST['observacao_status'] ?? null,
                    'status_operacional' => 'formalizado',
                ], usuarioAtualId(), usuarioAtualNome());

                $responsavelId = isset($_POST['responsavel_usuario_id']) && ctype_digit((string) $_POST['responsavel_usuario_id'])
                    ? (int) $_POST['responsavel_usuario_id']
                    : 0;
                if ($responsavelId > 0 && $podeAtribuirAcordo) {
                    $responsavel = acordosResolverUsuario($usuariosAtribuiveisAcordo, $responsavelId);
                    if ($responsavel !== null) {
                        acordosAtualizarResponsavel($pdoOperacao, $novoAcordoId, (int) $responsavel['id'], (string) $responsavel['nome'], 'Responsável definido no cadastro manual.');
                    }
                }

                registrarAuditoriaSistema('acordo.cadastrado_manual', 'acordo_operacional', $novoAcordoId, 'Acordo cadastrado manualmente na carteira.', null, ['origem' => 'manual']);
                header('Location: /operacao/acordo_detalhe.php?id=' . $novoAcordoId . '&ok_manual=1');
                exit;
            }

            if ($acao === 'desfazer_cadastro_manual') {
                if (!$podeDesfazer) {
                    throw new RuntimeException('Seu perfil não possui permissão para desfazer cadastro manual.');
                }
                $acordoManualId = isset($_POST['acordo_id']) && ctype_digit((string) $_POST['acordo_id']) ? (int) $_POST['acordo_id'] : 0;
                requireAcordoNoEscopo($acordoManualId, 'fechar_acordo');
                $carteiraRepository->desfazerCadastroManual($acordoManualId, trim((string) ($_POST['observacao_status'] ?? '')), usuarioAtualId(), usuarioAtualNome());
                registrarAuditoriaSistema('acordo.cadastro_manual_desfeito', 'acordo_operacional', $acordoManualId, 'Cadastro manual desfeito sem exclusão física.', null, null);
                header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'ok_desfeito=1');
                exit;
            }

            $acordoId = isset($_POST['acordo_id']) && ctype_digit((string) $_POST['acordo_id']) ? (int) $_POST['acordo_id'] : 0;
            $observacao = trim((string) ($_POST['observacao_status'] ?? ''));

            if ($acao === 'registrar_pagamento_rapido') {
                if (!$podeEditarCarteira || !$carteiraDisponivel) {
                    throw new RuntimeException('Seu perfil não possui permissão para registrar pagamento manual.');
                }
                requireAcordoNoEscopo($acordoId, 'fechar_acordo');
                $parcelaId = isset($_POST['parcela_id']) && ctype_digit((string) $_POST['parcela_id']) ? (int) $_POST['parcela_id'] : 0;
                if ($parcelaId <= 0) {
                    throw new RuntimeException('Parcela inválida para pagamento.');
                }
                $valorPago = $_POST['valor_pago'] ?? null;
                $carteiraRepository->corrigirParcelaManual(
                    $acordoId,
                    $parcelaId,
                    'paga',
                    date('Y-m-d'),
                    $valorPago,
                    'Pagamento manual registrado pela Carteira de Acordos.',
                    usuarioAtualId(),
                    usuarioAtualNome()
                );
                registrarAuditoriaSistema('acordo.parcela_paga_manual', 'acordo_operacional', $acordoId, 'Parcela marcada como paga pela Carteira de Acordos.', null, ['parcela_id' => $parcelaId, 'valor_pago' => $valorPago]);
                header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'ok_pagamento=1');
                exit;
            }

            if ($acao === 'atualizar_status') {
                if (!$podeAlterarStatus) {
                    throw new RuntimeException('Seu perfil não possui permissão para alterar o status do acordo.');
                }
                requireAcordoNoEscopo($acordoId, 'fechar_acordo');
                $status = (string) ($_POST['status'] ?? '');
                $carteiraDisponivel
                    ? $carteiraRepository->alterarStatusManual($acordoId, $status, $observacao, usuarioAtualId(), usuarioAtualNome())
                    : $repository->atualizarStatusOperacional($acordoId, $status, $observacao);
                registrarAuditoriaSistema('acordo.status_atualizado', 'acordo_operacional', $acordoId, 'Status do acordo atualizado.', null, ['status' => $status, 'observacao' => $observacao]);
                header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'ok_acordo=' . rawurlencode($status));
                exit;
            }

            if ($acao === 'preparar_integracao') {
                if (!$podeIntegrar) {
                    throw new RuntimeException('Seu perfil não possui permissão para preparar a integração do acordo.');
                }
                requireAcordoNoEscopo($acordoId, 'fechar_acordo');
                $repository->marcarPendenteEnvioCredor($acordoId, $observacao ?: 'Acordo marcado para envio ao credor.');
                header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'ok_integracao=1');
                exit;
            }

            if ($acao === 'sincronizar_credor') {
                if (!$podeSincronizarCredor) {
                    throw new RuntimeException('Seu perfil não possui permissão para sincronizar acordo com o credor.');
                }
                requireAcordoNoEscopo($acordoId, 'consultar');
                if (!$sincronizacaoRepository->moduloDisponivel()) {
                    throw new RuntimeException('Execute a migration 079_maturidade_operacional.sql antes de sincronizar acordos.');
                }

                $detalheSync = $repository->buscarDetalhe($acordoId, $escopoConsulta);
                if ($detalheSync === null) {
                    throw new RuntimeException('Acordo não encontrado para sincronização.');
                }

                $adapter = $adapterFactory->paraContrato($detalheSync);
                if ($adapter === null) {
                    $sincronizacaoRepository->registrar(
                        $acordoId,
                        !empty($detalheSync['contrato_id']) ? (int) $detalheSync['contrato_id'] : null,
                        !empty($detalheSync['credor_id']) ? (int) $detalheSync['credor_id'] : null,
                        (string) ($detalheSync['credor_nome'] ?? 'credor'),
                        'nao_suportado',
                        'Este credor ainda não possui adapter de sincronização implementado.',
                        [],
                        usuarioAtualId(),
                        usuarioAtualNome()
                    );
                    header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'ok_sync=nao_suportado');
                    exit;
                }

                try {
                    $payloadSync = $adapter->consultarAcordo(
                        (string) ($detalheSync['pessoa_documento'] ?? ''),
                        (string) ($detalheSync['codigo_contrato'] ?? '')
                    );
                    $agreement = is_array($payloadSync['Agreement'] ?? null) ? $payloadSync['Agreement'] : [];
                    $agreementId = trim((string) ($agreement['AgreementID'] ?? ''));
                    $notFound = ($payloadSync['agreement_not_found'] ?? false) === true
                        || ($agreementId === '' && mb_strtolower(trim((string) ($payloadSync['Message'] ?? ''))) === mb_strtolower('Dados do acordo não encontrados.'));
                    $localAtivo = in_array((string) ($detalheSync['status_operacional'] ?? ''), ['pendente', 'formalizado'], true);

                    $statusSync = 'ok';
                    $mensagemSync = $agreementId !== ''
                        ? 'Acordo localizado no credor.'
                        : 'Credor não retornou acordo vigente.';

                    if ($notFound && $localAtivo) {
                        $statusSync = 'divergencia';
                        $mensagemSync = 'CRM considera o acordo ativo, mas o credor não retornou acordo vigente.';
                    } elseif (!$notFound && $agreementId !== '' && !$localAtivo) {
                        $statusSync = 'divergencia';
                        $mensagemSync = 'Credor possui acordo vigente, mas o acordo local está encerrado.';
                    }

                    if ((string) $adapter->codigo() === 'ACME' && !empty($detalheSync['contrato_id'])) {
                        (new AtendimentoAdaptorRepository($pdoOperacao))->atualizarContratoAcordoAcme((int) $detalheSync['contrato_id'], $payloadSync);
                        $infoAcordoAcme = AcordoOperacionalRepository::interpretarAcordoAcme($payloadSync);
                        if ($infoAcordoAcme !== null) {
                            $conciliacaoAcme = $repository->sincronizarAcordoAcme($detalheSync, $payloadSync);
                            if (in_array((string) ($conciliacaoAcme['acordo_crm'] ?? ''), ['atualizado', 'registrado'], true)) {
                                $statusConciliado = (string) ($conciliacaoAcme['status_operacional'] ?? 'formalizado');
                                $statusSync = 'ok';
                                $mensagemSync = 'Retorno ACME conciliado no CRM: '
                                    . AcordoOperacionalRepository::statusLabel($statusConciliado)
                                    . '. ' . (string) ($conciliacaoAcme['status_motivo'] ?? '');
                            } elseif (!empty($conciliacaoAcme['acordo_crm_erro'])) {
                                $statusSync = 'divergencia';
                                $mensagemSync = 'A ACME retornou o acordo, mas o CRM não conseguiu conciliar o status: '
                                    . (string) $conciliacaoAcme['acordo_crm_erro'];
                            }
                        }
                    }

                    $sincronizacaoRepository->registrar(
                        $acordoId,
                        !empty($detalheSync['contrato_id']) ? (int) $detalheSync['contrato_id'] : null,
                        !empty($detalheSync['credor_id']) ? (int) $detalheSync['credor_id'] : null,
                        $adapter->codigo(),
                        $statusSync,
                        $mensagemSync,
                        $payloadSync,
                        usuarioAtualId(),
                        usuarioAtualNome()
                    );
                    registrarAuditoriaSistema('acordo.sincronizado_credor', 'acordo_operacional', $acordoId, $mensagemSync, null, ['status' => $statusSync, 'integracao' => $adapter->codigo()]);
                    header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'ok_sync=' . rawurlencode($statusSync));
                    exit;
                } catch (Throwable $e) {
                    $sincronizacaoRepository->registrar(
                        $acordoId,
                        !empty($detalheSync['contrato_id']) ? (int) $detalheSync['contrato_id'] : null,
                        !empty($detalheSync['credor_id']) ? (int) $detalheSync['credor_id'] : null,
                        $adapter->codigo(),
                        'erro',
                        $e->getMessage(),
                        [],
                        usuarioAtualId(),
                        usuarioAtualNome()
                    );
                    throw new RuntimeException('Falha ao sincronizar acordo com o credor: ' . $e->getMessage());
                }
            }

            if ($acao === 'atribuir_responsavel') {
                if (!$podeAtribuirAcordo) {
                    throw new RuntimeException('Seu perfil não possui permissão para atribuir acordo para outro operador.');
                }
                requireAcordoNoEscopo($acordoId, 'fechar_acordo');
                $responsavelId = isset($_POST['responsavel_usuario_id']) && ctype_digit((string) $_POST['responsavel_usuario_id']) ? (int) $_POST['responsavel_usuario_id'] : 0;
                $responsavel = acordosResolverUsuario($usuariosAtribuiveisAcordo, $responsavelId);
                if ($responsavel === null) {
                    throw new RuntimeException('Operador responsável não encontrado ou inativo.');
                }
                acordosAtualizarResponsavel($pdoOperacao, $acordoId, (int) $responsavel['id'], (string) $responsavel['nome'], $observacao);
                registrarAuditoriaSistema('acordo.responsavel_atribuido', 'acordo_operacional', $acordoId, 'Responsável do acordo alterado.', null, ['responsavel_usuario_id' => (int) $responsavel['id'], 'responsavel_nome' => (string) $responsavel['nome']]);
                header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'ok_responsavel=1');
                exit;
            }
        }

        // recalcularQuebrasPorCarencia() e atualizarPorIds() (mais abaixo)
        // rodavam aqui, SINCRONO, em TODO carregamento desta pagina -
        // 2-3 SELECTs + UPDATEs/INSERTs condicionais por acordo exibido,
        // duplicando o cron scripts/console/atualizar_carteira_acordos.php
        // (agendado em 2026-09-16, a cada 15min via /etc/cron.d - antes
        // NENHUM cron rodava isso, so esta pagina). Dois supervisores
        // abrindo a tela ao mesmo tempo tambem competiam pelas mesmas
        // linhas. Retirado; os dados agora ficam no maximo ~15min
        // desatualizados, atualizados pelo cron.
        $resumo = $repository->resumoGeral($filtros, $escopoConsulta);
        $acordos = $repository->listar($filtros, 200, $escopoConsulta);
        $acordos = acordosFiltrarLista($acordos, $filtros);
        $acordos = array_slice($acordos, 0, 200);
        $parcelasPorAcordo = $repository->listarParcelasPorAcordos(array_column($acordos, 'id'));
        if ($filtros['vencimento'] !== 'todos') {
            $acordos = array_values(array_filter($acordos, static function (array $acordo) use ($parcelasPorAcordo, $filtros): bool {
                $resumoParcelaFiltro = acordosResumoParcelas($parcelasPorAcordo[(int) ($acordo['id'] ?? 0)] ?? []);
                return acordosCombinaFiltroVencimento($resumoParcelaFiltro['proxima'] ?? null, (string) $filtros['vencimento']);
            }));
            $idsVencimento = array_map('intval', array_column($acordos, 'id'));
            $parcelasPorAcordo = array_intersect_key($parcelasPorAcordo, array_flip($idsVencimento));
        }
        if ($carteiraDisponivel && $acordos !== []) {
            $acompanhamentosPorAcordo = $carteiraRepository->acompanhamentosPorAcordos(array_column($acordos, 'id'));
            foreach ($acompanhamentosPorAcordo as $acompanhamentoResumo) {
                $situacaoResumo = (string) ($acompanhamentoResumo['situacao_carteira'] ?? '');
                if (array_key_exists($situacaoResumo, $resumoCarteiraVisual)) {
                    $resumoCarteiraVisual[$situacaoResumo]++;
                }
            }
            if ($filtros['carteira'] !== 'todos') {
                $acordos = array_values(array_filter($acordos, static function (array $acordo) use ($acompanhamentosPorAcordo, $filtros): bool {
                    $acompanhamentoFiltro = $acompanhamentosPorAcordo[(int) ($acordo['id'] ?? 0)] ?? null;
                    return is_array($acompanhamentoFiltro) && (string) ($acompanhamentoFiltro['situacao_carteira'] ?? '') === $filtros['carteira'];
                }));
                $idsFiltrados = array_map('intval', array_column($acordos, 'id'));
                $parcelasPorAcordo = array_intersect_key($parcelasPorAcordo, array_flip($idsFiltrados));
            }
        }

        if ($acordos !== [] && $sincronizacaoRepository->moduloDisponivel()) {
            $sincronizacoesPorAcordo = $sincronizacaoRepository->ultimasPorAcordos(array_map('intval', array_column($acordos, 'id')));
        }
    }
} catch (Throwable $e) {
    $erro = $e->getMessage();
}

if (isset($_GET['ok_acordo'])) {
    $mensagemSucesso = 'Status atualizado para ' . AcordoOperacionalRepository::statusLabel((string) $_GET['ok_acordo']) . '.';
} elseif (isset($_GET['ok_integracao'])) {
    $mensagemSucesso = 'Acordo preparado para integração com o credor.';
} elseif (isset($_GET['ok_responsavel'])) {
    $mensagemSucesso = 'Responsável atualizado com sucesso.';
} elseif (isset($_GET['ok_pagamento'])) {
    $mensagemSucesso = 'Pagamento manual registrado. A carteira foi recalculada.';
} elseif (isset($_GET['ok_desfeito'])) {
    $mensagemSucesso = 'Cadastro manual desfeito. O acordo foi cancelado e o histórico foi preservado.';
} elseif (isset($_GET['ok_sync'])) {
    $statusSyncMsg = (string) $_GET['ok_sync'];
    $mensagemSucesso = $statusSyncMsg === 'divergencia'
        ? 'Sincronização concluída com divergência entre CRM e credor. Revise o acordo.'
        : ($statusSyncMsg === 'nao_suportado'
            ? 'Este credor ainda não possui sincronização automática implementada.'
            : 'Sincronização com o credor concluída.');
}

$filtrosUrl = $filtros;
unset($filtrosUrl['situacao']);
$urlAtual = '/operacao/acordos.php' . queryString($filtrosUrl);
http_response_code($erro === null ? 200 : 500);
require __DIR__ . '/includes/header.php';
?>
<main class="container works-agreements-page" id="works-agreements-main">
    <?php if ($mensagemSucesso !== null): ?><section class="alert success"><?= h($mensagemSucesso) ?></section><?php endif; ?>
    <?php if ($erro !== null): ?><section class="card error"><strong>Erro ao carregar acordos:</strong> <?= h($erro) ?></section><?php endif; ?>

    <?php if (!$moduloDisponivel): ?>
        <section class="card error"><strong>Módulo de acordos indisponível.</strong><p>Execute as migrations 014 e 015 antes de usar esta tela.</p></section>
    <?php else: ?>
        <?php if ($podeCadastrarManual): ?>
            <details class="works-manual-agreement"<?= isset($_GET['novo_manual']) ? ' open' : '' ?>>
                <summary><strong>+ Cadastrar acordo manual</strong><span>Para acordo fechado fora da integração ou correção operacional controlada</span></summary>
                <form method="post" action="/operacao/acordos.php" class="works-manual-agreement-form">
                    <?= authCsrfInput() ?>
                    <input type="hidden" name="acao" value="cadastrar_acordo_manual">
                    <input type="hidden" name="redirect" value="/operacao/acordos.php">
                    <label>CPF/CNPJ<input type="text" name="documento" required inputmode="numeric" placeholder="Somente números"></label>
                    <label>Contrato<input type="text" name="codigo_contrato" required placeholder="Código exato do contrato"></label>
                    <label>Valor total<input type="text" name="valor_acordo" required inputmode="decimal" placeholder="0,00"></label>
                    <label>Entrada<input type="text" name="valor_entrada" inputmode="decimal" placeholder="Opcional"></label>
                    <label>Vencimento da entrada<input type="date" name="data_entrada"></label>
                    <label>Quantidade de parcelas<input type="number" name="quantidade_parcelas" min="1" max="120" value="1" required></label>
                    <label>1º vencimento<input type="date" name="data_primeira_parcela" required></label>
                    <label>Periodicidade<select name="periodicidade"><?php foreach (AcordoOperacionalRepository::PERIODICIDADES as $valor => $label): ?><option value="<?= h($valor) ?>"<?= $valor === 'mensal' ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
                    <label>Forma de pagamento<select name="forma_pagamento"><?php foreach (AcordoOperacionalRepository::FORMAS_PAGAMENTO as $valor => $label): ?><option value="<?= h($valor) ?>"><?= h($label) ?></option><?php endforeach; ?></select></label>
                    <?php if ($podeAtribuirAcordo && $usuariosAtribuiveisAcordo !== []): ?><label>Responsável<select name="responsavel_usuario_id"><option value="">Usuário logado</option><?php foreach ($usuariosAtribuiveisAcordo as $usuarioManual): ?><option value="<?= (int) $usuarioManual['id'] ?>"><?= h((string) $usuarioManual['nome']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                    <label class="works-manual-agreement-note">Observação<textarea name="observacao_status" rows="3" maxlength="2000" placeholder="Motivo e origem do cadastro manual"></textarea></label>
                    <div class="actions-row"><button class="button" type="submit">Salvar na carteira</button><span class="muted">Erros podem ser desfeitos sem apagar o histórico.</span></div>
                </form>
            </details>
        <?php endif; ?>

        <section class="works-agreement-metrics">
            <a class="works-agreement-metric is-primary<?= $filtros['status'] === 'ativos' ? ' is-selected' : '' ?>" href="/operacao/acordos.php?status=ativos"><small>Ativos</small><strong><?= number_format((int) $resumo['pendentes'] + (int) $resumo['formalizados'], 0, ',', '.') ?></strong><span>pendentes + formalizados</span></a>
            <a class="works-agreement-metric is-warning<?= $filtros['vencimento'] === 'hoje' ? ' is-selected' : '' ?>" href="/operacao/acordos.php?status=ativos&vencimento=hoje"><small>Vencem hoje</small><strong><?= number_format($carteiraDisponivel ? (int) $resumoCarteiraVisual['vence_hoje'] : (int) $resumo['hoje'], 0, ',', '.') ?></strong><span>exigem contato</span></a>
            <a class="works-agreement-metric is-danger<?= $filtros['status'] === 'quebrado' ? ' is-selected' : '' ?>" href="/operacao/acordos.php?status=quebrado"><small>Quebrados</small><strong><?= number_format((int) $resumo['quebrados'], 0, ',', '.') ?></strong><span>nova negociação liberada</span></a>
            <a class="works-agreement-metric is-success<?= $filtros['status'] === 'cumprido' ? ' is-selected' : '' ?>" href="/operacao/acordos.php?status=cumprido"><small>Quitados</small><strong><?= number_format((int) $resumo['cumpridos'], 0, ',', '.') ?></strong><span>finalizados com pagamento</span></a>
            <div class="works-agreement-metric"><small>Valor ativo</small><strong><?= h(dinheiro($resumo['valor_pendente'])) ?></strong><span>promessas pendentes</span></div>
        </section>
        <?php if ($carteiraDisponivel): ?>
        <section class="works-agreement-metrics works-agreement-lifecycle-metrics">
            <a class="works-agreement-metric<?= $filtros['carteira'] === 'em_dia' ? ' is-selected' : '' ?>" href="/operacao/acordos.php?status=ativos&carteira=em_dia"><small>Em dia</small><strong><?= number_format((int) $resumoCarteiraVisual['em_dia'], 0, ',', '.') ?></strong><span>próximo vencimento futuro</span></a>
            <a class="works-agreement-metric is-warning<?= $filtros['carteira'] === 'em_carencia' ? ' is-selected' : '' ?>" href="/operacao/acordos.php?status=ativos&carteira=em_carencia"><small>Em carência</small><strong><?= number_format((int) $resumoCarteiraVisual['em_carencia'], 0, ',', '.') ?></strong><span>precisam de contato</span></a>
            <a class="works-agreement-metric<?= $filtros['carteira'] === 'aguardando_proxima' ? ' is-selected' : '' ?>" href="/operacao/acordos.php?status=ativos&carteira=aguardando_proxima"><small>Aguardando próxima</small><strong><?= number_format((int) $resumoCarteiraVisual['aguardando_proxima'], 0, ',', '.') ?></strong><span>credor ainda não liberou</span></a>
        </section>
        <?php endif; ?>

        <section class="works-agreement-filter-bar">
            <form method="get" action="/operacao/acordos.php" class="js-stay-ajax">
                <label>Vencimento
                    <select name="vencimento">
                        <option value="todos"<?= $filtros['vencimento'] === 'todos' ? ' selected' : '' ?>>Todos</option>
                        <option value="vencidos"<?= $filtros['vencimento'] === 'vencidos' ? ' selected' : '' ?>>Vencidos</option>
                        <option value="hoje"<?= $filtros['vencimento'] === 'hoje' ? ' selected' : '' ?>>Hoje</option>
                        <option value="proximos_3"<?= $filtros['vencimento'] === 'proximos_3' ? ' selected' : '' ?>>Próximos 3 dias</option>
                        <option value="proximos_7"<?= $filtros['vencimento'] === 'proximos_7' ? ' selected' : '' ?>>Próximos 7 dias</option>
                        <option value="proximos_15"<?= $filtros['vencimento'] === 'proximos_15' ? ' selected' : '' ?>>Próximos 15 dias</option>
                        <option value="proximos_30"<?= $filtros['vencimento'] === 'proximos_30' ? ' selected' : '' ?>>Próximos 30 dias</option>
                        <option value="sem_vencimento"<?= $filtros['vencimento'] === 'sem_vencimento' ? ' selected' : '' ?>>Sem parcela aberta</option>
                    </select>
                </label>
                <label>Status
                    <select name="status">
                        <option value="ativos"<?= $filtros['status'] === 'ativos' ? ' selected' : '' ?>>Ativos</option>
                        <?php foreach (AcordoOperacionalRepository::STATUS_OPERACIONAL as $valor => $label): ?><option value="<?= h($valor) ?>"<?= $filtros['status'] === $valor ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                        <option value="todos"<?= $filtros['status'] === 'todos' ? ' selected' : '' ?>>Todos</option>
                    </select>
                </label>
                <label>Gerado de
                    <input type="date" name="emissao_inicio" value="<?= h($filtros['emissao_inicio']) ?>" aria-label="Data inicial de geração do acordo">
                </label>
                <label>Gerado até
                    <input type="date" name="emissao_fim" value="<?= h($filtros['emissao_fim']) ?>" aria-label="Data final de geração do acordo">
                </label>
                <?php if ($podeAtribuirAcordo): ?><label>Responsável<select name="responsavel"><option value="todos">Todos</option><option value="sem_responsavel"<?= $filtros['responsavel'] === 'sem_responsavel' ? ' selected' : '' ?>>Não definido</option><?php foreach ($usuariosAtribuiveisAcordo as $usuarioFiltro): ?><option value="<?= (int) $usuarioFiltro['id'] ?>"<?= $filtros['responsavel'] === (string) $usuarioFiltro['id'] ? ' selected' : '' ?>><?= h((string) $usuarioFiltro['nome']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                <?php if (count($credoresDisponiveisAcordo) > 1): ?>
                    <label>Credor
                        <select name="credor_id">
                            <option value="0">Todos</option>
                            <?php foreach ($credoresDisponiveisAcordo as $credorOpcao): ?>
                                <option value="<?= (int) $credorOpcao['id'] ?>"<?= $filtros['credor_id'] === (int) $credorOpcao['id'] ? ' selected' : '' ?>><?= h((string) $credorOpcao['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <?php if ($filasDisponiveisAcordo !== []): ?>
                    <label>Fila
                        <select name="fila">
                            <option value="todos">Todas</option>
                            <?php foreach ($filasDisponiveisAcordo as $filaOpcao): ?>
                                <option value="<?= h($filaOpcao) ?>"<?= $filtros['fila'] === $filaOpcao ? ' selected' : '' ?>><?= h($filaOpcao) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <label class="works-agreement-filter-search">Buscar<input type="search" name="q" value="<?= h($filtros['q']) ?>" placeholder="Cliente, CPF ou contrato"></label>
                <input type="hidden" name="carteira" value="<?= h($filtros['carteira']) ?>">
                <div class="actions-row"><button class="button" type="submit">Filtrar</button><a class="button secondary" href="/operacao/acordos.php">Limpar</a></div>
            </form>
        </section>

        <section class="works-agreement-list">
            <?php if ($acordos === []): ?><div class="card empty">Nenhum acordo encontrado para os filtros atuais.</div><?php endif; ?>
            <?php foreach ($acordos as $acordo): ?>
                <?php
                $parcelasResumo = acordosResumoParcelas($parcelasPorAcordo[(int) $acordo['id']] ?? []);
                $proxima = $parcelasResumo['proxima'];
                $situacaoCarencia = $proxima !== null
                    ? $repository->situacaoCarenciaParcela(
                        $proxima,
                        (int) ($acordo['credor_id'] ?? 0),
                        (string) ($acordo['produto'] ?? '')
                    )
                    : $repository->situacaoCarencia(null, (int) ($acordo['credor_id'] ?? 0), (string) ($acordo['produto'] ?? ''), null);
                $origemOperacional = acordosOrigemOperacional($acordo);
                $acompanhamento = $acompanhamentosPorAcordo[(int) $acordo['id']] ?? null;
                $ultimaSync = $sincronizacoesPorAcordo[(int) $acordo['id']] ?? null;
                $percentual = $parcelasResumo['total'] > 0 ? min(100, (int) round(($parcelasResumo['pagas'] / $parcelasResumo['total']) * 100)) : 0;
                ?>
                <article class="works-agreement-card status-<?= h((string) $acordo['status_operacional']) ?>">
                    <header>
                        <div>
                            <span class="works-contract-eyebrow">Acordo #<?= (int) $acordo['id'] ?></span>
                            <h3><?= h((string) $acordo['pessoa_nome']) ?></h3>
                            <p><?= documentoFormatado((string) $acordo['pessoa_documento']) ?> · contrato <?= h((string) ($acordo['codigo_contrato'] ?: '-')) ?></p>
                        </div>
                        <div class="works-agreement-badges">
                            <?= badge(AcordoOperacionalRepository::statusLabel((string) $acordo['status_operacional'])) ?>
                            <?php if (is_array($acompanhamento)): ?><?= badgeSoft(CarteiraAcordosRepository::situacaoLabel((string) $acompanhamento['situacao_carteira'])) ?><?php endif; ?>
                            <?= badgeSoft(acordosOrigemOperacionalLabel($origemOperacional)) ?>
                            <?= badgeSoft(AcordoOperacionalRepository::integracaoLabel((string) $acordo['status_integracao'])) ?>
                        </div>
                    </header>

                    <div class="works-agreement-card-grid">
                        <div><small>Credor / produto</small><strong><?= h((string) ($acordo['credor_nome'] ?: '-')) ?></strong><span><?= h((string) ($acordo['produto'] ?: 'Produto não informado')) ?></span></div>
                        <div><small>Valor do acordo</small><strong><?= h(dinheiro($acordo['valor_acordo'])) ?></strong><span><?= (int) ($acordo['quantidade_parcelas'] ?? 1) ?> parcela(s)</span></div>
                        <div><small>Gerado em</small><strong><?= h(dataHoraBr($acordo['criado_em'] ?? null)) ?></strong><span>Data de emissão no CRM</span></div>
                        <div><small>Próxima parcela</small><strong><?= $proxima !== null ? h(dataBr($proxima['data_vencimento'])) : 'Sem parcela aberta' ?></strong><span><?= $proxima !== null ? h(dinheiro($proxima['valor_parcela'])) : 'Pagamento concluído/encerrado' ?></span></div>
                        <div><small>Carência</small><strong class="works-grace-status is-<?= h($situacaoCarencia['classe']) ?>"><?= h($situacaoCarencia['label']) ?></strong><span><?= $proxima !== null ? 'Limite: ' . h($situacaoCarencia['data_limite_label']) . ' · ' . (int) $situacaoCarencia['dias'] . ' dia(s)' : '-' ?></span></div>
                        <div><small>Responsável</small><strong><?= h(acordosNomeResponsavel($acordo)) ?></strong><span>Fechado por: <?= h(acordosNomeFechamento($acordo)) ?></span></div>
                        <div><small>Próxima ação</small><strong><?= is_array($acompanhamento) ? h((string) ($acompanhamento['proxima_acao'] ?? '-')) : 'Aguardando cálculo' ?></strong><span><?= is_array($acompanhamento) ? 'Verificado em ' . h(dataHoraBr($acompanhamento['ultima_verificacao_em'] ?? null)) : 'Execute a migration/rotina 075' ?></span></div>
                        <div class="works-agreement-sync-fact"><small>Credor / sincronização</small>
                            <?php if (is_array($ultimaSync)): ?>
                                <strong class="works-sync-status is-<?= h((string) ($ultimaSync['status'] ?? 'ok')) ?>"><?= h(ucfirst((string) ($ultimaSync['status'] ?? 'ok'))) ?></strong>
                                <span><?= h(dataHoraBr($ultimaSync['criado_em'] ?? null)) ?> · <?= h((string) ($ultimaSync['mensagem'] ?? '')) ?></span>
                            <?php else: ?>
                                <strong>Não sincronizado</strong><span>A validação externa ainda não foi executada nesta carteira.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="works-agreement-progress">
                        <div><span style="width: <?= $percentual ?>%"></span></div>
                        <small><?= (int) $parcelasResumo['pagas'] ?> de <?= (int) $parcelasResumo['total'] ?> paga(s) · <?= h(dinheiro($parcelasResumo['valor_pago'])) ?> recebido</small>
                    </div>

                    <footer>
                        <a class="button" href="<?= h(worksAtendimentoUrl(!empty($acordo['contrato_id']) ? (int) $acordo['contrato_id'] : null, (int) $acordo['pessoa_id'], 'financeiro')) ?>">Abrir no Pactua</a>
                        <a class="button secondary" href="/operacao/acordo_detalhe.php?id=<?= (int) $acordo['id'] ?>">Ver parcelas</a>
                        <?php if ($podeSincronizarCredor && !empty($acordo['contrato_id'])): ?>
                            <form method="post" action="/operacao/acordos.php" class="works-agreement-sync-form js-stay-ajax">
                                <?= authCsrfInput() ?>
                                <input type="hidden" name="acao" value="sincronizar_credor">
                                <input type="hidden" name="acordo_id" value="<?= (int) $acordo['id'] ?>">
                                <input type="hidden" name="redirect" value="<?= h($urlAtual) ?>">
                                <button class="button secondary" type="submit">Sincronizar credor</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($podeEditarCarteira && $carteiraDisponivel && $proxima !== null && in_array((string) ($proxima['status'] ?? ''), ['pendente', 'vencida'], true)): ?>
                            <form method="post" action="/operacao/acordos.php" class="works-agreement-quick-pay js-stay-ajax" onsubmit="return confirm('Confirmar esta parcela como paga manualmente?');">
                                <?= authCsrfInput() ?><input type="hidden" name="acao" value="registrar_pagamento_rapido"><input type="hidden" name="acordo_id" value="<?= (int) $acordo['id'] ?>"><input type="hidden" name="parcela_id" value="<?= (int) ($proxima['id'] ?? 0) ?>"><input type="hidden" name="valor_pago" value="<?= h((string) ($proxima['valor_parcela'] ?? '')) ?>"><input type="hidden" name="redirect" value="<?= h($urlAtual) ?>"><button class="button secondary" type="submit">Marcar paga</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($podeAlterarStatus || $podeAtribuirAcordo || $podeDesfazer || ($podeIntegrar && ($acordo['status_integracao'] ?? '') === 'nao_enviado')): ?>
                            <details class="works-agreement-manage"><summary>Gerenciar</summary><div>
                                <?php if ($podeAlterarStatus): ?><form method="post" action="/operacao/acordos.php" class="js-stay-ajax"><?= authCsrfInput() ?><input type="hidden" name="acao" value="atualizar_status"><input type="hidden" name="acordo_id" value="<?= (int) $acordo['id'] ?>"><input type="hidden" name="redirect" value="<?= h($urlAtual) ?>"><label>Status<select name="status"><option value="pendente">Pendente</option><option value="formalizado">Formalizado</option><option value="cumprido">Quitado</option><option value="quebrado">Quebrado</option><option value="cancelado">Cancelado</option></select></label><input type="text" name="observacao_status" maxlength="500" placeholder="Motivo/observação"><button class="button secondary" type="submit">Atualizar status</button></form><?php endif; ?>
                                <?php if ($podeAtribuirAcordo && $usuariosAtribuiveisAcordo !== []): ?><form method="post" action="/operacao/acordos.php" class="js-stay-ajax"><?= authCsrfInput() ?><input type="hidden" name="acao" value="atribuir_responsavel"><input type="hidden" name="acordo_id" value="<?= (int) $acordo['id'] ?>"><input type="hidden" name="redirect" value="<?= h($urlAtual) ?>"><label>Responsável<select name="responsavel_usuario_id"><?php $responsavelAtualId = acordosResponsavelId($acordo); foreach ($usuariosAtribuiveisAcordo as $usuarioResp): ?><option value="<?= (int) $usuarioResp['id'] ?>"<?= $responsavelAtualId === (int) $usuarioResp['id'] ? ' selected' : '' ?>><?= h((string) $usuarioResp['nome']) ?></option><?php endforeach; ?></select></label><input type="text" name="observacao_status" maxlength="500" placeholder="Motivo da atribuição"><button class="button secondary" type="submit">Atribuir</button></form><?php endif; ?>
                                <?php if ($podeIntegrar && ($acordo['status_integracao'] ?? '') === 'nao_enviado'): ?><form method="post" action="/operacao/acordos.php" class="js-stay-ajax"><?= authCsrfInput() ?><input type="hidden" name="acao" value="preparar_integracao"><input type="hidden" name="acordo_id" value="<?= (int) $acordo['id'] ?>"><input type="hidden" name="redirect" value="<?= h($urlAtual) ?>"><button class="button secondary" type="submit">Preparar integração</button></form><?php endif; ?>
                                <?php if ($podeDesfazer && $carteiraDisponivel && $carteiraRepository->acordoEhManual((int) $acordo['id']) && !in_array((string) $acordo['status_operacional'], ['cancelado','cumprido'], true)): ?><form method="post" action="/operacao/acordos.php" class="js-stay-ajax" onsubmit="return confirm('Desfazer este cadastro manual? O acordo será cancelado, sem apagar o histórico.');"><?= authCsrfInput() ?><input type="hidden" name="acao" value="desfazer_cadastro_manual"><input type="hidden" name="acordo_id" value="<?= (int) $acordo['id'] ?>"><input type="hidden" name="redirect" value="<?= h($urlAtual) ?>"><input type="text" name="observacao_status" maxlength="500" required placeholder="Motivo da correção"><button class="button danger" type="submit">Desfazer cadastro manual</button></form><?php endif; ?>
                            </div></details>
                        <?php endif; ?>
                    </footer>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
<script>
(function () {
    'use strict';
    var main = document.getElementById('works-agreements-main');
    if (!main) {
        return;
    }

    function setBusy(form, busy) {
        var btn = form.querySelector('button[type="submit"]');
        if (!btn) {
            return;
        }
        if (busy) {
            btn.dataset.worksOrigLabel = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Aguarde…';
        } else if (btn.dataset.worksOrigLabel) {
            btn.disabled = false;
            btn.textContent = btn.dataset.worksOrigLabel;
        }
    }

    function ajaxSubmit(form) {
        setBusy(form, true);
        var method = (form.getAttribute('method') || 'get').toUpperCase();
        var url = form.getAttribute('action') || window.location.pathname;
        var init = { method: method, credentials: 'same-origin' };
        if (method === 'GET') {
            var params = new URLSearchParams(new FormData(form));
            url += '?' + params.toString();
        } else {
            init.body = new FormData(form);
        }

        fetch(url, init)
            .then(function (resp) { return resp.text().then(function (html) { return { html: html, url: resp.url }; }); })
            .then(function (result) {
                var doc = new DOMParser().parseFromString(result.html, 'text/html');
                var newMain = doc.getElementById('works-agreements-main');
                if (!newMain) {
                    window.location.href = result.url;
                    return;
                }
                var scrollX = window.scrollX;
                var scrollY = window.scrollY;
                main.innerHTML = newMain.innerHTML;
                history.pushState({}, '', result.url);
                // Erro precisa chamar atenção (rola até ele); sucesso não - o operador
                // já está olhando o card que acabou de atualizar, então mantemos a
                // posição de rolagem exatamente onde estava (achado 2026-09: "sincronizar"
                // jogava a tela lá pro alerta de sucesso no topo, obrigando rolar tudo de novo).
                var scrollTarget = main.querySelector('.card.error');
                if (scrollTarget) {
                    scrollTarget.scrollIntoView({ block: 'nearest' });
                } else {
                    window.scrollTo(scrollX, scrollY);
                }
            })
            .catch(function () {
                window.location.reload();
            });
    }

    document.addEventListener('submit', function (e) {
        var form = e.target.closest('.js-stay-ajax');
        if (!form) {
            return;
        }
        if (e.defaultPrevented) {
            return;
        }
        e.preventDefault();
        ajaxSubmit(form);
    }, false);

    window.addEventListener('popstate', function () {
        window.location.reload();
    });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
