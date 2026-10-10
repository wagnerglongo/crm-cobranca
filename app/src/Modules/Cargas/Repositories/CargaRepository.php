<?php

declare(strict_types=1);

namespace CRM\Modules\Cargas\Repositories;

use CRM\Modules\Cargas\Support\CargaEncoding;
use PDO;

final class CargaRepository
{
    /** @var array<string,\PDOStatement> */
    private array $preparedCache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * PDO::ATTR_EMULATE_PREPARES esta desligado (prepares reais no MySQL).
     * Sem cache, cada chamada a prepare() custa um round-trip de rede extra
     * (PREPARE) alem do EXECUTE. Um mesmo texto de SQL sempre produz o mesmo
     * plano, entao cacheamos por texto de SQL e reutilizamos o PDOStatement,
     * bindando/executando parametros novos a cada chamada.
     */
    private function prepareCached(string $sql): \PDOStatement
    {
        return $this->preparedCache[$sql] ??= $this->pdo->prepare($sql);
    }

    /** @return array<string,mixed>|null */
    public function findCredorByCodigo(string $codigo): ?array
    {
        $stmt = $this->prepareCached('SELECT * FROM credores WHERE codigo = :codigo AND ativo = 1 LIMIT 1');
        $stmt->execute(['codigo' => $codigo]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findCarteiraPadrao(int $credorId): ?array
    {
        $stmt = $this->prepareCached('SELECT * FROM carteiras WHERE credor_id = :credor_id AND codigo = :codigo LIMIT 1');
        $stmt->execute(['credor_id' => $credorId, 'codigo' => 'PADRAO']);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findLayout(string $codigo, int $credorId): ?array
    {
        $stmt = $this->prepareCached(
            'SELECT * FROM carga_layouts WHERE codigo = :codigo AND credor_id = :credor_id AND ativo = 1 ORDER BY versao DESC LIMIT 1'
        );
        $stmt->execute(['codigo' => $codigo, 'credor_id' => $credorId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findCargaByHash(string $hash): ?array
    {
        // Prefere uma carga efetivamente aplicada/concluida ao apontar duplicidade.
        // Assim uma tentativa interrompida antiga nao vira para sempre a referencia
        // de todos os reprocessamentos posteriores do mesmo arquivo.
        $stmt = $this->prepareCached(
            "SELECT *
             FROM cargas_arquivos
             WHERE hash_arquivo = :hash
             ORDER BY
                CASE
                    WHEN aplicacao_finalizada_em IS NOT NULL THEN 0
                    WHEN status = 'concluido' AND COALESCE(linhas_com_erro, 0) = 0 THEN 1
                    WHEN status = 'concluido_com_erros' THEN 2
                    WHEN status IN ('recebido','identificando_layout','aguardando_processamento','processando_raw','normalizando','validando','aplicando','indexando') THEN 3
                    WHEN status = 'erro' THEN 4
                    WHEN status = 'cancelado' THEN 5
                    ELSE 6
                END,
                id DESC
             LIMIT 1"
        );
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $data */
    public function createCargaArquivo(array $data): int
    {
        $stmt = $this->prepareCached(
            'INSERT INTO cargas_arquivos (
                duplicado_de_id,
                credor_id,
                carteira_id,
                layout_id,
                nome_original,
                caminho_storage,
                storage_driver,
                mime_type,
                encoding_detectado,
                hash_arquivo,
                tamanho_bytes,
                data_referencia,
                status,
                payload_amostra,
                payload_extra
            ) VALUES (
                :duplicado_de_id,
                :credor_id,
                :carteira_id,
                :layout_id,
                :nome_original,
                :caminho_storage,
                :storage_driver,
                :mime_type,
                :encoding_detectado,
                :hash_arquivo,
                :tamanho_bytes,
                :data_referencia,
                :status,
                :payload_amostra,
                :payload_extra
            )'
        );

        $stmt->execute([
            'duplicado_de_id' => $data['duplicado_de_id'] ?? null,
            'credor_id' => $data['credor_id'] ?? null,
            'carteira_id' => $data['carteira_id'] ?? null,
            'layout_id' => $data['layout_id'] ?? null,
            'nome_original' => $data['nome_original'],
            'caminho_storage' => $data['caminho_storage'],
            'storage_driver' => $data['storage_driver'] ?? 'local',
            'mime_type' => $data['mime_type'] ?? null,
            'encoding_detectado' => $data['encoding_detectado'] ?? 'utf-8-sig',
            'hash_arquivo' => $data['hash_arquivo'],
            'tamanho_bytes' => $data['tamanho_bytes'] ?? null,
            'data_referencia' => $data['data_referencia'] ?? null,
            'status' => $data['status'] ?? 'recebido',
            'payload_amostra' => $this->json($data['payload_amostra'] ?? null),
            'payload_extra' => $this->json($data['payload_extra'] ?? null),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $fields */
    public function updateCargaArquivo(int $cargaId, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $allowed = [
            'credor_id',
            'carteira_id',
            'layout_id',
            'status',
            'total_linhas',
            'linhas_raw_gravadas',
            'linhas_processadas',
            'linhas_staging',
            'linhas_validas',
            'linhas_com_erro',
            'pessoas_criadas',
            'pessoas_atualizadas',
            'contratos_criados',
            'contratos_atualizados',
            'erro_resumo',
            'payload_amostra',
            'payload_extra',
            'iniciado_em',
            'finalizado_em',
            'aplicacao_finalizada_em',
        ];

        $set = [];
        $params = ['id' => $cargaId];

        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }

            if ($column === 'aplicacao_finalizada_em' && !$this->colunaExiste('cargas_arquivos', $column)) {
                continue;
            }

            $set[] = $column . ' = :' . $column;
            $params[$column] = in_array($column, ['payload_amostra', 'payload_extra'], true) ? $this->json($value) : $value;
        }

        if ($set === []) {
            return;
        }

        $sql = 'UPDATE cargas_arquivos SET ' . implode(', ', $set) . ' WHERE id = :id';
        $stmt = $this->prepareCached($sql);
        $stmt->execute($params);
    }

    /**
     * @param list<array{numero_linha:int,linha_bruta:string,hash_linha:string,status:string,erro:?string,payload_parse:mixed}> $rows
     * @return array<int,int> numero_linha => raw_id
     */
    public function insertRawBatch(int $cargaId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        $lineNumbers = [];

        foreach ($rows as $row) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?)';
            $params[] = $cargaId;
            $params[] = $row['numero_linha'];
            // A linha pode vir em Windows-1252/CP1252. O parser e o hash usam os bytes
            // originais; somente a copia textual do RAW e convertida para UTF-8.
            $params[] = CargaEncoding::toUtf8ForStorage($row['linha_bruta']);
            $params[] = $row['hash_linha'];
            $params[] = $row['status'];
            $params[] = $row['erro'];
            $params[] = $this->json($row['payload_parse']);
            $lineNumbers[] = $row['numero_linha'];
        }

        $sql = 'INSERT INTO cargas_linhas_raw (
            carga_arquivo_id, numero_linha, linha_bruta, hash_linha, status, erro, payload_parse
        ) VALUES ' . implode(',', $placeholders);

        $stmt = $this->prepareCached($sql);
        $stmt->execute($params);

        return $this->findRawIdsByLineRange($cargaId, min($lineNumbers), max($lineNumbers));
    }

    /** @return array<int,int> numero_linha => raw_id */
    private function findRawIdsByLineRange(int $cargaId, int $minLine, int $maxLine): array
    {
        $stmt = $this->prepareCached(
            'SELECT id, numero_linha FROM cargas_linhas_raw WHERE carga_arquivo_id = :carga_id AND numero_linha BETWEEN :min_line AND :max_line'
        );
        $stmt->bindValue('carga_id', $cargaId, PDO::PARAM_INT);
        $stmt->bindValue('min_line', $minLine, PDO::PARAM_INT);
        $stmt->bindValue('max_line', $maxLine, PDO::PARAM_INT);
        $stmt->execute();

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['numero_linha']] = (int) $row['id'];
        }

        return $map;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    public function insertStagingBatch(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $inserted = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $inserted += $this->insertStagingChunk($chunk);
        }

        return $inserted;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function insertStagingChunk(array $rows): int
    {
        $columns = [
            'carga_arquivo_id','linha_raw_id','credor_id','carteira_id','layout_id',
            'documento','tipo_documento','nome','nome_mae','nome_pai','data_nascimento','sexo','estado_civil','rg','renda_mensal',
            'codigo_contrato','conta_id','devedor_origem_id','produto','subproduto','lote','fila','status_devedor','status_contrato',
            'filial_codigo','filial_nome','unidade_cobranca','moeda','indice','numero_cartao_mascarado','data_corte','data_bordero',
            'data_liberacao','data_vencimento_inicial','dias_atraso',
            'numero_parcela','data_vencimento_parcela','saldo_parcela','valor_original_parcela','valor_juros_parcela','valor_multa_parcela',
            'status_parcela','flag_liquidado','data_liquidacao',
            'saldo_atual','saldo_contabil','valor_total_pago','valor_principal','valor_financiado','valor_contratado','valor_iof','valor_tac',
            'valor_encargos','valor_mensal','taxa_pactuada','taxa_tir','cet_anual','cet_mensal',
            'telefone_1','telefone_2','telefone_3','email_1','email_2','cep','uf','cidade','bairro','logradouro','numero_endereco','complemento',
            'descricao_veiculo','marca_veiculo','modelo_veiculo','ano_fabricacao','ano_modelo','categoria_veiculo',
            'codigo_acordo','numero_parcela_acordo','data_acordo','data_movimento_acordo','data_vencimento_acordo','valor_total_acordo',
            'valor_parcela_acordo','quantidade_parcelas_acordo','status_acordo','modo_pagamento','tipo_pagamento',
            'data_pagamento','data_baixa','valor_pago','valor_recebido','valor_despesas','valor_tarifa','recibo','controle_origem',
            'nosso_numero','codigo_barras','linha_digitavel','data_vencimento_boleto','valor_vencimento_boleto',
            'codigo_operacao','motivo_codigo','motivo_descricao','data_quebra','bloqueio_deb_conta','data_status_bloqueio',
            'hash_negocio','status','erro','payload_origem','payload_normalizado','payload_extra',
        ];

        $rowPlaceholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $sql = 'INSERT INTO stg_carga_canonica (' . implode(',', $columns) . ') VALUES ' . implode(',', array_fill(0, count($rows), $rowPlaceholder));

        $params = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if (in_array($column, ['payload_origem', 'payload_normalizado', 'payload_extra'], true)) {
                    $value = $this->json($value);
                }
                $params[] = $value;
            }
        }

        $stmt = $this->prepareCached($sql);
        $stmt->execute($params);

        return count($rows);
    }

    /**
     * @param list<array{raw_id:int,status:string,erro:?string,payload_parse:mixed}> $updates
     */
    public function updateRawBatch(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $stmt = $this->prepareCached(
            'UPDATE cargas_linhas_raw SET status = :status, erro = :erro, payload_parse = :payload_parse WHERE id = :id'
        );

        foreach ($updates as $update) {
            $stmt->execute([
                'id' => $update['raw_id'],
                'status' => $update['status'],
                'erro' => $update['erro'],
                'payload_parse' => $this->json($update['payload_parse']),
            ]);
        }
    }

    /**
     * @param list<array<string,mixed>> $errors
     */
    public function insertErrorsBatch(array $errors): void
    {
        if ($errors === []) {
            return;
        }

        $columns = [
            'carga_arquivo_id',
            'linha_raw_id',
            'staging_id',
            'etapa',
            'codigo_erro',
            'mensagem',
            'campo_origem',
            'campo_canonico',
            'severidade',
            'valor_original',
            'payload_contexto',
        ];

        foreach (array_chunk($errors, 1000) as $chunk) {
            $rowPlaceholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
            $sql = 'INSERT INTO cargas_erros (' . implode(',', $columns) . ') VALUES ' . implode(',', array_fill(0, count($chunk), $rowPlaceholder));
            $params = [];

            foreach ($chunk as $error) {
                foreach ($columns as $column) {
                    $value = $error[$column] ?? null;
                    if ($column === 'payload_contexto') {
                        $value = $this->json($value);
                    } elseif ($column === 'valor_original' && is_array($value)) {
                        $value = $this->json($value);
                    } elseif ($column === 'valor_original' && $value !== null) {
                        $value = (string) $value;
                    }
                    $params[] = $value;
                }
            }

            $stmt = $this->prepareCached($sql);
            $stmt->execute($params);
        }
    }


    /** @return array<string,mixed>|null */
    public function findCargaArquivoForAplicacao(int $cargaId): ?array
    {
        $stmt = $this->prepareCached(
            "SELECT
                ca.*,
                c.codigo AS credor_codigo,
                cl.codigo AS layout_codigo
            FROM cargas_arquivos ca
            LEFT JOIN credores c ON c.id = ca.credor_id
            LEFT JOIN carga_layouts cl ON cl.id = ca.layout_id
            WHERE ca.id = :id
            LIMIT 1"
        );
        $stmt->bindValue('id', $cargaId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function countStagingValidos(int $cargaId): int
    {
        $stmt = $this->prepareCached(
            "SELECT COUNT(*) FROM stg_carga_canonica WHERE carga_arquivo_id = :carga_id AND status = 'valido'"
        );
        $stmt->bindValue('carga_id', $cargaId, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    public function countStagingStatus(int $cargaId, string $status): int
    {
        $stmt = $this->prepareCached(
            'SELECT COUNT(*) FROM stg_carga_canonica WHERE carga_arquivo_id = :carga_id AND status = :status'
        );
        $stmt->bindValue('carga_id', $cargaId, PDO::PARAM_INT);
        $stmt->bindValue('status', $status, PDO::PARAM_STR);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function fetchStagingValidos(int $cargaId, int $limit): array
    {
        $stmt = $this->prepareCached(
            "SELECT *
            FROM stg_carga_canonica
            WHERE carga_arquivo_id = :carga_id
              AND status = 'valido'
            ORDER BY id ASC
            LIMIT :limit_rows"
        );
        $stmt->bindValue('carga_id', $cargaId, PDO::PARAM_INT);
        $stmt->bindValue('limit_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findPessoaByDocumento(string $documento): ?array
    {
        $stmt = $this->prepareCached('SELECT * FROM pessoas WHERE documento = :documento LIMIT 1');
        $stmt->execute(['documento' => $documento]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $data */
    public function createPessoa(array $data): int
    {
        $stmt = $this->prepareCached(
            'INSERT INTO pessoas (
                documento,
                tipo_documento,
                nome,
                nome_mae,
                nome_pai,
                data_nascimento,
                sexo,
                estado_civil,
                rg,
                renda_mensal,
                origem_carga_id,
                ultima_carga_id,
                payload_extra
            ) VALUES (
                :documento,
                :tipo_documento,
                :nome,
                :nome_mae,
                :nome_pai,
                :data_nascimento,
                :sexo,
                :estado_civil,
                :rg,
                :renda_mensal,
                :origem_carga_id,
                :ultima_carga_id,
                :payload_extra
            )'
        );

        $stmt->execute([
            'documento' => $data['documento'],
            'tipo_documento' => $data['tipo_documento'] ?? 'OUTRO',
            'nome' => $data['nome'],
            'nome_mae' => $data['nome_mae'] ?? null,
            'nome_pai' => $data['nome_pai'] ?? null,
            'data_nascimento' => $data['data_nascimento'] ?? null,
            'sexo' => $data['sexo'] ?? null,
            'estado_civil' => $data['estado_civil'] ?? null,
            'rg' => $data['rg'] ?? null,
            'renda_mensal' => $data['renda_mensal'] ?? null,
            'origem_carga_id' => $data['origem_carga_id'] ?? null,
            'ultima_carga_id' => $data['ultima_carga_id'] ?? null,
            'payload_extra' => $this->json($data['payload_extra'] ?? null),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $fields */
    public function updatePessoa(int $pessoaId, array $fields): void
    {
        $allowed = [
            'tipo_documento',
            'nome',
            'nome_mae',
            'nome_pai',
            'data_nascimento',
            'sexo',
            'estado_civil',
            'rg',
            'renda_mensal',
            'ultima_carga_id',
            'payload_extra',
        ];
        $this->updateTableById('pessoas', $pessoaId, $fields, $allowed, ['payload_extra']);
    }

    /** @return array<string,mixed>|null */
    public function findContratoByCredorCodigo(int $credorId, string $codigoContrato): ?array
    {
        $stmt = $this->prepareCached(
            'SELECT * FROM contratos WHERE credor_id = :credor_id AND codigo_contrato = :codigo_contrato LIMIT 1'
        );
        $stmt->execute(['credor_id' => $credorId, 'codigo_contrato' => $codigoContrato]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $data */
    public function createContrato(array $data): int
    {
        $columns = [
            'credor_id',
            'carteira_id',
            'pessoa_id',
            'codigo_contrato',
            'conta_id',
            'devedor_origem_id',
            'produto',
            'subproduto',
            'lote',
            'fila',
            'status_devedor',
            'status_contrato',
            'filial_codigo',
            'filial_nome',
            'unidade_cobranca',
            'moeda',
            'indice',
            'numero_cartao_mascarado',
            'data_corte',
            'data_bordero',
            'data_liberacao',
            'data_vencimento_inicial',
            'dias_atraso',
            'saldo_atual',
            'saldo_contabil',
            'valor_total_pago',
            'valor_principal',
            'valor_financiado',
            'valor_contratado',
            'valor_iof',
            'valor_tac',
            'valor_encargos',
            'valor_mensal',
            'taxa_pactuada',
            'taxa_tir',
            'cet_anual',
            'cet_mensal',
            'origem_carga_id',
            'ultima_carga_id',
            'payload_extra',
        ];

        $sql = 'INSERT INTO contratos (' . implode(',', $columns) . ') VALUES (:' . implode(',:', $columns) . ')';
        $params = [];
        foreach ($columns as $column) {
            $params[$column] = $column === 'payload_extra' ? $this->json($data[$column] ?? null) : ($data[$column] ?? null);
        }

        $stmt = $this->prepareCached($sql);
        $stmt->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $fields */
    public function updateContrato(int $contratoId, array $fields): void
    {
        $allowed = [
            'carteira_id',
            'pessoa_id',
            'conta_id',
            'devedor_origem_id',
            'produto',
            'subproduto',
            'lote',
            'fila',
            'status_devedor',
            'status_contrato',
            'filial_codigo',
            'filial_nome',
            'unidade_cobranca',
            'moeda',
            'indice',
            'numero_cartao_mascarado',
            'data_corte',
            'data_bordero',
            'data_liberacao',
            'data_vencimento_inicial',
            'dias_atraso',
            'saldo_atual',
            'saldo_contabil',
            'valor_total_pago',
            'valor_principal',
            'valor_financiado',
            'valor_contratado',
            'valor_iof',
            'valor_tac',
            'valor_encargos',
            'valor_mensal',
            'taxa_pactuada',
            'taxa_tir',
            'cet_anual',
            'cet_mensal',
            'ultima_carga_id',
            'payload_extra',
        ];
        $this->updateTableById('contratos', $contratoId, $fields, $allowed, ['payload_extra']);
    }

    /**
     * Atualiza produto/saldo_contabil/payload_extra de varios contratos de
     * uma vez, via tabela temporaria + JOIN UPDATE (mesmo padrao ja usado
     * em lote para cargas complementares).
     * Cada contrato so aparece uma vez na lista (o chamador ja "dobra" os
     * campos quando o mesmo contrato aparece em varias linhas do staging).
     *
     * @param list<array{contrato_id:int,produto:?string,saldo_contabil:?string,payload_extra:string}> $updates
     */
    public function atualizarContratosBatch(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        // Sem ENGINE=MEMORY de proposito: a engine MEMORY nao suporta colunas
        // JSON/BLOB/TEXT (erro 1163), e payload_extra precisa ser JSON aqui.
        // InnoDB, mesmo assim, e barato o suficiente para uma tabela
        // temporaria de sessao com no maximo algumas centenas de linhas
        // (um lote por vez).
        $this->pdo->exec(<<<'SQL'
CREATE TEMPORARY TABLE IF NOT EXISTS tmp_contratos_complementar (
    contrato_id BIGINT UNSIGNED NOT NULL,
    produto VARCHAR(180) NULL,
    saldo_contabil DECIMAL(15,2) NULL,
    payload_extra JSON NOT NULL,
    PRIMARY KEY (contrato_id)
)
SQL);
        $this->pdo->exec('DELETE FROM tmp_contratos_complementar');

        foreach (array_chunk($updates, 250) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $u) {
                $values[] = '(?,?,?,?)';
                array_push($params, $u['contrato_id'], $u['produto'], $u['saldo_contabil'], $u['payload_extra']);
            }
            $stmt = $this->pdo->prepare('INSERT INTO tmp_contratos_complementar (contrato_id,produto,saldo_contabil,payload_extra) VALUES ' . implode(',', $values));
            $stmt->execute($params);
        }

        $this->pdo->exec(<<<'SQL'
UPDATE contratos c
INNER JOIN tmp_contratos_complementar t ON t.contrato_id = c.id
SET c.produto = COALESCE(t.produto, c.produto),
    c.saldo_contabil = COALESCE(t.saldo_contabil, c.saldo_contabil),
    c.payload_extra = t.payload_extra
SQL);
    }

    /** @return array<string,mixed>|null */
    public function findContato(int $pessoaId, string $tipo, string $valorNormalizado): ?array
    {
        $stmt = $this->prepareCached(
            'SELECT * FROM pessoa_contatos WHERE pessoa_id = :pessoa_id AND tipo = :tipo AND valor_normalizado = :valor_normalizado LIMIT 1'
        );
        $stmt->execute([
            'pessoa_id' => $pessoaId,
            'tipo' => $tipo,
            'valor_normalizado' => $valorNormalizado,
        ]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $data */
    public function createContato(array $data): int
    {
        $stmt = $this->prepareCached(
            'INSERT INTO pessoa_contatos (
                pessoa_id,
                tipo,
                subtipo,
                valor,
                valor_normalizado,
                ddd,
                telefone,
                email,
                status,
                principal,
                origem,
                origem_carga_id,
                ultima_carga_id,
                payload_extra
            ) VALUES (
                :pessoa_id,
                :tipo,
                :subtipo,
                :valor,
                :valor_normalizado,
                :ddd,
                :telefone,
                :email,
                :status,
                :principal,
                :origem,
                :origem_carga_id,
                :ultima_carga_id,
                :payload_extra
            )'
        );

        $stmt->execute([
            'pessoa_id' => $data['pessoa_id'],
            'tipo' => $data['tipo'],
            'subtipo' => $data['subtipo'] ?? null,
            'valor' => $data['valor'],
            'valor_normalizado' => $data['valor_normalizado'],
            'ddd' => $data['ddd'] ?? null,
            'telefone' => $data['telefone'] ?? null,
            'email' => $data['email'] ?? null,
            'status' => $data['status'] ?? 'novo',
            'principal' => $data['principal'] ?? 0,
            'origem' => $data['origem'] ?? null,
            'origem_carga_id' => $data['origem_carga_id'] ?? null,
            'ultima_carga_id' => $data['ultima_carga_id'] ?? null,
            'payload_extra' => $this->json($data['payload_extra'] ?? null),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $fields */
    public function updateContato(int $contatoId, array $fields): void
    {
        $allowed = ['subtipo', 'status', 'principal', 'origem', 'ultima_carga_id', 'payload_extra'];
        $this->updateTableById('pessoa_contatos', $contatoId, $fields, $allowed, ['payload_extra']);
    }

    /**
     * Upsert em lote de contatos, keyed por (pessoa_id,tipo,valor_normalizado)
     * - a mesma chave unica que createContato()/updateContato() tratam uma
     * linha por vez. Em conflito, so sobrescreve origem/ultima_carga_id
     * (igual ao updateContato() usado hoje pelo fluxo de carga complementar),
     * os demais campos so valem para a linha nova.
     *
     * @param list<array<string,mixed>> $contatos cada item com: pessoa_id,
     *     tipo, subtipo, valor, valor_normalizado, ddd, telefone, email,
     *     status, principal, origem, origem_carga_id, ultima_carga_id,
     *     payload_extra (ja em JSON)
     */
    public function upsertContatosBatch(array $contatos): void
    {
        if ($contatos === []) {
            return;
        }

        $values = [];
        $params = [];
        foreach ($contatos as $c) {
            $values[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
            array_push(
                $params,
                $c['pessoa_id'],
                $c['tipo'],
                $c['subtipo'],
                $c['valor'],
                $c['valor_normalizado'],
                $c['ddd'],
                $c['telefone'],
                $c['email'],
                $c['status'],
                $c['principal'],
                $c['origem'],
                $c['origem_carga_id'],
                $c['ultima_carga_id'],
                $c['payload_extra'],
            );
        }

        $sql = 'INSERT INTO pessoa_contatos (pessoa_id,tipo,subtipo,valor,valor_normalizado,ddd,telefone,email,status,principal,origem,origem_carga_id,ultima_carga_id,payload_extra) VALUES '
            . implode(',', $values)
            . ' AS novo ON DUPLICATE KEY UPDATE origem=novo.origem,ultima_carga_id=novo.ultima_carga_id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /** @return array<string,mixed>|null */
    public function findEnderecoSimilar(int $pessoaId, ?string $cep, ?string $uf, ?string $cidade): ?array
    {
        $stmt = $this->prepareCached(
            'SELECT *
            FROM pessoa_enderecos
            WHERE pessoa_id = :pessoa_id
              AND (
                    (:cep_busca IS NOT NULL AND cep = :cep_valor)
                    OR (:cep_busca_nula IS NULL AND uf <=> :uf_valor AND cidade <=> :cidade_valor)
                  )
            ORDER BY principal DESC, id ASC
            LIMIT 1'
        );
        $stmt->execute([
            'pessoa_id' => $pessoaId,
            'cep_busca' => $cep,
            'cep_valor' => $cep,
            'cep_busca_nula' => $cep,
            'uf_valor' => $uf,
            'cidade_valor' => $cidade,
        ]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Pre-carrega todos os contatos de um conjunto de pessoas em poucas
     * consultas (IN em lote de 500), em vez de um SELECT por contato dentro
     * de um loop por contrato.
     *
     * @param list<int> $pessoaIds
     * @return array<string,array<string,mixed>> chave pessoa_id|tipo|valor_normalizado
     */
    public function contatosPorPessoas(array $pessoaIds): array
    {
        $pessoaIds = array_values(array_unique(array_filter(array_map('intval', $pessoaIds), static fn($v) => $v > 0)));
        if ($pessoaIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($pessoaIds, 500) as $chunk) {
            $holders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->prepareCached('SELECT * FROM pessoa_contatos WHERE pessoa_id IN (' . $holders . ')');
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                $chave = $row['pessoa_id'] . '|' . $row['tipo'] . '|' . $row['valor_normalizado'];
                $out[$chave] = $row;
            }
        }
        return $out;
    }

    /**
     * Pre-carrega todos os enderecos de um conjunto de pessoas, agrupados por
     * pessoa_id, para que a logica de "endereco similar" (ver
     * encontrarEnderecoSimilar do servico de aplicacao) rode em memoria em
     * vez de um SELECT por contrato.
     *
     * @param list<int> $pessoaIds
     * @return array<int,list<array<string,mixed>>> chave pessoa_id
     */
    public function enderecosPorPessoas(array $pessoaIds): array
    {
        $pessoaIds = array_values(array_unique(array_filter(array_map('intval', $pessoaIds), static fn($v) => $v > 0)));
        if ($pessoaIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($pessoaIds, 500) as $chunk) {
            $holders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->prepareCached('SELECT * FROM pessoa_enderecos WHERE pessoa_id IN (' . $holders . ')');
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                $out[(int) $row['pessoa_id']][] = $row;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    public function createEndereco(array $data): int
    {
        $stmt = $this->prepareCached(
            'INSERT INTO pessoa_enderecos (
                pessoa_id,
                tipo,
                cep,
                uf,
                cidade,
                bairro,
                logradouro,
                numero,
                complemento,
                principal,
                origem,
                origem_carga_id,
                ultima_carga_id,
                payload_extra
            ) VALUES (
                :pessoa_id,
                :tipo,
                :cep,
                :uf,
                :cidade,
                :bairro,
                :logradouro,
                :numero,
                :complemento,
                :principal,
                :origem,
                :origem_carga_id,
                :ultima_carga_id,
                :payload_extra
            )'
        );

        $stmt->execute([
            'pessoa_id' => $data['pessoa_id'],
            'tipo' => $data['tipo'] ?? null,
            'cep' => $data['cep'] ?? null,
            'uf' => $data['uf'] ?? null,
            'cidade' => $data['cidade'] ?? null,
            'bairro' => $data['bairro'] ?? null,
            'logradouro' => $data['logradouro'] ?? null,
            'numero' => $data['numero'] ?? null,
            'complemento' => $data['complemento'] ?? null,
            'principal' => $data['principal'] ?? 0,
            'origem' => $data['origem'] ?? null,
            'origem_carga_id' => $data['origem_carga_id'] ?? null,
            'ultima_carga_id' => $data['ultima_carga_id'] ?? null,
            'payload_extra' => $this->json($data['payload_extra'] ?? null),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $fields */
    public function updateEndereco(int $enderecoId, array $fields): void
    {
        $allowed = ['tipo', 'cep', 'uf', 'cidade', 'bairro', 'logradouro', 'numero', 'complemento', 'principal', 'origem', 'ultima_carga_id', 'payload_extra'];
        $this->updateTableById('pessoa_enderecos', $enderecoId, $fields, $allowed, ['payload_extra']);
    }

    /** @return array<string,mixed>|null */
    public function findVeiculoByContrato(int $contratoId): ?array
    {
        $stmt = $this->prepareCached('SELECT * FROM contrato_veiculos WHERE contrato_id = :contrato_id ORDER BY id ASC LIMIT 1');
        $stmt->execute(['contrato_id' => $contratoId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $data */
    public function createVeiculo(array $data): int
    {
        $stmt = $this->prepareCached(
            'INSERT INTO contrato_veiculos (
                contrato_id,
                descricao,
                marca,
                modelo,
                ano_fabricacao,
                ano_modelo,
                categoria,
                placa,
                chassi,
                renavam,
                origem_carga_id,
                ultima_carga_id,
                payload_extra
            ) VALUES (
                :contrato_id,
                :descricao,
                :marca,
                :modelo,
                :ano_fabricacao,
                :ano_modelo,
                :categoria,
                :placa,
                :chassi,
                :renavam,
                :origem_carga_id,
                :ultima_carga_id,
                :payload_extra
            )'
        );

        $stmt->execute([
            'contrato_id' => $data['contrato_id'],
            'descricao' => $data['descricao'] ?? null,
            'marca' => $data['marca'] ?? null,
            'modelo' => $data['modelo'] ?? null,
            'ano_fabricacao' => $data['ano_fabricacao'] ?? null,
            'ano_modelo' => $data['ano_modelo'] ?? null,
            'categoria' => $data['categoria'] ?? null,
            'placa' => $data['placa'] ?? null,
            'chassi' => $data['chassi'] ?? null,
            'renavam' => $data['renavam'] ?? null,
            'origem_carga_id' => $data['origem_carga_id'] ?? null,
            'ultima_carga_id' => $data['ultima_carga_id'] ?? null,
            'payload_extra' => $this->json($data['payload_extra'] ?? null),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $fields */
    public function updateVeiculo(int $veiculoId, array $fields): void
    {
        $allowed = ['descricao', 'marca', 'modelo', 'ano_fabricacao', 'ano_modelo', 'categoria', 'placa', 'chassi', 'renavam', 'ultima_carga_id', 'payload_extra'];
        $this->updateTableById('contrato_veiculos', $veiculoId, $fields, $allowed, ['payload_extra']);
    }

    public function markStagingAplicado(int $stagingId, int $pessoaId, int $contratoId): void
    {
        $stmt = $this->prepareCached(
            "UPDATE stg_carga_canonica
            SET pessoa_id = :pessoa_id,
                contrato_id = :contrato_id,
                status = 'aplicado',
                erro = NULL,
                aplicado_em = CURRENT_TIMESTAMP
            WHERE id = :id"
        );
        $stmt->execute([
            'id' => $stagingId,
            'pessoa_id' => $pessoaId,
            'contrato_id' => $contratoId,
        ]);
    }

    public function markStagingErroAplicacao(int $stagingId, string $erro): void
    {
        $stmt = $this->prepareCached(
            "UPDATE stg_carga_canonica
            SET status = 'erro',
                erro = :erro,
                atualizado_em = CURRENT_TIMESTAMP
            WHERE id = :id"
        );
        $stmt->execute(['id' => $stagingId, 'erro' => $erro]);
    }

    public function updateRawStatus(int $rawId, string $status, ?string $erro = null): void
    {
        $stmt = $this->prepareCached(
            'UPDATE cargas_linhas_raw SET status = :status, erro = :erro WHERE id = :id'
        );
        $stmt->execute(['id' => $rawId, 'status' => $status, 'erro' => $erro]);
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $jsonColumns
     * @param array<string,mixed> $fields
     */
    private function updateTableById(string $table, int $id, array $fields, array $allowed, array $jsonColumns = []): void
    {
        if ($fields === []) {
            return;
        }

        $set = [];
        $params = ['id' => $id];
        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }
            $set[] = $column . ' = :' . $column;
            $params[$column] = in_array($column, $jsonColumns, true) ? $this->json($value) : $value;
        }

        if ($set === []) {
            return;
        }

        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE id = :id';
        $stmt = $this->prepareCached($sql);
        $stmt->execute($params);
    }


    /**
     * @return list<array<string,mixed>>
     */
    public function buscarCargasAcmePendentesAplicacao(int $max = 0): array
    {
        $sql = "SELECT
                ca.id,
                ca.nome_original,
                ca.status AS status_carga,
                ca.total_linhas,
                ca.linhas_validas,
                COUNT(stg.id) AS staging_validos
            FROM cargas_arquivos ca
            INNER JOIN credores cr ON cr.id = ca.credor_id
            INNER JOIN carga_layouts cl ON cl.id = ca.layout_id
            INNER JOIN stg_carga_canonica stg ON stg.carga_arquivo_id = ca.id
            WHERE ca.status = 'concluido'
              AND cr.codigo = 'ACME'
              AND cl.codigo = 'ACME_COBRANCA_PIPE'
              AND stg.status = 'valido'
            GROUP BY ca.id, ca.nome_original, ca.status, ca.total_linhas, ca.linhas_validas
            ORDER BY ca.id ASC";

        if ($max > 0) {
            $sql .= ' LIMIT :max_rows';
        }

        $stmt = $this->prepareCached($sql);
        if ($max > 0) {
            $stmt->bindValue('max_rows', $max, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function acquireNamedLock(string $name, int $timeoutSeconds = 0): bool
    {
        $stmt = $this->prepareCached('SELECT GET_LOCK(:name, :timeout_seconds)');
        $stmt->bindValue('name', $name);
        $stmt->bindValue('timeout_seconds', max(0, $timeoutSeconds), PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() === 1;
    }

    public function releaseNamedLock(string $name): void
    {
        $stmt = $this->prepareCached('SELECT RELEASE_LOCK(:name)');
        $stmt->bindValue('name', $name);
        $stmt->execute();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function colunaExiste(string $tabela, string $coluna): bool
    {
        try {
            $stmt = $this->prepareCached('SELECT 1
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :tabela
                  AND COLUMN_NAME = :coluna
                LIMIT 1');
            $stmt->execute(['tabela' => $tabela, 'coluna' => $coluna]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function json(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
