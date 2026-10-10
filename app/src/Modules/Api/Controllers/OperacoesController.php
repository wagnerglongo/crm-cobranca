<?php

declare(strict_types=1);

namespace CRM\Modules\Api\Controllers;

use CRM\Infrastructure\Http\ApiException;
use CRM\Infrastructure\Http\ApiRequest;
use CRM\Infrastructure\Http\ApiResponse;
use CRM\Modules\Api\Auth\AuthContext;
use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoResultadoRepository;
use CRM\Modules\Operacao\Repositories\AuditoriaSistemaRepository;
use CRM\Modules\Operacao\Repositories\ConsultaClienteRepository;
use CRM\Modules\Operacao\Services\AcmeApiClient;
use InvalidArgumentException;
use PDO;
use Throwable;

final class OperacoesController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AtendimentoAdaptorRepository $contratos,
        private readonly ConsultaClienteRepository $clientes,
        private readonly AtendimentoRepository $atendimentos,
        private readonly AtendimentoResultadoRepository $tabulacoes,
        private readonly AcordoOperacionalRepository $acordos,
        private readonly AuditoriaSistemaRepository $auditoria,
        private readonly AcmeApiClient $acme
    ) {
    }

    /** @param array<string,string> $params */
    public function tabulacoes(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $this->contexto($context);
        $somenteAtivas = !isset($request->query()['ativas']) || filter_var($request->query()['ativas'], FILTER_VALIDATE_BOOL);
        $rows = $this->tabulacoes->listarTodos();
        if ($somenteAtivas) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => (int) ($row['ativo'] ?? 0) === 1));
        }
        return ApiResponse::json(['data' => $rows, 'meta' => ['count' => count($rows), 'somente_ativas' => $somenteAtivas]]);
    }

    /** @param array<string,string> $params */
    public function criarAcionamentoPorContrato(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $codigo = $this->codigoContrato($params['contrato'] ?? null);
        $contrato = $this->contratoPorCodigo($codigo, $context);
        $body = $request->json();
        try {
            $atendimentoId = $this->registrarAcionamento($contrato, $body, $context);
        } catch (InvalidArgumentException $e) {
            throw new ApiException(422, 'VALIDATION_ERROR', $e->getMessage());
        }
        $this->auditoriaAcionamento($context, $atendimentoId, $body, $requestId, ['contrato' => $codigo]);
        return ApiResponse::json(['data' => ['acionamento' => $atendimentoId, 'contrato' => $codigo, 'documento' => $contrato['pessoa_documento'] ?? null, 'usuario' => $this->autor($context)]], 201);
    }

    /** @param array<string,string> $params */
    public function criarAcionamentoPorDocumento(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $documento = $this->documento($params['documento'] ?? null);
        $pessoa = $this->pessoaPorDocumento($documento, $context);
        $body = $request->json();
        try {
            $atendimentoId = $this->registrarAcionamento(['id' => null, 'pessoa_id' => $pessoa['id']], $body, $context);
        } catch (InvalidArgumentException $e) {
            throw new ApiException(422, 'VALIDATION_ERROR', $e->getMessage());
        }
        $this->auditoriaAcionamento($context, $atendimentoId, $body, $requestId, ['documento' => $documento]);
        return ApiResponse::json(['data' => ['acionamento' => $atendimentoId, 'documento' => $documento, 'usuario' => $this->autor($context)]], 201);
    }

    /** @param array<string,string> $params */
    public function simulacoesAcme(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $documento = $this->documento($params['documento'] ?? null);
        $codigo = $this->codigoContrato($params['contrato'] ?? null);
        $contrato = $this->contratoAcme($documento, $codigo, $context);
        try {
            $this->validarContratoAcmeDisponivel($contrato, $documento, $codigo, $context);
            $payloadAcme = $this->acme->gerarPropostasPortal($documento, $codigo);
            $this->contratos->atualizarContratoFinanceiroAcme((int) $contrato['id'], $payloadAcme);
            $simulacoes = $this->normalizarSimulacoesAcme($payloadAcme);
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ApiException(502, 'ACME_SIMULATION_ERROR', $e->getMessage());
        }
        $this->auditoria->registrar($context->usuarioId(), 'api.acme.simulacoes_consultadas', 'contrato', (int) $contrato['id'], 'Simulações ACME consultadas pela API.', null, ['documento' => $documento, 'contrato' => $codigo, 'api_token_id' => $context->tokenId, 'request_id' => $requestId]);
        return ApiResponse::json(['data' => array_merge(['documento' => $documento, 'tipo_documento' => strlen($documento) === 14 ? 'CNPJ' : 'CPF', 'contrato' => $codigo], $simulacoes)]);
    }

    /** @param array<string,string> $params */
    public function fecharAcordoAcme(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $documento = $this->documento($params['documento'] ?? null);
        $codigo = $this->codigoContrato($params['contrato'] ?? null);
        $contrato = $this->contratoAcme($documento, $codigo, $context);
        $body = $request->json();
        $bloqueio = $this->adquirirBloqueioAcordoAcme((int) $contrato['id']);
        try {
            $this->validarContratoAcmeDisponivel($contrato, $documento, $codigo, $context);
            $payloadPropostas = $this->acme->gerarPropostasPortal($documento, $codigo);
            $this->contratos->atualizarContratoFinanceiroAcme((int) $contrato['id'], $payloadPropostas);
            $simulacoes = $this->normalizarSimulacoesAcme($payloadPropostas);
            [$proposta, $dataAcme, $dataLocal] = $this->selecionarSimulacaoAcme($simulacoes, $body);
            $bodyLocal = array_merge($body, [
                'valor_acordo' => $proposta['valor_acordo'],
                'valor_entrada' => null,
                'data_entrada' => null,
                'quantidade_parcelas' => $proposta['quantidade_parcelas'],
                'data_primeira_parcela' => $dataLocal,
                'periodicidade' => (int) $proposta['quantidade_parcelas'] === 1 ? 'unica' : 'mensal',
            ]);
            $respostaAcme = $this->acme->salvarPropostaGerada([
                'UserID' => $context->usuarioId(),
                'DocNumber' => $documento,
                'Contract' => $codigo,
                'OwnerDescription' => $simulacoes['owner_description'],
                'NegotiationID' => $proposta['negotiation_id'],
                'PhoneID' => $body['phone_id'] ?? 0,
                'InstallmentDate' => $dataAcme,
            ]);
            $acordoId = $this->registrarAcordoLocal($contrato, $bodyLocal, $context, $requestId, [
                'credor_api' => 'ACME', 'integracao_credor_executada' => true, 'integracao_credor_resposta' => $respostaAcme,
                'negotiation_id' => $proposta['negotiation_id'], 'proposta_descricao' => $proposta['descricao'],
            ], 'enviado');
        } catch (InvalidArgumentException $e) {
            throw new ApiException(422, 'VALIDATION_ERROR', $e->getMessage());
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ApiException(502, 'ACME_AGREEMENT_ERROR', $e->getMessage());
        } finally {
            $this->liberarBloqueioAcordoAcme($bloqueio);
        }
        $this->auditoriaAcordo($context, $acordoId, $codigo, $documento, $requestId, 'api.acme.acordo_criado');
        return ApiResponse::json(['data' => ['acordo' => $acordoId, 'documento' => $documento, 'tipo_documento' => strlen($documento) === 14 ? 'CNPJ' : 'CPF', 'contrato' => $codigo, 'status_integracao' => 'enviado', 'usuario' => $this->autor($context)]], 201);
    }

    /** @param array<string,string> $params */
    public function criarAcionamentosLote(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $body = $request->json();
        $itens = $body['itens'] ?? null;
        if (!is_array($itens) || !array_is_list($itens) || $itens === [] || count($itens) > 100) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Informe itens com 1 a 100 referências por contrato ou documento.');
        }

        try {
            $this->pdo->beginTransaction();
            $criados = [];
            foreach ($itens as $index => $item) {
                if (!is_array($item)) {
                    throw new ApiException(422, 'VALIDATION_ERROR', 'Item ' . ($index + 1) . ' inválido.');
                }
                $temContrato = !empty($item['contrato']);
                $temDocumento = !empty($item['documento']);
                if ($temContrato === $temDocumento) {
                    throw new ApiException(422, 'VALIDATION_ERROR', 'Item ' . ($index + 1) . ' deve informar exatamente um campo: contrato ou documento.');
                }
                if ($temContrato) {
                    $referencia = $this->codigoContrato($item['contrato']);
                    $alvo = $this->contratoPorCodigo($referencia, $context);
                    $tipo = 'contrato';
                } else {
                    $referencia = $this->documento($item['documento']);
                    $pessoa = $this->pessoaPorDocumento($referencia, $context);
                    $alvo = ['id' => null, 'pessoa_id' => $pessoa['id']];
                    $tipo = 'documento';
                }
                $criados[] = ['acionamento' => $this->registrarAcionamento($alvo, array_merge($body, $item), $context), $tipo => $referencia];
            }
            $this->pdo->commit();
        } catch (ApiException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (InvalidArgumentException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new ApiException(422, 'VALIDATION_ERROR', $e->getMessage());
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->auditoria->registrar($context->usuarioId(), 'api.acionamento.lote_criado', 'atendimento', null, 'Acionamentos em massa criados pela API.', null, [
            'quantidade' => count($criados), 'referencias' => $itens, 'resultado_id' => $body['resultado_id'] ?? null, 'api_token_id' => $context->tokenId, 'request_id' => $requestId,
        ]);
        return ApiResponse::json(['data' => $criados, 'meta' => ['created' => count($criados), 'atomic' => true]], 201);
    }

    /** @param array<string,string> $params */
    public function listarAcordos(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $query = $request->query();
        $limite = filter_var($query['limit'] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);
        if ($limite === false) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'limit deve estar entre 1 e 200.');
        }
        $rows = $this->acordos->listar([
            'status' => $query['status'] ?? 'todos',
            'integracao' => $query['integracao'] ?? 'todos',
            'situacao' => $query['situacao'] ?? 'todos',
            'q' => $query['q'] ?? '',
            'emissao_inicio' => $query['emissao_inicio'] ?? null,
            'emissao_fim' => $query['emissao_fim'] ?? null,
        ], (int) $limite, $context->escopo);
        return ApiResponse::json(['data' => array_map(fn (array $row): array => $this->acordo($row), $rows), 'meta' => ['count' => count($rows), 'limit' => (int) $limite]]);
    }

    /** @param array<string,string> $params */
    public function acordosPorContrato(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $codigo = $this->codigoContrato($params['contrato'] ?? null);
        $rows = array_values(array_filter($this->acordos->listar(['status' => 'todos', 'q' => $codigo], 200, $context->escopo), static fn (array $row): bool => (string) ($row['codigo_contrato'] ?? '') === $codigo));
        return ApiResponse::json(['data' => array_map(fn (array $row): array => $this->acordo($row), $rows), 'meta' => ['contrato' => $codigo, 'count' => count($rows)]]);
    }

    /** @param array<string,string> $params */
    public function acordosPorDocumento(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $documento = $this->documento($params['documento'] ?? null);
        $rows = array_values(array_filter($this->acordos->listar(['status' => 'todos', 'q' => $documento], 200, $context->escopo), static fn (array $row): bool => (preg_replace('/\D+/', '', (string) ($row['pessoa_documento'] ?? '')) ?? '') === $documento));
        return ApiResponse::json(['data' => array_map(fn (array $row): array => $this->acordo($row), $rows), 'meta' => ['documento' => $documento, 'tipo_documento' => strlen($documento) === 14 ? 'CNPJ' : 'CPF', 'count' => count($rows)]]);
    }

    private function registrarAcionamento(array $contrato, array $body, AuthContext $context): int
    {
        $resultadoId = $this->id($body['resultado_id'] ?? null, 'resultado_id');
        $tabulacao = $this->tabulacoes->buscar($resultadoId);
        if ($tabulacao === null || (int) ($tabulacao['ativo'] ?? 0) !== 1) {
            throw new InvalidArgumentException('Tabulação inexistente ou inativa. Consulte GET /tabulacoes.');
        }
        return $this->atendimentos->registrar((int) $contrato['pessoa_id'], [
            'contrato_id' => !empty($contrato['id']) ? (int) $contrato['id'] : null,
            'resultado_id' => $resultadoId,
            'contato_id' => $body['contato_id'] ?? null,
            'usuario_id' => $context->usuarioId(),
            'operador_nome' => (string) ($context->usuario['nome'] ?? 'API'),
            'canal' => $body['canal'] ?? 'ligacao',
            'contato_utilizado' => $body['contato_utilizado'] ?? null,
            'observacao' => $body['observacao'] ?? null,
            'proximo_acionamento' => $body['proximo_acionamento'] ?? null,
            'motivo_inadimplencia_id' => $body['motivo_inadimplencia_id'] ?? null,
        ]);
    }

    private function contratoPorCodigo(string $codigo, AuthContext $context): array
    {
        $rows = array_values(array_filter(
            $this->contratos->buscarContratos($codigo, 'contrato', $context->escopo, 300),
            static fn (array $row): bool => in_array($codigo, array_map('strval', [$row['codigo_contrato'] ?? '', $row['conta_id'] ?? '', $row['devedor_origem_id'] ?? '']), true)
        ));
        if ($rows === []) {
            throw new ApiException(404, 'CONTRACT_NOT_FOUND', 'Contrato não encontrado.');
        }
        if (count($rows) > 1) {
            throw new ApiException(409, 'AMBIGUOUS_CONTRACT', 'O código existe em mais de um contrato no escopo. Use o endpoint específico do credor com documento e contrato.');
        }
        return $rows[0];
    }

    private function pessoaPorDocumento(string $documento, AuthContext $context): array
    {
        $rows = array_values(array_filter(
            $this->clientes->buscarPessoas($documento, 50, $context->escopo),
            static fn (array $row): bool => (preg_replace('/\D+/', '', (string) ($row['documento'] ?? '')) ?? '') === $documento
        ));
        if ($rows === []) {
            throw new ApiException(404, 'CLIENT_NOT_FOUND', 'Documento não encontrado.');
        }
        if (count($rows) > 1) {
            throw new ApiException(409, 'AMBIGUOUS_DOCUMENT', 'O documento possui mais de um cadastro no escopo e precisa ser saneado.');
        }
        return $rows[0];
    }

    private function contratoAcme(string $documento, string $codigo, AuthContext $context): array
    {
        $rows = array_values(array_filter(
            $this->contratos->buscarContratos($codigo, 'contrato', $context->escopo, 300),
            static fn (array $row): bool => (string) ($row['codigo_contrato'] ?? '') === $codigo
                && (preg_replace('/\D+/', '', (string) ($row['pessoa_documento'] ?? '')) ?? '') === $documento
                && strtoupper((string) ($row['credor_codigo'] ?? $row['credor_nome'] ?? '')) === 'ACME'
        ));
        if (count($rows) !== 1) {
            throw new ApiException(count($rows) === 0 ? 404 : 409, count($rows) === 0 ? 'ACME_CONTRACT_NOT_FOUND' : 'AMBIGUOUS_ACME_CONTRACT', 'Contrato ACME não encontrado de forma única para o documento informado.');
        }
        return $rows[0];
    }

    /** @return array<string,mixed> */
    private function normalizarSimulacoesAcme(array $payload): array
    {
        $valorDivida = isset($payload['Amount']) && is_numeric($payload['Amount']) ? (float) $payload['Amount'] : null;
        $ownerDescription = trim((string) ($payload['OwnerDescription'] ?? ''));

        $propostas = [];
        foreach ($this->listaAcme($payload['ListProposalType'] ?? []) as $item) {
            $negotiationId = (int) ($item['ProposalTypeID'] ?? 0);
            $descricao = trim((string) ($item['ProposalTypeDescription'] ?? ''));
            if ($negotiationId <= 0 || $descricao === '') {
                continue;
            }
            $quantidade = $this->parcelasDescricaoAcme($descricao);
            $valorReferencia = $this->valorDescricaoAcme($descricao);
            $valorAcordo = $valorReferencia !== null ? round($valorReferencia * $quantidade, 2) : $valorDivida;
            $propostas[] = [
                'negotiation_id' => $negotiationId,
                'descricao' => $descricao,
                'calculo' => (string) ($item['CalculationType'] ?? ''),
                'quantidade_parcelas' => $quantidade,
                'valor_referencia' => $valorReferencia,
                'valor_acordo' => $valorAcordo,
            ];
        }

        $datas = [];
        $datasPayload = is_array($payload['ListInstallmentDate'] ?? null) ? $payload['ListInstallmentDate'] : [];
        foreach ($datasPayload as $valor) {
            $valorAcme = trim((string) $valor);
            $data = $this->dataAcme($valorAcme);
            if ($valorAcme === '' || $data === null) {
                continue;
            }
            $datas[] = ['data_primeira_parcela' => $data, 'valor_acme' => $valorAcme];
        }

        $tiposPagamento = [];
        foreach ($this->listaAcme($payload['ListPaymentType'] ?? []) as $item) {
            $tag = trim((string) ($item['PaymentTypeTag'] ?? ''));
            $descricao = trim((string) ($item['PaymentTypeDescription'] ?? ''));
            if ($tag !== '' || $descricao !== '') {
                $tiposPagamento[] = ['tag' => $tag, 'descricao' => $descricao !== '' ? $descricao : $tag];
            }
        }

        return [
            'owner_description' => $ownerDescription,
            'valor_divida' => $valorDivida,
            'propostas' => $propostas,
            'datas_primeira_parcela' => $datas,
            'tipos_pagamento' => $tiposPagamento,
        ];
    }

    /** @return array{0:array<string,mixed>,1:string,2:string} */
    private function selecionarSimulacaoAcme(array $simulacoes, array $body): array
    {
        $negotiationId = $this->id($body['negotiation_id'] ?? null, 'negotiation_id');
        $proposta = null;
        foreach (($simulacoes['propostas'] ?? []) as $item) {
            if (is_array($item) && (int) ($item['negotiation_id'] ?? 0) === $negotiationId) {
                $proposta = $item;
                break;
            }
        }
        if ($proposta === null) {
            throw new ApiException(422, 'ACME_PROPOSAL_NOT_FOUND', 'A proposta informada não está disponível na simulação ACME atual. Consulte as simulações novamente.');
        }
        if (!isset($proposta['valor_acordo']) || !is_numeric($proposta['valor_acordo']) || (float) $proposta['valor_acordo'] <= 0) {
            throw new ApiException(422, 'ACME_PROPOSAL_VALUE_INVALID', 'A ACME não retornou um valor válido para a proposta selecionada.');
        }

        $dataSolicitada = trim((string) ($body['data_primeira_parcela'] ?? ''));
        $dataAcme = null;
        foreach (($simulacoes['datas_primeira_parcela'] ?? []) as $item) {
            if (is_array($item) && (string) ($item['data_primeira_parcela'] ?? '') === $dataSolicitada) {
                $dataAcme = (string) ($item['valor_acme'] ?? '');
                break;
            }
        }
        if ($dataSolicitada === '' || $dataAcme === null || $dataAcme === '') {
            throw new ApiException(422, 'ACME_INSTALLMENT_DATE_NOT_FOUND', 'A data informada não está disponível na simulação ACME atual.');
        }
        if (trim((string) ($simulacoes['owner_description'] ?? '')) === '') {
            throw new ApiException(502, 'ACME_OWNER_DESCRIPTION_MISSING', 'A ACME não retornou OwnerDescription para o contrato.');
        }

        return [$proposta, $dataAcme, $dataSolicitada];
    }

    /** @return list<array<string,mixed>> */
    private function listaAcme(mixed $valor): array
    {
        return is_array($valor)
            ? array_values(array_filter($valor, static fn (mixed $item): bool => is_array($item)))
            : [];
    }

    private function dataAcme(string $valor): ?string
    {
        try {
            return (new \DateTimeImmutable($valor))->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function parcelasDescricaoAcme(string $descricao): int
    {
        if (preg_match('/a\s*vista|à\s*vista/iu', $descricao)) {
            return 1;
        }
        if (preg_match('/\b(\d+)\s*\+\s*(\d+)\b/u', $descricao, $partes)) {
            return max(1, (int) $partes[1] + (int) $partes[2]);
        }
        if (preg_match('/\b(\d+)\s*x\b/iu', $descricao, $partes)) {
            return max(1, (int) $partes[1]);
        }
        return 1;
    }

    private function valorDescricaoAcme(string $descricao): ?float
    {
        if (!preg_match_all('/(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2}|\d+\.\d{2})/', $descricao, $valores) || empty($valores[1])) {
            return null;
        }
        $valor = (string) end($valores[1]);
        if (str_contains($valor, ',')) {
            $valor = str_replace('.', '', $valor);
            $valor = str_replace(',', '.', $valor);
        }
        $numero = filter_var($valor, FILTER_VALIDATE_FLOAT);
        return $numero === false ? null : (float) $numero;
    }

    private function validarContratoAcmeDisponivel(array $contrato, string $documento, string $codigo, AuthContext $context): void
    {
        if ((int) ($contrato['retirado_credor'] ?? 0) === 1) {
            throw new ApiException(409, 'CONTRACT_WITHDRAWN', 'Contrato retirado pelo credor; não é permitido criar acordo.');
        }

        $statusContrato = $this->textoNormalizado((string) ($contrato['status_contrato'] ?? ''));
        if ($this->textoContem($statusContrato, ['liquid', 'quitad', 'pago', 'paga'])) {
            throw new ApiException(409, 'ACME_DEBT_SETTLED', 'A dívida deste contrato já está liquidada/paga e não aceita novo acordo.');
        }

        $contratoId = (int) ($contrato['id'] ?? 0);
        // Busca DIRETO por contrato_id (nao mais "os 100 acordos mais
        // recentes da pessoa, filtrados em PHP" - ver o comentario em
        // AcordoOperacionalRepository::buscarAcordoBloqueanteContrato).
        $acordoBloqueante = $this->acordos->buscarAcordoBloqueanteContrato($contratoId, $context->escopo);
        if ($acordoBloqueante !== null) {
            $status = (string) ($acordoBloqueante['status_operacional'] ?? '');
            if (in_array($status, ['pendente', 'formalizado'], true)) {
                throw new ApiException(409, 'ACME_ACTIVE_AGREEMENT_EXISTS', 'Já existe acordo ativo para este contrato no CRM.');
            }
            if ($status === 'cumprido') {
                throw new ApiException(409, 'ACME_DEBT_SETTLED', 'Este contrato já possui acordo cumprido/quitado no CRM.');
            }
        }

        try {
            $payload = $this->acme->consultarAcordo($documento, $codigo);
            $situacao = AcordoOperacionalRepository::interpretarAcordoAcme($payload);
        } catch (Throwable $e) {
            throw new ApiException(502, 'ACME_STATUS_CHECK_ERROR', 'Não foi possível confirmar a situação atual do contrato na ACME: ' . $e->getMessage());
        }

        if ($situacao === null) {
            return;
        }
        if ((bool) ($situacao['divida_liquidada'] ?? false) || (string) ($situacao['status_operacional'] ?? '') === 'cumprido') {
            throw new ApiException(409, 'ACME_DEBT_SETTLED', 'A ACME informa que a dívida ou o acordo deste contrato já está liquidado/quitado.');
        }
        if ((bool) ($situacao['impede_nova_proposta'] ?? false)) {
            throw new ApiException(409, 'ACME_ACTIVE_AGREEMENT_EXISTS', 'A ACME informa que já existe acordo ativo para este contrato.');
        }
    }

    private function adquirirBloqueioAcordoAcme(int $contratoId): string
    {
        $chave = 'api_acme_acordo_' . substr(hash('sha256', (string) $contratoId), 0, 40);
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:chave, 10)');
        $stmt->bindValue('chave', $chave);
        $stmt->execute();
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new ApiException(409, 'ACME_AGREEMENT_IN_PROGRESS', 'Já existe um fechamento de acordo em andamento para este contrato. Tente novamente em alguns instantes.');
        }
        return $chave;
    }

    private function liberarBloqueioAcordoAcme(string $chave): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:chave)');
            $stmt->bindValue('chave', $chave);
            $stmt->execute();
        } catch (Throwable) {
            // A conexão MySQL também libera automaticamente o advisory lock ao encerrar.
        }
    }

    /** @param list<string> $termos */
    private function textoContem(string $texto, array $termos): bool
    {
        foreach ($termos as $termo) {
            if (str_contains($texto, $termo)) {
                return true;
            }
        }
        return false;
    }

    private function textoNormalizado(string $texto): string
    {
        return strtr(mb_strtolower(trim($texto)), [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function registrarAcordoLocal(array $contrato, array $body, AuthContext $context, string $requestId, array $extra = [], string $statusIntegracao = 'nao_enviado'): int
    {
        if ((int) ($contrato['retirado_credor'] ?? 0) === 1) {
            throw new ApiException(409, 'CONTRACT_WITHDRAWN', 'Contrato retirado pelo credor; não é permitido criar acordo.');
        }
        return $this->acordos->registrar((int) $contrato['pessoa_id'], [
            'contrato_id' => (int) $contrato['id'], 'valor_acordo' => $body['valor_acordo'] ?? null,
            'valor_entrada' => $body['valor_entrada'] ?? null, 'data_entrada' => $body['data_entrada'] ?? null,
            'quantidade_parcelas' => $body['quantidade_parcelas'] ?? 1, 'data_primeira_parcela' => $body['data_primeira_parcela'] ?? null,
            'periodicidade' => $body['periodicidade'] ?? 'mensal', 'forma_pagamento' => $body['forma_pagamento'] ?? 'nao_informado',
            'observacao' => $body['observacao'] ?? null, 'operador_nome' => (string) ($context->usuario['nome'] ?? 'API'),
            'criado_por_usuario_id' => $context->usuarioId(), 'api_token_id' => $context->tokenId,
            'origem' => 'manual', 'status_operacional' => 'pendente', 'status_integracao' => $statusIntegracao,
            'origem_tela' => 'api/v1', 'payload_extra_adicional' => array_merge($extra, [
                'origem_api' => true, 'request_id' => $requestId, 'usuario_id' => $context->usuarioId(), 'api_token_id' => $context->tokenId,
            ]),
        ]);
    }

    /** @param array<string,mixed> $body @param array<string,mixed> $referencia */
    private function auditoriaAcionamento(AuthContext $context, int $id, array $body, string $requestId, array $referencia): void
    {
        $this->auditoria->registrar($context->usuarioId(), 'api.acionamento.criado', 'atendimento', $id, 'Acionamento criado pela API.', null, array_merge($referencia, [
            'resultado_id' => $body['resultado_id'] ?? null, 'api_token_id' => $context->tokenId, 'request_id' => $requestId,
        ]));
    }

    private function auditoriaAcordo(AuthContext $context, int $id, string $contrato, string $documento, string $requestId, string $evento): void
    {
        $this->auditoria->registrar($context->usuarioId(), $evento, 'acordo_operacional', $id, 'Acordo criado por usuário autenticado via API.', null, [
            'documento' => $documento, 'contrato' => $contrato, 'api_token_id' => $context->tokenId, 'request_id' => $requestId,
        ]);
    }

    /** @return array{id:int,nome:string,login:string,token_id:int} */
    private function autor(AuthContext $context): array
    {
        return ['id' => $context->usuarioId(), 'nome' => (string) ($context->usuario['nome'] ?? ''), 'login' => (string) ($context->usuario['login'] ?? ''), 'token_id' => $context->tokenId];
    }

    private function documento(mixed $value): string
    {
        $documento = preg_replace('/\D+/', '', (string) $value) ?? '';
        if (!in_array(strlen($documento), [11, 14], true)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Documento inválido. Informe CPF com 11 dígitos ou CNPJ com 14 dígitos.');
        }
        return $documento;
    }

    private function codigoContrato(mixed $value): string
    {
        $codigo = trim((string) $value);
        if ($codigo === '' || mb_strlen($codigo) > 180) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Contrato inválido.');
        }
        return $codigo;
    }

    private function acordo(array $row): array
    {
        $campos = ['codigo_acordo_credor','protocolo_credor','valor_acordo','valor_entrada','data_entrada','quantidade_parcelas','valor_parcela','data_primeira_parcela','data_promessa_pagamento','periodicidade','forma_pagamento','status_operacional','status_integracao','origem','operador_nome','observacao','pessoa_nome','pessoa_documento','codigo_contrato','produto','saldo_atual','dias_atraso','credor_nome','carteira_nome','criado_em','atualizado_em'];
        return array_intersect_key($row, array_flip($campos));
    }

    private function contexto(?AuthContext $context): AuthContext
    {
        if ($context === null) {
            throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'Autenticação Bearer obrigatória.');
        }
        return $context;
    }

    private function id(mixed $value, string $campo): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Identificador inválido.', [$campo => ['Informe um inteiro positivo.']]);
        }
        return (int) $id;
    }
}
