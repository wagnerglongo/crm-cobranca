<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\AtendimentoRepository;
use CRM\Modules\Operacao\Repositories\CampanhaRepository;
use CRM\Modules\Operacao\Repositories\ConsultaClienteRepository;
use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use CRM\Modules\Operacao\Repositories\AgendaOperadorRepository;
use CRM\Modules\Operacao\Repositories\MotivoInadimplenciaRepository;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoAdminTecnico();
requireOperacaoPermissao('clientes.consultar');

$pageTitle = 'Ficha do Cliente';
$pageSubtitle = 'Visão consolidada da pessoa, contatos, endereços, contratos, veículos, cargas e histórico operacional.';
$activePage = 'consulta';

$pessoaId = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
$retornoOrigemId = isset($_GET['retorno_id']) && ctype_digit((string) $_GET['retorno_id']) ? (int) $_GET['retorno_id'] : 0;
$campanhaItemId = isset($_GET['campanha_item_id']) && ctype_digit((string) $_GET['campanha_item_id']) ? (int) $_GET['campanha_item_id'] : 0;
$termoVolta = trim((string) ($_GET['q'] ?? ''));
$acaoRapidaFicha = trim((string) ($_GET['acao_rapida'] ?? ''));
$contratoRapidoId = isset($_GET['contrato_id']) && ctype_digit((string) $_GET['contrato_id']) ? (int) $_GET['contrato_id'] : 0;
$erro = null;
$pessoa = null;
$resumo = [
    'total_contratos' => 0,
    'saldo_total' => 0.0,
    'total_contatos' => 0,
    'total_enderecos' => 0,
    'ultima_atualizacao_contrato' => null,
];
$contatos = [];
$enderecos = [];
$contratos = [];
$veiculosPorContrato = [];
$cargasOrigem = [];
$atendimentoDisponivel = false;
$resumoAtendimento = [
    'total_atendimentos' => 0,
    'ultimo_atendimento' => null,
    'proximo_acionamento' => null,
];
$historicoAtendimentos = [];
$retornosPendentesPessoa = [];
$campanhaItemAtual = null;
$campanhasCliente = [];
$campanhaDisponivel = false;
$acordoOperacionalDisponivel = false;
$acordosOperacionaisPessoa = [];
$parcelasAcordosOperacionaisPessoa = [];
$resumoAcordosOperacionaisPessoa = [
    'total' => 0,
    'pendentes' => 0,
    'vencidos' => 0,
    'valor_pendente' => 0.0,
];
$resultadosAtendimento = [];
$resultadoPadronizadoDisponivel = false;
$retornoStatusDisponivel = false;
$mensagemSucessoAtendimento = isset($_GET['ok_atendimento']) ? 'Atendimento registrado com sucesso.' : null;
if (isset($_GET['ok_retorno_tratado'])) {
    $mensagemSucessoAtendimento = trim((string) $mensagemSucessoAtendimento . ' Retorno origem marcado como tratado.');
}
if (isset($_GET['ok_campanha_item'])) {
    $mensagemSucessoAtendimento = trim((string) $mensagemSucessoAtendimento . ' Item da fila manual marcado como trabalhado.');
}
if (isset($_GET['ok_acordo'])) {
    $mensagemSucessoAtendimento = trim((string) $mensagemSucessoAtendimento . ' Acordo operacional registrado com parcelas.');
}
$mensagemErroAtendimento = null;
$mensagemSucessoContato = isset($_GET['ok_contato']) ? 'Contato atualizado com sucesso.' : null;
$mensagemErroContato = null;
$mensagemSucessoAgenda = isset($_GET['ok_agenda']) ? 'Agenda/fila atualizada com sucesso.' : null;
$mensagemErroAgenda = null;
$agendaDisponivel = false;
$reservaAtivaCliente = null;
$usuariosOperacionaisAgenda = [];
$formAtendimento = $_POST;
$timelineCliente = [];
$ultimoAtendimentoOperacional = null;
$proximoRetornoOperacional = null;
$ultimoAcordoOperacional = null;
$acordoAtivoOperacional = null;
$contatoOperacionalPrioritario = null;
$contratoOperacionalPrincipal = null;
$statusOperacionalCliente = [];
$proximaAcaoOperacional = [
    'titulo' => 'Registrar nova tentativa',
    'descricao' => 'Nenhuma pendência crítica localizada. Inicie uma nova tentativa pela ficha.',
    'href' => '#historico',
    'tipo' => 'neutro',
];

/** @param list<array<string,mixed>> $historico @param list<array<string,mixed>> $acordos @param list<array<string,mixed>> $retornos */
function montarTimelineClienteOperacao(array $historico, array $acordos, array $retornos): array
{
    $timeline = [];

    foreach ($historico as $item) {
        $timeline[] = [
            'tipo' => 'atendimento',
            'data' => $item['criado_em'] ?? null,
            'titulo' => AtendimentoRepository::resultadoLabelRegistro($item),
            'badge' => AtendimentoRepository::canalLabel($item['canal'] ?? null),
            'operador' => $item['operador_nome'] ?? null,
            'contrato' => $item['codigo_contrato'] ?? null,
            'contato' => $item['contato_utilizado'] ?: ($item['contato_valor'] ?? null),
            'descricao' => $item['observacao'] ?? null,
            'extra' => !empty($item['proximo_acionamento']) ? 'Retorno: ' . dataHoraBr($item['proximo_acionamento']) : null,
        ];
    }

    foreach ($acordos as $acordo) {
        $timeline[] = [
            'tipo' => 'acordo',
            'data' => $acordo['criado_em'] ?? null,
            'titulo' => 'Acordo ' . AcordoOperacionalRepository::statusLabel((string) ($acordo['status_operacional'] ?? 'pendente')),
            'badge' => dinheiro($acordo['valor_acordo'] ?? null),
            'operador' => $acordo['operador_nome'] ?? null,
            'contrato' => $acordo['codigo_contrato'] ?? null,
            'contato' => null,
            'descricao' => $acordo['observacao'] ?? null,
            'extra' => 'Primeiro vencimento: ' . dataBr($acordo['data_promessa_pagamento'] ?? null),
        ];
    }

    foreach ($retornos as $retorno) {
        $timeline[] = [
            'tipo' => 'retorno',
            'data' => $retorno['proximo_acionamento'] ?? null,
            'titulo' => 'Retorno pendente',
            'badge' => AtendimentoRepository::retornoSituacaoLabel((string) ($retorno['retorno_situacao'] ?? 'pendente')),
            'operador' => null,
            'contrato' => $retorno['codigo_contrato'] ?? null,
            'contato' => $retorno['contato_utilizado'] ?? null,
            'descricao' => $retorno['observacao'] ?? null,
            'extra' => 'Agendado para ' . dataHoraBr($retorno['proximo_acionamento'] ?? null),
        ];
    }

    usort($timeline, static function (array $a, array $b): int {
        $ta = strtotime((string) ($a['data'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['data'] ?? '')) ?: 0;
        return $tb <=> $ta;
    });

    return array_slice($timeline, 0, 50);
}


/** @param list<array<string,mixed>> $resultados */
function resultadoRapidoFichaOperacional(array $resultados, string $resultadoLegado): array
{
    foreach ($resultados as $resultado) {
        if ((string) ($resultado['resultado_legacy'] ?? '') === $resultadoLegado && !empty($resultado['id'])) {
            return [
                'resultado_id' => (string) $resultado['id'],
                'resultado' => $resultadoLegado,
            ];
        }
    }

    return ['resultado' => $resultadoLegado];
}

/** @param array<string,mixed> $form @param list<array<string,mixed>> $resultados */
function aplicarAcaoRapidaFichaOperacional(array $form, string $acao, array $resultados): array
{
    $templates = [
        'sem_contato' => [
            'resultado' => 'sem_contato',
            'canal' => 'ligacao',
            'observacao' => 'Tentativa sem contato registrada pela ficha operacional.',
        ],
        'contato_efetivo' => [
            'resultado' => 'atendeu',
            'canal' => 'ligacao',
            'observacao' => 'Contato efetivo realizado. Registrar detalhes da negociação.',
        ],
        'agendar_retorno' => [
            'resultado' => 'recado',
            'canal' => 'ligacao',
            'observacao' => 'Cliente precisa de novo retorno. Conferir data e horário antes de salvar.',
            'proximo_acionamento' => (new DateTimeImmutable('tomorrow 09:00'))->format('Y-m-d\TH:i'),
        ],
        'criar_acordo' => [
            'resultado' => 'promessa_pagamento',
            'canal' => 'ligacao',
            'observacao' => 'Cliente aceitou negociar. Preencher os dados do acordo antes de salvar.',
            'registrar_acordo' => '1',
        ],
    ];

    if (!isset($templates[$acao])) {
        return $form;
    }

    $template = $templates[$acao];
    $resultado = resultadoRapidoFichaOperacional($resultados, (string) $template['resultado']);

    foreach ($resultado as $key => $value) {
        if (empty($form[$key])) {
            $form[$key] = $value;
        }
    }

    foreach ($template as $key => $value) {
        if ($key === 'resultado') {
            continue;
        }
        if (empty($form[$key])) {
            $form[$key] = $value;
        }
    }

    return $form;
}

/** @param list<array<string,mixed>> $contatos */
function contatoPrioritarioFichaOperacional(array $contatos): ?array
{
    if ($contatos === []) {
        return null;
    }

    usort($contatos, static function (array $a, array $b): int {
        $score = static function (array $c): int {
            $pontos = 0;
            if ((int) ($c['principal'] ?? 0) === 1) {
                $pontos += 100;
            }
            $status = (string) ($c['status'] ?? 'novo');
            $tipo = (string) ($c['tipo'] ?? 'outro');
            $pontos += match ($status) {
                'hot' => 80,
                'correto' => 65,
                'novo' => 35,
                'nao_confirmado' => 20,
                'desconhecido' => 10,
                default => 0,
            };
            $pontos += match ($tipo) {
                'whatsapp' => 20,
                'telefone' => 15,
                'email' => 8,
                default => 0,
            };
            return $pontos;
        };

        return $score($b) <=> $score($a);
    });

    return $contatos[0];
}

/**
 * Regra de negócio (2026-09-23): contato negativado não pode aparecer como
 * opção pra acionamento nem pra fechar acordo.
 */
function contatoNegativadoFichaOperacional(array $contato): bool
{
    $payload = $contato['payload_extra'] ?? null;
    $decoded = is_string($payload) && trim($payload) !== '' ? json_decode($payload, true) : null;
    $situacao = is_array($decoded) ? (string) ($decoded['situacao_validacao'] ?? '') : '';
    return $situacao === 'negativado';
}

/** @param list<array<string,mixed>> $contratos */
function contratoPrincipalFichaOperacional(array $contratos): ?array
{
    if ($contratos === []) {
        return null;
    }

    usort($contratos, static function (array $a, array $b): int {
        $saldoA = (float) ($a['saldo_atual'] ?? 0);
        $saldoB = (float) ($b['saldo_atual'] ?? 0);
        if ($saldoA !== $saldoB) {
            return $saldoB <=> $saldoA;
        }

        $atA = strtotime((string) ($a['atualizado_em'] ?? '')) ?: 0;
        $atB = strtotime((string) ($b['atualizado_em'] ?? '')) ?: 0;
        return $atB <=> $atA;
    });

    return $contratos[0];
}

function retornoVencidoFichaOperacional(?array $retorno): bool
{
    if ($retorno === null || empty($retorno['proximo_acionamento'])) {
        return false;
    }

    $timestamp = strtotime((string) $retorno['proximo_acionamento']);
    return $timestamp !== false && $timestamp < time();
}

/** @param list<array<string,mixed>> $retornos */
function primeiroRetornoVencidoFichaOperacional(array $retornos): ?array
{
    foreach ($retornos as $retorno) {
        if (retornoVencidoFichaOperacional($retorno)) {
            return $retorno;
        }
    }

    return null;
}

/** @param list<array<string,mixed>> $acordos */
function acordoAtivoFichaOperacional(array $acordos): ?array
{
    foreach ($acordos as $acordo) {
        if (in_array((string) ($acordo['status_operacional'] ?? ''), ['pendente', 'formalizado'], true)) {
            return $acordo;
        }
    }

    return null;
}

/** @param list<array<string,mixed>> $campanhas @param list<array<string,mixed>> $retornos */
function statusOperacionalFicha(array $campanhas, ?array $reserva, array $retornos, ?array $acordoAtivo): array
{
    $status = [];
    $temCampanhaAtiva = false;
    $temFilaGeral = false;

    foreach ($campanhas as $campanha) {
        $itemStatus = (string) ($campanha['status'] ?? '');
        $campanhaStatus = (string) ($campanha['campanha_status'] ?? '');
        if (in_array($campanhaStatus, ['ativa', 'pausada'], true) && in_array($itemStatus, ['pendente', 'em_atendimento'], true)) {
            $temCampanhaAtiva = true;
        }
        if ($itemStatus === 'pendente' && $reserva === null) {
            $temFilaGeral = true;
        }
    }

    if ($temCampanhaAtiva) {
        $status[] = ['label' => 'Em campanha', 'tipo' => 'info'];
    }
    if ($temFilaGeral) {
        $status[] = ['label' => 'Fila geral', 'tipo' => 'neutro'];
    }
    if ($reserva !== null) {
        $status[] = ['label' => 'Reservado', 'tipo' => 'ok'];
    }
    if (primeiroRetornoVencidoFichaOperacional($retornos) !== null) {
        $status[] = ['label' => 'Retorno vencido', 'tipo' => 'danger'];
    } elseif ($retornos !== []) {
        $status[] = ['label' => 'Retorno agendado', 'tipo' => 'warning'];
    }
    if ($acordoAtivo !== null) {
        $status[] = ['label' => 'Acordo ativo', 'tipo' => 'ok'];
    }

    if ($status === []) {
        $status[] = ['label' => 'Sem pendência operacional', 'tipo' => 'neutro'];
    }

    return $status;
}

/** @param list<array<string,mixed>> $retornos */
function proximaAcaoFichaOperacional(array $retornos, ?array $reserva, ?array $acordoAtivo, ?array $contato, int $pessoaId): array
{
    $retornoVencido = primeiroRetornoVencidoFichaOperacional($retornos);
    if ($retornoVencido !== null) {
        return [
            'titulo' => 'Tratar retorno vencido agora',
            'descricao' => 'Existe retorno pendente com horário vencido. Abra o atendimento já vinculado a esse retorno.',
            'href' => '/operacao/cliente_detalhe.php' . queryString(['id' => $pessoaId, 'retorno_id' => (int) $retornoVencido['id']]) . '#historico',
            'tipo' => 'danger',
        ];
    }

    if ($retornos !== []) {
        $retorno = $retornos[0];
        return [
            'titulo' => 'Realizar retorno agendado',
            'descricao' => 'Próximo retorno: ' . dataHoraBr($retorno['proximo_acionamento'] ?? null) . '.',
            'href' => '/operacao/cliente_detalhe.php' . queryString(['id' => $pessoaId, 'retorno_id' => (int) $retorno['id']]) . '#historico',
            'tipo' => 'warning',
        ];
    }

    if ($acordoAtivo !== null) {
        return [
            'titulo' => 'Acompanhar acordo ativo',
            'descricao' => 'Contrato possui acordo pendente/formalizado. Verifique parcelas e status antes de nova negociação.',
            'href' => '/operacao/acordo_detalhe.php?id=' . (int) $acordoAtivo['id'],
            'tipo' => 'ok',
        ];
    }

    if ($reserva === null) {
        return [
            'titulo' => 'Reservar e iniciar atendimento',
            'descricao' => 'Cliente sem dono ativo. Reserve para evitar conflito entre operadores.',
            'href' => '#agenda-cliente',
            'tipo' => 'info',
        ];
    }

    if ($contato !== null) {
        return [
            'titulo' => 'Tentar contato prioritário',
            'descricao' => ConsultaClienteRepository::contatoTipoLabel((string) ($contato['tipo'] ?? 'outro')) . ' ' . (string) ($contato['valor'] ?? '') . '.',
            'href' => '#historico',
            'tipo' => 'info',
        ];
    }

    return [
        'titulo' => 'Registrar nova tentativa',
        'descricao' => 'Sem contato priorizado. Registre a tentativa e atualize o histórico operacional.',
        'href' => '#historico',
        'tipo' => 'neutro',
    ];
}

try {
    if ($pessoaId <= 0) {
        throw new RuntimeException('ID do cliente não informado ou inválido.');
    }

    $pdo = operacaoPdo();
    $repository = new ConsultaClienteRepository($pdo);
    $atendimentoRepository = new AtendimentoRepository($pdo);
    $motivosInadimplencia = (new MotivoInadimplenciaRepository($pdo))->listarAtivos();
    $campanhaRepository = new CampanhaRepository($pdo);
    $acordoOperacionalRepository = new AcordoOperacionalRepository($pdo);
    $agendaRepository = new AgendaOperadorRepository($pdo);
    $pessoa = $repository->buscarPessoaPorId($pessoaId);

    if ($pessoa !== null) {
        requirePessoaNoEscopo($pessoaId, 'consultar');
    }

    if ($pessoa === null) {
        http_response_code(404);
        throw new RuntimeException('Cliente não encontrado.');
    }

    $escopoConsulta = usuarioEscopoCredoresAcao('consultar');
    $escopoOperar = usuarioEscopoCredoresAcao('operar');
    $escopoAcordo = usuarioEscopoCredoresAcao('fechar_acordo');

    $resumo = $repository->buscarResumoPessoa($pessoaId, $escopoConsulta);
    $contatos = $repository->listarContatos($pessoaId);
    $enderecos = $repository->listarEnderecos($pessoaId);
    $contratos = $repository->listarContratos($pessoaId, $escopoConsulta);
    $veiculosPorContrato = $repository->listarVeiculosPorContratos(array_column($contratos, 'id'));
    $cargasOrigem = $repository->listarCargasOrigemPessoa($pessoaId, 20);

    $atendimentoDisponivel = $atendimentoRepository->moduloDisponivel();
    $resultadoPadronizadoDisponivel = $atendimentoRepository->resultadoPadronizadoDisponivel();
    $retornoStatusDisponivel = $atendimentoRepository->retornoStatusDisponivel();
    $campanhaDisponivel = $campanhaRepository->moduloDisponivel();
    $acordoOperacionalDisponivel = $acordoOperacionalRepository->moduloDisponivel();
    $agendaDisponivel = $agendaRepository->moduloDisponivel();
    if ($agendaDisponivel) {
        $reservaAtivaCliente = $agendaRepository->reservaAtivaPessoa($pessoaId);
        $usuariosOperacionaisAgenda = $agendaRepository->listarUsuariosOperacionais();
    }
    if ($campanhaDisponivel && $campanhaItemId > 0) {
        $campanhaItemAtual = $campanhaRepository->buscarItemDaPessoa($campanhaItemId, $pessoaId, usuarioEscopoCredoresAcao('operar'));
        if ($campanhaItemAtual !== null && empty($formAtendimento['contrato_id']) && !empty($campanhaItemAtual['contrato_id'])) {
            $formAtendimento['contrato_id'] = (string) $campanhaItemAtual['contrato_id'];
        }
    }
    $resultadosAtendimento = $atendimentoRepository->listarResultadosAtivos();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if ($acaoRapidaFicha !== '') {
            $formAtendimento = aplicarAcaoRapidaFichaOperacional($formAtendimento, $acaoRapidaFicha, $resultadosAtendimento);
        }
        if ($contratoRapidoId > 0) {
            foreach ($contratos as $contratoRapido) {
                if ((int) $contratoRapido['id'] === $contratoRapidoId) {
                    $formAtendimento['contrato_id'] = (string) $contratoRapidoId;
                    break;
                }
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string) ($_POST['acao'] ?? ''), ['reservar_cliente_agenda', 'transferir_cliente_agenda', 'liberar_reserva_cliente'], true)) {
        if (!$agendaDisponivel) {
            throw new RuntimeException('Módulo de agenda/fila ainda não está ativo.');
        }
        requireOperacaoPermissao('fila.trabalhar');
        requirePessoaNoEscopo($pessoaId, 'operar');
        validarCsrf();

        try {
            $acaoAgenda = (string) ($_POST['acao'] ?? '');
            if ($acaoAgenda === 'liberar_reserva_cliente') {
                $reservaId = isset($_POST['reserva_id']) && ctype_digit((string) $_POST['reserva_id']) ? (int) $_POST['reserva_id'] : 0;
                $agendaRepository->liberarReserva($reservaId, usuarioAtualId() ?? 0, 'Liberado pela ficha do cliente.');
                registrarAuditoriaSistema('fila.reserva_liberada', 'operacao_cliente_reserva', $reservaId, 'Reserva operacional liberada pela ficha do cliente.');
            } else {
                if ($acaoAgenda === 'transferir_cliente_agenda') {
                    requireOperacaoPermissao('fila.transferir');
                    $destinoUsuarioId = isset($_POST['usuario_id']) && ctype_digit((string) $_POST['usuario_id']) ? (int) $_POST['usuario_id'] : 0;
                    $origemReserva = 'transferencia';
                } else {
                    $destinoUsuarioId = usuarioAtualId() ?? 0;
                    $origemReserva = 'manual';
                }

                $contratoReservaId = isset($_POST['contrato_id']) && ctype_digit((string) $_POST['contrato_id']) ? (int) $_POST['contrato_id'] : null;
                if ($contratoReservaId !== null) {
                    requireContratoNoEscopo($contratoReservaId, 'operar');
                }

                $reservaId = $agendaRepository->reservarCliente([
                    'pessoa_id' => $pessoaId,
                    'contrato_id' => $contratoReservaId,
                    'usuario_id' => $destinoUsuarioId,
                    'origem' => $origemReserva,
                    'observacao' => $_POST['observacao'] ?? null,
                    'horas' => $agendaRepository->regraInt('horas_reserva_manual', 8),
                ], usuarioAtualId() ?? 0);

                registrarAuditoriaSistema(
                    $acaoAgenda === 'transferir_cliente_agenda' ? 'fila.cliente_transferido' : 'fila.cliente_reservado',
                    'operacao_cliente_reserva',
                    $reservaId,
                    'Reserva/transferência operacional feita pela ficha do cliente.',
                    null,
                    ['pessoa_id' => $pessoaId, 'contrato_id' => $contratoReservaId, 'usuario_destino_id' => $destinoUsuarioId]
                );
            }

            header('Location: /operacao/cliente_detalhe.php' . queryString(['id' => $pessoaId, 'q' => $termoVolta, 'ok_agenda' => 1]) . '#agenda-cliente');
            exit;
        } catch (Throwable $e) {
            $mensagemErroAgenda = $e->getMessage();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string) ($_POST['acao'] ?? ''), ['adicionar_contato', 'atualizar_contato'], true)) {
        requireOperacaoPermissao('contatos.gerenciar');
        requirePessoaNoEscopo($pessoaId, 'operar');
        validarCsrf();

        try {
            if ((string) ($_POST['acao'] ?? '') === 'adicionar_contato') {
                $novoContatoId = $repository->adicionarContatoManual($pessoaId, $_POST);
                registrarAuditoriaSistema(
                    'contato.criado',
                    'pessoa_contato',
                    $novoContatoId,
                    'Contato manual criado na ficha do cliente.',
                    null,
                    [
                        'pessoa_id' => $pessoaId,
                        'tipo' => $_POST['tipo'] ?? null,
                        'valor' => $_POST['valor'] ?? null,
                        'status' => $_POST['status'] ?? null,
                        'principal' => $_POST['principal'] ?? null,
                    ]
                );
            } else {
                $contatoId = isset($_POST['contato_id']) && ctype_digit((string) $_POST['contato_id']) ? (int) $_POST['contato_id'] : 0;
                $antesContato = $repository->buscarContatoPorId($pessoaId, $contatoId);
                if ($antesContato === null) {
                    throw new RuntimeException('Contato não encontrado.');
                }
                $repository->atualizarContatoManual($pessoaId, $contatoId, $_POST);
                $depoisContato = $repository->buscarContatoPorId($pessoaId, $contatoId);
                registrarAuditoriaSistema(
                    'contato.atualizado',
                    'pessoa_contato',
                    $contatoId,
                    'Contato atualizado na ficha do cliente.',
                    [
                        'status' => $antesContato['status'] ?? null,
                        'subtipo' => $antesContato['subtipo'] ?? null,
                        'principal' => $antesContato['principal'] ?? null,
                    ],
                    [
                        'pessoa_id' => $pessoaId,
                        'status' => $depoisContato['status'] ?? null,
                        'subtipo' => $depoisContato['subtipo'] ?? null,
                        'principal' => $depoisContato['principal'] ?? null,
                    ]
                );
            }

            header('Location: /operacao/cliente_detalhe.php' . queryString(['id' => $pessoaId, 'q' => $termoVolta, 'ok_contato' => 1]) . '#contatos');
            exit;
        } catch (Throwable $e) {
            $mensagemErroContato = $e->getMessage();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'registrar_atendimento') {
        requireOperacaoPermissao('atendimentos.registrar');
        requirePessoaNoEscopo($pessoaId, 'operar');
        if (!empty($_POST['contrato_id']) && ctype_digit((string) $_POST['contrato_id'])) {
            requireContratoNoEscopo((int) $_POST['contrato_id'], 'operar');
        }
        if ((string) ($_POST['registrar_acordo'] ?? '') === '1') {
            requireOperacaoPermissao('acordos.criar');
            if (empty($_POST['contrato_id']) || !ctype_digit((string) $_POST['contrato_id'])) {
                throw new RuntimeException('Selecione um contrato permitido para registrar acordo.');
            }
            requireContratoNoEscopo((int) $_POST['contrato_id'], 'fechar_acordo');
        }
        $_POST['usuario_id'] = usuarioAtualId();
        if (trim((string) ($_POST['operador_nome'] ?? '')) === '' && usuarioAtualNome() !== null) {
            $_POST['operador_nome'] = usuarioAtualNome();
        }

        if (isset($_POST['retorno_origem_id']) && ctype_digit((string) $_POST['retorno_origem_id'])) {
            $retornoOrigemId = (int) $_POST['retorno_origem_id'];
        }
        if (isset($_POST['campanha_item_id']) && ctype_digit((string) $_POST['campanha_item_id'])) {
            $campanhaItemId = (int) $_POST['campanha_item_id'];
        }

        try {
            if ($campanhaItemId > 0) {
                if (!$campanhaDisponivel) {
                    throw new RuntimeException('Módulo de campanhas indisponível para vincular o atendimento à fila.');
                }

                $campanhaItemAtual = $campanhaRepository->buscarItemDaPessoa($campanhaItemId, $pessoaId, usuarioEscopoCredoresAcao('operar'));
                if ($campanhaItemAtual === null) {
                    throw new RuntimeException('Item de campanha inválido para este cliente.');
                }
            }

            $pdo->beginTransaction();

            $novoAtendimentoId = $atendimentoRepository->registrar($pessoaId, $_POST);
            registrarAuditoriaSistema(
                'atendimento.registrado',
                'atendimento',
                $novoAtendimentoId,
                'Atendimento registrado na ficha do cliente.',
                null,
                [
                    'pessoa_id' => $pessoaId,
                    'contrato_id' => $_POST['contrato_id'] ?? null,
                    'canal' => $_POST['canal'] ?? null,
                    'resultado' => $_POST['resultado'] ?? null,
                    'resultado_id' => $_POST['resultado_id'] ?? null,
                    'campanha_item_id' => $campanhaItemId > 0 ? $campanhaItemId : null,
                    'retorno_origem_id' => $retornoOrigemId > 0 ? $retornoOrigemId : null,
                ]
            );
            $retornoMarcado = false;
            $campanhaItemMarcado = false;
            $acordoRegistrado = false;

            if ($acordoOperacionalDisponivel && (string) ($_POST['registrar_acordo'] ?? '') === '1') {
                $origemAcordo = 'manual';
                if ($campanhaItemId > 0) {
                    $origemAcordo = 'fila_manual';
                } elseif ($retornoOrigemId > 0) {
                    $origemAcordo = 'retorno';
                }

                $novoAcordoId = $acordoOperacionalRepository->registrar($pessoaId, [
                    'contrato_id' => $_POST['contrato_id'] ?? null,
                    'atendimento_id' => $novoAtendimentoId,
                    'campanha_item_id' => $campanhaItemId > 0 ? $campanhaItemId : null,
                    'retorno_origem_id' => $retornoOrigemId > 0 ? $retornoOrigemId : null,
                    'valor_acordo' => $_POST['acordo_valor'] ?? null,
                    'valor_entrada' => $_POST['acordo_valor_entrada'] ?? null,
                    'data_entrada' => $_POST['acordo_data_entrada'] ?? null,
                    'quantidade_parcelas' => $_POST['acordo_quantidade_parcelas'] ?? 1,
                    'data_primeira_parcela' => $_POST['acordo_data_primeira_parcela'] ?? null,
                    'periodicidade' => $_POST['acordo_periodicidade'] ?? 'mensal',
                    'forma_pagamento' => $_POST['acordo_forma_pagamento'] ?? 'nao_informado',
                    'acordo_observacao' => $_POST['acordo_observacao'] ?? null,
                    'operador_nome' => $_POST['operador_nome'] ?? null,
                    'origem' => $origemAcordo,
                    'status_operacional' => 'pendente',
                    'origem_tela' => 'operacao/cliente_detalhe.php',
                    'usuario_id' => usuarioAtualId(),
                ]);
                registrarAuditoriaSistema(
                    'acordo.criado',
                    'acordo_operacional',
                    $novoAcordoId,
                    'Acordo operacional criado pela ficha do cliente.',
                    null,
                    [
                        'pessoa_id' => $pessoaId,
                        'contrato_id' => $_POST['contrato_id'] ?? null,
                        'atendimento_id' => $novoAtendimentoId,
                        'campanha_item_id' => $campanhaItemId > 0 ? $campanhaItemId : null,
                        'retorno_origem_id' => $retornoOrigemId > 0 ? $retornoOrigemId : null,
                        'valor_acordo' => $_POST['acordo_valor'] ?? null,
                        'valor_entrada' => $_POST['acordo_valor_entrada'] ?? null,
                        'quantidade_parcelas' => $_POST['acordo_quantidade_parcelas'] ?? 1,
                        'origem' => $origemAcordo,
                    ]
                );
                $acordoRegistrado = true;
            }

            if ($retornoStatusDisponivel
                && $retornoOrigemId > 0
                && (string) ($_POST['marcar_retorno_origem'] ?? '') === '1'
            ) {
                $atendimentoRepository->atualizarStatusRetorno(
                    $retornoOrigemId,
                    'tratado',
                    'Tratado a partir do atendimento #' . $novoAtendimentoId . '.',
                    $pessoaId
                );
                $retornoMarcado = true;
            }

            if ($campanhaDisponivel && $campanhaItemId > 0) {
                $campanhaRepository->marcarItemTrabalhado($campanhaItemId, $novoAtendimentoId, $pessoaId);
                $campanhaItemMarcado = true;
            }

            $pdo->commit();

            $queryVolta = queryString([
                'id' => $pessoaId,
                'q' => $termoVolta,
                'ok_atendimento' => 1,
                'ok_retorno_tratado' => $retornoMarcado ? 1 : null,
                'ok_campanha_item' => $campanhaItemMarcado ? 1 : null,
                'ok_acordo' => $acordoRegistrado ? 1 : null,
            ]);
            header('Location: /operacao/cliente_detalhe.php' . $queryVolta . '#historico');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensagemErroAtendimento = $e->getMessage();
        }
    }

    $resumoAtendimento = $atendimentoRepository->resumoPessoa($pessoaId);
    $historicoAtendimentos = $atendimentoRepository->listarPorPessoa($pessoaId, 30, $escopoConsulta);
    $retornosPendentesPessoa = $atendimentoRepository->listarRetornosPendentesPessoa($pessoaId, 5, $escopoConsulta);
    if ($acordoOperacionalDisponivel) {
        $acordosOperacionaisPessoa = $acordoOperacionalRepository->listarPorPessoa($pessoaId, 20, $escopoConsulta);
        $parcelasAcordosOperacionaisPessoa = $acordoOperacionalRepository->listarParcelasPorAcordos(array_column($acordosOperacionaisPessoa, 'id'));
        $resumoAcordosOperacionaisPessoa = $acordoOperacionalRepository->resumoPessoa($pessoaId, $escopoConsulta);
    }
    $campanhasCliente = $campanhaDisponivel ? $campanhaRepository->listarItensDaPessoa($pessoaId, 8, $escopoConsulta) : [];
    if ($campanhaItemAtual !== null) {
        $campanhasCliente = array_values(array_filter($campanhasCliente, static fn (array $item): bool => (int) $item['id'] !== (int) $campanhaItemAtual['id']));
        array_unshift($campanhasCliente, $campanhaItemAtual);
    }
    $ultimoAtendimentoOperacional = $historicoAtendimentos[0] ?? null;
    $proximoRetornoOperacional = $retornosPendentesPessoa[0] ?? null;
    $ultimoAcordoOperacional = $acordosOperacionaisPessoa[0] ?? null;
    $acordoAtivoOperacional = acordoAtivoFichaOperacional($acordosOperacionaisPessoa);
    $contatoOperacionalPrioritario = contatoPrioritarioFichaOperacional($contatos);
    $contratoOperacionalPrincipal = contratoPrincipalFichaOperacional($contratos);
    $statusOperacionalCliente = statusOperacionalFicha($campanhasCliente, $reservaAtivaCliente, $retornosPendentesPessoa, $acordoAtivoOperacional);
    $proximaAcaoOperacional = proximaAcaoFichaOperacional($retornosPendentesPessoa, $reservaAtivaCliente, $acordoAtivoOperacional, $contatoOperacionalPrioritario, $pessoaId);
    $timelineCliente = montarTimelineClienteOperacao($historicoAtendimentos, $acordosOperacionaisPessoa, $retornosPendentesPessoa);
} catch (Throwable $e) {
    $erro = $e->getMessage();
    if (http_response_code() === 200) {
        http_response_code(500);
    }
}

require __DIR__ . '/includes/header.php';
?>
<main class="container">
    <section class="card compact">
        <div class="actions-row">
            <a class="button secondary" href="/operacao/consulta_cliente.php<?= h(queryString(['q' => $termoVolta])) ?>">Voltar para consulta</a>
            <a class="button secondary" href="/operacao/index.php">Início da operação</a>
            <?php if ($pessoaId > 0 && usuarioPode('historico.visualizar')): ?>
                <a class="button" href="/operacao/cliente_historico.php?id=<?= (int) $pessoaId ?>">Histórico completo</a>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($erro !== null): ?>
        <section class="card error">
            <strong>Não foi possível carregar a ficha:</strong> <?= h($erro) ?>
        </section>
    <?php elseif ($pessoa !== null): ?>
        <section class="card">
            <div class="detail-header">
                <div>
                    <span class="eyebrow">Cliente #<?= (int) $pessoa['id'] ?></span>
                    <h2><?= h($pessoa['nome']) ?></h2>
                    <div class="muted"><?= h($pessoa['tipo_documento']) ?> <?= documentoFormatado((string) $pessoa['documento']) ?></div>
                </div>
                <div class="topbar-actions">
                    <?= badge((string) $pessoa['tipo_documento']) ?>
                    <?= badgeSoft('Ficha operacional 3.0') ?>
                </div>
            </div>
        </section>

        <section class="card ops-hero-card">
            <div class="ops-hero-grid">
                <div class="ops-hero-main">
                    <span class="eyebrow">Comando operacional</span>
                    <h3><?= h((string) $proximaAcaoOperacional['titulo']) ?></h3>
                    <p><?= h((string) $proximaAcaoOperacional['descricao']) ?></p>
                    <div class="status-pill-row">
                        <?php foreach ($statusOperacionalCliente as $statusFicha): ?>
                            <span class="status-pill status-pill-<?= h((string) $statusFicha['tipo']) ?>"><?= h((string) $statusFicha['label']) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="actions-row quick-actions">
                        <a class="button" href="<?= h((string) $proximaAcaoOperacional['href']) ?>">Executar próxima ação</a>
                        <a class="button secondary" href="/operacao/cliente_detalhe.php<?= h(queryString(['id' => $pessoaId, 'q' => $termoVolta, 'acao_rapida' => 'sem_contato', 'contrato_id' => $contratoOperacionalPrincipal['id'] ?? null])) ?>#historico">Atendimento sem contato</a>
                        <a class="button secondary" href="/operacao/cliente_detalhe.php<?= h(queryString(['id' => $pessoaId, 'q' => $termoVolta, 'acao_rapida' => 'contato_efetivo', 'contrato_id' => $contratoOperacionalPrincipal['id'] ?? null])) ?>#historico">Contato efetivo</a>
                        <a class="button secondary" href="/operacao/cliente_detalhe.php<?= h(queryString(['id' => $pessoaId, 'q' => $termoVolta, 'acao_rapida' => 'agendar_retorno', 'contrato_id' => $contratoOperacionalPrincipal['id'] ?? null])) ?>#historico">Agendar retorno</a>
                        <?php if ($acordoOperacionalDisponivel && usuarioPode('acordos.criar')): ?>
                            <a class="button secondary" href="/operacao/cliente_detalhe.php<?= h(queryString(['id' => $pessoaId, 'q' => $termoVolta, 'acao_rapida' => 'criar_acordo', 'contrato_id' => $contratoOperacionalPrincipal['id'] ?? null])) ?>#historico">Criar acordo</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ops-hero-side">
                    <div class="ops-owner-card">
                        <small>Dono atual</small>
                        <strong><?= $reservaAtivaCliente !== null ? h((string) $reservaAtivaCliente['usuario_nome']) : 'Sem dono ativo' ?></strong>
                        <span><?= $reservaAtivaCliente !== null ? 'Reserva ' . h((string) $reservaAtivaCliente['origem']) . ' até ' . h(dataHoraBr($reservaAtivaCliente['expira_em'] ?? null)) : 'Cliente disponível para reserva/fila.' ?></span>
                    </div>
                    <div class="ops-facts-mini">
                        <div><small>Último atendimento</small><strong><?= $ultimoAtendimentoOperacional !== null ? h(dataHoraBr($ultimoAtendimentoOperacional['criado_em'] ?? null)) : '—' ?></strong><span><?= $ultimoAtendimentoOperacional !== null ? h(AtendimentoRepository::resultadoLabelRegistro($ultimoAtendimentoOperacional)) : 'Sem atendimento' ?></span></div>
                        <div><small>Próximo retorno</small><strong><?= $proximoRetornoOperacional !== null ? h(dataHoraBr($proximoRetornoOperacional['proximo_acionamento'] ?? null)) : '—' ?></strong><span><?= $proximoRetornoOperacional !== null ? h(AtendimentoRepository::retornoSituacaoLabel((string) ($proximoRetornoOperacional['retorno_situacao'] ?? 'pendente'))) : 'Sem retorno pendente' ?></span></div>
                        <div><small>Último acordo</small><strong><?= $ultimoAcordoOperacional !== null ? h(dinheiro($ultimoAcordoOperacional['valor_acordo'] ?? null)) : '—' ?></strong><span><?= $ultimoAcordoOperacional !== null ? h(AcordoOperacionalRepository::statusLabel((string) ($ultimoAcordoOperacional['status_operacional'] ?? 'pendente'))) : 'Sem acordo' ?></span></div>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($agendaDisponivel): ?>
            <section id="agenda-cliente" class="card agenda-client-card">
                <div class="section-title">
                    <div>
                        <h3>Agenda/fila do cliente</h3>
                        <p>Reserve este cliente para você ou transfira para um operador específico sem alterar a carga original.</p>
                    </div>
                    <?php if ($reservaAtivaCliente !== null): ?>
                        <?= badge('Reservado para ' . (string) $reservaAtivaCliente['usuario_nome']) ?>
                    <?php else: ?>
                        <?= badgeSoft('Sem reserva ativa') ?>
                    <?php endif; ?>
                </div>

                <?php if ($mensagemSucessoAgenda !== null): ?><div class="alert success"><?= h($mensagemSucessoAgenda) ?></div><?php endif; ?>
                <?php if ($mensagemErroAgenda !== null): ?><div class="alert danger"><?= h($mensagemErroAgenda) ?></div><?php endif; ?>

                <?php if ($reservaAtivaCliente !== null): ?>
                    <div class="agenda-current-reservation">
                        <div>
                            <strong><?= h((string) $reservaAtivaCliente['usuario_nome']) ?></strong>
                            <span>Origem: <?= h((string) $reservaAtivaCliente['origem']) ?> · expira em <?= h(dataHoraBr($reservaAtivaCliente['expira_em'] ?? null)) ?></span>
                            <?php if (!empty($reservaAtivaCliente['observacao'])): ?><span><?= h((string) $reservaAtivaCliente['observacao']) ?></span><?php endif; ?>
                        </div>
                        <?php if (usuarioPode('fila.transferir') || (int) ($reservaAtivaCliente['usuario_id'] ?? 0) === (usuarioAtualId() ?? 0)): ?>
                            <form method="post" class="inline-form">
                                <?= authCsrfInput() ?>
                                <input type="hidden" name="acao" value="liberar_reserva_cliente">
                                <input type="hidden" name="reserva_id" value="<?= (int) $reservaAtivaCliente['id'] ?>">
                                <button class="button secondary" type="submit">Liberar reserva</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="agenda-client-actions">
                    <form method="post" class="form-card agenda-client-form">
                        <?= authCsrfInput() ?>
                        <input type="hidden" name="acao" value="reservar_cliente_agenda">
                        <div class="form-grid">
                            <label class="field">
                                <span>Contrato vinculado</span>
                                <select name="contrato_id">
                                    <option value="">Cliente geral</option>
                                    <?php foreach ($contratos as $contratoAgenda): ?>
                                        <option value="<?= (int) $contratoAgenda['id'] ?>"><?= h((string) $contratoAgenda['codigo_contrato']) ?> - <?= h((string) ($contratoAgenda['credor_nome'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="field field-wide">
                                <span>Observação</span>
                                <input type="text" name="observacao" maxlength="1000" placeholder="Ex.: cliente pediu retorno exclusivo pelo operador atual">
                            </label>
                        </div>
                        <div class="actions-row">
                            <button class="button" type="submit">Reservar para mim</button>
                        </div>
                    </form>

                    <?php if (usuarioPode('fila.transferir')): ?>
                        <form method="post" class="form-card agenda-client-form">
                            <?= authCsrfInput() ?>
                            <input type="hidden" name="acao" value="transferir_cliente_agenda">
                            <div class="form-grid">
                                <label class="field">
                                    <span>Operador destino</span>
                                    <select name="usuario_id" required>
                                        <option value="">Selecione</option>
                                        <?php foreach ($usuariosOperacionaisAgenda as $usuarioAgenda): ?>
                                            <option value="<?= (int) $usuarioAgenda['id'] ?>"><?= h((string) $usuarioAgenda['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>Contrato vinculado</span>
                                    <select name="contrato_id">
                                        <option value="">Cliente geral</option>
                                        <?php foreach ($contratos as $contratoAgenda): ?>
                                            <option value="<?= (int) $contratoAgenda['id'] ?>"><?= h((string) $contratoAgenda['codigo_contrato']) ?> - <?= h((string) ($contratoAgenda['credor_nome'] ?? '')) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="field field-wide">
                                    <span>Observação</span>
                                    <input type="text" name="observacao" maxlength="1000" placeholder="Motivo da transferência ou orientação ao operador">
                                </label>
                            </div>
                            <div class="actions-row">
                                <button class="button secondary" type="submit">Transferir/reservar operador</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($retornosPendentesPessoa !== []): ?>
            <section class="alert warning retorno-pendente-alert">
                <strong>Este cliente possui retorno pendente.</strong>
                <?php foreach ($retornosPendentesPessoa as $retornoPendente): ?>
                    <div class="retorno-pendente-item">
                        <span><?= h(dataHoraBr($retornoPendente['proximo_acionamento'])) ?></span>
                        <?= badge(AtendimentoRepository::retornoSituacaoLabel((string) $retornoPendente['retorno_situacao'])) ?>
                        <?php if (!empty($retornoPendente['codigo_contrato'])): ?><?= badge('Contrato ' . (string) $retornoPendente['codigo_contrato']) ?><?php endif; ?>
                        <a href="/operacao/cliente_detalhe.php?id=<?= (int) $pessoaId ?>&retorno_id=<?= (int) $retornoPendente['id'] ?>#historico">Registrar atendimento deste retorno</a>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if ($campanhaItemAtual !== null): ?>
            <section class="alert warning retorno-pendente-alert">
                <strong>Cliente aberto pela fila manual.</strong>
                <div class="retorno-pendente-item">
                    <span>Campanha #<?= (int) $campanhaItemAtual['campanha_id'] ?> - <?= h((string) $campanhaItemAtual['campanha_nome']) ?></span>
                    <?= badge(CampanhaRepository::statusItemLabel((string) $campanhaItemAtual['status'])) ?>
                    <?php if (!empty($campanhaItemAtual['codigo_contrato'])): ?><?= badge('Contrato ' . (string) $campanhaItemAtual['codigo_contrato']) ?><?php endif; ?>
                    <?php if (!empty($campanhaItemAtual['saldo_atual'])): ?><?= badge(dinheiro($campanhaItemAtual['saldo_atual'])) ?><?php endif; ?>
                    <a href="/operacao/fila_manual.php?campanha_id=<?= (int) $campanhaItemAtual['campanha_id'] ?>">Voltar para fila</a>
                </div>
            </section>
        <?php elseif ($campanhaItemId > 0): ?>
            <section class="alert danger">Item de fila não encontrado para este cliente ou migration 012 não aplicada.</section>
        <?php endif; ?>

        <?php if ($campanhasCliente !== []): ?>
            <section class="card ops-list-card">
                <div class="section-title">
                    <div>
                        <h3>Campanhas e fila deste cliente</h3>
                        <p>Mostra se o cliente está em campanha, fila geral, item pendente ou já trabalhado.</p>
                    </div>
                    <span class="badge"><?= count($campanhasCliente) ?></span>
                </div>
                <div class="ops-list-grid">
                    <?php foreach ($campanhasCliente as $campanhaCliente): ?>
                        <article class="ops-mini-card">
                            <div class="ops-mini-head">
                                <strong><?= h((string) $campanhaCliente['campanha_nome']) ?></strong>
                                <?= badge(CampanhaRepository::statusItemLabel((string) ($campanhaCliente['status'] ?? 'pendente'))) ?>
                            </div>
                            <p><?= h((string) ($campanhaCliente['credor_nome'] ?? 'Credor não vinculado')) ?><?= !empty($campanhaCliente['codigo_contrato']) ? ' · contrato ' . h((string) $campanhaCliente['codigo_contrato']) : '' ?></p>
                            <small>Status da campanha: <?= h(CampanhaRepository::statusCampanhaLabel((string) ($campanhaCliente['campanha_status'] ?? 'rascunho'))) ?> · Gerado em <?= h(dataHoraBr($campanhaCliente['gerado_em'] ?? null)) ?></small>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="card">
            <div class="metrics-grid">
                <div class="metric">
                    <small>Total de contratos</small>
                    <strong><?= number_format((int) $resumo['total_contratos'], 0, ',', '.') ?></strong>
                </div>
                <div class="metric">
                    <small>Saldo total</small>
                    <strong><?= h(dinheiro($resumo['saldo_total'])) ?></strong>
                </div>
                <div class="metric">
                    <small>Total de contatos</small>
                    <strong><?= number_format((int) $resumo['total_contatos'], 0, ',', '.') ?></strong>
                </div>
                <div class="metric">
                    <small>Atendimentos</small>
                    <strong><?= number_format((int) $resumoAtendimento['total_atendimentos'], 0, ',', '.') ?></strong>
                </div>
                <div class="metric">
                    <small>Próximo acionamento</small>
                    <strong><?= h(dataHoraBr($resumoAtendimento['proximo_acionamento'])) ?></strong>
                </div>
            </div>
        </section>

        <nav class="tabs-row" aria-label="Seções da ficha">
            <a class="tab-link" href="#dados">Dados cadastrais</a>
            <a class="tab-link" href="#contatos">Contatos</a>
            <a class="tab-link" href="#enderecos">Endereços</a>
            <a class="tab-link" href="#contratos">Contratos</a>
            <a class="tab-link" href="#cargas">Cargas/origem</a>
            <a class="tab-link" href="#historico">Atendimento</a>
            <a class="tab-link" href="#timeline">Timeline</a>
            <a class="tab-link" href="#acordos">Acordos</a>
        </nav>

        <section id="dados" class="card">
            <div class="section-title">
                <div>
                    <h3>Dados cadastrais</h3>
                    <p>Dados consolidados nas tabelas canônicas de pessoas.</p>
                </div>
            </div>
            <div class="facts">
                <div class="fact"><small>Documento</small><strong><?= documentoFormatado((string) $pessoa['documento']) ?></strong></div>
                <div class="fact"><small>Nascimento</small><strong><?= h(dataBr($pessoa['data_nascimento'])) ?></strong></div>
                <div class="fact"><small>Sexo</small><strong><?= valorOuTraco($pessoa['sexo']) ?></strong></div>
                <div class="fact"><small>Estado civil</small><strong><?= valorOuTraco($pessoa['estado_civil']) ?></strong></div>
                <div class="fact"><small>Nome da mãe</small><strong><?= valorOuTraco($pessoa['nome_mae']) ?></strong></div>
                <div class="fact"><small>Nome do pai</small><strong><?= valorOuTraco($pessoa['nome_pai']) ?></strong></div>
                <div class="fact"><small>RG</small><strong><?= valorOuTraco($pessoa['rg']) ?></strong></div>
                <div class="fact"><small>Renda mensal</small><strong><?= h(dinheiro($pessoa['renda_mensal'])) ?></strong></div>
                <div class="fact"><small>Origem da carga</small><strong><?= valorOuTraco($pessoa['origem_carga_nome']) ?></strong></div>
                <div class="fact"><small>Última carga</small><strong><?= valorOuTraco($pessoa['ultima_carga_nome']) ?></strong></div>
                <div class="fact"><small>Última carga finalizada</small><strong><?= h(dataHoraBr($pessoa['ultima_carga_finalizado_em'])) ?></strong></div>
                <div class="fact"><small>Atualizado em</small><strong><?= h(dataHoraBr($pessoa['atualizado_em'])) ?></strong></div>
            </div>
        </section>

        <section id="contatos" class="card contact-manager-card">
            <div class="section-title">
                <div>
                    <h3>Contatos</h3>
                    <p>Telefones, WhatsApp e e-mails consolidados das cargas e também adicionados manualmente pela operação.</p>
                </div>
                <span class="badge"><?= count($contatos) ?></span>
            </div>

            <?php if ($mensagemSucessoContato !== null): ?>
                <div class="alert success"><?= h($mensagemSucessoContato) ?></div>
            <?php endif; ?>
            <?php if ($mensagemErroContato !== null): ?>
                <div class="alert danger"><?= h($mensagemErroContato) ?></div>
            <?php endif; ?>

            <?php if ($contatoOperacionalPrioritario !== null): ?>
                <div class="contact-priority-card">
                    <div>
                        <span class="eyebrow">Contato sugerido para ação</span>
                        <strong><?= h(ConsultaClienteRepository::contatoTipoLabel((string) ($contatoOperacionalPrioritario['tipo'] ?? 'outro')) . ' · ' . (string) ($contatoOperacionalPrioritario['valor'] ?? '')) ?></strong>
                        <small>Status <?= h(ConsultaClienteRepository::contatoStatusLabel((string) ($contatoOperacionalPrioritario['status'] ?? 'novo'))) ?><?= (int) ($contatoOperacionalPrioritario['principal'] ?? 0) === 1 ? ' · principal' : '' ?></small>
                    </div>
                    <a class="button secondary" href="/operacao/cliente_detalhe.php<?= h(queryString(['id' => $pessoaId, 'q' => $termoVolta, 'acao_rapida' => 'contato_efetivo', 'contrato_id' => $contratoOperacionalPrincipal['id'] ?? null])) ?>#historico">Usar no atendimento</a>
                </div>
            <?php endif; ?>

            <div class="contact-summary-grid">
                <div class="metric"><small>Telefones/WhatsApp</small><strong><?= number_format(count(array_filter($contatos, static fn (array $c): bool => in_array((string) $c['tipo'], ['telefone', 'whatsapp'], true))), 0, ',', '.') ?></strong></div>
                <div class="metric"><small>E-mails</small><strong><?= number_format(count(array_filter($contatos, static fn (array $c): bool => (string) $c['tipo'] === 'email')), 0, ',', '.') ?></strong></div>
                <div class="metric"><small>Hot/correto</small><strong><?= number_format(count(array_filter($contatos, static fn (array $c): bool => in_array((string) $c['status'], ['hot', 'correto'], true))), 0, ',', '.') ?></strong></div>
                <div class="metric"><small>Ruins/bloqueados</small><strong><?= number_format(count(array_filter($contatos, static fn (array $c): bool => in_array((string) $c['status'], ['incorreto', 'bloqueado'], true))), 0, ',', '.') ?></strong></div>
            </div>

            <?php if (usuarioPode('contatos.gerenciar')): ?>
                <details class="inline-details add-contact-details">
                    <summary>Adicionar contato manual</summary>
                    <form class="form-card contact-add-form" method="post" action="/operacao/cliente_detalhe.php<?= h(queryString(['id' => $pessoaId, 'q' => $termoVolta])) ?>#contatos">
                        <?= authCsrfInput() ?>
                        <input type="hidden" name="acao" value="adicionar_contato">
                        <div class="form-grid">
                            <label class="field">
                                <span>Tipo</span>
                                <select name="tipo" required>
                                    <?php foreach (ConsultaClienteRepository::CONTATO_TIPOS as $valor => $label): ?>
                                        <option value="<?= h($valor) ?>"><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="field">
                                <span>Valor</span>
                                <input type="text" name="valor" maxlength="255" placeholder="Telefone, WhatsApp ou e-mail" required>
                            </label>
                            <label class="field">
                                <span>Status</span>
                                <select name="status">
                                    <?php foreach (ConsultaClienteRepository::CONTATO_STATUS as $valor => $label): ?>
                                        <option value="<?= h($valor) ?>" <?= $valor === 'novo' ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="field">
                                <span>Subtipo</span>
                                <input type="text" name="subtipo" maxlength="60" placeholder="Ex.: pessoal, trabalho, recado">
                            </label>
                            <label class="field field-wide">
                                <span>Observação</span>
                                <input type="text" name="observacao" maxlength="1000" placeholder="Ex.: informado pelo cliente no atendimento">
                            </label>
                            <label class="check-row contact-main-check">
                                <input type="checkbox" name="principal" value="1">
                                <span>Marcar como principal deste tipo.</span>
                            </label>
                        </div>
                        <div class="actions-row">
                            <button class="button" type="submit">Salvar contato</button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>

            <?php if ($contatos === []): ?>
                <div class="empty">Nenhum contato localizado.</div>
            <?php else: ?>
                <div class="contact-grid">
                    <?php foreach ($contatos as $contato): ?>
                        <article class="contact-card contact-status-<?= h((string) $contato['status']) ?>">
                            <div class="contact-card-head">
                                <div>
                                    <span class="contact-kind"><?= h(ConsultaClienteRepository::contatoTipoLabel((string) $contato['tipo'])) ?></span>
                                    <strong><?= h($contato['valor']) ?></strong>
                                </div>
                                <div class="contact-actions-mini">
                                    <?= badge(ConsultaClienteRepository::contatoStatusLabel((string) $contato['status'])) ?>
                                    <?php if ((int) $contato['principal'] === 1): ?><?= badgeSoft('principal') ?><?php endif; ?>
                                </div>
                            </div>
                            <div class="contact-meta">
                                <span><?= valorOuTraco($contato['subtipo']) ?></span>
                                <span><?= valorOuTraco($contato['ultima_carga_nome'] ?? $contato['origem'] ?? null) ?></span>
                                <?php if (!empty($contato['atualizado_em'])): ?><span>Atualizado <?= h(dataHoraBr($contato['atualizado_em'])) ?></span><?php endif; ?>
                            </div>
                            <div class="actions-row contact-row-actions">
                                <button class="button small secondary" type="button" data-copy="<?= h($contato['valor']) ?>" onclick="navigator.clipboard && navigator.clipboard.writeText(this.dataset.copy)">Copiar</button>
                                <?php if (in_array((string) $contato['tipo'], ['telefone', 'whatsapp'], true)): ?>
                                    <a class="button small secondary" href="tel:<?= h(preg_replace('/\D+/', '', (string) $contato['valor']) ?? '') ?>">Ligar</a>
                                <?php endif; ?>
                                <?php if ((string) $contato['tipo'] === 'email'): ?>
                                    <a class="button small secondary" href="mailto:<?= h((string) $contato['valor']) ?>">E-mail</a>
                                <?php endif; ?>
                            </div>

                            <?php if (usuarioPode('contatos.gerenciar')): ?>
                                <details class="inline-details contact-edit-details">
                                    <summary>Atualizar status</summary>
                                    <form class="contact-edit-form" method="post" action="/operacao/cliente_detalhe.php<?= h(queryString(['id' => $pessoaId, 'q' => $termoVolta])) ?>#contatos">
                                        <?= authCsrfInput() ?>
                                        <input type="hidden" name="acao" value="atualizar_contato">
                                        <input type="hidden" name="contato_id" value="<?= (int) $contato['id'] ?>">
                                        <div class="form-grid mini-grid">
                                            <label class="field">
                                                <span>Status</span>
                                                <select name="status">
                                                    <?php foreach (ConsultaClienteRepository::CONTATO_STATUS as $valor => $label): ?>
                                                        <option value="<?= h($valor) ?>" <?= ((string) $contato['status'] === $valor) ? 'selected' : '' ?>><?= h($label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label class="field">
                                                <span>Subtipo</span>
                                                <input type="text" name="subtipo" maxlength="60" value="<?= h($contato['subtipo'] ?? '') ?>">
                                            </label>
                                            <label class="field field-wide">
                                                <span>Observação</span>
                                                <input type="text" name="observacao" maxlength="1000" placeholder="Motivo da atualização">
                                            </label>
                                            <label class="check-row contact-main-check">
                                                <input type="checkbox" name="principal" value="1" <?= ((int) $contato['principal'] === 1) ? 'checked' : '' ?>>
                                                <span>Principal deste tipo</span>
                                            </label>
                                        </div>
                                        <div class="actions-row">
                                            <button class="button small" type="submit">Salvar status</button>
                                        </div>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section id="enderecos" class="card">
            <div class="section-title"><h3>Endereços</h3><span class="badge"><?= count($enderecos) ?></span></div>
            <?php if ($enderecos === []): ?>
                <div class="empty">Nenhum endereço localizado.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Cidade/UF</th>
                            <th>CEP</th>
                            <th>Logradouro</th>
                            <th>Bairro</th>
                            <th>Origem</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($enderecos as $endereco): ?>
                            <tr>
                                <td><?= valorOuTraco($endereco['tipo']) ?></td>
                                <td><strong><?= valorOuTraco($endereco['cidade']) ?> / <?= valorOuTraco($endereco['uf']) ?></strong></td>
                                <td><?= valorOuTraco($endereco['cep']) ?></td>
                                <td><?= valorOuTraco(trim((string) ($endereco['logradouro'] ?? '') . ' ' . (string) ($endereco['numero'] ?? ''))) ?></td>
                                <td><?= valorOuTraco($endereco['bairro']) ?></td>
                                <td><?= valorOuTraco($endereco['ultima_carga_nome'] ?? $endereco['origem'] ?? null) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section id="contratos" class="card">
            <div class="section-title">
                <div>
                    <h3>Contratos</h3>
                    <p>Contratos dentro do escopo do usuário. O contrato com maior saldo fica como foco para botões rápidos.</p>
                </div>
                <span class="badge"><?= count($contratos) ?></span>
            </div>
            <?php if ($contratos === []): ?>
                <div class="empty">Nenhum contrato localizado.</div>
            <?php else: ?>
                <?php foreach ($contratos as $contrato): ?>
                    <article class="contract-card contract-card-038 <?= $contratoOperacionalPrincipal !== null && (int) $contratoOperacionalPrincipal['id'] === (int) $contrato['id'] ? 'contract-focus' : '' ?>">
                        <div class="contract-head">
                            <div>
                                <h3>Contrato <?= h($contrato['codigo_contrato']) ?></h3>
                                <div class="muted"><?= h($contrato['credor_nome']) ?><?= $contrato['carteira_nome'] ? ' / ' . h($contrato['carteira_nome']) : '' ?></div>
                            </div>
                            <div class="contract-badges">
                                <?php if ($contratoOperacionalPrincipal !== null && (int) $contratoOperacionalPrincipal['id'] === (int) $contrato['id']): ?><?= badgeSoft('contrato em foco') ?><?php endif; ?>
                                <?= badge((string) $contrato['status_contrato'], 'sem status') ?>
                                <?= badge(dinheiro($contrato['saldo_atual'])) ?>
                            </div>
                        </div>

                        <div class="facts">
                            <div class="fact"><small>Produto</small><strong><?= valorOuTraco($contrato['produto']) ?></strong></div>
                            <div class="fact"><small>Saldo atual</small><strong><?= h(dinheiro($contrato['saldo_atual'])) ?></strong></div>
                            <div class="fact"><small>Total pago</small><strong><?= h(dinheiro($contrato['valor_total_pago'])) ?></strong></div>
                            <div class="fact"><small>Dias atraso</small><strong><?= valorOuTraco($contrato['dias_atraso']) ?></strong></div>
                            <div class="fact"><small>Fila</small><strong><?= valorOuTraco($contrato['fila']) ?></strong></div>
                            <div class="fact"><small>Lote</small><strong><?= valorOuTraco($contrato['lote']) ?></strong></div>
                            <div class="fact"><small>Conta origem</small><strong><?= valorOuTraco($contrato['conta_id']) ?></strong></div>
                            <div class="fact"><small>Devedor origem</small><strong><?= valorOuTraco($contrato['devedor_origem_id']) ?></strong></div>
                            <div class="fact"><small>Status devedor</small><strong><?= valorOuTraco($contrato['status_devedor']) ?></strong></div>
                            <div class="fact"><small>Data corte</small><strong><?= h(dataBr($contrato['data_corte'])) ?></strong></div>
                            <div class="fact"><small>Origem carga</small><strong><?= valorOuTraco($contrato['origem_carga_nome']) ?></strong></div>
                            <div class="fact"><small>Última carga</small><strong><?= valorOuTraco($contrato['ultima_carga_nome']) ?></strong></div>
                        </div>

                        <div class="contract-actions">
                            <a class="button secondary" href="/operacao/cliente_detalhe.php<?= h(queryString(['id' => $pessoaId, 'q' => $termoVolta, 'acao_rapida' => 'contato_efetivo', 'contrato_id' => (int) $contrato['id']])) ?>#historico">Atender este contrato</a>
                            <a class="button secondary" href="/operacao/cliente_detalhe.php<?= h(queryString(['id' => $pessoaId, 'q' => $termoVolta, 'acao_rapida' => 'agendar_retorno', 'contrato_id' => (int) $contrato['id']])) ?>#historico">Agendar retorno</a>
                            <?php if ($acordoOperacionalDisponivel && usuarioPode('acordos.criar')): ?>
                                <a class="button secondary" href="/operacao/cliente_detalhe.php<?= h(queryString(['id' => $pessoaId, 'q' => $termoVolta, 'acao_rapida' => 'criar_acordo', 'contrato_id' => (int) $contrato['id']])) ?>#historico">Criar acordo</a>
                            <?php endif; ?>
                        </div>

                        <?php $veiculos = $veiculosPorContrato[(int) $contrato['id']] ?? []; ?>
                        <?php if ($veiculos !== []): ?>
                            <?php foreach ($veiculos as $veiculo): ?>
                                <div class="vehicle">
                                    <strong>Veículo:</strong>
                                    <?= valorOuTraco($veiculo['descricao']) ?>
                                    <?= $veiculo['marca'] ? ' | Marca: ' . h($veiculo['marca']) : '' ?>
                                    <?= $veiculo['modelo'] ? ' | Modelo: ' . h($veiculo['modelo']) : '' ?>
                                    <?= $veiculo['ano_fabricacao'] ? ' | Ano fab.: ' . h($veiculo['ano_fabricacao']) : '' ?>
                                    <?= $veiculo['ano_modelo'] ? ' | Ano mod.: ' . h($veiculo['ano_modelo']) : '' ?>
                                    <?= $veiculo['categoria'] ? ' | Categoria: ' . h($veiculo['categoria']) : '' ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section id="cargas" class="card">
            <div class="section-title"><h3>Cargas/origem</h3><span class="badge"><?= count($cargasOrigem) ?></span></div>
            <?php if ($cargasOrigem === []): ?>
                <div class="empty">Nenhuma carga de origem vinculada diretamente a este cliente.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Arquivo</th>
                            <th>Status</th>
                            <th>Linhas</th>
                            <th>Válidas</th>
                            <th>Erros</th>
                            <th>Finalizada</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cargasOrigem as $carga): ?>
                            <tr>
                                <td>#<?= (int) $carga['id'] ?></td>
                                <td><?= h($carga['nome_original']) ?></td>
                                <td><?= badge((string) $carga['status']) ?></td>
                                <td><?= number_format((int) $carga['total_linhas'], 0, ',', '.') ?></td>
                                <td><?= number_format((int) $carga['linhas_validas'], 0, ',', '.') ?></td>
                                <td><?= number_format((int) $carga['linhas_com_erro'], 0, ',', '.') ?></td>
                                <td><?= h(dataHoraBr($carga['finalizado_em'] ?? $carga['criado_em'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section id="historico" class="card">
            <div class="section-title">
                <div>
                    <h3>Histórico operacional</h3>
                    <p>Registro manual inicial de tentativas, retornos e acordos operacionais com parcelas. Ainda sem login, boleto ou baixa automática.</p>
                </div>
                <?= badgeSoft($resultadoPadronizadoDisponivel ? 'tabulação padronizada' : ($atendimentoDisponivel ? 'compatibilidade legado' : 'migration pendente')) ?>
            </div>

            <?php if ($mensagemSucessoAtendimento !== null): ?>
                <div class="alert success"><?= h($mensagemSucessoAtendimento) ?></div>
            <?php endif; ?>

            <?php if ($mensagemErroAtendimento !== null): ?>
                <div class="alert danger"><?= h($mensagemErroAtendimento) ?></div>
            <?php endif; ?>

            <?php if (!$atendimentoDisponivel): ?>
                <div class="placeholder-card muted">
                    Para ativar o histórico, execute a migration <strong>009_operacao_historico_atendimentos.sql</strong> no MySQL.
                    Enquanto ela não for aplicada, a ficha continua abrindo normalmente e nenhum atendimento é gravado.
                </div>
            <?php else: ?>
                <form class="form-card" method="post" action="/operacao/cliente_detalhe.php<?= h(queryString(['id' => $pessoaId, 'q' => $termoVolta, 'retorno_id' => $retornoOrigemId > 0 ? $retornoOrigemId : null, 'campanha_item_id' => $campanhaItemId > 0 ? $campanhaItemId : null])) ?>#historico">
                    <input type="hidden" name="acao" value="registrar_atendimento">
                    <?php if ($campanhaItemAtual !== null): ?>
                        <input type="hidden" name="campanha_item_id" value="<?= (int) $campanhaItemAtual['id'] ?>">
                        <div class="alert warning retorno-origem-box">
                            <strong>Este atendimento será vinculado à campanha #<?= (int) $campanhaItemAtual['campanha_id'] ?>.</strong>
                            <span>Ao salvar, o item da fila será marcado como trabalhado.</span>
                        </div>
                    <?php endif; ?>
                    <?php if ($retornoOrigemId > 0 && $retornoStatusDisponivel): ?>
                        <input type="hidden" name="retorno_origem_id" value="<?= (int) $retornoOrigemId ?>">
                        <div class="alert warning retorno-origem-box">
                            <strong>Atendimento aberto a partir do retorno #<?= (int) $retornoOrigemId ?>.</strong>
                            <label class="check-row">
                                <input type="checkbox" name="marcar_retorno_origem" value="1" checked>
                                <span>Marcar o retorno origem como tratado ao salvar este atendimento.</span>
                            </label>
                        </div>
                    <?php elseif ($retornoOrigemId > 0): ?>
                        <div class="alert danger">Execute a migration <code>011_retorno_status_atendimentos.sql</code> para marcar o retorno origem como tratado.</div>
                    <?php endif; ?>

                    <div class="form-grid">
                        <label class="field">
                            <span>Operador</span>
                            <input type="text" name="operador_nome" maxlength="120" placeholder="Nome do operador" value="<?= h($formAtendimento['operador_nome'] ?? usuarioAtualNome() ?? '') ?>">
                        </label>

                        <label class="field">
                            <span>Canal</span>
                            <select name="canal" required>
                                <?php foreach (AtendimentoRepository::CANAIS as $valor => $label): ?>
                                    <option value="<?= h($valor) ?>" <?= (($formAtendimento['canal'] ?? 'ligacao') === $valor) ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="field">
                            <span>Resultado</span>
                            <?php if ($resultadoPadronizadoDisponivel): ?>
                                <select name="resultado_id" id="ficha-resultado-select" required>
                                    <option value="">Selecione</option>
                                    <?php foreach ($resultadosAtendimento as $resultadoOpcao): ?>
                                        <?php $pedeMotivoFicha = (int) ($resultadoOpcao['considera_contato_efetivo'] ?? 0) === 1 && (int) ($resultadoOpcao['considera_sucesso'] ?? 0) === 0 && $acordoAtivoOperacional === null; ?>
                                        <option value="<?= (int) $resultadoOpcao['id'] ?>" data-cpc="<?= $pedeMotivoFicha ? 1 : 0 ?>" data-reagenda="<?= (int) ($resultadoOpcao['gera_reagendamento'] ?? 0) === 1 ? 1 : 0 ?>" <?= ((string) ($formAtendimento['resultado_id'] ?? '') === (string) $resultadoOpcao['id']) ? 'selected' : '' ?>>
                                            <?= h((string) $resultadoOpcao['nome']) ?><?= !empty($resultadoOpcao['categoria']) ? ' / ' . h((string) $resultadoOpcao['categoria']) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <select name="resultado" required>
                                    <option value="">Selecione</option>
                                    <?php foreach (AtendimentoRepository::RESULTADOS as $valor => $label): ?>
                                        <option value="<?= h($valor) ?>" <?= (($formAtendimento['resultado'] ?? '') === $valor) ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </label>

                        <label class="field">
                            <span>Contato usado</span>
                            <select name="contato_id">
                                <option value="">Não vincular contato</option>
                                <?php foreach ($contatos as $contato): ?>
                                    <?php if (contatoNegativadoFichaOperacional($contato)) continue; ?>
                                    <option value="<?= (int) $contato['id'] ?>" <?= ((string) ($formAtendimento['contato_id'] ?? '') === (string) $contato['id']) ? 'selected' : '' ?>>
                                        <?= h(strtoupper((string) $contato['tipo']) . ' - ' . (string) $contato['valor']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="field">
                            <span>Contato manual</span>
                            <input type="text" name="contato_utilizado" maxlength="255" placeholder="Telefone/e-mail usado, se não estiver na lista" value="<?= h($formAtendimento['contato_utilizado'] ?? '') ?>">
                        </label>

                        <label class="field" id="ficha-motivo-field" hidden>
                            <span>Motivo da inadimplência</span>
                            <select name="motivo_inadimplencia_id" id="ficha-motivo-select">
                                <option value="">Selecione o motivo</option>
                                <?php foreach ($motivosInadimplencia as $motivoOpcao): ?>
                                    <option value="<?= (int) $motivoOpcao['id'] ?>" <?= ((string) ($formAtendimento['motivo_inadimplencia_id'] ?? '') === (string) $motivoOpcao['id']) ? 'selected' : '' ?>>
                                        <?= h((string) $motivoOpcao['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="muted">Houve contato com a pessoa certa (CPC) e não teve sucesso - por que o acordo não fechou?</small>
                        </label>

                        <label class="field">
                            <span>Contrato relacionado</span>
                            <select name="contrato_id">
                                <option value="">Não vincular contrato</option>
                                <?php foreach ($contratos as $contrato): ?>
                                    <option value="<?= (int) $contrato['id'] ?>" <?= ((string) ($formAtendimento['contrato_id'] ?? '') === (string) $contrato['id']) ? 'selected' : '' ?>>
                                        <?= h((string) $contrato['codigo_contrato']) ?><?= $contrato['produto'] ? ' - ' . h($contrato['produto']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="field" id="ficha-reagenda-field">
                            <span>Próximo acionamento</span>
                            <input type="datetime-local" name="proximo_acionamento" id="ficha-reagenda-input" value="<?= h($formAtendimento['proximo_acionamento'] ?? '') ?>">
                        </label>
                    </div>

                    <?php if ($acordoOperacionalDisponivel && usuarioPode('acordos.criar')): ?>
                        <div class="promise-box acordo-form-box">
                            <label class="check-row">
                                <input type="checkbox" name="registrar_acordo" value="1" <?= ((string) ($formAtendimento['registrar_acordo'] ?? '') === '1') ? 'checked' : '' ?>>
                                <span>Registrar acordo neste atendimento.</span>
                            </label>
                            <div class="form-grid">
                                <label class="field">
                                    <span>Valor total do acordo</span>
                                    <input type="text" name="acordo_valor" inputmode="decimal" maxlength="30" placeholder="Ex.: 1.200,00" value="<?= h($formAtendimento['acordo_valor'] ?? '') ?>">
                                </label>
                                <label class="field">
                                    <span>Valor de entrada</span>
                                    <input type="text" name="acordo_valor_entrada" inputmode="decimal" maxlength="30" placeholder="Opcional. Ex.: 200,00" value="<?= h($formAtendimento['acordo_valor_entrada'] ?? '') ?>">
                                </label>
                                <label class="field">
                                    <span>Vencimento da entrada</span>
                                    <input type="date" name="acordo_data_entrada" value="<?= h($formAtendimento['acordo_data_entrada'] ?? '') ?>">
                                </label>
                                <label class="field">
                                    <span>Quantidade de parcelas</span>
                                    <input type="number" name="acordo_quantidade_parcelas" min="1" max="120" step="1" value="<?= h($formAtendimento['acordo_quantidade_parcelas'] ?? '1') ?>">
                                </label>
                                <label class="field">
                                    <span>Data da primeira parcela</span>
                                    <input type="date" name="acordo_data_primeira_parcela" value="<?= h($formAtendimento['acordo_data_primeira_parcela'] ?? '') ?>">
                                </label>
                                <label class="field">
                                    <span>Periodicidade</span>
                                    <select name="acordo_periodicidade">
                                        <?php foreach (AcordoOperacionalRepository::PERIODICIDADES as $valor => $label): ?>
                                            <option value="<?= h($valor) ?>" <?= (($formAtendimento['acordo_periodicidade'] ?? 'mensal') === $valor) ? 'selected' : '' ?>><?= h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>Forma de pagamento</span>
                                    <select name="acordo_forma_pagamento">
                                        <?php foreach (AcordoOperacionalRepository::FORMAS_PAGAMENTO as $valor => $label): ?>
                                            <option value="<?= h($valor) ?>" <?= (($formAtendimento['acordo_forma_pagamento'] ?? 'nao_informado') === $valor) ? 'selected' : '' ?>><?= h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="field field-wide">
                                    <span>Observação do acordo</span>
                                    <input type="text" name="acordo_observacao" maxlength="2000" placeholder="Ex.: acordo em 1 entrada + 3 parcelas via boleto" value="<?= h($formAtendimento['acordo_observacao'] ?? '') ?>">
                                </label>
                            </div>
                            <span class="muted">Acordo operacional: gera as parcelas previstas, mas ainda não cria boleto, não dá baixa em pagamento e não envia API automaticamente.</span>
                        </div>
                    <?php else: ?>
                        <div class="placeholder-card muted">Para registrar acordo operacional com parcelas, execute as migrations <strong>014_acordos_operacionais_integracao.sql</strong> e <strong>015_acordos_operacionais_parcelas.sql</strong>.</div>
                    <?php endif; ?>

                    <label class="field field-full">
                        <span>Observação</span>
                        <textarea name="observacao" rows="4" maxlength="3000" placeholder="Ex.: não atendeu, pediu retorno amanhã, telefone inválido, deixou recado..."><?= h($formAtendimento['observacao'] ?? '') ?></textarea>
                    </label>

                    <div class="actions-row">
                        <button class="button" type="submit">Registrar atendimento</button>
                        <span class="muted">Grava histórico operacional. Se marcado, também registra acordo sem alterar saldo/contrato.</span>
                    </div>
                </form>

                <div class="subsection-title">
                    <h4>Últimos atendimentos</h4>
                    <span class="badge"><?= count($historicoAtendimentos) ?></span>
                </div>

                <?php if ($historicoAtendimentos === []): ?>
                    <div class="empty">Nenhum atendimento registrado para este cliente.</div>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($historicoAtendimentos as $item): ?>
                            <article class="timeline-item">
                                <div class="timeline-main">
                                    <div class="timeline-head">
                                        <strong><?= h(AtendimentoRepository::resultadoLabelRegistro($item)) ?></strong>
                                        <span class="muted"><?= h(dataHoraBr($item['criado_em'])) ?></span>
                                    </div>
                                    <div class="timeline-meta">
                                        <?= badge(AtendimentoRepository::canalLabel($item['canal'])) ?>
                                        <?php if ($item['operador_nome']): ?><?= badge((string) $item['operador_nome']) ?><?php endif; ?>
                                        <?php if (!empty($item['resultado_categoria'])): ?><?= badgeSoft((string) $item['resultado_categoria']) ?><?php endif; ?>
                                        <?php if ($item['codigo_contrato']): ?><?= badge('Contrato ' . (string) $item['codigo_contrato']) ?><?php endif; ?>
                                    </div>
                                    <?php if ($item['contato_utilizado'] || $item['contato_valor']): ?>
                                        <p><strong>Contato:</strong> <?= h($item['contato_utilizado'] ?: $item['contato_valor']) ?></p>
                                    <?php endif; ?>
                                    <?php if ($item['observacao']): ?>
                                        <p><?= nl2br(h($item['observacao'])) ?></p>
                                    <?php endif; ?>
                                    <?php if ($item['proximo_acionamento']): ?>
                                        <p><strong>Próximo acionamento:</strong> <?= h(dataHoraBr($item['proximo_acionamento'])) ?></p>
                                        <p>
                                            <strong>Status do retorno:</strong>
                                            <?= badge(AtendimentoRepository::retornoStatusLabel((string) ($item['retorno_status_normalizado'] ?? 'pendente'))) ?>
                                            <?php if (!empty($item['retorno_tratado_em'])): ?>
                                                <span class="muted">Atualizado em <?= h(dataHoraBr($item['retorno_tratado_em'])) ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <?php if (!empty($item['retorno_observacao'])): ?>
                                            <p><strong>Observação do retorno:</strong> <?= nl2br(h($item['retorno_observacao'])) ?></p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section id="timeline" class="card timeline-unificada-card">
            <div class="section-title">
                <div>
                    <h3>Timeline única do cliente</h3>
                    <p>Visão rápida combinando atendimentos, retornos pendentes e acordos operacionais.</p>
                </div>
                <span class="badge"><?= count($timelineCliente) ?></span>
            </div>
            <?php if ($timelineCliente === []): ?>
                <div class="empty">Nenhum evento operacional encontrado para montar a timeline.</div>
            <?php else: ?>
                <div class="timeline timeline-unificada">
                    <?php foreach ($timelineCliente as $eventoTimeline): ?>
                        <article class="timeline-item timeline-type-<?= h((string) $eventoTimeline['tipo']) ?>">
                            <div class="timeline-main">
                                <div class="timeline-head">
                                    <strong><?= h((string) $eventoTimeline['titulo']) ?></strong>
                                    <span class="muted"><?= h(dataHoraBr($eventoTimeline['data'])) ?></span>
                                </div>
                                <div class="timeline-meta">
                                    <?= badge((string) $eventoTimeline['badge']) ?>
                                    <?php if (!empty($eventoTimeline['operador'])): ?><?= badge((string) $eventoTimeline['operador']) ?><?php endif; ?>
                                    <?php if (!empty($eventoTimeline['contrato'])): ?><?= badge('Contrato ' . (string) $eventoTimeline['contrato']) ?><?php endif; ?>
                                </div>
                                <?php if (!empty($eventoTimeline['contato'])): ?><p><strong>Contato:</strong> <?= h((string) $eventoTimeline['contato']) ?></p><?php endif; ?>
                                <?php if (!empty($eventoTimeline['descricao'])): ?><p><?= nl2br(h((string) $eventoTimeline['descricao'])) ?></p><?php endif; ?>
                                <?php if (!empty($eventoTimeline['extra'])): ?><p class="muted"><?= h((string) $eventoTimeline['extra']) ?></p><?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section id="acordos" class="card">
            <div class="section-title">
                <div>
                    <h3>Acordos</h3>
                    <p>Controle dos acordos registrados pelo operador, incluindo entrada e parcelas previstas. Boleto, pagamento confirmado e integração automática com credor ficam para etapas posteriores.</p>
                </div>
                <?= $acordoOperacionalDisponivel ? badgeSoft('acordo operacional') : badgeSoft('migration pendente') ?>
            </div>

            <?php if (!$acordoOperacionalDisponivel): ?>
                <div class="placeholder-card muted">Execute as migrations <strong>014_acordos_operacionais_integracao.sql</strong> e <strong>015_acordos_operacionais_parcelas.sql</strong> para ativar acordos operacionais com parcelas.</div>
            <?php else: ?>
                <div class="metrics-grid">
                    <div class="metric">
                        <small>Acordos</small>
                        <strong><?= number_format((int) $resumoAcordosOperacionaisPessoa['total'], 0, ',', '.') ?></strong>
                    </div>
                    <div class="metric metric-warning">
                        <small>Pendentes</small>
                        <strong><?= number_format((int) $resumoAcordosOperacionaisPessoa['pendentes'], 0, ',', '.') ?></strong>
                    </div>
                    <div class="metric metric-danger">
                        <small>Vencidos</small>
                        <strong><?= number_format((int) $resumoAcordosOperacionaisPessoa['vencidos'], 0, ',', '.') ?></strong>
                    </div>
                    <div class="metric">
                        <small>Valor pendente</small>
                        <strong><?= h(dinheiro($resumoAcordosOperacionaisPessoa['valor_pendente'])) ?></strong>
                    </div>
                </div>

                <?php if ($acordosOperacionaisPessoa === []): ?>
                    <div class="empty">Nenhum acordo registrado para este cliente.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="acordos-table">
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Status</th>
                                <th>Primeiro vencimento</th>
                                <th>Valor total</th>
                                <th>Parcelamento</th>
                                <th>Contrato</th>
                                <th>Origem</th>
                                <th>Operador</th>
                                <th>Criado em</th>
                                <th>Observação</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($acordosOperacionaisPessoa as $acordo): ?>
                                <tr class="acordo-status-<?= h((string) $acordo['status_operacional']) ?>">
                                    <td>#<?= (int) $acordo['id'] ?></td>
                                    <td>
                                        <?= badge(AcordoOperacionalRepository::statusLabel((string) $acordo['status_operacional'])) ?>
                                        <?php if (in_array((string) ($acordo['status_operacional'] ?? ''), ['pendente', 'formalizado'], true)): ?><?= badgeSoft('bloqueia novo acordo') ?><?php endif; ?>
                                    </td>
                                    <td><?= h(dataBr($acordo['data_promessa_pagamento'])) ?></td>
                                    <td><strong><?= h(dinheiro($acordo['valor_acordo'])) ?></strong></td>
                                    <td>
                                        <?php $parcelasDoAcordo = $parcelasAcordosOperacionaisPessoa[(int) $acordo['id']] ?? []; ?>
                                        <strong><?= (int) ($acordo['quantidade_parcelas'] ?? 1) ?>x</strong>
                                        <?php if ((float) ($acordo['valor_entrada'] ?? 0) > 0): ?>
                                            <small>Entrada <?= h(dinheiro($acordo['valor_entrada'])) ?> em <?= h(dataBr($acordo['data_entrada'])) ?></small>
                                        <?php endif; ?>
                                        <small><?= h(AcordoOperacionalRepository::formaPagamentoLabel((string) ($acordo['forma_pagamento'] ?? 'nao_informado'))) ?> / <?= h(AcordoOperacionalRepository::periodicidadeLabel((string) ($acordo['periodicidade'] ?? 'mensal'))) ?></small>
                                        <?php if ($parcelasDoAcordo !== []): ?>
                                            <ul class="parcelas-mini-list">
                                                <?php foreach (array_slice($parcelasDoAcordo, 0, 4) as $parcela): ?>
                                                    <li><?= (int) $parcela['numero_parcela'] === 0 ? 'Entrada' : '#' . (int) $parcela['numero_parcela'] ?> - <?= h(dataBr($parcela['data_vencimento'])) ?> - <?= h(dinheiro($parcela['valor_parcela'])) ?></li>
                                                <?php endforeach; ?>
                                                <?php if (count($parcelasDoAcordo) > 4): ?><li>+ <?= count($parcelasDoAcordo) - 4 ?> parcela(s)</li><?php endif; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= !empty($acordo['codigo_contrato']) ? h((string) $acordo['codigo_contrato']) : '<span class="muted">-</span>' ?>
                                        <?php if (!empty($acordo['produto'])): ?><small><?= h((string) $acordo['produto']) ?></small><?php endif; ?>
                                    </td>
                                    <td><?= badgeSoft(AcordoOperacionalRepository::origemLabel((string) $acordo['origem'])) ?></td>
                                    <td><?= valorOuTraco($acordo['operador_nome']) ?></td>
                                    <td><?= h(dataHoraBr($acordo['criado_em'])) ?></td>
                                    <td><?= !empty($acordo['observacao']) ? nl2br(h($acordo['observacao'])) : '<span class="muted">-</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="actions-row">
                        <a class="button secondary" href="/operacao/acordos.php?q=<?= h($pessoa['documento']) ?>">Gerenciar acordos deste cliente</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<script>
(() => {
    const select = document.getElementById('ficha-resultado-select');
    const motivoField = document.getElementById('ficha-motivo-field');
    const motivoSelect = document.getElementById('ficha-motivo-select');
    const reagendaField = document.getElementById('ficha-reagenda-field');
    const reagendaInput = document.getElementById('ficha-reagenda-input');
    if (!select || !motivoField || !motivoSelect) return;

    const atualizar = () => {
        const opcao = select.options[select.selectedIndex];
        const pedeMotivo = !!opcao && opcao.dataset.cpc === '1';
        motivoField.hidden = !pedeMotivo;
        if (pedeMotivo) {
            motivoSelect.setAttribute('required', 'required');
        } else {
            motivoSelect.removeAttribute('required');
            motivoSelect.value = '';
        }

        const pedeReagenda = !!opcao && opcao.dataset.reagenda === '1';
        if (reagendaField) reagendaField.classList.toggle('is-required', pedeReagenda);
        if (reagendaInput) {
            if (pedeReagenda) {
                reagendaInput.setAttribute('required', 'required');
            } else {
                reagendaInput.removeAttribute('required');
            }
        }
    };

    select.addEventListener('change', atualizar);
    atualizar();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
