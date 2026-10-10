<?php

declare(strict_types=1);

namespace CRM\Modules\Api\Controllers;

use CRM\Infrastructure\Http\ApiException;
use CRM\Infrastructure\Http\ApiRequest;
use CRM\Infrastructure\Http\ApiResponse;
use CRM\Modules\Api\Auth\AuthContext;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Repositories\ConsultaClienteRepository;

final class ClientesController
{
    public function __construct(
        private readonly ConsultaClienteRepository $clientes,
        private readonly AtendimentoAdaptorRepository $contratos
    ) {
    }

    /** @param array<string,string> $params */
    public function pesquisar(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $query = $request->query();
        $termo = trim((string) ($query['q'] ?? ''));
        if (mb_strlen($termo) < 2 || mb_strlen($termo) > 180) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Informe q com 2 a 180 caracteres.', [
                'q' => ['Informe nome, documento, telefone ou contrato com pelo menos 2 caracteres.'],
            ]);
        }
        $limite = $this->limite($query['limit'] ?? 20, 50);
        $rows = $this->clientes->buscarPessoas($termo, $limite, $context->escopo);

        return ApiResponse::json([
            'data' => array_map(fn (array $row): array => $this->pessoaResumo($row), $rows),
            'meta' => ['count' => count($rows), 'limit' => $limite, 'query' => $termo],
        ]);
    }

    /** @param array<string,string> $params */
    public function porDocumento(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $documento = preg_replace('/\D+/', '', (string) ($params['documento'] ?? '')) ?? '';
        if (!in_array(strlen($documento), [11, 14], true)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Documento inválido.', [
                'documento' => ['Informe CPF com 11 dígitos ou CNPJ com 14 dígitos.'],
            ]);
        }
        $rows = array_values(array_filter(
            $this->clientes->buscarPessoas($documento, 50, $context->escopo),
            static fn (array $row): bool => (preg_replace('/\D+/', '', (string) ($row['documento'] ?? '')) ?? '') === $documento
        ));
        if ($rows === []) {
            throw new ApiException(404, 'CLIENT_NOT_FOUND', 'Cliente não encontrado.');
        }
        if (count($rows) > 1) {
            throw new ApiException(409, 'AMBIGUOUS_DOCUMENT', 'O documento possui mais de um cadastro no escopo e precisa ser saneado.');
        }
        $pessoaId = (int) ($rows[0]['id'] ?? 0);
        $pessoa = $this->clientes->buscarPessoaPorId($pessoaId);
        $cliente = $pessoa !== null ? $this->pessoaDetalhe($pessoa) : $this->pessoaResumo($rows[0]);
        unset($cliente['id'], $cliente['origem_carga_id'], $cliente['ultima_carga_id']);
        return ApiResponse::json(['data' => [
            'cliente' => $cliente,
            'resumo' => $this->clientes->buscarResumoPessoa($pessoaId, $context->escopo),
            'contratos' => array_map(fn (array $row): array => $this->contratoExterno($row), $this->contratos->listarContratosPessoa($pessoaId, $context->escopo, 300)),
        ]]);
    }

    /** @param array<string,string> $params */
    public function detalhe(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $pessoaId = $this->id($params['id'] ?? null, 'cliente');
        $this->garantirPessoaNoEscopo($pessoaId, $context);
        $pessoa = $this->clientes->buscarPessoaPorId($pessoaId);
        if ($pessoa === null) {
            throw new ApiException(404, 'CLIENT_NOT_FOUND', 'Cliente não encontrado.');
        }

        return ApiResponse::json([
            'data' => [
                'cliente' => $this->pessoaDetalhe($pessoa),
                'resumo' => $this->clientes->buscarResumoPessoa($pessoaId, $context->escopo),
            ],
        ]);
    }

    /** @param array<string,string> $params */
    public function contratos(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $pessoaId = $this->id($params['id'] ?? null, 'cliente');
        $this->garantirPessoaNoEscopo($pessoaId, $context);
        $limite = $this->limite($request->query()['limit'] ?? 100, 300);
        $rows = $this->contratos->listarContratosPessoa($pessoaId, $context->escopo, $limite);
        return ApiResponse::json(['data' => array_map(fn (array $row): array => $this->contrato($row), $rows), 'meta' => ['count' => count($rows), 'limit' => $limite]]);
    }

    /** @param array<string,string> $params */
    public function contatos(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $pessoaId = $this->id($params['id'] ?? null, 'cliente');
        $this->garantirPessoaNoEscopo($pessoaId, $context);
        $rows = $this->clientes->listarContatos($pessoaId);
        return ApiResponse::json(['data' => array_map(fn (array $row): array => $this->somente($row, [
            'id', 'pessoa_id', 'tipo', 'subtipo', 'valor', 'valor_normalizado', 'ddd', 'telefone', 'email',
            'status', 'principal', 'origem', 'ultima_carga_nome', 'criado_em', 'atualizado_em',
        ]), $rows), 'meta' => ['count' => count($rows)]]);
    }

    /** @param array<string,string> $params */
    public function enderecos(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $pessoaId = $this->id($params['id'] ?? null, 'cliente');
        $this->garantirPessoaNoEscopo($pessoaId, $context);
        $rows = $this->clientes->listarEnderecos($pessoaId);
        return ApiResponse::json(['data' => array_map(fn (array $row): array => $this->somente($row, [
            'id', 'pessoa_id', 'tipo', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'cep',
            'principal', 'origem', 'ultima_carga_nome', 'criado_em', 'atualizado_em',
        ]), $rows), 'meta' => ['count' => count($rows)]]);
    }

    /** @param array<string,string> $params */
    public function veiculos(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $pessoaId = $this->id($params['id'] ?? null, 'cliente');
        $this->garantirPessoaNoEscopo($pessoaId, $context);
        $contratos = $this->contratos->listarContratosPessoa($pessoaId, $context->escopo, 300);
        $ids = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $contratos);
        $agrupados = $this->clientes->listarVeiculosPorContratos($ids);
        $data = [];
        foreach ($agrupados as $contratoId => $veiculos) {
            foreach ($veiculos as $row) {
                $data[] = $this->somente($row, [
                    'id', 'contrato_id', 'placa', 'chassi', 'renavam', 'marca', 'modelo', 'ano_fabricacao',
                    'ano_modelo', 'cor', 'situacao', 'criado_em', 'atualizado_em',
                ]);
            }
        }
        return ApiResponse::json(['data' => $data, 'meta' => ['count' => count($data)]]);
    }

    /** @param array<string,string> $params */
    public function cargasOrigem(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $context = $this->contexto($context);
        $pessoaId = $this->id($params['id'] ?? null, 'cliente');
        $this->garantirPessoaNoEscopo($pessoaId, $context);
        $limite = $this->limite($request->query()['limit'] ?? 20, 50);
        $rows = $this->clientes->listarCargasOrigemPessoa($pessoaId, $limite);
        return ApiResponse::json(['data' => $rows, 'meta' => ['count' => count($rows), 'limit' => $limite]]);
    }

    private function garantirPessoaNoEscopo(int $pessoaId, AuthContext $context): void
    {
        if (!$this->clientes->pessoaTemContratoNoEscopo($pessoaId, $context->escopo)) {
            // O mesmo 404 é usado para inexistente e fora do escopo para não revelar cadastros.
            throw new ApiException(404, 'CLIENT_NOT_FOUND', 'Cliente não encontrado.');
        }
    }

    private function pessoaResumo(array $row): array
    {
        return $this->somente($row, [
            'id', 'documento', 'tipo_documento', 'nome', 'data_nascimento', 'sexo', 'estado_civil',
            'total_contratos', 'total_contatos', 'saldo_total_aproximado', 'ultimo_contrato_atualizado_em',
            'criado_em', 'atualizado_em',
        ]);
    }

    private function pessoaDetalhe(array $row): array
    {
        return $this->somente($row, [
            'id', 'documento', 'tipo_documento', 'nome', 'nome_mae', 'data_nascimento', 'sexo', 'estado_civil',
            'origem_carga_id', 'ultima_carga_id', 'origem_carga_nome', 'origem_carga_criado_em',
            'ultima_carga_nome', 'ultima_carga_finalizado_em', 'criado_em', 'atualizado_em',
        ]);
    }

    private function contrato(array $row): array
    {
        return $this->somente($row, [
            'id', 'pessoa_id', 'credor_id', 'carteira_id', 'codigo_contrato', 'conta_id', 'produto', 'subproduto',
            'status', 'fase', 'classificacao', 'data_contratacao', 'data_vencimento', 'dias_atraso', 'saldo_atual',
            'saldo_contabil', 'valor_original', 'credor_codigo', 'credor_nome', 'carteira_codigo', 'carteira_nome',
            'total_parcelas', 'acordos_ativos', 'ultimo_atendimento_em', 'retirado_credor', 'criado_em', 'atualizado_em',
        ]);
    }

    private function contratoExterno(array $row): array
    {
        $ret = $this->contrato($row);
        unset($ret['id'], $ret['pessoa_id'], $ret['credor_id'], $ret['carteira_id']);
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

    private function id(mixed $value, string $nome): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Identificador inválido.', [$nome . '_id' => ['Informe um inteiro positivo.']]);
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
