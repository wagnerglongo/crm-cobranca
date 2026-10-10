<?php

declare(strict_types=1);

namespace CRM\Modules\Api\Controllers;

use CRM\Infrastructure\Http\ApiException;
use CRM\Infrastructure\Http\ApiRequest;
use CRM\Infrastructure\Http\ApiResponse;
use CRM\Modules\Api\Auth\AuthContext;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;

final class ContratosController
{
    public function __construct(private readonly AtendimentoAdaptorRepository $contratos)
    {
    }

    /** @param array<string,string> $params */
    public function pesquisar(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $query = $request->query();
        $termo = trim((string) ($query['q'] ?? ''));
        if (mb_strlen($termo) < 2 || mb_strlen($termo) > 180) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Informe q com 2 a 180 caracteres.', ['q' => ['Campo obrigatório.']]);
        }
        $tipo = trim((string) ($query['tipo'] ?? 'auto'));
        if (!in_array($tipo, ['auto', 'documento', 'contrato', 'telefone', 'nome'], true)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Tipo de busca inválido.', ['tipo' => ['Use auto, documento, contrato, telefone ou nome.']]);
        }
        $limite = $this->limite($query['limit'] ?? 50, 300);
        $rows = $this->contratos->buscarContratos($termo, $tipo, $context->escopo, $limite);
        return ApiResponse::json(['data' => array_map(fn (array $row): array => $this->contrato($row), $rows), 'meta' => ['count' => count($rows), 'limit' => $limite, 'query' => $termo, 'tipo' => $tipo]]);
    }

    /** @param array<string,string> $params */
    public function porCodigo(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $codigo = trim((string) ($params['contrato'] ?? ''));
        if ($codigo === '' || mb_strlen($codigo) > 180) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Código do contrato inválido.');
        }
        $rows = array_values(array_filter(
            $this->contratos->buscarContratos($codigo, 'contrato', $context->escopo, 300),
            static fn (array $row): bool => in_array($codigo, array_map('strval', [
                $row['codigo_contrato'] ?? '', $row['conta_id'] ?? '', $row['devedor_origem_id'] ?? '',
            ]), true)
        ));
        if ($rows === []) {
            throw new ApiException(404, 'CONTRACT_NOT_FOUND', 'Contrato não encontrado.');
        }
        return ApiResponse::json(['data' => array_map(fn (array $row): array => $this->contratoExterno($row), $rows), 'meta' => ['count' => count($rows), 'contrato' => $codigo]]);
    }

    /** @param array<string,string> $params */
    public function detalhe(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $id = $this->id($params['id'] ?? null);
        $row = $this->contratos->buscarContrato($id, $context->escopo);
        if ($row === null) {
            throw new ApiException(404, 'CONTRACT_NOT_FOUND', 'Contrato não encontrado.');
        }
        return ApiResponse::json(['data' => $this->contrato($row)]);
    }

    /** @param array<string,string> $params */
    public function parcelas(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $id = $this->id($params['id'] ?? null);
        $this->garantirContrato($id, $context);
        $limite = $this->limite($request->query()['limit'] ?? 300, 1000);
        $rows = $this->contratos->listarParcelasContrato($id, $limite);
        return ApiResponse::json([
            'data' => array_map(fn (array $row): array => $this->somente($row, [
                'id', 'contrato_id', 'numero_parcela', 'data_vencimento', 'valor_original', 'saldo_parcela',
                'status', 'data_pagamento', 'valor_pago', 'criado_em', 'atualizado_em',
            ]), $rows),
            'resumo' => $this->contratos->resumoParcelasContrato($id),
            'meta' => ['count' => count($rows), 'limit' => $limite],
        ]);
    }

    /** @param array<string,string> $params */
    public function historico(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $id = $this->id($params['id'] ?? null);
        $contrato = $this->garantirContrato($id, $context);
        $limite = $this->limite($request->query()['limit'] ?? 40, 100);
        $rows = $this->contratos->listarHistorico((int) ($contrato['pessoa_id'] ?? 0), $id, $limite);
        return ApiResponse::json(['data' => array_map(fn (array $row): array => $this->somente($row, [
            'id', 'pessoa_id', 'contrato_id', 'usuario_id', 'usuario_nome', 'canal', 'tipo', 'resultado_id',
            'resultado_nome', 'resultado_categoria', 'contato_id', 'contato_valor', 'contato_tipo', 'observacao',
            'proximo_acionamento_em', 'criado_em',
        ]), $rows), 'meta' => ['count' => count($rows), 'limit' => $limite]]);
    }

    private function garantirContrato(int $id, AuthContext $context): array
    {
        $row = $this->contratos->buscarContrato($id, $context->escopo);
        if ($row === null) {
            throw new ApiException(404, 'CONTRACT_NOT_FOUND', 'Contrato não encontrado.');
        }
        return $row;
    }

    private function contrato(array $row): array
    {
        return $this->somente($row, [
            'id', 'pessoa_id', 'pessoa_nome', 'pessoa_documento', 'pessoa_tipo_documento', 'pessoa_data_nascimento',
            'credor_id', 'carteira_id', 'codigo_contrato', 'conta_id', 'devedor_origem_id', 'produto', 'subproduto',
            'status', 'fase', 'classificacao', 'data_contratacao', 'data_vencimento', 'dias_atraso', 'saldo_atual',
            'saldo_contabil', 'valor_original', 'credor_codigo', 'credor_nome', 'carteira_codigo', 'carteira_nome',
            'origem_carga_id', 'ultima_carga_id', 'origem_carga_nome', 'ultima_carga_nome', 'total_contatos',
            'total_enderecos', 'total_parcelas', 'acordos_ativos', 'ultimo_atendimento_em', 'retirado_credor',
            'criado_em', 'atualizado_em',
        ]);
    }

    private function contratoExterno(array $row): array
    {
        $ret = $this->contrato($row);
        unset($ret['id'], $ret['pessoa_id'], $ret['credor_id'], $ret['carteira_id'], $ret['origem_carga_id'], $ret['ultima_carga_id']);
        return $ret;
    }

    /** @param list<string> $campos */
    private function somente(array $row, array $campos): array
    {
        $ret = [];
        foreach ($campos as $campo) {
            if (array_key_exists($campo, $row)) {
                $ret[$campo] = $row[$campo];
            }
        }
        return $ret;
    }

    private function contexto(?AuthContext $context): AuthContext
    {
        if ($context === null) {
            throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'Autenticação Bearer obrigatória.');
        }
        return $context;
    }

    private function id(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Identificador do contrato inválido.');
        }
        return (int) $id;
    }

    private function limite(mixed $value, int $maximo): int
    {
        $limite = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximo]]);
        if ($limite === false) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Limite inválido.', ['limit' => ['Use um inteiro entre 1 e ' . $maximo . '.']]);
        }
        return (int) $limite;
    }
}
