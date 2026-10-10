<?php

declare(strict_types=1);

namespace CRM\Modules\Cargas\Services;

use CRM\Modules\Cargas\Repositories\CargaRepository;
use RuntimeException;
use Throwable;

final class AplicarCargaAcmeService
{
    /**
     * Achado 2026-10-06: a carga diaria da ACME regravava TODA pessoa,
     * contato e endereco todo dia (~690 mil escritas/dia, ~80% do total)
     * mesmo sem nenhum dado mudar - a unica diferenca era o id da carga e o
     * ponteiro pra linha de staging. Cada escrita vira evento de CDC (~6h/dia
     * de projecao), binlog, Redpanda e ClickHouse. Agora, se o que seria
     * gravado so difere nesses ponteiros, a linha nao e regravada; qualquer
     * dado diferente atualiza tudo como antes. Contratos NAO entram nessa
     * regra: continuam atualizados toda carga, porque a regra de "retirado
     * pelo credor" usa contratos.ultima_carga_id.
     */
    private const PONTEIROS_PESSOA = [['acme', 'ultima_carga_id'], ['acme', 'ultimo_staging_id']];
    private const PONTEIROS_CONTATO = [['acme_ultima_ocorrencia', 'staging_id']];
    private const PONTEIROS_ENDERECO = [['staging_id']];

    /** @var array<int,true> */
    private array $pessoasCriadas = [];

    /** @var array<int,true> */
    private array $pessoasAtualizadas = [];

    /** @var array<int,true> */
    private array $contratosCriados = [];

    /** @var array<int,true> */
    private array $contratosAtualizados = [];

    public function __construct(private readonly CargaRepository $repository)
    {
    }

    /**
     * @return array<string,mixed>
     */
    /**
     * Nenhum dos scripts que chamam este metodo (manual via CLI ou o
     * processamento diario automatizado) tinha protecao contra duas
     * execucoes simultaneas para a MESMA carga - um risco real com varios
     * operadores/crons acionando o mesmo comando ao mesmo tempo. O lock
     * nomeado do MySQL (por carga_id, nao global) resolve isso sem impedir
     * que cargas diferentes sejam aplicadas em paralelo.
     */
    public function aplicar(int $cargaId, int $batchSize = 500): array
    {
        $lockName = 'crm:aplicar_carga:' . $cargaId;
        if (!$this->repository->acquireNamedLock($lockName, 0)) {
            throw new RuntimeException('Já existe uma aplicação em andamento para a carga #' . $cargaId . '.');
        }

        try {
            return $this->aplicarSemLock($cargaId, $batchSize);
        } finally {
            $this->repository->releaseNamedLock($lockName);
        }
    }

    /** @return array<string,mixed> */
    private function aplicarSemLock(int $cargaId, int $batchSize): array
    {
        $batchSize = max(1, min($batchSize, 5000));
        $carga = $this->repository->findCargaArquivoForAplicacao($cargaId);

        if ($carga === null) {
            throw new RuntimeException('Carga #' . $cargaId . ' não encontrada.');
        }

        $this->validarCargaAplicavel($carga);

        $validosEncontrados = $this->repository->countStagingValidos($cargaId);
        $summary = $this->novoResumo($cargaId, $validosEncontrados);

        if ($validosEncontrados === 0) {
            $summary['status'] = 'sem_linhas_validas';
            return $summary;
        }

        $this->repository->updateCargaArquivo($cargaId, [
            'status' => 'aplicando',
            'erro_resumo' => null,
            'aplicacao_finalizada_em' => null,
        ]);

        try {
            while (true) {
                $rows = $this->repository->fetchStagingValidos($cargaId, $batchSize);
                if ($rows === []) {
                    break;
                }

                $this->repository->beginTransaction();

                try {
                    foreach ($rows as $row) {
                        try {
                            $this->aplicarLinha($row, $summary);
                        } catch (Throwable $e) {
                            $this->registrarErroLinha($row, $e);
                            $summary['erros']++;
                            $summary['erros_no_ultimo_lote']++;
                        }
                    }

                    $this->repository->commit();
                } catch (Throwable $e) {
                    $this->repository->rollBack();
                    throw $e;
                }

                $summary['linhas_aplicadas'] = (int) $summary['linhas_aplicadas']
                    + count($rows)
                    - (int) $summary['erros_no_ultimo_lote'];
                $summary['erros_no_ultimo_lote'] = 0;
            }

            $summary['pessoas_criadas'] = count($this->pessoasCriadas);
            $summary['pessoas_atualizadas'] = count($this->pessoasAtualizadas);
            $summary['contratos_criados'] = count($this->contratosCriados);
            $summary['contratos_atualizados'] = count($this->contratosAtualizados);
            $summary['status'] = $summary['erros'] > 0 ? 'aplicado_com_erros' : 'aplicado';
            $aplicadoEm = date('Y-m-d H:i:s');

            $this->repository->updateCargaArquivo($cargaId, [
                'status' => 'concluido',
                'pessoas_criadas' => (int) ($carga['pessoas_criadas'] ?? 0) + (int) $summary['pessoas_criadas'],
                'pessoas_atualizadas' => (int) ($carga['pessoas_atualizadas'] ?? 0) + (int) $summary['pessoas_atualizadas'],
                'contratos_criados' => (int) ($carga['contratos_criados'] ?? 0) + (int) $summary['contratos_criados'],
                'contratos_atualizados' => (int) ($carga['contratos_atualizados'] ?? 0) + (int) $summary['contratos_atualizados'],
                'erro_resumo' => $summary['erros'] > 0
                    ? 'Merge ACME aplicado com ' . $summary['erros'] . ' erro(s). Veja cargas_erros. Esta carga não virou referência operacional.'
                    : null,
                'aplicacao_finalizada_em' => $summary['erros'] === 0 ? $aplicadoEm : null,
                'payload_extra' => $this->mergePayload($carga['payload_extra'] ?? null, [
                    'aplicacao_acme_ultima' => [
                        'aplicado_em' => $aplicadoEm,
                        'status' => $summary['status'],
                        'linhas_validas_encontradas' => $summary['staging_validos_encontrados'],
                        'linhas_aplicadas' => $summary['linhas_aplicadas'],
                        'erros' => $summary['erros'],
                        'contatos_criados' => $summary['contatos_criados'],
                        'enderecos_criados' => $summary['enderecos_criados'],
                        'enderecos_atualizados' => $summary['enderecos_atualizados'],
                        'veiculos_criados' => $summary['veiculos_criados'],
                        'veiculos_atualizados' => $summary['veiculos_atualizados'],
                        'sem_alteracao' => [
                            'pessoas' => $summary['pessoas_sem_alteracao'],
                            'contatos' => $summary['contatos_sem_alteracao'],
                            'enderecos' => $summary['enderecos_sem_alteracao'],
                        ],
                    ],
                ]),
            ]);

            return $summary;
        } catch (Throwable $e) {
            $this->repository->updateCargaArquivo($cargaId, [
                'status' => 'erro',
                'erro_resumo' => 'Erro fatal ao aplicar merge ACME: ' . $e->getMessage(),
                'aplicacao_finalizada_em' => null,
            ]);

            throw $e;
        }
    }

    /** @param array<string,mixed> $carga */
    private function validarCargaAplicavel(array $carga): void
    {
        $status = (string) ($carga['status'] ?? '');
        $credor = strtoupper((string) ($carga['credor_codigo'] ?? ''));
        $layout = (string) ($carga['layout_codigo'] ?? '');
        $id = (int) ($carga['id'] ?? 0);

        if ($status !== 'concluido') {
            throw new RuntimeException('Carga #' . $id . ' não pode ser aplicada. Status atual: ' . $status . '. Permitido apenas status concluido.');
        }

        if ($credor !== 'ACME') {
            throw new RuntimeException('Carga #' . $id . ' não é do credor ACME. Credor atual: ' . ($credor ?: 'indefinido') . '.');
        }

        if ($layout !== 'ACME_COBRANCA_PIPE') {
            throw new RuntimeException('Carga #' . $id . ' não usa o layout ACME_COBRANCA_PIPE. Layout atual: ' . ($layout ?: 'indefinido') . '.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function novoResumo(int $cargaId, int $validosEncontrados): array
    {
        return [
            'carga_id' => $cargaId,
            'staging_validos_encontrados' => $validosEncontrados,
            'linhas_aplicadas' => 0,
            'pessoas_criadas' => 0,
            'pessoas_atualizadas' => 0,
            'contratos_criados' => 0,
            'contratos_atualizados' => 0,
            'contatos_criados' => 0,
            'contatos_atualizados' => 0,
            'enderecos_criados' => 0,
            'enderecos_atualizados' => 0,
            'veiculos_criados' => 0,
            'veiculos_atualizados' => 0,
            'pessoas_sem_alteracao' => 0,
            'contatos_sem_alteracao' => 0,
            'enderecos_sem_alteracao' => 0,
            'erros' => 0,
            'erros_no_ultimo_lote' => 0,
            'status' => 'pendente',
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $summary
     */
    private function aplicarLinha(array $row, array &$summary): void
    {
        $documento = $this->texto($row['documento'] ?? null);
        $codigoContrato = $this->texto($row['codigo_contrato'] ?? null);

        if ($documento === null) {
            throw new RuntimeException('Linha staging #' . $row['id'] . ' sem documento.');
        }

        if ($codigoContrato === null) {
            throw new RuntimeException('Linha staging #' . $row['id'] . ' sem codigo_contrato.');
        }

        $pessoa = $this->upsertPessoa($row, $documento, $summary);
        $contrato = $this->upsertContrato($row, (int) $pessoa['id'], $codigoContrato);

        $this->upsertContatos($row, (int) $pessoa['id'], $summary);
        $this->upsertEndereco($row, (int) $pessoa['id'], $summary);
        $this->upsertVeiculo($row, (int) $contrato['id'], $summary);

        $this->repository->markStagingAplicado((int) $row['id'], (int) $pessoa['id'], (int) $contrato['id']);
        $this->repository->updateRawStatus((int) $row['linha_raw_id'], 'aplicada');
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $summary
     */
    private function upsertPessoa(array $row, string $documento, array &$summary): array
    {
        $cargaId = (int) $row['carga_arquivo_id'];
        $pessoa = $this->repository->findPessoaByDocumento($documento);
        $payload = $this->payloadPessoa($row, $pessoa['payload_extra'] ?? null);

        if ($pessoa === null) {
            $id = $this->repository->createPessoa([
                'documento' => $documento,
                'tipo_documento' => $this->texto($row['tipo_documento'] ?? null) ?? 'OUTRO',
                'nome' => $this->texto($row['nome'] ?? null) ?? $documento,
                'nome_mae' => $this->texto($row['nome_mae'] ?? null),
                'nome_pai' => $this->texto($row['nome_pai'] ?? null),
                'data_nascimento' => $this->texto($row['data_nascimento'] ?? null),
                'sexo' => $this->texto($row['sexo'] ?? null),
                'estado_civil' => $this->texto($row['estado_civil'] ?? null),
                'rg' => $this->texto($row['rg'] ?? null),
                'renda_mensal' => $row['renda_mensal'] ?? null,
                'origem_carga_id' => $cargaId,
                'ultima_carga_id' => $cargaId,
                'payload_extra' => $payload,
            ]);
            $this->pessoasCriadas[$id] = true;
            return $this->repository->findPessoaByDocumento($documento) ?? ['id' => $id];
        }

        $id = (int) $pessoa['id'];
        $campos = $this->filtrarNaoVazios([
            'tipo_documento' => $this->texto($row['tipo_documento'] ?? null),
            'nome' => $this->texto($row['nome'] ?? null),
            'nome_mae' => $this->texto($row['nome_mae'] ?? null),
            'nome_pai' => $this->texto($row['nome_pai'] ?? null),
            'data_nascimento' => $this->texto($row['data_nascimento'] ?? null),
            'sexo' => $this->texto($row['sexo'] ?? null),
            'estado_civil' => $this->texto($row['estado_civil'] ?? null),
            'rg' => $this->texto($row['rg'] ?? null),
            'renda_mensal' => $row['renda_mensal'] ?? null,
            'ultima_carga_id' => $cargaId,
            'payload_extra' => $payload,
        ]);

        if ($this->semMudancaReal($pessoa, $campos, self::PONTEIROS_PESSOA)) {
            $summary['pessoas_sem_alteracao']++;
            return $pessoa;
        }

        $this->repository->updatePessoa($id, $campos);

        if (!isset($this->pessoasCriadas[$id])) {
            $this->pessoasAtualizadas[$id] = true;
        }

        return $this->repository->findPessoaByDocumento($documento) ?? $pessoa;
    }

    /** @param array<string,mixed> $row */
    private function upsertContrato(array $row, int $pessoaId, string $codigoContrato): array
    {
        $cargaId = (int) $row['carga_arquivo_id'];
        $credorId = (int) $row['credor_id'];
        $contrato = $this->repository->findContratoByCredorCodigo($credorId, $codigoContrato);
        $payload = $this->payloadContrato($row, $contrato['payload_extra'] ?? null);
        $data = $this->contratoData($row, $pessoaId, $codigoContrato, $payload);

        if ($contrato === null) {
            $id = $this->repository->createContrato([
                ...$data,
                'credor_id' => $credorId,
                'origem_carga_id' => $cargaId,
                'ultima_carga_id' => $cargaId,
            ]);
            $this->contratosCriados[$id] = true;
            return $this->repository->findContratoByCredorCodigo($credorId, $codigoContrato) ?? ['id' => $id];
        }

        $id = (int) $contrato['id'];
        $this->repository->updateContrato($id, $this->filtrarNaoVazios([
            ...$data,
            'ultima_carga_id' => $cargaId,
        ]));

        if (!isset($this->contratosCriados[$id])) {
            $this->contratosAtualizados[$id] = true;
        }

        return $this->repository->findContratoByCredorCodigo($credorId, $codigoContrato) ?? $contrato;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $summary
     */
    private function upsertContatos(array $row, int $pessoaId, array &$summary): void
    {
        $items = $this->contatosDaLinha($row);

        foreach ($items as $item) {
            $tipo = (string) ($item['tipo'] ?? '');
            $valor = $this->texto($item['valor'] ?? null);
            $valorNormalizado = $this->texto($item['valor_normalizado'] ?? null);

            if (!in_array($tipo, ['telefone', 'email'], true) || $valor === null) {
                continue;
            }

            $valorNormalizado = $valorNormalizado ?? ($tipo === 'email'
                ? strtolower($valor)
                : (preg_replace('/\D+/', '', $valor) ?: null));

            if ($valorNormalizado === null || $valorNormalizado === '') {
                continue;
            }

            if ($tipo === 'telefone' && !in_array(strlen($valorNormalizado), [10, 11], true)) {
                continue;
            }

            if ($tipo === 'email' && filter_var($valorNormalizado, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $contato = $this->repository->findContato($pessoaId, $tipo, $valorNormalizado);
            $payloadContato = [
                'staging_id' => (int) $row['id'],
                'layout' => 'ACME_COBRANCA_PIPE',
                'campo_origem' => $item['campo_origem'] ?? null,
            ];

            if ($contato === null) {
                $this->repository->createContato([
                    'pessoa_id' => $pessoaId,
                    'tipo' => $tipo,
                    'subtipo' => $item['subtipo'] ?? null,
                    'valor' => $valorNormalizado,
                    'valor_normalizado' => $valorNormalizado,
                    'ddd' => $tipo === 'telefone' && strlen($valorNormalizado) >= 10 ? substr($valorNormalizado, 0, 2) : null,
                    'telefone' => $tipo === 'telefone' ? $valorNormalizado : null,
                    'email' => $tipo === 'email' ? $valorNormalizado : null,
                    'status' => $item['status'] ?? ($tipo === 'email' ? 'novo' : 'novo'),
                    'principal' => (int) ($item['principal'] ?? 0),
                    'origem' => 'ACME',
                    'origem_carga_id' => (int) $row['carga_arquivo_id'],
                    'ultima_carga_id' => (int) $row['carga_arquivo_id'],
                    'payload_extra' => $payloadContato,
                ]);
                $summary['contatos_criados']++;
                continue;
            }

            $campos = [
                'ultima_carga_id' => (int) $row['carga_arquivo_id'],
                'payload_extra' => $this->mergePayload($contato['payload_extra'] ?? null, [
                    'acme_ultima_ocorrencia' => $payloadContato,
                ]),
            ];

            if ($this->semMudancaReal($contato, $campos, self::PONTEIROS_CONTATO)) {
                $summary['contatos_sem_alteracao']++;
                continue;
            }

            $this->repository->updateContato((int) $contato['id'], $campos);
            $summary['contatos_atualizados']++;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return list<array<string,mixed>>
     */
    private function contatosDaLinha(array $row): array
    {
        $payloadExtra = $this->decodeJson($row['payload_extra'] ?? null);
        $payloadItems = $payloadExtra['contatos_detectados_acme'] ?? [];
        $items = [];

        if (is_array($payloadItems) && $payloadItems !== []) {
            foreach ($payloadItems as $payloadItem) {
                if (!is_array($payloadItem)) {
                    continue;
                }
                $items[] = $payloadItem;
            }
        }

        // Compatibilidade com formatos antigos de staging.
        $items[] = ['tipo' => 'telefone', 'subtipo' => 'celular_hot', 'valor' => $this->texto($row['telefone_1'] ?? null), 'status' => 'hot', 'principal' => 1, 'campo_origem' => 'CelularHot'];
        $items[] = ['tipo' => 'telefone', 'subtipo' => 'fixo_hot', 'valor' => $this->texto($row['telefone_2'] ?? null), 'status' => 'hot', 'principal' => 0, 'campo_origem' => 'FixoHot'];
        $items[] = ['tipo' => 'telefone', 'subtipo' => 'telefone_extra', 'valor' => $this->texto($row['telefone_3'] ?? null), 'status' => 'novo', 'principal' => 0, 'campo_origem' => 'telefone_3'];
        $items[] = ['tipo' => 'email', 'subtipo' => 'email_hot', 'valor' => $this->texto($row['email_1'] ?? null), 'status' => 'hot', 'principal' => 1, 'campo_origem' => 'EmailHot'];
        $items[] = ['tipo' => 'email', 'subtipo' => 'email_extra', 'valor' => $this->texto($row['email_2'] ?? null), 'status' => 'correto', 'principal' => 0, 'campo_origem' => 'EmailCorreto'];

        $deduplicados = [];
        $seen = [];
        foreach ($items as $item) {
            $tipo = (string) ($item['tipo'] ?? '');
            $valor = $this->texto($item['valor_normalizado'] ?? $item['valor'] ?? null);
            if ($tipo === '' || $valor === null) {
                continue;
            }

            $normalizado = $tipo === 'email'
                ? strtolower($valor)
                : (preg_replace('/\D+/', '', $valor) ?: '');

            if ($normalizado === '') {
                continue;
            }

            $key = $tipo . ':' . $normalizado;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $item['valor'] = $normalizado;
            $item['valor_normalizado'] = $normalizado;
            $deduplicados[] = $item;
        }

        return $deduplicados;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $summary
     */
    private function upsertEndereco(array $row, int $pessoaId, array &$summary): void
    {
        $data = $this->filtrarNaoVazios([
            'pessoa_id' => $pessoaId,
            'tipo' => 'cobranca',
            'cep' => $this->texto($row['cep'] ?? null),
            'uf' => $this->texto($row['uf'] ?? null),
            'cidade' => $this->texto($row['cidade'] ?? null),
            'bairro' => $this->texto($row['bairro'] ?? null),
            'logradouro' => $this->texto($row['logradouro'] ?? null),
            'numero' => $this->texto($row['numero_endereco'] ?? null),
            'complemento' => $this->texto($row['complemento'] ?? null),
            'principal' => 0,
            'origem' => 'ACME',
            'origem_carga_id' => (int) $row['carga_arquivo_id'],
            'ultima_carga_id' => (int) $row['carga_arquivo_id'],
            'payload_extra' => [
                'staging_id' => (int) $row['id'],
                'layout' => 'ACME_COBRANCA_PIPE',
            ],
        ]);

        if (!$this->temAlgum($data, ['cep', 'uf', 'cidade', 'bairro', 'logradouro'])) {
            return;
        }

        $endereco = $this->repository->findEnderecoSimilar(
            $pessoaId,
            $data['cep'] ?? null,
            $data['uf'] ?? null,
            $data['cidade'] ?? null
        );

        if ($endereco === null) {
            $this->repository->createEndereco($data);
            $summary['enderecos_criados']++;
            return;
        }

        $campos = $this->mergeNaoVazioComPayload($endereco, $data);

        if ($this->semMudancaReal($endereco, $campos, self::PONTEIROS_ENDERECO, ['origem_carga_id'])) {
            $summary['enderecos_sem_alteracao']++;
            return;
        }

        $this->repository->updateEndereco((int) $endereco['id'], $campos);
        $summary['enderecos_atualizados']++;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $summary
     */
    private function upsertVeiculo(array $row, int $contratoId, array &$summary): void
    {
        $data = $this->filtrarNaoVazios([
            'contrato_id' => $contratoId,
            'descricao' => $this->texto($row['descricao_veiculo'] ?? null),
            'marca' => $this->texto($row['marca_veiculo'] ?? null),
            'modelo' => $this->texto($row['modelo_veiculo'] ?? null),
            'ano_fabricacao' => $row['ano_fabricacao'] ?? null,
            'ano_modelo' => $row['ano_modelo'] ?? null,
            'categoria' => $this->texto($row['categoria_veiculo'] ?? null),
            'origem_carga_id' => (int) $row['carga_arquivo_id'],
            'ultima_carga_id' => (int) $row['carga_arquivo_id'],
            'payload_extra' => [
                'staging_id' => (int) $row['id'],
                'layout' => 'ACME_COBRANCA_PIPE',
            ],
        ]);

        if (!$this->temAlgum($data, ['descricao', 'marca', 'modelo', 'ano_fabricacao', 'ano_modelo', 'categoria'])) {
            return;
        }

        $veiculo = $this->repository->findVeiculoByContrato($contratoId);
        if ($veiculo === null) {
            $this->repository->createVeiculo($data);
            $summary['veiculos_criados']++;
            return;
        }

        $this->repository->updateVeiculo((int) $veiculo['id'], $this->mergeNaoVazioComPayload($veiculo, $data));
        $summary['veiculos_atualizados']++;
    }

    /** @param array<string,mixed> $row */
    private function contratoData(array $row, int $pessoaId, string $codigoContrato, array $payload): array
    {
        return $this->filtrarNaoVazios([
            'carteira_id' => $row['carteira_id'] ?? null,
            'pessoa_id' => $pessoaId,
            'codigo_contrato' => $codigoContrato,
            'conta_id' => $this->texto($row['conta_id'] ?? null),
            'devedor_origem_id' => $this->texto($row['devedor_origem_id'] ?? null),
            'produto' => $this->texto($row['produto'] ?? null),
            'subproduto' => $this->texto($row['subproduto'] ?? null),
            'lote' => $this->texto($row['lote'] ?? null),
            'fila' => $this->texto($row['fila'] ?? null),
            'status_devedor' => $this->texto($row['status_devedor'] ?? null),
            'status_contrato' => $this->texto($row['status_contrato'] ?? null),
            'filial_codigo' => $this->texto($row['filial_codigo'] ?? null),
            'filial_nome' => $this->texto($row['filial_nome'] ?? null),
            'unidade_cobranca' => $this->texto($row['unidade_cobranca'] ?? null),
            'moeda' => $this->texto($row['moeda'] ?? null),
            'indice' => $this->texto($row['indice'] ?? null),
            'numero_cartao_mascarado' => $this->texto($row['numero_cartao_mascarado'] ?? null),
            'data_corte' => $this->texto($row['data_corte'] ?? null),
            'data_bordero' => $this->texto($row['data_bordero'] ?? null),
            'data_liberacao' => $this->texto($row['data_liberacao'] ?? null),
            'data_vencimento_inicial' => $this->texto($row['data_vencimento_inicial'] ?? null) ?? $this->texto($row['data_vencimento_parcela'] ?? null),
            'dias_atraso' => $row['dias_atraso'] ?? null,
            'saldo_atual' => $row['saldo_atual'] ?? null,
            'saldo_contabil' => $row['saldo_contabil'] ?? null,
            'valor_total_pago' => $row['valor_total_pago'] ?? null,
            'valor_principal' => $row['valor_principal'] ?? null,
            'valor_financiado' => $row['valor_financiado'] ?? null,
            'valor_contratado' => $row['valor_contratado'] ?? null,
            'valor_iof' => $row['valor_iof'] ?? null,
            'valor_tac' => $row['valor_tac'] ?? null,
            'valor_encargos' => $row['valor_encargos'] ?? null,
            'valor_mensal' => $row['valor_mensal'] ?? null,
            'taxa_pactuada' => $row['taxa_pactuada'] ?? null,
            'taxa_tir' => $row['taxa_tir'] ?? null,
            'cet_anual' => $row['cet_anual'] ?? null,
            'cet_mensal' => $row['cet_mensal'] ?? null,
            'payload_extra' => $payload,
        ]);
    }

    /** @param array<string,mixed> $row */
    private function payloadPessoa(array $row, mixed $payloadAtual): array
    {
        $extra = $this->decodeJson($row['payload_extra'] ?? null);
        $normalizacao = [];
        foreach (['documento_original', 'documento_normalizado', 'documento_normalizacao'] as $key) {
            if (isset($extra[$key])) {
                $normalizacao[$key] = $extra[$key];
            }
        }

        return $this->mergePayload($payloadAtual, [
            'acme' => [
                'ultima_carga_id' => (int) $row['carga_arquivo_id'],
                'ultimo_staging_id' => (int) $row['id'],
                'documento' => $normalizacao,
            ],
        ]);
    }

    /** @param array<string,mixed> $row */
    private function payloadContrato(array $row, mixed $payloadAtual): array
    {
        return $this->mergePayload($payloadAtual, [
            'acme' => [
                'ultima_carga_id' => (int) $row['carga_arquivo_id'],
                'ultimo_staging_id' => (int) $row['id'],
                'payload_extra' => $this->decodeJson($row['payload_extra'] ?? null),
            ],
        ]);
    }

    /** @param array<string,mixed> $row */
    private function registrarErroLinha(array $row, Throwable $e): void
    {
        $message = substr($e->getMessage(), 0, 2000);
        $this->repository->markStagingErroAplicacao((int) $row['id'], $message);
        $this->repository->updateRawStatus((int) $row['linha_raw_id'], 'erro', $message);
        $this->repository->insertErrorsBatch([[ 
            'carga_arquivo_id' => (int) $row['carga_arquivo_id'],
            'linha_raw_id' => (int) $row['linha_raw_id'],
            'staging_id' => (int) $row['id'],
            'etapa' => 'aplicacao',
            'codigo_erro' => 'ACME_MERGE_LINHA_ERRO',
            'mensagem' => $message,
            'campo_origem' => null,
            'campo_canonico' => null,
            'severidade' => 'erro',
            'valor_original' => null,
            'payload_contexto' => [
                'documento' => $row['documento'] ?? null,
                'codigo_contrato' => $row['codigo_contrato'] ?? null,
                'trace_resumido' => $e->getFile() . ':' . $e->getLine(),
            ],
        ]]);
    }

    /**
     * True quando o update so mudaria ultima_carga_id e os ponteiros de
     * carga/staging do payload_extra. Na duvida (tipo diferente, coluna
     * ausente) responde false - ou seja, atualiza como sempre fez.
     *
     * @param array<string,mixed> $atual linha atual do banco
     * @param array<string,mixed> $novo colunas que o update gravaria
     * @param list<list<string>> $ponteiros caminhos no payload_extra que so apontam pra carga/staging
     * @param list<string> $ignorar colunas presentes em $novo que o update nao grava
     */
    private function semMudancaReal(array $atual, array $novo, array $ponteiros, array $ignorar = []): bool
    {
        foreach ($novo as $coluna => $valor) {
            if ($coluna === 'ultima_carga_id' || in_array($coluna, $ignorar, true)) {
                continue;
            }

            if ($coluna === 'payload_extra') {
                if (!$this->mesmoPayload($atual['payload_extra'] ?? null, $valor, $ponteiros)) {
                    return false;
                }
                continue;
            }

            if (!array_key_exists($coluna, $atual) || $atual[$coluna] === null || $valor === null
                || (string) $atual[$coluna] !== (string) $valor) {
                return false;
            }
        }

        return true;
    }

    /** @param list<list<string>> $ponteiros */
    private function mesmoPayload(mixed $atual, mixed $novo, array $ponteiros): bool
    {
        $a = $this->decodeJson($atual);
        $b = $this->decodeJson($novo);
        foreach ($ponteiros as $caminho) {
            $a = $this->semCaminho($a, $caminho);
            $b = $this->semCaminho($b, $caminho);
        }

        return json_encode($this->canonico($a)) === json_encode($this->canonico($b));
    }

    /**
     * @param array<string,mixed> $dados
     * @param list<string> $caminho
     * @return array<string,mixed>
     */
    private function semCaminho(array $dados, array $caminho): array
    {
        $chave = array_shift($caminho);
        if ($chave === null || !array_key_exists($chave, $dados)) {
            return $dados;
        }

        if ($caminho === []) {
            unset($dados[$chave]);
        } elseif (is_array($dados[$chave])) {
            $dados[$chave] = $this->semCaminho($dados[$chave], $caminho);
        }

        return $dados;
    }

    /** Ordena chaves (o MySQL devolve o JSON com outra ordem) sem mexer em listas. */
    private function canonico(mixed $valor): mixed
    {
        if (!is_array($valor)) {
            return $valor;
        }

        $valor = array_map(fn (mixed $item): mixed => $this->canonico($item), $valor);
        if (!array_is_list($valor)) {
            ksort($valor);
        }

        return $valor;
    }

    private function texto(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function filtrarNaoVazios(array $data): array
    {
        return array_filter(
            $data,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $keys
     */
    private function temAlgum(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $atual
     * @param array<string,mixed> $novo
     * @return array<string,mixed>
     */
    private function mergeNaoVazioComPayload(array $atual, array $novo): array
    {
        if (array_key_exists('payload_extra', $novo)) {
            $novo['payload_extra'] = $this->mergePayload($atual['payload_extra'] ?? null, $novo['payload_extra']);
        }

        return $this->filtrarNaoVazios($novo);
    }

    /**
     * @param array<string,mixed> $novo
     * @return array<string,mixed>
     */
    private function mergePayload(mixed $atual, array $novo): array
    {
        $base = $this->decodeJson($atual);
        return $this->arrayReplaceRecursive($base, $novo);
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $novo
     * @return array<string,mixed>
     */
    private function arrayReplaceRecursive(array $base, array $novo): array
    {
        foreach ($novo as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->arrayReplaceRecursive($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
