<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use CRM\Modules\Operacao\Support\EscopoCredorSql;
use PDO;
use Throwable;

final class OperacaoDashboardRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,int> */
    public function obterResumoBase(?array $escopo = null): array
    {
        $scopePessoas = EscopoCredorSql::contrato($escopo, 'c', 'dash_base_p');
        $scopeContratos = EscopoCredorSql::contrato($escopo, 'c2', 'dash_base_c');
        $scopeContatos = EscopoCredorSql::contrato($escopo, 'c3', 'dash_base_pc');
        $sql = "SELECT
                (SELECT COUNT(DISTINCT c.pessoa_id) FROM contratos c WHERE " . $scopePessoas['sql'] . ") AS total_pessoas,
                (SELECT COUNT(*) FROM contratos c2 WHERE " . $scopeContratos['sql'] . ") AS total_contratos,
                (SELECT COUNT(DISTINCT pc.id) FROM pessoa_contatos pc INNER JOIN contratos c3 ON c3.pessoa_id = pc.pessoa_id WHERE " . $scopeContatos['sql'] . ") AS total_contatos,
                (SELECT COUNT(*)
                   FROM cargas_arquivos ca
                   INNER JOIN credores cr ON cr.id = ca.credor_id
                  WHERE cr.codigo = 'ACME'
                    AND ca.status IN ('concluido', 'concluido_com_erros')) AS total_cargas_acme_processadas";

        $stmt = $this->pdo->prepare($sql);
        EscopoCredorSql::bind($stmt, array_merge($scopePessoas['params'], $scopeContratos['params'], $scopeContatos['params']));
        $stmt->execute();
        $row = $stmt->fetch() ?: [];

        return [
            'total_pessoas' => (int) ($row['total_pessoas'] ?? 0),
            'total_contratos' => (int) ($row['total_contratos'] ?? 0),
            'total_contatos' => (int) ($row['total_contatos'] ?? 0),
            'total_cargas_acme_processadas' => (int) ($row['total_cargas_acme_processadas'] ?? 0),
        ];
    }

    /** @return array<string,int|bool> */
    public function obterResumoAtendimento(): array
    {
        $fallback = [
            'modulo_disponivel' => false,
            'resultado_padronizado_disponivel' => false,
            'total_atendimentos' => 0,
            'atendimentos_hoje' => 0,
            'contatos_efetivos_hoje' => 0,
            'sucessos_hoje' => 0,
            'promessas_hoje' => 0,
            'telefones_ruins_hoje' => 0,
            'proximos_pendentes' => 0,
            'retornos_vencidos' => 0,
        ];

        if (!$this->tabelaExiste('atendimentos_historico')) {
            return $fallback;
        }

        $usaResultadoPadronizado = $this->resultadoPadronizadoDisponivel();

        $usaStatusRetorno = $this->retornoStatusDisponivel();
        $condicaoPendentePrefixada = $usaStatusRetorno
            ? "ah.proximo_acionamento IS NOT NULL AND COALESCE(ah.retorno_status, 'pendente') = 'pendente'"
            : 'ah.proximo_acionamento IS NOT NULL';
        $condicaoPendenteSimples = $usaStatusRetorno
            ? "proximo_acionamento IS NOT NULL AND COALESCE(retorno_status, 'pendente') = 'pendente'"
            : 'proximo_acionamento IS NOT NULL';

        if ($usaResultadoPadronizado) {
            $sql = "SELECT
                    COUNT(*) AS total_atendimentos,
                    SUM(CASE WHEN DATE(ah.criado_em) = CURRENT_DATE THEN 1 ELSE 0 END) AS atendimentos_hoje,
                    SUM(CASE WHEN DATE(ah.criado_em) = CURRENT_DATE AND COALESCE(ar.considera_contato_efetivo, 0) = 1 THEN 1 ELSE 0 END) AS contatos_efetivos_hoje,
                    SUM(CASE WHEN DATE(ah.criado_em) = CURRENT_DATE AND COALESCE(ar.considera_sucesso, 0) = 1 THEN 1 ELSE 0 END) AS sucessos_hoje,
                    SUM(CASE WHEN DATE(ah.criado_em) = CURRENT_DATE AND (ar.codigo = 'PROMESSA_PAGAMENTO' OR ah.resultado = 'promessa_pagamento') THEN 1 ELSE 0 END) AS promessas_hoje,
                    SUM(CASE WHEN DATE(ah.criado_em) = CURRENT_DATE AND COALESCE(ar.considera_telefone_ruim, 0) = 1 THEN 1 ELSE 0 END) AS telefones_ruins_hoje,
                    SUM(CASE WHEN " . $condicaoPendentePrefixada . " AND ah.proximo_acionamento >= NOW() THEN 1 ELSE 0 END) AS proximos_pendentes,
                    SUM(CASE WHEN " . $condicaoPendentePrefixada . " AND ah.proximo_acionamento < NOW() THEN 1 ELSE 0 END) AS retornos_vencidos
                FROM atendimentos_historico ah
                LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id";
        } else {
            $sql = "SELECT
                    COUNT(*) AS total_atendimentos,
                    SUM(CASE WHEN DATE(criado_em) = CURRENT_DATE THEN 1 ELSE 0 END) AS atendimentos_hoje,
                    SUM(CASE WHEN DATE(criado_em) = CURRENT_DATE AND resultado IN ('atendeu', 'promessa_pagamento', 'em_negociacao') THEN 1 ELSE 0 END) AS contatos_efetivos_hoje,
                    SUM(CASE WHEN DATE(criado_em) = CURRENT_DATE AND resultado IN ('atendeu', 'promessa_pagamento', 'em_negociacao') THEN 1 ELSE 0 END) AS sucessos_hoje,
                    SUM(CASE WHEN DATE(criado_em) = CURRENT_DATE AND resultado = 'promessa_pagamento' THEN 1 ELSE 0 END) AS promessas_hoje,
                    SUM(CASE WHEN DATE(criado_em) = CURRENT_DATE AND resultado = 'numero_invalido' THEN 1 ELSE 0 END) AS telefones_ruins_hoje,
                    SUM(CASE WHEN " . $condicaoPendenteSimples . " AND proximo_acionamento >= NOW() THEN 1 ELSE 0 END) AS proximos_pendentes,
                    SUM(CASE WHEN " . $condicaoPendenteSimples . " AND proximo_acionamento < NOW() THEN 1 ELSE 0 END) AS retornos_vencidos
                FROM atendimentos_historico";
        }

        $row = $this->pdo->query($sql)->fetch() ?: [];

        $acordosHoje = null;
        if ($this->tabelaExiste('acordos_operacionais')) {
            $acordosHoje = (int) $this->pdo->query("SELECT COUNT(*) FROM acordos_operacionais WHERE DATE(criado_em) = CURRENT_DATE")->fetchColumn();
        }

        return [
            'modulo_disponivel' => true,
            'resultado_padronizado_disponivel' => $usaResultadoPadronizado,
            'total_atendimentos' => (int) ($row['total_atendimentos'] ?? 0),
            'atendimentos_hoje' => (int) ($row['atendimentos_hoje'] ?? 0),
            'contatos_efetivos_hoje' => (int) ($row['contatos_efetivos_hoje'] ?? 0),
            'sucessos_hoje' => (int) ($row['sucessos_hoje'] ?? 0),
            'promessas_hoje' => $acordosHoje ?? (int) ($row['promessas_hoje'] ?? 0),
            'telefones_ruins_hoje' => (int) ($row['telefones_ruins_hoje'] ?? 0),
            'proximos_pendentes' => (int) ($row['proximos_pendentes'] ?? 0),
            'retornos_vencidos' => (int) ($row['retornos_vencidos'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listarResultadosHoje(int $limite = 8): array
    {
        if (!$this->tabelaExiste('atendimentos_historico')) {
            return [];
        }

        $limite = max(1, min($limite, 20));

        if ($this->resultadoPadronizadoDisponivel()) {
            $stmt = $this->pdo->prepare("SELECT
                    COALESCE(ar.nome, ah.resultado, 'Sem resultado') AS nome,
                    COALESCE(ar.categoria, 'Legado') AS categoria,
                    COUNT(*) AS total
                FROM atendimentos_historico ah
                LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id
                WHERE DATE(ah.criado_em) = CURRENT_DATE
                GROUP BY COALESCE(ar.nome, ah.resultado, 'Sem resultado'), COALESCE(ar.categoria, 'Legado')
                ORDER BY total DESC, nome ASC
                LIMIT :limite");
        } else {
            $stmt = $this->pdo->prepare("SELECT
                    CASE ah.resultado
                        WHEN 'sem_contato' THEN 'Sem contato'
                        WHEN 'nao_atende' THEN 'Não atende'
                        WHEN 'atendeu' THEN 'Atendeu'
                        WHEN 'ocupado' THEN 'Ocupado'
                        WHEN 'caixa_postal' THEN 'Caixa postal'
                        WHEN 'numero_invalido' THEN 'Número inválido'
                        WHEN 'recado' THEN 'Recado'
                        WHEN 'promessa_pagamento' THEN 'Promessa de pagamento'
                        WHEN 'em_negociacao' THEN 'Em negociação'
                        ELSE 'Outro'
                    END AS nome,
                    'Legado' AS categoria,
                    COUNT(*) AS total
                FROM atendimentos_historico ah
                WHERE DATE(ah.criado_em) = CURRENT_DATE
                GROUP BY ah.resultado
                ORDER BY total DESC, nome ASC
                LIMIT :limite");
        }

        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function listarProximosAcionamentos(int $limite = 6, ?array $escopo = null): array
    {
        if (!$this->tabelaExiste('atendimentos_historico')) {
            return [];
        }

        $limite = max(1, min($limite, 20));
        $resultadoSelect = $this->resultadoPadronizadoDisponivel()
            ? 'ar.nome AS resultado_nome, ar.categoria AS resultado_categoria'
            : 'NULL AS resultado_nome, NULL AS resultado_categoria';
        $resultadoJoin = $this->resultadoPadronizadoDisponivel()
            ? 'LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id'
            : '';
        $retornoPendenteWhere = $this->retornoStatusDisponivel()
            ? "COALESCE(ah.retorno_status, 'pendente') = 'pendente'"
            : '1 = 1';

        $scopeContrato = EscopoCredorSql::contrato($escopo, 'c', 'dash_prox_c');
        $scopePessoa = EscopoCredorSql::contrato($escopo, 'c_scope', 'dash_prox_p');

        $stmt = $this->pdo->prepare('SELECT
                ah.id,
                ah.pessoa_id,
                ah.canal,
                ah.resultado,
                ah.contato_utilizado,
                ah.proximo_acionamento,
                ah.operador_nome,
                ' . $resultadoSelect . ',
                p.nome AS pessoa_nome,
                p.documento AS pessoa_documento,
                c.codigo_contrato,
                c.produto
            FROM atendimentos_historico ah
            INNER JOIN pessoas p ON p.id = ah.pessoa_id
            LEFT JOIN contratos c ON c.id = ah.contrato_id
            ' . $resultadoJoin . '
            WHERE ah.proximo_acionamento IS NOT NULL
              AND ah.proximo_acionamento >= NOW()
              AND ' . $retornoPendenteWhere . '
              AND ((ah.contrato_id IS NOT NULL AND ' . $scopeContrato['sql'] . ') OR (ah.contrato_id IS NULL AND EXISTS (SELECT 1 FROM contratos c_scope WHERE c_scope.pessoa_id = ah.pessoa_id AND ' . $scopePessoa['sql'] . ')))
            ORDER BY ah.proximo_acionamento ASC, ah.id ASC
            LIMIT :limite');
        EscopoCredorSql::bind($stmt, array_merge($scopeContrato['params'], $scopePessoa['params']));
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function listarUltimasCargasAcme(int $limite = 5): array
    {
        $limite = max(1, min($limite, 20));

        $stmt = $this->pdo->prepare("SELECT
                ca.id,
                ca.nome_original,
                ca.status,
                ca.total_linhas,
                ca.linhas_validas,
                ca.linhas_com_erro,
                ca.finalizado_em,
                ca.criado_em
            FROM cargas_arquivos ca
            INNER JOIN credores cr ON cr.id = ca.credor_id
            WHERE cr.codigo = 'ACME'
            ORDER BY ca.id DESC
            LIMIT :limite");
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function retornoStatusDisponivel(): bool
    {
        return $this->colunaExiste('atendimentos_historico', 'retorno_status')
            && $this->colunaExiste('atendimentos_historico', 'retorno_tratado_em')
            && $this->colunaExiste('atendimentos_historico', 'retorno_observacao');
    }

    private function resultadoPadronizadoDisponivel(): bool
    {
        return $this->tabelaExiste('atendimento_resultados')
            && $this->colunaExiste('atendimentos_historico', 'resultado_id');
    }

    private function tabelaExiste(string $tabela): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :tabela');
            $stmt->execute(['tabela' => $tabela]);

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function colunaExiste(string $tabela, string $coluna): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :tabela
                  AND COLUMN_NAME = :coluna');
            $stmt->execute([
                'tabela' => $tabela,
                'coluna' => $coluna,
            ]);

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
