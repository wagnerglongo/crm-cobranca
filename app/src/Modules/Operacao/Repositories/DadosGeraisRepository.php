<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use CRM\Modules\Operacao\Support\EscopoCredorSql;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Consulta canonica para segmentacao de base e geracao de mailing.
 *
 * MySQL continua sendo a fonte oficial. A consulta e streaming no CSV e nao
 * materializa listas em memoria, mesmo em exportacoes grandes.
 */
final class DadosGeraisRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $filtros
     * @param array{todos?:bool,credor_ids?:array,carteira_ids?:array}|null $escopo
     * @return array{metricas:array<string,int|float>,amostra:list<array<string,mixed>>}
     */
    public function previsualizar(array $filtros, ?array $escopo = null, int $limite = 40): array
    {
        [$where, $params] = $this->montarWhere($filtros, $escopo, 'dados_preview');
        $limite = max(1, min(100, $limite));

        // A base derivada (parcelas, pagamentos, contatos, tentativas e acordos)
        // e cara. A previa usa um unico statement para que metricas e amostra
        // compartilhem o mesmo conjunto filtrado, evitando montar a CTE duas vezes.
        $sql = $this->cteBase() . ",
filtrados AS (
    SELECT cb.*
    FROM candidatos cb
    WHERE {$where}
),
metricas AS (
    SELECT
        COUNT(*) AS total_contratos,
        COUNT(DISTINCT pessoa_id) AS clientes_unicos,
        COALESCE(SUM(valor_faltante), 0) AS saldo_total,
        COALESCE(SUM(valor_original), 0) AS valor_original_total,
        COALESCE(SUM(valor_pago), 0) AS valor_pago_total,
        COALESCE(AVG(aging), 0) AS aging_medio,
        SUM(CASE WHEN telefones_validos > 0 THEN 1 ELSE 0 END) AS com_telefone,
        SUM(CASE WHEN whatsapps_validos > 0 THEN 1 ELSE 0 END) AS com_whatsapp,
        SUM(CASE WHEN (telefones_validos + whatsapps_validos) = 0 THEN 1 ELSE 0 END) AS sem_telefone,
        SUM(CASE WHEN acordos_ativos > 0 THEN 1 ELSE 0 END) AS com_acordo_ativo,
        SUM(CASE WHEN retirado_credor = 1 THEN 1 ELSE 0 END) AS retirados
    FROM filtrados
),
amostra AS (
    SELECT
        contrato_id,
        pessoa_nome AS cliente,
        pessoa_documento AS documento,
        codigo_contrato AS contrato,
        credor_nome AS credor,
        carteira_nome AS carteira,
        produto,
        subproduto,
        status_contrato,
        data_vencimento_original,
        aging,
        saldo_atual,
        valor_original,
        valor_pago,
        valor_faltante,
        quantidade_tentativas,
        ultima_tentativa,
        ultimo_resultado_nome,
        telefone_1,
        whatsapp_1,
        email_1,
        uf,
        cidade,
        retirado_credor
    FROM filtrados
    ORDER BY aging DESC, valor_faltante DESC, contrato_id ASC
    LIMIT :dados_preview_limite
)
SELECT
    m.total_contratos AS __total_contratos,
    m.clientes_unicos AS __clientes_unicos,
    m.saldo_total AS __saldo_total,
    m.valor_original_total AS __valor_original_total,
    m.valor_pago_total AS __valor_pago_total,
    m.aging_medio AS __aging_medio,
    m.com_telefone AS __com_telefone,
    m.com_whatsapp AS __com_whatsapp,
    m.sem_telefone AS __sem_telefone,
    m.com_acordo_ativo AS __com_acordo_ativo,
    m.retirados AS __retirados,
    a.*
FROM metricas m
LEFT JOIN amostra a ON 1 = 1
ORDER BY a.aging DESC, a.valor_faltante DESC, a.contrato_id ASC";

        $stmt = $this->pdo->prepare($sql);
        $this->bind($stmt, $params);
        $stmt->bindValue(':dados_preview_limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $first = $rows[0] ?? [];

        $amostra = [];
        foreach ($rows as $row) {
            if (!isset($row['contrato_id']) || $row['contrato_id'] === null) {
                continue;
            }
            foreach (array_keys($row) as $key) {
                if (str_starts_with((string) $key, '__')) {
                    unset($row[$key]);
                }
            }
            $amostra[] = $row;
        }

        return [
            'metricas' => [
                'total_contratos' => (int) ($first['__total_contratos'] ?? 0),
                'clientes_unicos' => (int) ($first['__clientes_unicos'] ?? 0),
                'saldo_total' => (float) ($first['__saldo_total'] ?? 0),
                'valor_original_total' => (float) ($first['__valor_original_total'] ?? 0),
                'valor_pago_total' => (float) ($first['__valor_pago_total'] ?? 0),
                'aging_medio' => (float) ($first['__aging_medio'] ?? 0),
                'com_telefone' => (int) ($first['__com_telefone'] ?? 0),
                'com_whatsapp' => (int) ($first['__com_whatsapp'] ?? 0),
                'sem_telefone' => (int) ($first['__sem_telefone'] ?? 0),
                'com_acordo_ativo' => (int) ($first['__com_acordo_ativo'] ?? 0),
                'retirados' => (int) ($first['__retirados'] ?? 0),
            ],
            'amostra' => $amostra,
        ];
    }

    /**
     * @param array<string,mixed> $filtros
     * @param array{todos?:bool,credor_ids?:array,carteira_ids?:array}|null $escopo
     * @return array{stmt:PDOStatement,colunas:array<string,string>,arquivo:string,limite:int}
     */
    public function prepararExportacao(array $filtros, ?array $escopo, string $formato): array
    {
        if (!in_array($formato, ['mailing', 'base'], true)) {
            throw new RuntimeException('Formato de exportacao invalido.');
        }

        [$where, $params] = $this->montarWhere($filtros, $escopo, 'dados_export');
        $limite = $this->maxRows();
        $this->habilitarStreamingMysql();
        [$select, $colunas] = $formato === 'mailing'
            ? $this->selectMailing()
            : $this->selectBase();

        $sql = $this->cteBase()
            . "\nSELECT " . implode(",\n", $select)
            . "\nFROM candidatos cb"
            . "\nWHERE {$where}"
            . "\nORDER BY cb.aging DESC, cb.valor_faltante DESC, cb.contrato_id ASC"
            . "\nLIMIT {$limite}";

        $stmt = $this->pdo->prepare($sql);
        $this->bind($stmt, $params);
        $stmt->execute();

        return [
            'stmt' => $stmt,
            'colunas' => $colunas,
            'arquivo' => 'works_' . ($formato === 'mailing' ? 'mailing_discadora' : 'base_geral') . '_' . date('Y-m-d_His') . '.csv',
            'limite' => $limite,
        ];
    }

    /** @return array{0:list<string>,1:array<string,string>} */
    private function selectMailing(): array
    {
        $select = [
            'cb.pessoa_nome AS cliente',
            'cb.pessoa_documento AS documento',
            'cb.codigo_contrato AS contrato',
            'cb.credor_nome AS credor',
            'cb.carteira_nome AS carteira',
            'cb.produto',
            'cb.subproduto',
            'cb.status_contrato',
            'cb.data_vencimento_original AS vencimento_original',
            'cb.aging',
            'cb.saldo_considerado AS saldo_atual',
            'cb.valor_original',
            'cb.valor_pago',
            'cb.valor_faltante',
            'cb.telefone_1', 'cb.telefone_2', 'cb.telefone_3', 'cb.telefone_4', 'cb.telefone_5', 'cb.telefone_6',
            'cb.whatsapp_1', 'cb.whatsapp_2', 'cb.whatsapp_3',
            'cb.email_1', 'cb.email_2',
            'cb.uf', 'cb.cidade',
            'cb.quantidade_tentativas',
            'cb.ultima_tentativa',
            'cb.ultimo_resultado_nome AS ultimo_resultado',
            'cb.ultimo_status_acordo',
            'CASE WHEN cb.retirado_credor = 1 THEN \'SIM\' ELSE \'NAO\' END AS retirado_credor',
        ];

        $colunas = [
            'cliente' => 'Cliente', 'documento' => 'CPF/CNPJ', 'contrato' => 'Contrato',
            'credor' => 'Credor', 'carteira' => 'Carteira', 'produto' => 'Produto', 'subproduto' => 'Subproduto',
            'status_contrato' => 'Status contrato', 'vencimento_original' => 'Vencimento original', 'aging' => 'Aging',
            'saldo_atual' => 'Saldo atual', 'valor_original' => 'Valor original', 'valor_pago' => 'Valor pago',
            'valor_faltante' => 'Valor faltante',
            'telefone_1' => 'Telefone 1', 'telefone_2' => 'Telefone 2', 'telefone_3' => 'Telefone 3',
            'telefone_4' => 'Telefone 4', 'telefone_5' => 'Telefone 5', 'telefone_6' => 'Telefone 6',
            'whatsapp_1' => 'WhatsApp 1', 'whatsapp_2' => 'WhatsApp 2', 'whatsapp_3' => 'WhatsApp 3',
            'email_1' => 'Email 1', 'email_2' => 'Email 2', 'uf' => 'UF', 'cidade' => 'Cidade',
            'quantidade_tentativas' => 'Qtd tentativas', 'ultima_tentativa' => 'Ultima tentativa',
            'ultimo_resultado' => 'Ultimo resultado', 'ultimo_status_acordo' => 'Ultimo status acordo',
            'retirado_credor' => 'Retirado pelo credor',
        ];

        return [$select, $colunas];
    }

    /** @return array{0:list<string>,1:array<string,string>} */
    private function selectBase(): array
    {
        $select = [
            'cb.contrato_id', 'cb.pessoa_id', 'cb.credor_id', 'cb.carteira_id',
            'cb.pessoa_nome AS cliente', 'cb.pessoa_documento AS documento', 'cb.tipo_documento',
            'cb.nome_mae', 'cb.nome_pai', 'cb.data_nascimento', 'cb.sexo', 'cb.estado_civil', 'cb.rg', 'cb.renda_mensal',
            'cb.codigo_contrato AS contrato', 'cb.conta_id', 'cb.devedor_origem_id', 'cb.credor_nome AS credor', 'cb.carteira_nome AS carteira',
            'cb.produto', 'cb.subproduto', 'cb.lote', 'cb.fila', 'cb.status_devedor', 'cb.status_contrato',
            'cb.filial_codigo', 'cb.filial_nome', 'cb.unidade_cobranca', 'cb.moeda', 'cb.indice', 'cb.numero_cartao_mascarado',
            'cb.data_corte', 'cb.data_bordero', 'cb.data_liberacao',
            'cb.data_entrada_base', 'cb.contrato_atualizado_em', 'cb.data_vencimento_original', 'cb.aging',
            'cb.saldo_atual', 'cb.saldo_contabil', 'cb.saldo_considerado', 'cb.valor_original', 'cb.valor_pago', 'cb.valor_faltante',
            'cb.valor_principal', 'cb.valor_financiado', 'cb.valor_contratado', 'cb.valor_iof', 'cb.valor_tac', 'cb.valor_encargos', 'cb.valor_mensal',
            'cb.taxa_pactuada', 'cb.taxa_tir', 'cb.cet_anual', 'cb.cet_mensal',
            'cb.total_parcelas', 'cb.parcelas_pagas', 'cb.parcelas_abertas',
            'cb.data_ultimo_pagamento', 'cb.quantidade_tentativas', 'cb.ultima_tentativa',
            'cb.ultimo_resultado_id', 'cb.ultimo_resultado_nome AS ultimo_resultado',
            'cb.total_acordos', 'cb.acordos_ativos', 'cb.acordos_quebrados', 'cb.acordos_cumpridos', 'cb.acordos_cancelados',
            'cb.ultimo_status_acordo',
            'cb.telefones_validos', 'cb.whatsapps_validos', 'cb.emails_validos',
            'cb.telefone_1', 'cb.telefone_2', 'cb.telefone_3', 'cb.telefone_4', 'cb.telefone_5', 'cb.telefone_6',
            'cb.whatsapp_1', 'cb.whatsapp_2', 'cb.whatsapp_3', 'cb.email_1', 'cb.email_2',
            'cb.cep', 'cb.uf', 'cb.cidade', 'cb.bairro', 'cb.logradouro', 'cb.numero', 'cb.complemento',
            'cb.ultima_carga_id', 'cb.ultima_carga_data', 'cb.ultima_carga_arquivo', 'cb.ultima_carga_layout_id',
            'CASE WHEN cb.retirado_credor = 1 THEN \'SIM\' ELSE \'NAO\' END AS retirado_credor',
        ];

        $colunas = [];
        foreach ([
            'contrato_id' => 'Contrato ID', 'pessoa_id' => 'Pessoa ID', 'credor_id' => 'Credor ID', 'carteira_id' => 'Carteira ID',
            'cliente' => 'Cliente', 'documento' => 'CPF/CNPJ', 'tipo_documento' => 'Tipo documento',
            'nome_mae' => 'Nome mae', 'nome_pai' => 'Nome pai', 'data_nascimento' => 'Data nascimento', 'sexo' => 'Sexo', 'estado_civil' => 'Estado civil', 'rg' => 'RG', 'renda_mensal' => 'Renda mensal',
            'contrato' => 'Contrato', 'conta_id' => 'Conta ID', 'devedor_origem_id' => 'Devedor origem ID', 'credor' => 'Credor', 'carteira' => 'Carteira',
            'produto' => 'Produto', 'subproduto' => 'Subproduto', 'lote' => 'Lote', 'fila' => 'Fila',
            'status_devedor' => 'Status devedor', 'status_contrato' => 'Status contrato', 'filial_codigo' => 'Filial codigo',
            'filial_nome' => 'Filial', 'unidade_cobranca' => 'Unidade cobranca', 'moeda' => 'Moeda', 'indice' => 'Indice', 'numero_cartao_mascarado' => 'Cartao mascarado',
            'data_corte' => 'Data corte', 'data_bordero' => 'Data bordero', 'data_liberacao' => 'Data liberacao',
            'data_entrada_base' => 'Entrada na base', 'contrato_atualizado_em' => 'Atualizado em',
            'data_vencimento_original' => 'Vencimento original', 'aging' => 'Aging', 'saldo_atual' => 'Saldo atual bruto',
            'saldo_contabil' => 'Saldo contabil', 'saldo_considerado' => 'Saldo considerado', 'valor_original' => 'Valor original', 'valor_pago' => 'Valor pago',
            'valor_faltante' => 'Valor faltante', 'valor_principal' => 'Valor principal', 'valor_financiado' => 'Valor financiado',
            'valor_contratado' => 'Valor contratado', 'valor_iof' => 'Valor IOF', 'valor_tac' => 'Valor TAC', 'valor_encargos' => 'Valor encargos', 'valor_mensal' => 'Valor mensal',
            'taxa_pactuada' => 'Taxa pactuada', 'taxa_tir' => 'Taxa TIR', 'cet_anual' => 'CET anual', 'cet_mensal' => 'CET mensal',
            'total_parcelas' => 'Total parcelas', 'parcelas_pagas' => 'Parcelas pagas', 'parcelas_abertas' => 'Parcelas abertas',
            'data_ultimo_pagamento' => 'Ultimo pagamento', 'quantidade_tentativas' => 'Qtd tentativas',
            'ultima_tentativa' => 'Ultima tentativa', 'ultimo_resultado_id' => 'Ultimo resultado ID', 'ultimo_resultado' => 'Ultimo resultado',
            'total_acordos' => 'Total acordos', 'acordos_ativos' => 'Acordos ativos', 'acordos_quebrados' => 'Acordos quebrados',
            'acordos_cumpridos' => 'Acordos cumpridos', 'acordos_cancelados' => 'Acordos cancelados', 'ultimo_status_acordo' => 'Ultimo status acordo',
            'telefones_validos' => 'Telefones validos', 'whatsapps_validos' => 'WhatsApps validos', 'emails_validos' => 'Emails validos',
            'telefone_1' => 'Telefone 1', 'telefone_2' => 'Telefone 2', 'telefone_3' => 'Telefone 3',
            'telefone_4' => 'Telefone 4', 'telefone_5' => 'Telefone 5', 'telefone_6' => 'Telefone 6',
            'whatsapp_1' => 'WhatsApp 1', 'whatsapp_2' => 'WhatsApp 2', 'whatsapp_3' => 'WhatsApp 3',
            'email_1' => 'Email 1', 'email_2' => 'Email 2', 'cep' => 'CEP', 'uf' => 'UF', 'cidade' => 'Cidade',
            'bairro' => 'Bairro', 'logradouro' => 'Logradouro', 'numero' => 'Numero', 'complemento' => 'Complemento',
            'ultima_carga_id' => 'Ultima carga ID', 'ultima_carga_data' => 'Data ultima carga', 'ultima_carga_arquivo' => 'Arquivo ultima carga', 'ultima_carga_layout_id' => 'Layout ultima carga ID', 'retirado_credor' => 'Retirado pelo credor',
        ] as $key => $label) {
            $colunas[$key] = $label;
        }

        return [$select, $colunas];
    }

    /**
     * CTE compartilhada pela previa e pela exportacao.
     */
    private function cteBase(): string
    {
        return <<<'SQL'
WITH cargas_finalizadas AS (
    SELECT
        ca.id,
        ca.credor_id,
        ca.carteira_id,
        ca.layout_id,
        COALESCE(ca.data_referencia, DATE(ca.aplicacao_finalizada_em)) AS data_negocio,
        ca.aplicacao_finalizada_em,
        ROW_NUMBER() OVER (
            PARTITION BY ca.credor_id, ca.carteira_id, ca.layout_id
            ORDER BY COALESCE(ca.data_referencia, DATE(ca.aplicacao_finalizada_em)) DESC, ca.aplicacao_finalizada_em DESC, ca.id DESC
        ) AS rn_layout,
        ROW_NUMBER() OVER (
            PARTITION BY ca.credor_id, ca.carteira_id
            ORDER BY COALESCE(ca.data_referencia, DATE(ca.aplicacao_finalizada_em)) DESC, ca.aplicacao_finalizada_em DESC, ca.id DESC
        ) AS rn_geral
    FROM cargas_arquivos ca
    WHERE ca.aplicacao_finalizada_em IS NOT NULL
),
parcelas AS (
    SELECT
        contrato_id,
        MIN(data_vencimento) AS vencimento_original,
        COUNT(*) AS total_parcelas,
        SUM(CASE WHEN flag_liquidado = 1 OR status_parcela IN ('paga','pago','liquidada','liquidado') THEN 1 ELSE 0 END) AS parcelas_pagas,
        SUM(CASE WHEN COALESCE(flag_liquidado, 0) = 0 AND COALESCE(status_parcela, '') NOT IN ('paga','pago','liquidada','liquidado') THEN 1 ELSE 0 END) AS parcelas_abertas,
        SUM(COALESCE(valor_original, 0)) AS valor_original_parcelas
    FROM contrato_parcelas
    GROUP BY contrato_id
),
pagamentos_base AS (
    SELECT
        contrato_id,
        SUM(GREATEST(COALESCE(valor_recebido, 0), COALESCE(valor_pago, 0))) AS valor_pago_importado,
        MAX(COALESCE(data_pagamento, data_baixa, data_inclusao)) AS data_ultimo_pagamento
    FROM pagamentos
    WHERE contrato_id IS NOT NULL
    GROUP BY contrato_id
),
atend_contrato AS (
    SELECT
        ah.contrato_id,
        COUNT(*) AS quantidade_tentativas,
        MAX(ah.criado_em) AS ultima_tentativa,
        CAST(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(ah.resultado_id, 0) ORDER BY ah.criado_em DESC, ah.id DESC), ',', 1), '0') AS UNSIGNED) AS ultimo_resultado_id,
        NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(ar.nome, '') ORDER BY ah.criado_em DESC, ah.id DESC SEPARATOR '||'), '||', 1), '') AS ultimo_resultado_nome
    FROM atendimentos_historico ah
    LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id
    WHERE ah.contrato_id IS NOT NULL
    GROUP BY ah.contrato_id
),
atend_pessoa AS (
    SELECT
        ah.pessoa_id,
        COUNT(*) AS quantidade_tentativas,
        MAX(ah.criado_em) AS ultima_tentativa,
        CAST(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(ah.resultado_id, 0) ORDER BY ah.criado_em DESC, ah.id DESC), ',', 1), '0') AS UNSIGNED) AS ultimo_resultado_id,
        NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(ar.nome, '') ORDER BY ah.criado_em DESC, ah.id DESC SEPARATOR '||'), '||', 1), '') AS ultimo_resultado_nome
    FROM atendimentos_historico ah
    LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id
    WHERE ah.contrato_id IS NULL
    GROUP BY ah.pessoa_id
),
contatos_ranked AS (
    SELECT
        pc.pessoa_id,
        pc.tipo,
        CASE WHEN pc.tipo = 'email' THEN COALESCE(NULLIF(pc.email, ''), NULLIF(pc.valor_normalizado, ''), pc.valor) ELSE pc.valor_normalizado END AS contato_valor,
        ROW_NUMBER() OVER (
            PARTITION BY pc.pessoa_id, pc.tipo
            ORDER BY pc.principal DESC,
                CASE pc.status WHEN 'hot' THEN 0 WHEN 'correto' THEN 1 WHEN 'novo' THEN 2 WHEN 'nao_confirmado' THEN 3 WHEN 'desconhecido' THEN 4 ELSE 5 END,
                pc.id DESC
        ) AS rn
    FROM pessoa_contatos pc
    WHERE pc.status NOT IN ('incorreto','bloqueado')
      AND (
        (pc.tipo IN ('telefone','whatsapp') AND CHAR_LENGTH(COALESCE(pc.valor_normalizado, '')) >= 8)
        OR (pc.tipo = 'email' AND COALESCE(NULLIF(pc.email, ''), NULLIF(pc.valor_normalizado, ''), NULLIF(pc.valor, '')) IS NOT NULL)
      )
),
contatos AS (
    SELECT
        pessoa_id,
        SUM(CASE WHEN tipo = 'telefone' THEN 1 ELSE 0 END) AS telefones_validos,
        SUM(CASE WHEN tipo = 'whatsapp' THEN 1 ELSE 0 END) AS whatsapps_validos,
        SUM(CASE WHEN tipo = 'email' THEN 1 ELSE 0 END) AS emails_validos,
        MAX(CASE WHEN tipo = 'telefone' AND rn = 1 THEN contato_valor END) AS telefone_1,
        MAX(CASE WHEN tipo = 'telefone' AND rn = 2 THEN contato_valor END) AS telefone_2,
        MAX(CASE WHEN tipo = 'telefone' AND rn = 3 THEN contato_valor END) AS telefone_3,
        MAX(CASE WHEN tipo = 'telefone' AND rn = 4 THEN contato_valor END) AS telefone_4,
        MAX(CASE WHEN tipo = 'telefone' AND rn = 5 THEN contato_valor END) AS telefone_5,
        MAX(CASE WHEN tipo = 'telefone' AND rn = 6 THEN contato_valor END) AS telefone_6,
        MAX(CASE WHEN tipo = 'whatsapp' AND rn = 1 THEN contato_valor END) AS whatsapp_1,
        MAX(CASE WHEN tipo = 'whatsapp' AND rn = 2 THEN contato_valor END) AS whatsapp_2,
        MAX(CASE WHEN tipo = 'whatsapp' AND rn = 3 THEN contato_valor END) AS whatsapp_3,
        MAX(CASE WHEN tipo = 'email' AND rn = 1 THEN contato_valor END) AS email_1,
        MAX(CASE WHEN tipo = 'email' AND rn = 2 THEN contato_valor END) AS email_2
    FROM contatos_ranked
    GROUP BY pessoa_id
),
enderecos_ranked AS (
    SELECT
        pe.*,
        ROW_NUMBER() OVER (PARTITION BY pe.pessoa_id ORDER BY pe.principal DESC, pe.id DESC) AS rn
    FROM pessoa_enderecos pe
),
enderecos AS (
    SELECT
        pessoa_id,
        MAX(CASE WHEN rn = 1 THEN cep END) AS cep,
        MAX(CASE WHEN rn = 1 THEN uf END) AS uf,
        MAX(CASE WHEN rn = 1 THEN cidade END) AS cidade,
        MAX(CASE WHEN rn = 1 THEN bairro END) AS bairro,
        MAX(CASE WHEN rn = 1 THEN logradouro END) AS logradouro,
        MAX(CASE WHEN rn = 1 THEN numero END) AS numero,
        MAX(CASE WHEN rn = 1 THEN complemento END) AS complemento
    FROM enderecos_ranked
    GROUP BY pessoa_id
),
acordos_base AS (
    SELECT
        contrato_id,
        COUNT(*) AS total_acordos,
        SUM(CASE WHEN status_operacional IN ('pendente','formalizado') THEN 1 ELSE 0 END) AS acordos_ativos,
        SUM(CASE WHEN status_operacional = 'quebrado' THEN 1 ELSE 0 END) AS acordos_quebrados,
        SUM(CASE WHEN status_operacional = 'cumprido' THEN 1 ELSE 0 END) AS acordos_cumpridos,
        SUM(CASE WHEN status_operacional = 'cancelado' THEN 1 ELSE 0 END) AS acordos_cancelados,
        SUBSTRING_INDEX(GROUP_CONCAT(status_operacional ORDER BY criado_em DESC, id DESC), ',', 1) AS ultimo_status_acordo
    FROM acordos_operacionais
    WHERE contrato_id IS NOT NULL
    GROUP BY contrato_id
),
contrato_base AS (
    SELECT
        co.id AS contrato_id,
        co.pessoa_id,
        co.credor_id,
        co.carteira_id,
        co.codigo_contrato,
        co.conta_id,
        co.devedor_origem_id,
        co.produto,
        co.subproduto,
        co.lote,
        co.fila,
        co.status_devedor,
        co.status_contrato,
        co.filial_codigo,
        co.filial_nome,
        co.unidade_cobranca,
        co.moeda,
        co.indice,
        co.numero_cartao_mascarado,
        co.data_corte,
        co.data_bordero,
        co.data_liberacao,
        co.dias_atraso AS dias_atraso_carga,
        co.saldo_atual,
        co.saldo_contabil,
        co.valor_total_pago AS valor_total_pago_carga,
        co.valor_principal,
        co.valor_financiado,
        co.valor_contratado,
        co.valor_iof,
        co.valor_tac,
        co.valor_encargos,
        co.valor_mensal,
        co.taxa_pactuada,
        co.taxa_tir,
        co.cet_anual,
        co.cet_mensal,
        co.atualizado_em AS contrato_atualizado_em,
        co.ultima_carga_id,
        p.nome AS pessoa_nome,
        p.documento AS pessoa_documento,
        p.tipo_documento,
        p.nome_mae,
        p.nome_pai,
        p.data_nascimento,
        p.sexo,
        p.estado_civil,
        p.rg,
        p.renda_mensal,
        cr.nome AS credor_nome,
        car.nome AS carteira_nome,
        COALESCE(carga_origem.recebido_em, co.criado_em) AS data_entrada_base,
        COALESCE(co.data_vencimento_inicial, parcelas.vencimento_original) AS data_vencimento_original,
        COALESCE(parcelas.total_parcelas, 0) AS total_parcelas,
        COALESCE(parcelas.parcelas_pagas, 0) AS parcelas_pagas,
        COALESCE(parcelas.parcelas_abertas, 0) AS parcelas_abertas,
        COALESCE(parcelas.valor_original_parcelas, 0) AS valor_original_parcelas,
        COALESCE(pagamentos_base.valor_pago_importado, 0) AS valor_pago_importado,
        pagamentos_base.data_ultimo_pagamento,
        COALESCE(atend_contrato.quantidade_tentativas, atend_pessoa.quantidade_tentativas, 0) AS quantidade_tentativas,
        COALESCE(atend_contrato.ultima_tentativa, atend_pessoa.ultima_tentativa) AS ultima_tentativa,
        COALESCE(atend_contrato.ultimo_resultado_id, atend_pessoa.ultimo_resultado_id) AS ultimo_resultado_id,
        COALESCE(atend_contrato.ultimo_resultado_nome, atend_pessoa.ultimo_resultado_nome) AS ultimo_resultado_nome,
        COALESCE(contatos.telefones_validos, 0) AS telefones_validos,
        COALESCE(contatos.whatsapps_validos, 0) AS whatsapps_validos,
        COALESCE(contatos.emails_validos, 0) AS emails_validos,
        contatos.telefone_1, contatos.telefone_2, contatos.telefone_3, contatos.telefone_4, contatos.telefone_5, contatos.telefone_6,
        contatos.whatsapp_1, contatos.whatsapp_2, contatos.whatsapp_3,
        contatos.email_1, contatos.email_2,
        enderecos.cep, enderecos.uf, enderecos.cidade, enderecos.bairro, enderecos.logradouro, enderecos.numero, enderecos.complemento,
        COALESCE(acordos_base.total_acordos, 0) AS total_acordos,
        COALESCE(acordos_base.acordos_ativos, 0) AS acordos_ativos,
        COALESCE(acordos_base.acordos_quebrados, 0) AS acordos_quebrados,
        COALESCE(acordos_base.acordos_cumpridos, 0) AS acordos_cumpridos,
        COALESCE(acordos_base.acordos_cancelados, 0) AS acordos_cancelados,
        acordos_base.ultimo_status_acordo,
        COALESCE(ca_ult.data_referencia, DATE(ca_ult.finalizado_em), DATE(ca_ult.atualizado_em), DATE(ca_ult.criado_em)) AS ultima_carga_data,
        ca_ult.nome_original AS ultima_carga_arquivo,
        ca_ult.layout_id AS ultima_carga_layout_id,
        CASE
            WHEN co.status_contrato = 'retirado_pelo_credor' THEN 1
            WHEN co.ultima_carga_id IS NOT NULL
             AND COALESCE(ref_layout.data_negocio, ref_geral.data_negocio) IS NOT NULL
             AND COALESCE(ca_ult.data_referencia, DATE(ca_ult.finalizado_em), DATE(ca_ult.atualizado_em), DATE(ca_ult.criado_em)) IS NOT NULL
             AND COALESCE(ref_layout.data_negocio, ref_geral.data_negocio) > COALESCE(ca_ult.data_referencia, DATE(ca_ult.finalizado_em), DATE(ca_ult.atualizado_em), DATE(ca_ult.criado_em))
            THEN 1 ELSE 0
        END AS retirado_credor
    FROM contratos co
    INNER JOIN pessoas p ON p.id = co.pessoa_id
    INNER JOIN credores cr ON cr.id = co.credor_id
    LEFT JOIN carteiras car ON car.id = co.carteira_id
    LEFT JOIN cargas_arquivos carga_origem ON carga_origem.id = co.origem_carga_id
    LEFT JOIN cargas_arquivos ca_ult ON ca_ult.id = co.ultima_carga_id
    LEFT JOIN cargas_finalizadas ref_layout
        ON ref_layout.credor_id = co.credor_id
       AND (ref_layout.carteira_id <=> co.carteira_id)
       AND ca_ult.layout_id IS NOT NULL
       AND (ref_layout.layout_id <=> ca_ult.layout_id)
       AND ref_layout.rn_layout = 1
    LEFT JOIN cargas_finalizadas ref_geral
        ON ref_geral.credor_id = co.credor_id
       AND (ref_geral.carteira_id <=> co.carteira_id)
       AND ref_geral.rn_geral = 1
       AND ca_ult.layout_id IS NULL
    LEFT JOIN parcelas ON parcelas.contrato_id = co.id
    LEFT JOIN pagamentos_base ON pagamentos_base.contrato_id = co.id
    LEFT JOIN atend_contrato ON atend_contrato.contrato_id = co.id
    LEFT JOIN atend_pessoa ON atend_pessoa.pessoa_id = co.pessoa_id AND atend_contrato.contrato_id IS NULL
    LEFT JOIN contatos ON contatos.pessoa_id = co.pessoa_id
    LEFT JOIN enderecos ON enderecos.pessoa_id = co.pessoa_id
    LEFT JOIN acordos_base ON acordos_base.contrato_id = co.id
),
valores AS (
    SELECT
        b.*,
        CASE
            WHEN b.data_vencimento_original IS NOT NULL THEN GREATEST(DATEDIFF(CURDATE(), b.data_vencimento_original), 0)
            ELSE GREATEST(COALESCE(b.dias_atraso_carga, 0), 0)
        END AS aging,
        GREATEST(COALESCE(b.saldo_atual, b.saldo_contabil, 0), 0) AS saldo_considerado,
        GREATEST(COALESCE(b.valor_total_pago_carga, 0), COALESCE(b.valor_pago_importado, 0), 0) AS valor_pago,
        GREATEST(COALESCE(
            NULLIF(b.valor_contratado, 0),
            NULLIF(b.valor_financiado, 0),
            NULLIF(b.valor_principal, 0),
            NULLIF(b.valor_original_parcelas, 0),
            NULLIF(COALESCE(b.saldo_atual, 0) + GREATEST(COALESCE(b.valor_total_pago_carga, 0), COALESCE(b.valor_pago_importado, 0), 0), 0),
            NULLIF(b.saldo_contabil, 0),
            0
        ), 0) AS valor_original
    FROM contrato_base b
),
candidatos AS (
    SELECT
        v.*,
        GREATEST(COALESCE(v.saldo_atual, v.saldo_contabil, v.valor_original - v.valor_pago, 0), 0) AS valor_faltante
    FROM valores v
)
SQL;
    }

    /**
     * @param array<string,mixed> $filtros
     * @param array{todos?:bool,credor_ids?:array,carteira_ids?:array}|null $escopo
     * @return array{0:string,1:array<string,mixed>}
     */
    private function montarWhere(array $filtros, ?array $escopo, string $prefix): array
    {
        $where = [];
        $params = [];
        $scope = EscopoCredorSql::contrato($escopo, 'cb', $prefix . '_scope');
        $where[] = $scope['sql'];
        $params += $scope['params'];

        $situacao = (string) ($filtros['situacao_base'] ?? 'ativos');
        if ($situacao === 'ativos') {
            $where[] = 'cb.retirado_credor = 0';
        } elseif ($situacao === 'retirados') {
            $where[] = 'cb.retirado_credor = 1';
        }

        $add = static function (array &$where, array &$params, string $sql, string $key, mixed $value): void {
            $where[] = $sql;
            $params[$key] = $value;
        };

        if (isset($filtros['credor_id'])) {
            $add($where, $params, 'cb.credor_id = :' . $prefix . '_credor', $prefix . '_credor', (int) $filtros['credor_id']);
        }
        if (isset($filtros['carteira_id'])) {
            $add($where, $params, 'cb.carteira_id = :' . $prefix . '_carteira', $prefix . '_carteira', (int) $filtros['carteira_id']);
        }

        foreach ([
            'produto' => 'cb.produto', 'subproduto' => 'cb.subproduto', 'codigo_contrato' => 'cb.codigo_contrato',
            'conta_id' => 'cb.conta_id', 'lote' => 'cb.lote', 'fila' => 'cb.fila', 'status_devedor' => 'cb.status_devedor',
            'filial' => 'cb.filial_nome', 'unidade_cobranca' => 'cb.unidade_cobranca', 'cliente_nome' => 'cb.pessoa_nome',
        ] as $key => $expr) {
            if (isset($filtros[$key])) {
                $param = $prefix . '_' . $key;
                $add($where, $params, $expr . ' LIKE :' . $param, $param, '%' . $filtros[$key] . '%');
            }
        }
        if (isset($filtros['status_contrato'])) {
            $add($where, $params, 'cb.status_contrato = :' . $prefix . '_status_contrato', $prefix . '_status_contrato', $filtros['status_contrato']);
        }
        if (isset($filtros['documento'])) {
            $add($where, $params, 'cb.pessoa_documento = :' . $prefix . '_documento', $prefix . '_documento', preg_replace('/\D+/', '', (string) $filtros['documento']) ?: (string) $filtros['documento']);
        }

        $this->faixaData($where, $params, $filtros['data_entrada'] ?? null, 'DATE(cb.data_entrada_base)', $prefix . '_entrada');
        $this->faixaData($where, $params, $filtros['data_atualizacao'] ?? null, 'DATE(cb.contrato_atualizado_em)', $prefix . '_atualizacao');
        $this->faixaData($where, $params, $filtros['vencimento_original'] ?? null, 'cb.data_vencimento_original', $prefix . '_vencimento');
        $this->faixaData($where, $params, $filtros['data_ultima_tentativa'] ?? null, 'DATE(cb.ultima_tentativa)', $prefix . '_tentativa');
        $this->faixaData($where, $params, $filtros['data_ultimo_pagamento'] ?? null, 'cb.data_ultimo_pagamento', $prefix . '_pagamento');

        $this->faixaNumero($where, $params, $filtros['dia_vencimento'] ?? null, 'DAY(cb.data_vencimento_original)', $prefix . '_dia_vencimento');
        $this->faixaNumero($where, $params, $filtros['aging'] ?? null, 'cb.aging', $prefix . '_aging');
        $this->faixaNumero($where, $params, $filtros['saldo_atual'] ?? null, 'cb.saldo_considerado', $prefix . '_saldo_atual');
        $this->faixaNumero($where, $params, $filtros['valor_original'] ?? null, 'cb.valor_original', $prefix . '_valor_original');
        $this->faixaNumero($where, $params, $filtros['valor_pago'] ?? null, 'cb.valor_pago', $prefix . '_valor_pago');
        $this->faixaNumero($where, $params, $filtros['valor_faltante'] ?? null, 'cb.valor_faltante', $prefix . '_valor_faltante');
        $this->faixaNumero($where, $params, $filtros['quantidade_tentativas'] ?? null, 'cb.quantidade_tentativas', $prefix . '_tentativas');
        $this->faixaNumero($where, $params, $filtros['total_parcelas'] ?? null, 'cb.total_parcelas', $prefix . '_parcelas');

        if (isset($filtros['situacao_tentativas'])) {
            $where[] = $filtros['situacao_tentativas'] === 'nunca' ? 'cb.quantidade_tentativas = 0' : 'cb.quantidade_tentativas > 0';
        }
        if (isset($filtros['dias_sem_tentativa']) && is_array($filtros['dias_sem_tentativa'])) {
            $faixa = $filtros['dias_sem_tentativa'];
            if (($faixa['min'] ?? null) !== null) {
                $key = $prefix . '_dias_sem_min';
                $where[] = !empty($faixa['incluir_nunca'])
                    ? '(cb.ultima_tentativa IS NULL OR DATEDIFF(CURDATE(), DATE(cb.ultima_tentativa)) >= :' . $key . ')'
                    : 'cb.ultima_tentativa IS NOT NULL AND DATEDIFF(CURDATE(), DATE(cb.ultima_tentativa)) >= :' . $key;
                $params[$key] = (int) $faixa['min'];
            }
            if (($faixa['max'] ?? null) !== null) {
                $key = $prefix . '_dias_sem_max';
                $where[] = 'cb.ultima_tentativa IS NOT NULL AND DATEDIFF(CURDATE(), DATE(cb.ultima_tentativa)) <= :' . $key;
                $params[$key] = (int) $faixa['max'];
            }
        }
        if (isset($filtros['ultimo_resultado_id'])) {
            $add($where, $params, 'cb.ultimo_resultado_id = :' . $prefix . '_resultado', $prefix . '_resultado', (int) $filtros['ultimo_resultado_id']);
        }
        if (isset($filtros['situacao_acordo'])) {
            $where[] = match ($filtros['situacao_acordo']) {
                'sem_acordo' => 'cb.total_acordos = 0',
                'sem_ativo' => 'cb.acordos_ativos = 0',
                'ativo' => 'cb.acordos_ativos > 0',
                'quebrado' => 'cb.acordos_quebrados > 0',
                'cumprido' => 'cb.acordos_cumpridos > 0',
                'cancelado' => 'cb.acordos_cancelados > 0',
                default => '1=1',
            };
        }
        if (isset($filtros['tipo_contato'])) {
            $where[] = match ($filtros['tipo_contato']) {
                'telefone_valido' => '(cb.telefones_validos + cb.whatsapps_validos) > 0',
                'sem_telefone_valido' => '(cb.telefones_validos + cb.whatsapps_validos) = 0',
                'whatsapp' => 'cb.whatsapps_validos > 0',
                'email' => 'cb.emails_validos > 0',
                'qualquer_valido' => '(cb.telefones_validos + cb.whatsapps_validos + cb.emails_validos) > 0',
                default => '1=1',
            };
        }
        if (isset($filtros['uf'])) {
            $add($where, $params, 'cb.uf = :' . $prefix . '_uf', $prefix . '_uf', $filtros['uf']);
        }
        if (isset($filtros['cidade'])) {
            $add($where, $params, 'cb.cidade LIKE :' . $prefix . '_cidade', $prefix . '_cidade', '%' . $filtros['cidade'] . '%');
        }

        return [implode(' AND ', $where !== [] ? $where : ['1=1']), $params];
    }

    /** @param list<string> $where @param array<string,mixed> $params */
    private function faixaData(array &$where, array &$params, mixed $faixa, string $expr, string $prefix): void
    {
        if (!is_array($faixa)) {
            return;
        }
        if (($faixa['min'] ?? null) !== null) {
            $where[] = $expr . ' >= :' . $prefix . '_min';
            $params[$prefix . '_min'] = $faixa['min'];
        }
        if (($faixa['max'] ?? null) !== null) {
            $where[] = $expr . ' <= :' . $prefix . '_max';
            $params[$prefix . '_max'] = $faixa['max'];
        }
    }

    /** @param list<string> $where @param array<string,mixed> $params */
    private function faixaNumero(array &$where, array &$params, mixed $faixa, string $expr, string $prefix): void
    {
        if (!is_array($faixa)) {
            return;
        }
        if (($faixa['min'] ?? null) !== null) {
            $where[] = $expr . ' >= :' . $prefix . '_min';
            $params[$prefix . '_min'] = $faixa['min'];
        }
        if (($faixa['max'] ?? null) !== null) {
            $where[] = $expr . ' <= :' . $prefix . '_max';
            $params[$prefix . '_max'] = $faixa['max'];
        }
    }

    /** @param array<string,mixed> $params */
    private function bind(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $param = ':' . ltrim((string) $key, ':');
            if (is_int($value)) {
                $stmt->bindValue($param, $value, PDO::PARAM_INT);
            } elseif ($value === null) {
                $stmt->bindValue($param, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($param, (string) $value, PDO::PARAM_STR);
            }
        }
    }


    private function habilitarStreamingMysql(): void
    {
        try {
            if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            }
        } catch (\Throwable) {
            // Mantém compatibilidade caso o driver não exponha o atributo.
        }
    }

    private function maxRows(): int
    {
        $value = filter_var(getenv('DADOS_GERAIS_CSV_MAX_LINHAS') ?: 1000000, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1000, 'max_range' => 2000000],
        ]);
        return $value === false ? 1000000 : (int) $value;
    }
}
