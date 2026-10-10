<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoRepository;
use CRM\Modules\Operacao\Repositories\CredorIntegracaoRepository;
use CRM\Modules\Operacao\Repositories\IntegracaoEventoSaidaRepository;
use CRM\Modules\Operacao\Services\AcmeAcionamentoSyncService;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('clientes.consultar');

header('Content-Type: application/json; charset=utf-8');

/** @param array<string,mixed> $payload */
function worksAcionamentosAcmeJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @param list<array<string,mixed>> $itens @return list<array<string,mixed>> */
function worksAcionamentosAcmeItens(array $itens, int $contratoAtualId, string $contratoAtual): array
{
    $saida = [];
    $visibilidadeContratos = [];
    foreach ($itens as $item) {
        $contratoItemId = (int) ($item['contrato_id'] ?? 0);
        $codigoContrato = trim((string) ($item['codigo_contrato'] ?? $item['contrato_crm_codigo'] ?? ''));

        // A ACME pode devolver todos os contratos do documento. Itens de outros
        // contratos so podem ser expostos quando o usuario tambem possui escopo.
        if ($contratoItemId > 0 && $contratoItemId !== $contratoAtualId) {
            if (!array_key_exists($contratoItemId, $visibilidadeContratos)) {
                $visibilidadeContratos[$contratoItemId] = usuarioPodeContratoId($contratoItemId, 'consultar');
            }
            if (!$visibilidadeContratos[$contratoItemId]) {
                continue;
            }
        } elseif ($contratoItemId <= 0 && $codigoContrato !== $contratoAtual) {
            continue;
        }

        $tracking = (string) ($item['identificador_externo'] ?? '');
        if (str_starts_with($tracking, 'tracking:')) {
            $tracking = substr($tracking, 9);
        }
        $saida[] = [
            'id' => (int) ($item['id'] ?? 0),
            'tracking_id' => $tracking,
            'record_id' => (string) ($item['registro_externo'] ?? ''),
            'documento' => (string) ($item['documento'] ?? ''),
            'contrato' => $codigoContrato,
            'contrato_atual' => $contratoItemId === $contratoAtualId || ($codigoContrato !== '' && $codigoContrato === $contratoAtual),
            'observacao' => (string) ($item['observacao'] ?? ''),
            'action_id' => (string) ($item['codigo_acao_externa'] ?? ''),
            'action_description' => (string) ($item['descricao_acao_externa'] ?? ''),
            'resultado_local' => (string) ($item['resultado_local_nome'] ?? ''),
            'agendado_em' => $item['agendado_em'] ?? null,
            'criado_em' => $item['ocorrido_em'] ?? null,
            'sincronizado_em' => $item['sincronizado_em'] ?? null,
        ];
    }
    return $saida;
}

/** @param list<array<string,mixed>> $itens @return list<array<string,mixed>> */
function worksAcionamentosAcmeEnvios(array $itens): array
{
    $labels = [
        'pendente' => 'Pendente',
        'processando' => 'Enviando',
        'enviado' => 'Enviado',
        'confirmado' => 'Confirmado',
        'erro_retry' => 'Aguardando nova tentativa',
        'erro_definitivo' => 'Erro definitivo',
        'cancelado' => 'Cancelado',
    ];
    $saida = [];
    foreach ($itens as $item) {
        $status = (string) ($item['status'] ?? '');
        $saida[] = [
            'id' => (int) ($item['id'] ?? 0),
            'atendimento_id' => (int) ($item['atendimento_id'] ?? 0),
            'event_uuid' => (string) ($item['evento_uuid'] ?? ''),
            'status' => $status,
            'status_label' => $labels[$status] ?? ucfirst(str_replace('_', ' ', $status)),
            'tentativas' => (int) ($item['tentativas'] ?? 0),
            'max_tentativas' => (int) ($item['max_tentativas'] ?? 0),
            'http_status' => isset($item['ultimo_http_status']) ? (int) $item['ultimo_http_status'] : null,
            'protocolo' => (string) ($item['protocolo_externo'] ?? ''),
            'erro' => (string) ($item['ultimo_erro'] ?? ''),
            'resultado' => (string) ($item['resultado_nome'] ?? ''),
            'action_id' => (string) ($item['codigo_externo'] ?? ''),
            'action_description' => (string) ($item['descricao_externa'] ?? ''),
            'integracao' => (string) ($item['integracao_nome'] ?? ''),
            'adapter' => (string) ($item['adapter'] ?? ''),
            'criado_em' => $item['criado_em'] ?? null,
            'enviado_em' => $item['enviado_em'] ?? null,
        ];
    }
    return $saida;
}

function worksAcionamentosAcmeTextoChave(mixed $valor): string
{
    $texto = trim((string) ($valor ?? ''));
    if ($texto === '') {
        return '';
    }
    $texto = mb_strtolower($texto, 'UTF-8');
    return preg_replace('/\s+/u', ' ', $texto) ?? $texto;
}

function worksAcionamentosAcmeTs(mixed $valor): ?int
{
    $raw = trim((string) ($valor ?? ''));
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    return $ts === false ? null : $ts;
}

/**
 * Une o historico local com o retorno ACME sem duplicar o acionamento que nasceu
 * no Pactua. Como a ACME nao devolve o event_uuid, a correlacao usa ActionID,
 * contrato, observacao e proximidade temporal. O registro local continua sendo
 * a fonte principal; o retorno ACME apenas confirma/enriquece esse item.
 *
 * @param list<array<string,mixed>> $historicoLocal
 * @param list<array<string,mixed>> $envios
 * @param list<array<string,mixed>> $externos
 * @return array{timeline:list<array<string,mixed>>,vinculados:int,externos_nao_vinculados:int}
 */
function worksAcionamentosAcmeTimeline(array $historicoLocal, array $envios, array $externos): array
{
    $localPorId = [];
    foreach ($historicoLocal as $item) {
        $id = (int) ($item['id'] ?? 0);
        if ($id > 0) {
            $localPorId[$id] = $item;
        }
    }

    $envioPorAtendimento = [];
    $enviosElegiveis = [];
    foreach ($envios as $indice => $envio) {
        if (($envio['adapter'] ?? '') !== '' && (string) $envio['adapter'] !== 'ACME_ACIONAMENTOS') {
            continue;
        }
        $atendimentoId = (int) ($envio['atendimento_id'] ?? 0);
        if ($atendimentoId <= 0 || !isset($localPorId[$atendimentoId])) {
            continue;
        }
        if (!isset($envioPorAtendimento[$atendimentoId])) {
            $envioPorAtendimento[$atendimentoId] = $envio;
        }
        $enviosElegiveis[$indice] = $envio;
    }

    $externoPorAtendimento = [];
    $externosConsumidos = [];
    $vinculados = 0;

    foreach ($externos as $indiceExterno => $externo) {
        if (!(bool) ($externo['contrato_atual'] ?? false)) {
            continue;
        }
        $actionId = trim((string) ($externo['action_id'] ?? ''));
        if ($actionId === '') {
            continue;
        }
        $externoTs = worksAcionamentosAcmeTs($externo['criado_em'] ?? null);
        $externoObs = worksAcionamentosAcmeTextoChave($externo['observacao'] ?? '');
        $melhorIndice = null;
        $melhorAtendimentoId = 0;
        $melhorScore = PHP_INT_MAX;

        foreach ($enviosElegiveis as $indiceEnvio => $envio) {
            if (isset($externosConsumidos['envio:' . $indiceEnvio])) {
                continue;
            }
            if (trim((string) ($envio['action_id'] ?? '')) !== $actionId) {
                continue;
            }

            $atendimentoId = (int) ($envio['atendimento_id'] ?? 0);
            $local = $localPorId[$atendimentoId] ?? null;
            if (!is_array($local)) {
                continue;
            }

            $localObs = worksAcionamentosAcmeTextoChave($local['observacao'] ?? '');
            $obsIgual = $externoObs !== '' && $localObs !== '' && hash_equals($externoObs, $localObs);
            $tempos = array_values(array_filter([
                worksAcionamentosAcmeTs($local['criado_em'] ?? null),
                worksAcionamentosAcmeTs($envio['criado_em'] ?? null),
                worksAcionamentosAcmeTs($envio['enviado_em'] ?? null),
            ], static fn (?int $v): bool => $v !== null));

            $delta = null;
            if ($externoTs !== null && $tempos !== []) {
                $delta = min(array_map(static fn (int $ts): int => abs($externoTs - $ts), $tempos));
            }

            // Observacao identica tolera diferenca maior de relogio. Sem texto
            // identico, exija proximidade forte para evitar unir duas tentativas
            // consecutivas com o mesmo ActionID.
            if ($obsIgual) {
                if ($delta !== null && $delta > 900) {
                    continue;
                }
                $score = $delta ?? 0;
            } else {
                if ($delta === null || $delta > 120) {
                    continue;
                }
                $score = 10000 + $delta;
            }

            if ($score < $melhorScore) {
                $melhorScore = $score;
                $melhorIndice = $indiceEnvio;
                $melhorAtendimentoId = $atendimentoId;
            }
        }

        if ($melhorIndice !== null && $melhorAtendimentoId > 0) {
            $externoPorAtendimento[$melhorAtendimentoId] = $externo;
            $externosConsumidos[$indiceExterno] = true;
            $externosConsumidos['envio:' . $melhorIndice] = true;
            $vinculados++;
        }
    }

    $timeline = [];
    foreach ($historicoLocal as $local) {
        $atendimentoId = (int) ($local['id'] ?? 0);
        $envio = $envioPorAtendimento[$atendimentoId] ?? null;
        $externo = $externoPorAtendimento[$atendimentoId] ?? null;
        $resultadoNome = trim((string) ($local['resultado_nome'] ?? ''));
        if ($resultadoNome === '') {
            $resultadoNome = AtendimentoRepository::resultadoLabel($local['resultado'] ?? null);
        }

        $timeline[] = [
            'tipo' => 'works',
            'fonte' => 'works',
            'fonte_label' => 'Pactua',
            'id' => $atendimentoId,
            'criado_em' => $local['criado_em'] ?? null,
            'resultado' => $resultadoNome,
            'canal' => (string) ($local['canal'] ?? ''),
            'canal_label' => AtendimentoRepository::canalLabel($local['canal'] ?? null),
            'contato_utilizado' => (string) ($local['contato_utilizado'] ?? ''),
            'observacao' => (string) ($local['observacao'] ?? ''),
            'proximo_acionamento' => $local['proximo_acionamento'] ?? null,
            'acme' => $envio === null ? null : [
                'status' => (string) ($envio['status'] ?? ''),
                'status_label' => (string) ($envio['status_label'] ?? ''),
                'action_id' => (string) ($envio['action_id'] ?? ''),
                'action_description' => (string) ($envio['action_description'] ?? ''),
                'erro' => (string) ($envio['erro'] ?? ''),
                'confirmado_no_historico' => $externo !== null,
                'tracking_id' => (string) ($externo['tracking_id'] ?? ''),
                'record_id' => (string) ($externo['record_id'] ?? ''),
            ],
        ];
    }

    $externosNaoVinculados = 0;
    foreach ($externos as $indice => $externo) {
        if (isset($externosConsumidos[$indice]) || !(bool) ($externo['contrato_atual'] ?? false)) {
            continue;
        }
        $externosNaoVinculados++;
        $timeline[] = [
            'tipo' => 'acme',
            'fonte' => 'acme',
            'fonte_label' => 'ACME',
            'id' => (int) ($externo['id'] ?? 0),
            'criado_em' => $externo['criado_em'] ?? null,
            'resultado' => trim((string) ($externo['action_description'] ?? '')) ?: ('Ação ACME ' . (string) ($externo['action_id'] ?? '')),
            'canal' => 'acme',
            'canal_label' => 'ACME',
            'contato_utilizado' => '',
            'observacao' => (string) ($externo['observacao'] ?? ''),
            'proximo_acionamento' => $externo['agendado_em'] ?? null,
            'acme' => [
                'status' => 'externo',
                'status_label' => 'Origem ACME',
                'action_id' => (string) ($externo['action_id'] ?? ''),
                'action_description' => (string) ($externo['action_description'] ?? ''),
                'erro' => '',
                'confirmado_no_historico' => true,
                'tracking_id' => (string) ($externo['tracking_id'] ?? ''),
                'record_id' => (string) ($externo['record_id'] ?? ''),
            ],
        ];
    }

    usort($timeline, static function (array $a, array $b): int {
        $aTs = worksAcionamentosAcmeTs($a['criado_em'] ?? null) ?? 0;
        $bTs = worksAcionamentosAcmeTs($b['criado_em'] ?? null) ?? 0;
        if ($aTs !== $bTs) {
            return $bTs <=> $aTs;
        }
        if (($a['fonte'] ?? '') !== ($b['fonte'] ?? '')) {
            return ($a['fonte'] ?? '') === 'works' ? -1 : 1;
        }
        return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
    });

    return [
        'timeline' => array_slice($timeline, 0, 150),
        'vinculados' => $vinculados,
        'externos_nao_vinculados' => $externosNaoVinculados,
    ];
}

try {
    $contratoId = filter_var($_GET['contrato_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    if ($contratoId <= 0) {
        worksAcionamentosAcmeJson(['ok' => false, 'erro' => 'Contrato não informado.'], 422);
    }

    $pdo = operacaoPdo();
    $repository = new AtendimentoAdaptorRepository($pdo);
    $contrato = $repository->buscarContrato($contratoId, usuarioEscopoCredoresAcao('consultar'));
    if ($contrato === null) {
        worksAcionamentosAcmeJson(['ok' => false, 'erro' => 'Contrato não encontrado ou fora do seu escopo.'], 404);
    }
    requireContratoNoEscopo($contratoId, 'consultar');

    $credorId = (int) ($contrato['credor_id'] ?? 0);
    $pessoaId = (int) ($contrato['pessoa_id'] ?? 0);
    $documento = preg_replace('/\D+/', '', (string) ($contrato['pessoa_documento'] ?? $contrato['documento'] ?? '')) ?? '';
    $codigoContrato = trim((string) ($contrato['codigo_contrato'] ?? ''));
    $atualizar = (string) ($_GET['refresh'] ?? '1') === '1';
    $forcarAtualizacao = (string) ($_GET['force'] ?? '0') === '1';

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $integracoes = new CredorIntegracaoRepository($pdo);
    $integracao = $integracoes->integracaoAtivaPorAdapter($credorId, 'ACME_ACIONAMENTOS');
    $syncService = new AcmeAcionamentoSyncService($pdo);
    $resultado = $syncService->cache($credorId, $documento);
    $apiErro = null;
    $sincronizados = 0;
    $cacheReutilizado = false;
    $cacheSeconds = max(600, min(3600, (int) Database::env('ACME_API_ACTION_HISTORY_CACHE_SECONDS', '600')));
    $ultimaSincronizacao = trim((string) ($resultado['ultima_sincronizacao'] ?? ''));
    $ultimaSincronizacaoTs = $ultimaSincronizacao !== '' ? strtotime($ultimaSincronizacao) : false;
    $idadeCache = $ultimaSincronizacaoTs !== false ? max(0, time() - $ultimaSincronizacaoTs) : null;
    $cacheRecente = !$forcarAtualizacao
        && $cacheSeconds > 0
        && $idadeCache !== null
        && $idadeCache < $cacheSeconds;

    if ($atualizar && $integracao !== null && !$cacheRecente) {
        try {
            $resultado = $syncService->sincronizar($credorId, $pessoaId, $contratoId, $documento);
            $sincronizados = (int) ($resultado['sincronizados'] ?? 0);
        } catch (Throwable $e) {
            $apiErro = $e->getMessage();
            $resultado = $syncService->cache($credorId, $documento);
        }
    } elseif ($atualizar && $integracao !== null && $cacheRecente) {
        $cacheReutilizado = true;
    }

    $eventosRepository = new IntegracaoEventoSaidaRepository($pdo);
    $envios = worksAcionamentosAcmeEnvios($eventosRepository->listarPorContrato($contratoId));
    $itens = worksAcionamentosAcmeItens(
        is_array($resultado['itens'] ?? null) ? $resultado['itens'] : [],
        $contratoId,
        $codigoContrato
    );
    $historicoLocal = $repository->listarHistorico($pessoaId, $contratoId, 100);
    $unificado = worksAcionamentosAcmeTimeline($historicoLocal, $envios, $itens);

    worksAcionamentosAcmeJson([
        'ok' => true,
        'disponivel' => $integracao !== null,
        'fonte' => $atualizar && !$cacheReutilizado && $apiErro === null && $integracao !== null ? 'api' : 'crm',
        'api_erro' => $apiErro,
        'cache_reutilizado' => $cacheReutilizado,
        'cache_segundos' => $cacheSeconds,
        'ultima_sincronizacao' => $resultado['ultima_sincronizacao'] ?? null,
        'sincronizados' => $sincronizados,
        'total_externo' => count($itens),
        'vinculados_works' => (int) ($unificado['vinculados'] ?? 0),
        'externos_nao_vinculados' => (int) ($unificado['externos_nao_vinculados'] ?? 0),
        'timeline' => $unificado['timeline'] ?? [],
        // Mantidos por compatibilidade/diagnostico; a interface nao os renderiza separadamente.
        'itens' => $itens,
        'envios' => $envios,
    ]);
} catch (Throwable $e) {
    worksAcionamentosAcmeJson(['ok' => false, 'erro' => $e->getMessage()], 500);
}
