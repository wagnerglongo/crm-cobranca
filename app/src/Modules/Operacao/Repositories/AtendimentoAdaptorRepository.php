<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use CRM\Modules\Operacao\Services\AcmeCatalogoContato;
use CRM\Modules\Operacao\Services\ContatoIntegracaoService;
use CRM\Modules\Operacao\Support\EscopoCredorSql;
use PDO;
use Throwable;

final class AtendimentoAdaptorRepository
{
    public const TIPOS_BUSCA = [
        'auto' => 'automatico',
        'contrato' => 'contrato',
        'documento' => 'cpf/cnpj',
        'telefone' => 'telefone',
        'nome' => 'nome',
    ];

    public const CONTATO_CATEGORIAS = [
        '' => 'Não selecionada',
        'celular' => 'Celular',
        'residencial' => 'Residencial',
        'comercial' => 'Comercial',
        'whatsapp' => 'WhatsApp',
        'email' => 'E-mail',
        'hot' => 'Hot',
        'recado' => 'Recado',
        'referencia' => 'Referência',
        'outro' => 'Outro',
    ];

    public const CONTATO_SITUACOES = [
        '' => 'Não selecionado',
        'falta_validar' => 'Falta validar',
        'negativado' => 'Negativado',
        'validado' => 'Validado',
    ];

    public const ENDERECO_CATEGORIAS = [
        '' => 'Não selecionada',
        'residencial' => 'Residencial',
        'comercial' => 'Comercial',
        'correspondencia' => 'Correspondência',
        'cobranca' => 'Cobrança',
        'hot' => 'Hot',
        'outro' => 'Outro',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{tipo:string,termo:string,rotulo:string,digitos:string} */
    public function normalizarBusca(string $termo, string $tipoInformado = 'auto'): array
    {
        $termo = trim(preg_replace('/\s+/', ' ', $termo) ?? $termo);
        $tipoInformado = array_key_exists($tipoInformado, self::TIPOS_BUSCA) ? $tipoInformado : 'auto';

        // O operador pode escrever o identificador em qualquer posição, inclusive
        // colado ao valor: "nomemaria" => nome / "maria" e "123cpf" => CPF / "123".
        // Alias longos são avaliados primeiro para evitar que "tel" capture "telefone".
        $aliases = [
            'documento' => ['documento', 'cnpj', 'cpf'],
            'contrato' => ['contrato', 'dívida', 'divida'],
            'telefone' => ['whatsapp', 'telefone', 'celular'],
            'nome' => ['cliente', 'nome'],
        ];
        $aliasEncontrado = false;
        foreach ($aliases as $tipoAlias => $palavras) {
            foreach ($palavras as $palavra) {
                $pattern = '/' . preg_quote($palavra, '/') . '/iu';
                if (!preg_match($pattern, $termo)) {
                    continue;
                }
                $tipoInformado = $tipoAlias;
                $termo = (string) preg_replace($pattern, '', $termo, 1);
                $termo = trim((string) preg_replace('/[\s:;,\-_=]+/u', ' ', $termo));
                $aliasEncontrado = true;
                break 2;
            }
        }

        if (!$aliasEncontrado) {
            $aliasesCurtos = [
                'telefone' => ['wpp', 'tel'],
                'contrato' => ['conta'],
            ];
            foreach ($aliasesCurtos as $tipoAlias => $palavras) {
                foreach ($palavras as $palavra) {
                    $pattern = '/(?:^|[\s:;,\-_=])' . preg_quote($palavra, '/') . '(?:$|[\s:;,\-_=])/iu';
                    if (!preg_match($pattern, $termo)) {
                        continue;
                    }
                    $tipoInformado = $tipoAlias;
                    $termo = (string) preg_replace($pattern, ' ', $termo, 1);
                    $termo = trim((string) preg_replace('/[\s:;,\-_=]+/u', ' ', $termo));
                    break 2;
                }
            }
        }

        $digitos = preg_replace('/\D+/', '', $termo) ?? '';
        $tipo = $tipoInformado;

        if ($tipo === 'auto') {
            if ($digitos !== '' && strlen($digitos) >= 11 && strlen($digitos) <= 14 && $this->documentoProvavel($digitos)) {
                $tipo = 'documento';
            } elseif ($digitos !== '' && strlen($digitos) >= 8 && strlen($digitos) <= 10) {
                $tipo = 'telefone';
            } elseif ($digitos !== '' && strlen($digitos) >= 6) {
                $tipo = 'contrato';
            } else {
                $tipo = 'nome';
            }
        }

        return [
            'tipo' => $tipo,
            'termo' => $termo,
            'rotulo' => self::TIPOS_BUSCA[$tipo] ?? 'automatico',
            'digitos' => $digitos,
        ];
    }

    /** @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopo @return list<array<string,mixed>> */
    public function buscarContratos(string $termo, string $tipoBusca = 'auto', ?array $escopo = null, int $limite = 300): array
    {
        $busca = $this->normalizarBusca($termo, $tipoBusca);
        if ($busca['termo'] === '' || mb_strlen($busca['termo']) < 2) {
            return [];
        }

        $limite = max(1, min($limite, 1000));
        $whereBusca = [];
        $params = [];
        $termoBusca = '%' . $busca['termo'] . '%';
        $digitosBusca = '%' . $busca['digitos'] . '%';

        if ($busca['tipo'] === 'documento') {
            if ($busca['digitos'] === '') {
                return [];
            }
            $whereBusca[] = 'p.documento LIKE :q_documento';
            $params['q_documento'] = $digitosBusca;
        } elseif ($busca['tipo'] === 'contrato') {
            // MySQL/PDO com prepared statement nativo não aceita reutilizar o mesmo
            // placeholder nomeado várias vezes no SQL. Usar nomes únicos evita
            // SQLSTATE HY093/invalid parameter number na busca por contrato.
            $whereBusca[] = 'c.codigo_contrato LIKE :q_contrato_codigo';
            $whereBusca[] = 'c.conta_id LIKE :q_contrato_conta';
            $whereBusca[] = 'c.devedor_origem_id LIKE :q_contrato_devedor';
            $params['q_contrato_codigo'] = $termoBusca;
            $params['q_contrato_conta'] = $termoBusca;
            $params['q_contrato_devedor'] = $termoBusca;
        } elseif ($busca['tipo'] === 'telefone') {
            if ($busca['digitos'] === '') {
                return [];
            }
            $whereBusca[] = 'EXISTS (
                SELECT 1
                FROM pessoa_contatos pc_busca
                WHERE pc_busca.pessoa_id = p.id
                  AND pc_busca.valor_normalizado LIKE :q_telefone
            )';
            $params['q_telefone'] = $digitosBusca;
        } else {
            $whereBusca[] = 'p.nome LIKE :q_nome';
            $params['q_nome'] = $termoBusca;
        }

        $scope = EscopoCredorSql::contrato($escopo, 'c', 'adaptor_busca');
        $params = array_merge($params, $scope['params']);
        $extraSelect = $this->extraSelectContrato();

        // Primeiro seleciona somente os IDs. Nomes comuns (por exemplo,
        // "Maria") podem casar com milhares de pessoas; levar c.* e os JSONs
        // para a ordenacao faz o MySQL criar uma tabela temporaria enorme em
        // /tmp. A consulta detalhada abaixo passa a trabalhar apenas sobre o
        // conjunto ja limitado.
        $idSql = 'SELECT c.id
            FROM contratos c
            INNER JOIN pessoas p ON p.id = c.pessoa_id
            LEFT JOIN cargas_arquivos ca_ult ON ca_ult.id = c.ultima_carga_id
            WHERE ' . $scope['sql'] . '
              AND (' . implode(' OR ', $whereBusca) . ')
            ORDER BY ' . $extraSelect['retirado_credor'] . ' ASC,
                p.nome ASC,
                c.atualizado_em DESC,
                c.id DESC
            LIMIT :limite';

        $idStmt = $this->pdo->prepare($idSql);
        foreach ($params as $key => $value) {
            $idStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $idStmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $idStmt->execute();
        $contratoIds = array_map('intval', array_column($idStmt->fetchAll(), 'id'));
        if ($contratoIds === []) {
            return [];
        }

        $idPlaceholders = [];
        $detailParams = $scope['params'];
        foreach ($contratoIds as $index => $contratoId) {
            $key = 'contrato_resultado_' . $index;
            $idPlaceholders[] = ':' . $key;
            $detailParams[$key] = $contratoId;
        }

        $sql = 'SELECT
                c.*,
                p.id AS pessoa_id,
                p.nome AS pessoa_nome,
                p.documento AS pessoa_documento,
                p.tipo_documento AS pessoa_tipo_documento,
                p.data_nascimento AS pessoa_data_nascimento,
                cr.codigo AS credor_codigo,
                cr.nome AS credor_nome,
                car.codigo AS carteira_codigo,
                car.nome AS carteira_nome,
                ca_orig.nome_original AS origem_carga_nome,
                ca_orig.data_referencia AS origem_carga_data_referencia,
                ca_orig.finalizado_em AS origem_carga_finalizado_em,
                ca_ult.nome_original AS ultima_carga_nome,
                ca_ult.data_referencia AS ultima_carga_data_referencia,
                ca_ult.finalizado_em AS ultima_carga_finalizado_em,
                (SELECT COUNT(*) FROM pessoa_contatos pc_count WHERE pc_count.pessoa_id = p.id) AS total_contatos,
                (SELECT COUNT(*) FROM pessoa_enderecos pe_count WHERE pe_count.pessoa_id = p.id) AS total_enderecos,
                (SELECT COUNT(*) FROM contrato_parcelas cp_count WHERE cp_count.contrato_id = c.id) AS total_parcelas,
                ' . $extraSelect['acordos_ativos'] . ' AS acordos_ativos,
                ' . $extraSelect['ultimo_atendimento'] . ' AS ultimo_atendimento_em,
                ' . $extraSelect['ultima_carga_escopo_id'] . ' AS ultima_carga_escopo_id,
                ' . $extraSelect['ultima_carga_escopo_data'] . ' AS ultima_carga_escopo_data_referencia,
                ' . $extraSelect['ultima_carga_escopo_finalizado'] . ' AS ultima_carga_escopo_finalizado_em,
                ' . $extraSelect['retirado_credor'] . ' AS retirado_credor
            FROM contratos c
            INNER JOIN pessoas p ON p.id = c.pessoa_id
            INNER JOIN credores cr ON cr.id = c.credor_id
            LEFT JOIN carteiras car ON car.id = c.carteira_id
            LEFT JOIN cargas_arquivos ca_orig ON ca_orig.id = c.origem_carga_id
            LEFT JOIN cargas_arquivos ca_ult ON ca_ult.id = c.ultima_carga_id
            WHERE ' . $scope['sql'] . '
              AND c.id IN (' . implode(',', $idPlaceholders) . ')';

        $stmt = $this->pdo->prepare($sql);
        foreach ($detailParams as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        $rowsById = [];
        foreach ($stmt->fetchAll() as $row) {
            $rowsById[(int) $row['id']] = $row;
        }

        $ordered = [];
        foreach ($contratoIds as $contratoId) {
            if (isset($rowsById[$contratoId])) {
                $ordered[] = $rowsById[$contratoId];
            }
        }

        return $ordered;
    }

    /** @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopo @return list<array<string,mixed>> */
    public function listarContratosPessoa(int $pessoaId, ?array $escopo = null, int $limite = 300): array
    {
        if ($pessoaId <= 0) {
            return [];
        }

        $limite = max(1, min($limite, 1000));
        $scope = EscopoCredorSql::contrato($escopo, 'c', 'adaptor_pessoa');
        $extraSelect = $this->extraSelectContrato();
        $params = array_merge(['pessoa_id' => $pessoaId], $scope['params']);

        $sql = 'SELECT
                c.*,
                p.id AS pessoa_id,
                p.nome AS pessoa_nome,
                p.documento AS pessoa_documento,
                p.tipo_documento AS pessoa_tipo_documento,
                p.data_nascimento AS pessoa_data_nascimento,
                cr.codigo AS credor_codigo,
                cr.nome AS credor_nome,
                car.codigo AS carteira_codigo,
                car.nome AS carteira_nome,
                ca_orig.nome_original AS origem_carga_nome,
                ca_orig.data_referencia AS origem_carga_data_referencia,
                ca_orig.finalizado_em AS origem_carga_finalizado_em,
                ca_ult.nome_original AS ultima_carga_nome,
                ca_ult.data_referencia AS ultima_carga_data_referencia,
                ca_ult.finalizado_em AS ultima_carga_finalizado_em,
                (SELECT COUNT(*) FROM pessoa_contatos pc_count WHERE pc_count.pessoa_id = p.id) AS total_contatos,
                (SELECT COUNT(*) FROM pessoa_enderecos pe_count WHERE pe_count.pessoa_id = p.id) AS total_enderecos,
                (SELECT COUNT(*) FROM contrato_parcelas cp_count WHERE cp_count.contrato_id = c.id) AS total_parcelas,
                ' . $extraSelect['acordos_ativos'] . ' AS acordos_ativos,
                ' . $extraSelect['ultimo_atendimento'] . ' AS ultimo_atendimento_em,
                ' . $extraSelect['ultima_carga_escopo_id'] . ' AS ultima_carga_escopo_id,
                ' . $extraSelect['ultima_carga_escopo_data'] . ' AS ultima_carga_escopo_data_referencia,
                ' . $extraSelect['ultima_carga_escopo_finalizado'] . ' AS ultima_carga_escopo_finalizado_em,
                ' . $extraSelect['retirado_credor'] . ' AS retirado_credor
            FROM contratos c
            INNER JOIN pessoas p ON p.id = c.pessoa_id
            INNER JOIN credores cr ON cr.id = c.credor_id
            LEFT JOIN carteiras car ON car.id = c.carteira_id
            LEFT JOIN cargas_arquivos ca_orig ON ca_orig.id = c.origem_carga_id
            LEFT JOIN cargas_arquivos ca_ult ON ca_ult.id = c.ultima_carga_id
            WHERE ' . $scope['sql'] . '
              AND p.id = :pessoa_id
            ORDER BY retirado_credor ASC, c.atualizado_em DESC, c.id DESC
            LIMIT :limite';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopo @return array<string,mixed>|null */
    public function buscarContrato(int $contratoId, ?array $escopo = null): ?array
    {
        $scope = EscopoCredorSql::contrato($escopo, 'c', 'adaptor_contrato');
        $extraSelect = $this->extraSelectContrato();
        $stmt = $this->pdo->prepare('SELECT
                c.*,
                p.id AS pessoa_id,
                p.nome AS pessoa_nome,
                p.documento AS pessoa_documento,
                p.tipo_documento AS pessoa_tipo_documento,
                p.nome_mae AS pessoa_nome_mae,
                p.data_nascimento AS pessoa_data_nascimento,
                p.sexo AS pessoa_sexo,
                p.estado_civil AS pessoa_estado_civil,
                cr.codigo AS credor_codigo,
                cr.nome AS credor_nome,
                car.codigo AS carteira_codigo,
                car.nome AS carteira_nome,
                ca_orig.nome_original AS origem_carga_nome,
                ca_orig.data_referencia AS origem_carga_data_referencia,
                ca_orig.finalizado_em AS origem_carga_finalizado_em,
                ca_ult.nome_original AS ultima_carga_nome,
                ca_ult.data_referencia AS ultima_carga_data_referencia,
                ca_ult.finalizado_em AS ultima_carga_finalizado_em,
                (SELECT COUNT(*) FROM pessoa_contatos pc_count WHERE pc_count.pessoa_id = p.id) AS total_contatos,
                (SELECT COUNT(*) FROM pessoa_enderecos pe_count WHERE pe_count.pessoa_id = p.id) AS total_enderecos,
                (SELECT COUNT(*) FROM contrato_parcelas cp_count WHERE cp_count.contrato_id = c.id) AS total_parcelas,
                ' . $extraSelect['acordos_ativos'] . ' AS acordos_ativos,
                ' . $extraSelect['ultimo_atendimento'] . ' AS ultimo_atendimento_em,
                ' . $extraSelect['ultima_carga_escopo_id'] . ' AS ultima_carga_escopo_id,
                ' . $extraSelect['ultima_carga_escopo_data'] . ' AS ultima_carga_escopo_data_referencia,
                ' . $extraSelect['ultima_carga_escopo_finalizado'] . ' AS ultima_carga_escopo_finalizado_em,
                ' . $extraSelect['retirado_credor'] . ' AS retirado_credor
            FROM contratos c
            INNER JOIN pessoas p ON p.id = c.pessoa_id
            INNER JOIN credores cr ON cr.id = c.credor_id
            LEFT JOIN carteiras car ON car.id = c.carteira_id
            LEFT JOIN cargas_arquivos ca_orig ON ca_orig.id = c.origem_carga_id
            LEFT JOIN cargas_arquivos ca_ult ON ca_ult.id = c.ultima_carga_id
            WHERE c.id = :contrato_id
              AND ' . $scope['sql'] . '
            LIMIT 1');
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->bindValue('contrato_id', $contratoId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function listarParcelasContrato(int $contratoId, int $limite = 300): array
    {
        $limite = max(1, min($limite, 1000));
        $stmt = $this->pdo->prepare('SELECT *
            FROM contrato_parcelas
            WHERE contrato_id = :contrato_id
            ORDER BY
                CASE WHEN data_vencimento IS NULL THEN 1 ELSE 0 END ASC,
                data_vencimento ASC,
                CAST(NULLIF(numero_parcela, \'\') AS UNSIGNED) ASC,
                id ASC
            LIMIT :limite');
        $stmt->bindValue('contrato_id', $contratoId, PDO::PARAM_INT);
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string,float|int|null> */
    public function resumoParcelasContrato(int $contratoId): array
    {
        $stmt = $this->pdo->prepare('SELECT
                COUNT(*) AS total_parcelas,
                COALESCE(SUM(saldo_parcela), 0) AS total_saldo_parcelas,
                COALESCE(SUM(valor_original), 0) AS total_original,
                MIN(data_vencimento) AS primeiro_vencimento,
                MAX(data_vencimento) AS ultimo_vencimento
            FROM contrato_parcelas
            WHERE contrato_id = :contrato_id');
        $stmt->bindValue('contrato_id', $contratoId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch() ?: [];

        return [
            'total_parcelas' => (int) ($row['total_parcelas'] ?? 0),
            'total_saldo_parcelas' => (float) ($row['total_saldo_parcelas'] ?? 0),
            'total_original' => (float) ($row['total_original'] ?? 0),
            'primeiro_vencimento' => $row['primeiro_vencimento'] ?? null,
            'ultimo_vencimento' => $row['ultimo_vencimento'] ?? null,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listarAcordosContrato(int $contratoId, int $limite = 50): array
    {
        if (!$this->tabelaExiste('acordos_operacionais')) {
            return [];
        }

        $limite = max(1, min($limite, 200));
        $stmt = $this->pdo->prepare('SELECT *
            FROM acordos_operacionais
            WHERE contrato_id = :contrato_id
            ORDER BY
                CASE status_operacional
                    WHEN \'pendente\' THEN 0
                    WHEN \'formalizado\' THEN 1
                    WHEN \'cumprido\' THEN 2
                    WHEN \'quebrado\' THEN 3
                    WHEN \'cancelado\' THEN 4
                    ELSE 5
                END ASC,
                data_promessa_pagamento DESC,
                id DESC
            LIMIT :limite');
        $stmt->bindValue('contrato_id', $contratoId, PDO::PARAM_INT);
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @param list<int> $acordoIds @return array<int,list<array<string,mixed>>> */
    public function listarParcelasAcordos(array $acordoIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $acordoIds), static fn (int $id): bool => $id > 0));
        if ($ids === [] || !$this->tabelaExiste('acordos_operacionais_parcelas')) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $key = 'acordo_' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $stmt = $this->pdo->prepare('SELECT *
            FROM acordos_operacionais_parcelas
            WHERE acordo_operacional_id IN (' . implode(',', $placeholders) . ')
            ORDER BY acordo_operacional_id ASC, numero_parcela ASC');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $ret = [];
        foreach ($stmt->fetchAll() as $row) {
            $ret[(int) $row['acordo_operacional_id']][] = $row;
        }

        return $ret;
    }

    /** @return list<array<string,mixed>> */
    public function listarHistorico(int $pessoaId, int $contratoId, int $limite = 40): array
    {
        if (!$this->tabelaExiste('atendimentos_historico')) {
            return [];
        }

        $limite = max(1, min($limite, 100));
        $resultadoSelect = $this->colunaExiste('atendimentos_historico', 'resultado_id') && $this->tabelaExiste('atendimento_resultados')
            ? 'ar.nome AS resultado_nome, ar.categoria AS resultado_categoria'
            : 'NULL AS resultado_nome, NULL AS resultado_categoria';
        $resultadoJoin = $this->colunaExiste('atendimentos_historico', 'resultado_id') && $this->tabelaExiste('atendimento_resultados')
            ? 'LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id'
            : '';

        $stmt = $this->pdo->prepare('SELECT
                ah.*,
                ' . $resultadoSelect . ',
                pc.valor AS contato_valor,
                pc.tipo AS contato_tipo,
                c.codigo_contrato
            FROM atendimentos_historico ah
            ' . $resultadoJoin . '
            LEFT JOIN pessoa_contatos pc ON pc.id = ah.contato_id
            LEFT JOIN contratos c ON c.id = ah.contrato_id
            WHERE ah.pessoa_id = :pessoa_id
              AND (ah.contrato_id = :contrato_id OR ah.contrato_id IS NULL)
            ORDER BY ah.criado_em DESC, ah.id DESC
            LIMIT :limite');
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->bindValue('contrato_id', $contratoId, PDO::PARAM_INT);
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function listarContatos(int $pessoaId): array
    {
        $stmt = $this->pdo->prepare('SELECT
                pc.*,
                ca_origem.nome_original AS origem_carga_nome,
                ca_ultima.nome_original AS ultima_carga_nome,
                COALESCE(ca_ultima.nome_original, ca_origem.nome_original, pc.origem, \'manual\') AS origem_descricao
            FROM pessoa_contatos pc
            LEFT JOIN cargas_arquivos ca_origem ON ca_origem.id = pc.origem_carga_id
            LEFT JOIN cargas_arquivos ca_ultima ON ca_ultima.id = pc.ultima_carga_id
            WHERE pc.pessoa_id = :pessoa_id
            ORDER BY pc.principal DESC,
                CASE pc.status
                    WHEN \'hot\' THEN 0
                    WHEN \'correto\' THEN 1
                    WHEN \'novo\' THEN 2
                    WHEN \'nao_confirmado\' THEN 3
                    WHEN \'desconhecido\' THEN 4
                    WHEN \'incorreto\' THEN 5
                    ELSE 6
                END ASC,
                pc.tipo ASC,
                pc.id ASC');
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function buscarContatoPorId(int $pessoaId, int $contatoId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pessoa_contatos WHERE id = :id AND pessoa_id = :pessoa_id LIMIT 1');
        $stmt->bindValue('id', $contatoId, PDO::PARAM_INT);
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $dados */
    public function atualizarContatoWorks(int $pessoaId, int $contatoId, array $dados): void
    {
        $contato = $this->buscarContatoPorId($pessoaId, $contatoId);
        if ($contato === null) {
            throw new \InvalidArgumentException('Contato não encontrado para este cliente.');
        }

        $categoria = $this->normalizarContatoCategoria($dados['subtipo'] ?? ($contato['subtipo'] ?? null));
        $situacao = $this->normalizarContatoSituacao($dados['situacao'] ?? null);
        $principal = (string) ($dados['principal'] ?? '') === '1' ? 1 : 0;

        if ($principal === 1) {
            $this->limparPrincipalContatos($pessoaId, (string) $contato['tipo']);
        }

        $payloadExtra = $this->decodeJsonObject($contato['payload_extra'] ?? null);
        if ($situacao === null) {
            unset($payloadExtra['situacao_validacao']);
        } else {
            $payloadExtra['situacao_validacao'] = $situacao;
        }
        $payloadExtra['atualizado_por_tela'] = 'operacao/works.php';

        $stmt = $this->pdo->prepare('UPDATE pessoa_contatos
            SET subtipo = :subtipo,
                principal = :principal,
                payload_extra = :payload_extra
            WHERE id = :id AND pessoa_id = :pessoa_id
            LIMIT 1');
        $this->bindNullableString($stmt, 'subtipo', $categoria);
        $stmt->bindValue('principal', $principal, PDO::PARAM_INT);
        $stmt->bindValue('payload_extra', json_encode($payloadExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->bindValue('id', $contatoId, PDO::PARAM_INT);
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();

        (new ContatoIntegracaoService($this->pdo))->contatoAlterado($contatoId, $contato);
    }

    private function limparPrincipalContatos(int $pessoaId, string $tipo): void
    {
        $stmt = $this->pdo->prepare('UPDATE pessoa_contatos SET principal = 0 WHERE pessoa_id = :pessoa_id AND tipo = :tipo');
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->bindValue('tipo', $tipo);
        $stmt->execute();
    }

    private function normalizarContatoCategoria(mixed $value): ?string
    {
        $categoria = strtolower(trim((string) ($value ?? '')));
        if ($categoria === '') {
            return null;
        }

        if (!array_key_exists($categoria, self::CONTATO_CATEGORIAS) || $categoria === '') {
            throw new \InvalidArgumentException('Categoria de contato inválida.');
        }

        return $categoria;
    }

    private function normalizarContatoSituacao(mixed $value): ?string
    {
        $situacao = strtolower(trim((string) ($value ?? '')));
        if ($situacao === '') {
            return null;
        }

        if (!array_key_exists($situacao, self::CONTATO_SITUACOES)) {
            throw new \InvalidArgumentException('Situação do contato inválida.');
        }

        return $situacao;
    }

    /** @return list<array<string,mixed>> */
    public function listarEnderecos(int $pessoaId): array
    {
        $stmt = $this->pdo->prepare('SELECT *
            FROM pessoa_enderecos
            WHERE pessoa_id = :pessoa_id
            ORDER BY principal DESC, id ASC');
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function buscarEnderecoPorId(int $pessoaId, int $enderecoId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pessoa_enderecos WHERE id = :id AND pessoa_id = :pessoa_id LIMIT 1');
        $stmt->bindValue('id', $enderecoId, PDO::PARAM_INT);
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $dados */
    public function adicionarEnderecoManual(int $pessoaId, array $dados): int
    {
        $tipo = $this->textoCurto($dados['tipo'] ?? 'residencial', 60) ?: 'residencial';
        $cep = $this->textoCurto($dados['cep'] ?? null, 20);
        $uf = strtoupper((string) ($this->textoCurto($dados['uf'] ?? null, 2) ?? '')) ?: null;
        $cidade = $this->textoCurto($dados['cidade'] ?? null, 120);
        $bairro = $this->textoCurto($dados['bairro'] ?? null, 150);
        $logradouro = $this->textoCurto($dados['logradouro'] ?? null, 255);
        $numero = $this->textoCurto($dados['numero'] ?? null, 30);
        $complemento = $this->textoCurto($dados['complemento'] ?? null, 255);
        $principal = (string) ($dados['principal'] ?? '') === '1' ? 1 : 0;

        if ($logradouro === null && $cidade === null && $cep === null) {
            throw new \InvalidArgumentException('Informe ao menos logradouro, cidade ou CEP do endereço.');
        }

        if ($principal === 1) {
            $this->limparPrincipalEnderecos($pessoaId);
        }

        $stmt = $this->pdo->prepare('INSERT INTO pessoa_enderecos (
                pessoa_id, tipo, cep, uf, cidade, bairro, logradouro, numero, complemento, principal, origem, payload_extra
            ) VALUES (
                :pessoa_id, :tipo, :cep, :uf, :cidade, :bairro, :logradouro, :numero, :complemento, :principal, \'manual\', :payload_extra
            )');
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $this->bindNullableString($stmt, 'tipo', $tipo);
        $this->bindNullableString($stmt, 'cep', $cep);
        $this->bindNullableString($stmt, 'uf', $uf);
        $this->bindNullableString($stmt, 'cidade', $cidade);
        $this->bindNullableString($stmt, 'bairro', $bairro);
        $this->bindNullableString($stmt, 'logradouro', $logradouro);
        $this->bindNullableString($stmt, 'numero', $numero);
        $this->bindNullableString($stmt, 'complemento', $complemento);
        $stmt->bindValue('principal', $principal, PDO::PARAM_INT);
        $stmt->bindValue('payload_extra', json_encode(['origem_manual' => true, 'criado_por_tela' => 'operacao/works.php'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->execute();

        $enderecoId = (int) $this->pdo->lastInsertId();
        (new ContatoIntegracaoService($this->pdo))->enderecoAlterado($enderecoId, null);

        return $enderecoId;
    }

    /** @param array<string,mixed> $dados */
    public function atualizarEnderecoManual(int $pessoaId, int $enderecoId, array $dados): void
    {
        $endereco = $this->buscarEnderecoPorId($pessoaId, $enderecoId);
        if ($endereco === null) {
            throw new \InvalidArgumentException('Endereço não encontrado para este cliente.');
        }

        $tipo = $this->textoCurto($dados['tipo'] ?? ($endereco['tipo'] ?? 'residencial'), 60) ?: 'residencial';
        $principal = (string) ($dados['principal'] ?? '') === '1' ? 1 : 0;

        if ($principal === 1) {
            $this->limparPrincipalEnderecos($pessoaId);
        }

        $payloadExtra = $this->decodeJsonObject($endereco['payload_extra'] ?? null);
        $payloadExtra['atualizado_por_tela'] = 'operacao/works.php';

        $stmt = $this->pdo->prepare('UPDATE pessoa_enderecos
            SET tipo = :tipo,
                principal = :principal,
                payload_extra = :payload_extra
            WHERE id = :id AND pessoa_id = :pessoa_id
            LIMIT 1');
        $this->bindNullableString($stmt, 'tipo', $tipo);
        $stmt->bindValue('principal', $principal, PDO::PARAM_INT);
        $stmt->bindValue('payload_extra', json_encode($payloadExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->bindValue('id', $enderecoId, PDO::PARAM_INT);
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();

        (new ContatoIntegracaoService($this->pdo))->enderecoAlterado($enderecoId, $endereco);
    }


    /** @param array<string,mixed> $payload */
    /**
     * Escrita atomica via JSON_SET: antes fazia SELECT payload_extra,
     * decodificava em PHP e reescrevia o campo inteiro - uma janela real
     * de leitura-modificacao-escrita. Duas chamadas concorrentes pro
     * mesmo contrato (ex: sincronizar financeiro e registrar boleto ao
     * mesmo tempo) podiam se sobrescrever e perder uma das duas
     * atualizacoes silenciosamente. acme_financeiro sempre substitui o
     * valor antigo por completo (nao depende dele pra nada), entao nao ha
     * nada pra mesclar em PHP - so precisa nao apagar as OUTRAS chaves de
     * payload_extra, que e exatamente o que JSON_SET faz em uma unica
     * instrucao atomica.
     */
    public function atualizarContratoFinanceiroAcme(int $contratoId, array $payload): void
    {
        $this->garantirContratoExiste($contratoId, 'Contrato não encontrado para atualização ACME.');

        $amount = isset($payload['Amount']) && is_numeric($payload['Amount']) ? (float) $payload['Amount'] : null;
        $financeiro = [
            'atualizado_em' => date('c'),
            'doc_number' => (string) ($payload['DocNumber'] ?? ''),
            'contract' => (string) ($payload['Contract'] ?? ''),
            'amount' => $amount,
            'provider' => (string) ($payload['Provider'] ?? ''),
            'provider_id' => $payload['ProviderID'] ?? null,
            'owner_description' => (string) ($payload['OwnerDescription'] ?? ''),
            'debt_date' => (string) ($payload['DebtDate'] ?? ''),
            'more_detail' => (string) ($payload['MoreDetail'] ?? ''),
            'custom_proposal_tag' => (string) ($payload['CustomProposalTag'] ?? ''),
            'binding_id' => (string) ($payload['BindingID'] ?? ''),
            'proposal_payload' => $this->compactarPayloadFinanceiroAcme($payload),
        ];

        $stmt = $this->pdo->prepare("UPDATE contratos SET payload_extra = JSON_SET(COALESCE(payload_extra, JSON_OBJECT()), '$.acme_financeiro', CAST(:financeiro AS JSON)), saldo_atual = COALESCE(:saldo_atual, saldo_atual) WHERE id = :id LIMIT 1");
        $stmt->bindValue('financeiro', json_encode($financeiro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->bindValue('saldo_atual', $amount !== null ? number_format($amount, 2, '.', '') : null);
        $stmt->bindValue('id', $contratoId, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function garantirContratoExiste(int $contratoId, string $mensagemErro): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM contratos WHERE id = :id LIMIT 1');
        $stmt->bindValue('id', $contratoId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException($mensagemErro);
        }
    }

    /** @return array<string,mixed> */
    public function obterFinanceiroAcmeCache(int $contratoId): array
    {
        $stmt = $this->pdo->prepare('SELECT payload_extra FROM contratos WHERE id = :id LIMIT 1');
        $stmt->bindValue('id', $contratoId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            return [];
        }

        $extra = $this->decodeJsonObject($row['payload_extra'] ?? null);
        $financeiro = $extra['acme_financeiro'] ?? [];
        return is_array($financeiro) ? $financeiro : [];
    }


    /**
     * Mesma escrita atomica via JSON_SET de atualizarContratoFinanceiroAcme
     * - ver o comentario la pra o raciocinio completo.
     *
     * @param array<string,mixed> $payload
     */
    public function atualizarContratoAcordoAcme(int $contratoId, array $payload): void
    {
        $this->garantirContratoExiste($contratoId, 'Contrato não encontrado para atualização de acordo ACME.');

        $debt = is_array($payload['Debt'] ?? null) ? $payload['Debt'] : [];
        $agreement = is_array($payload['Agreement'] ?? null) ? $payload['Agreement'] : [];
        $debtAmount = isset($debt['DebtAmount']) && is_numeric($debt['DebtAmount']) ? (float) $debt['DebtAmount'] : null;
        $debtStatus = trim((string) ($debt['StatusDescription'] ?? ''));
        if (mb_strlen($debtStatus) > 120) {
            $debtStatus = mb_substr($debtStatus, 0, 120);
        }
        $acordo = [
            'atualizado_em' => date('c'),
            'has_agreement_history' => (bool) ($payload['HasAgreementHistory'] ?? false),
            'debt' => $debt,
            'agreement' => $agreement,
            'raw' => $payload,
        ];

        $stmt = $this->pdo->prepare("UPDATE contratos SET payload_extra = JSON_SET(COALESCE(payload_extra, JSON_OBJECT()), '$.acme_acordo', CAST(:acordo AS JSON)), saldo_atual = COALESCE(:saldo_atual, saldo_atual), status_contrato = COALESCE(:status_contrato, status_contrato) WHERE id = :id LIMIT 1");
        $stmt->bindValue('acordo', json_encode($acordo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->bindValue('saldo_atual', $debtAmount !== null ? number_format($debtAmount, 2, '.', '') : null);
        $stmt->bindValue('status_contrato', $debtStatus !== '' ? $debtStatus : null);
        $stmt->bindValue('id', $contratoId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** @return array<string,mixed> */
    public function obterAcordoAcmeCache(int $contratoId): array
    {
        $stmt = $this->pdo->prepare('SELECT payload_extra FROM contratos WHERE id = :id LIMIT 1');
        $stmt->bindValue('id', $contratoId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            return [];
        }

        $extra = $this->decodeJsonObject($row['payload_extra'] ?? null);
        $acordo = $extra['acme_acordo'] ?? [];
        return is_array($acordo) ? $acordo : [];
    }


    /** @return array<string,mixed> */
    public function obterBoletoAcmeCache(int $contratoId, int $installmentId): array
    {
        if ($contratoId <= 0 || $installmentId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT payload_extra FROM contratos WHERE id = :id LIMIT 1');
        $stmt->bindValue('id', $contratoId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            return [];
        }

        $extra = $this->decodeJsonObject($row['payload_extra'] ?? null);
        $boletos = is_array($extra['acme_boletos'] ?? null) ? $extra['acme_boletos'] : [];
        $cache = $boletos[(string) $installmentId] ?? $boletos[$installmentId] ?? [];
        return is_array($cache) ? $cache : [];
    }

    /** @param array<string,mixed> $boleto */
    /**
     * Diferente de atualizarContratoFinanceiroAcme/atualizarContratoAcordoAcme
     * (corrigidas com UPDATE atomico via JSON_SET), este metodo NAO da pra
     * tornar atomico do mesmo jeito: o historico "acoes" precisa ser lido
     * e ter um item ANEXADO (nao substituido), e o cache e' por parcela
     * (chave dinamica dentro de acme_boletos). Duas chamadas concorrentes
     * pra parcelas diferentes do MESMO contrato competiam pela mesma
     * coluna payload_extra e uma podia perder o "acoes" que a outra
     * tinha acabado de gravar (lost update classico). `SELECT ... FOR
     * UPDATE` trava a linha do contrato entre a leitura e a escrita,
     * serializando essas chamadas ao inves de deixar as duas lerem o
     * mesmo estado antigo.
     *
     * @return array<string,mixed>
     */
    public function salvarBoletoAcmeCache(int $contratoId, int $installmentId, array $boleto, ?int $usuarioId = null, ?string $usuarioNome = null): array
    {
        if ($contratoId <= 0 || $installmentId <= 0) {
            throw new \InvalidArgumentException('Contrato ou parcela inválida para salvar boleto ACME.');
        }

        return $this->comLockDoContrato(function () use ($contratoId, $installmentId, $boleto, $usuarioId, $usuarioNome): array {
            $stmt = $this->pdo->prepare('SELECT payload_extra FROM contratos WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->bindValue('id', $contratoId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row === false) {
                throw new \InvalidArgumentException('Contrato não encontrado para salvar boleto ACME.');
            }

            $extra = $this->decodeJsonObject($row['payload_extra'] ?? null);
            $boletos = is_array($extra['acme_boletos'] ?? null) ? $extra['acme_boletos'] : [];
            $linha = trim((string) ($boleto['DigitableLine'] ?? $boleto['linha_digitavel'] ?? ''));
            $linha = preg_replace('/^Linha\s+digit[aá]vel\s*:\s*/iu', '', $linha) ?? $linha;
            $base64 = trim((string) ($boleto['Base64'] ?? $boleto['base64'] ?? ''));
            $filename = trim((string) ($boleto['FileName'] ?? $boleto['filename'] ?? ('Boleto_ACME_' . $installmentId . '.pdf')));

            $cacheAnterior = is_array($boletos[(string) $installmentId] ?? null) ? $boletos[(string) $installmentId] : [];
            $acoes = is_array($cacheAnterior['acoes'] ?? null) ? $cacheAnterior['acoes'] : [];
            $acoes[] = [
                'acao' => 'cache_atualizado',
                'usuario_id' => $usuarioId,
                'usuario_nome' => $usuarioNome,
                'criado_em' => date('c'),
            ];
            $acoes = array_slice($acoes, -30);

            $cache = [
                'installment_id' => $installmentId,
                'filename' => $filename,
                'linha_digitavel' => $linha,
                'base64' => $base64,
                'tem_pdf' => $base64 !== '',
                'raw' => $boleto,
                'atualizado_em' => date('c'),
                'acoes' => $acoes,
            ];
            $boletos[(string) $installmentId] = $cache;
            $extra['acme_boletos'] = $boletos;

            $stmt = $this->pdo->prepare('UPDATE contratos SET payload_extra = :payload_extra WHERE id = :id LIMIT 1');
            $stmt->bindValue('payload_extra', json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $stmt->bindValue('id', $contratoId, PDO::PARAM_INT);
            $stmt->execute();

            return $cache;
        });
    }

    public function registrarAcaoBoletoAcme(int $contratoId, int $installmentId, string $acao, ?int $usuarioId = null, ?string $usuarioNome = null): void
    {
        if ($contratoId <= 0 || $installmentId <= 0) {
            return;
        }

        $this->comLockDoContrato(function () use ($contratoId, $installmentId, $acao, $usuarioId, $usuarioNome): void {
            $stmt = $this->pdo->prepare('SELECT payload_extra FROM contratos WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->bindValue('id', $contratoId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row === false) {
                return;
            }

            $extra = $this->decodeJsonObject($row['payload_extra'] ?? null);
            $boletos = is_array($extra['acme_boletos'] ?? null) ? $extra['acme_boletos'] : [];
            $cache = is_array($boletos[(string) $installmentId] ?? null) ? $boletos[(string) $installmentId] : [];
            if ($cache === []) {
                return;
            }

            $acoes = is_array($cache['acoes'] ?? null) ? $cache['acoes'] : [];
            $acoes[] = [
                'acao' => $acao,
                'usuario_id' => $usuarioId,
                'usuario_nome' => $usuarioNome,
                'criado_em' => date('c'),
            ];
            $cache['acoes'] = array_slice($acoes, -30);
            $boletos[(string) $installmentId] = $cache;
            $extra['acme_boletos'] = $boletos;

            $stmt = $this->pdo->prepare('UPDATE contratos SET payload_extra = :payload_extra WHERE id = :id LIMIT 1');
            $stmt->bindValue('payload_extra', json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $stmt->bindValue('id', $contratoId, PDO::PARAM_INT);
            $stmt->execute();
        });
    }

    /**
     * Roda $fn dentro de uma transacao (reaproveitando uma ja aberta pelo
     * chamador, se houver) - existe so pra nao duplicar o
     * begin/commit/rollback nos dois metodos acima.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function comLockDoContrato(callable $fn): mixed
    {
        $jaEmTransacao = $this->pdo->inTransaction();
        if (!$jaEmTransacao) {
            $this->pdo->beginTransaction();
        }
        try {
            $resultado = $fn();
            if (!$jaEmTransacao) {
                $this->pdo->commit();
            }
            return $resultado;
        } catch (\Throwable $e) {
            if (!$jaEmTransacao) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function compactarPayloadFinanceiroAcme(array $payload): array
    {
        return [
            'ListProposalType' => $payload['ListProposalType'] ?? [],
            'ListInstallmentDate' => $payload['ListInstallmentDate'] ?? [],
            'ListAgreementType' => $payload['ListAgreementType'] ?? [],
            'ListPaymentType' => $payload['ListPaymentType'] ?? [],
            'ListHowReceiveBankSlip' => $payload['ListHowReceiveBankSlip'] ?? [],
        ];
    }

    /** @param array<string,mixed> $payload @return array{telefones_inseridos:int,emails_inseridos:int,enderecos_inseridos:int} */
    public function sincronizarLocalizacaoAcme(int $pessoaId, array $payload, ?AcmeCatalogoContato $catalogo = null): array
    {
        $telefones = 0;
        $emails = 0;
        $enderecos = 0;

        // Decisão de projeto (2026-10-06): código que o CRM não conhece -> consulta
        // as tabelas de códigos da ACME só nessa hora (no máximo 1x/hora).
        $catalogo ??= AcmeCatalogoContato::local();
        $catalogo->garantirCodigos([
            'status' => array_merge(
                array_column($this->listaArray($payload['RecordsPhones'] ?? []), 'Status'),
                array_column($this->listaArray($payload['RecordsMails'] ?? []), 'Status'),
                array_column($this->listaArray($payload['Addresses'] ?? []), 'AddressesStatus')
            ),
            'telefone' => array_column($this->listaArray($payload['RecordsPhones'] ?? []), 'PhoneType'),
            'email' => array_column($this->listaArray($payload['RecordsMails'] ?? []), 'EmailType'),
            'endereco' => array_column($this->listaArray($payload['Addresses'] ?? []), 'AddressType'),
        ]);

        foreach ($this->listaArray($payload['RecordsPhones'] ?? []) as $item) {
            $telefone = preg_replace('/\D+/', '', (string) ($item['PhoneNumber'] ?? '')) ?? '';
            if ($telefone === '') {
                continue;
            }
            $normalizado = $telefone;
            if ($this->contatoExistePorValor($pessoaId, $normalizado)) {
                continue;
            }

            $ddd = preg_replace('/\D+/', '', (string) ($item['DDD'] ?? '')) ?? '';
            if ($ddd === '' && strlen($telefone) >= 10) {
                $ddd = substr($telefone, 0, 2);
            }
            $numeroSemDdd = $ddd !== '' && str_starts_with($telefone, $ddd) ? substr($telefone, strlen($ddd)) : $telefone;
            $statusCodigo = (string) ($item['Status'] ?? '');
            $statusAlias = trim((string) ($item['StatusAlias'] ?? '')) ?: (string) $catalogo->descricao('status', $statusCodigo);
            $status = $this->statusContatoAcme($statusAlias, $statusCodigo);
            $categoria = $this->categoriaContatoAcme($statusAlias);
            $payloadExtra = [
                'origem_api' => 'acme',
                'acme_record_id' => $item['RecordID'] ?? null,
                'acme_phone_id' => $item['PhoneID'] ?? null,
                'acme_phone_type' => $item['PhoneType'] ?? null,
                'acme_phone_type_alias' => trim((string) ($item['PhoneTypeAlias'] ?? '')) ?: (string) $catalogo->descricao('telefone', $item['PhoneType'] ?? ''),
                'acme_status' => $statusCodigo,
                'acme_status_alias' => $statusAlias,
                'acme_priority' => $item['Priority'] ?? null,
                'sincronizado_em' => date('c'),
            ] + $this->marcaStatusSemEquivalente($status, $statusCodigo);

            $stmt = $this->pdo->prepare('INSERT INTO pessoa_contatos (
                    pessoa_id, tipo, subtipo, valor, valor_normalizado, ddd, telefone, email, status, principal, origem, payload_extra
                ) VALUES (
                    :pessoa_id, :tipo, :subtipo, :valor, :valor_normalizado, :ddd, :telefone, NULL, :status, 0, :origem, :payload_extra
                )');
            $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
            $stmt->bindValue('tipo', 'telefone');
            $this->bindNullableString($stmt, 'subtipo', $categoria);
            $stmt->bindValue('valor', $telefone);
            $stmt->bindValue('valor_normalizado', $normalizado);
            $this->bindNullableString($stmt, 'ddd', $ddd !== '' ? $ddd : null);
            $this->bindNullableString($stmt, 'telefone', $numeroSemDdd !== '' ? $numeroSemDdd : $telefone);
            $stmt->bindValue('status', $status);
            $stmt->bindValue('origem', 'acme_api');
            $stmt->bindValue('payload_extra', json_encode($payloadExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $stmt->execute();
            $telefones++;
        }

        foreach ($this->listaArray($payload['RecordsMails'] ?? []) as $item) {
            $email = mb_strtolower(trim((string) ($item['MailAddress'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if ($this->contatoExistePorValor($pessoaId, $email, 'email')) {
                continue;
            }

            $statusCodigo = (string) ($item['Status'] ?? '');
            $statusAlias = trim((string) ($item['StatusAlias'] ?? '')) ?: (string) $catalogo->descricao('status', $statusCodigo);
            $status = $this->statusContatoAcme($statusAlias, $statusCodigo);
            $categoria = $this->categoriaContatoAcme($statusAlias);
            $payloadExtra = [
                'origem_api' => 'acme',
                'acme_record_id' => $item['RecordID'] ?? null,
                'acme_mail_id' => $item['MailID'] ?? null,
                'acme_status' => $statusCodigo,
                'acme_status_alias' => $statusAlias,
                'acme_email_type' => $item['EmailType'] ?? null,
                'acme_email_type_alias' => trim((string) ($item['EmailTypeAlias'] ?? '')) ?: (string) $catalogo->descricao('email', $item['EmailType'] ?? ''),
                'sincronizado_em' => date('c'),
            ] + $this->marcaStatusSemEquivalente($status, $statusCodigo);

            $stmt = $this->pdo->prepare('INSERT INTO pessoa_contatos (
                    pessoa_id, tipo, subtipo, valor, valor_normalizado, ddd, telefone, email, status, principal, origem, payload_extra
                ) VALUES (
                    :pessoa_id, :tipo, :subtipo, :valor, :valor_normalizado, NULL, NULL, :email, :status, 0, :origem, :payload_extra
                )');
            $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
            $stmt->bindValue('tipo', 'email');
            $this->bindNullableString($stmt, 'subtipo', $categoria);
            $stmt->bindValue('valor', $email);
            $stmt->bindValue('valor_normalizado', $email);
            $stmt->bindValue('email', $email);
            $stmt->bindValue('status', $status);
            $stmt->bindValue('origem', 'acme_api');
            $stmt->bindValue('payload_extra', json_encode($payloadExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $stmt->execute();
            $emails++;
        }

        foreach ($this->listaArray($payload['Addresses'] ?? []) as $item) {
            $cep = preg_replace('/\D+/', '', (string) ($item['CEP'] ?? '')) ?? '';
            $cep = $cep !== '' ? $cep : null;
            $logradouro = $this->textoCurto($item['Locale'] ?? null, 255);
            $numero = $this->textoCurto($item['Number'] ?? null, 30);
            $cidade = $this->textoCurto($item['City'] ?? null, 120);
            $uf = strtoupper((string) ($this->textoCurto($item['State'] ?? null, 2) ?? '')) ?: null;
            if ($logradouro === null && $cep === null && $cidade === null) {
                continue;
            }
            if ($this->enderecoExisteAcme($pessoaId, $cep, $logradouro, $numero)) {
                continue;
            }

            $statusAlias = trim((string) ($item['AddressesStatusAlias'] ?? '')) ?: (string) $catalogo->descricao('status', $item['AddressesStatus'] ?? '');
            $tipoAlias = trim((string) ($item['AddressTypeAlias'] ?? '')) ?: (string) $catalogo->descricao('endereco', $item['AddressType'] ?? '');
            $categoria = $this->categoriaEnderecoAcme($tipoAlias, $statusAlias);
            $payloadExtra = [
                'origem_api' => 'acme',
                'acme_record_id' => $item['RecordID'] ?? null,
                'acme_address_id' => $item['AddressID'] ?? null,
                'acme_address_type' => (string) ($item['AddressType'] ?? ''),
                'acme_address_type_alias' => $tipoAlias,
                'acme_address_status' => (string) ($item['AddressesStatus'] ?? ''),
                'acme_address_status_alias' => $statusAlias,
                'sincronizado_em' => date('c'),
            ];

            $stmt = $this->pdo->prepare('INSERT INTO pessoa_enderecos (
                    pessoa_id, tipo, cep, uf, cidade, bairro, logradouro, numero, complemento, principal, origem, payload_extra
                ) VALUES (
                    :pessoa_id, :tipo, :cep, :uf, :cidade, :bairro, :logradouro, :numero, :complemento, 0, :origem, :payload_extra
                )');
            $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
            $this->bindNullableString($stmt, 'tipo', $categoria);
            $this->bindNullableString($stmt, 'cep', $cep);
            $this->bindNullableString($stmt, 'uf', $uf);
            $this->bindNullableString($stmt, 'cidade', $cidade);
            $this->bindNullableString($stmt, 'bairro', $this->textoCurto($item['Region'] ?? null, 150));
            $this->bindNullableString($stmt, 'logradouro', $logradouro);
            $this->bindNullableString($stmt, 'numero', $numero);
            $this->bindNullableString($stmt, 'complemento', $this->textoCurto($item['Complement'] ?? null, 255));
            $stmt->bindValue('origem', 'acme_api');
            $stmt->bindValue('payload_extra', json_encode($payloadExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $stmt->execute();
            $enderecos++;
        }

        return [
            'telefones_inseridos' => $telefones,
            'emails_inseridos' => $emails,
            'enderecos_inseridos' => $enderecos,
        ];
    }

    private function contatoExistePorValor(int $pessoaId, string $valorNormalizado, ?string $tipo = null): bool
    {
        $sql = 'SELECT id FROM pessoa_contatos WHERE pessoa_id = :pessoa_id AND valor_normalizado = :valor_normalizado';
        if ($tipo !== null) {
            $sql .= ' AND tipo = :tipo';
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->bindValue('valor_normalizado', $valorNormalizado);
        if ($tipo !== null) {
            $stmt->bindValue('tipo', $tipo);
        }
        $stmt->execute();
        return (bool) $stmt->fetchColumn();
    }

    private function enderecoExisteAcme(int $pessoaId, ?string $cep, ?string $logradouro, ?string $numero): bool
    {
        $stmt = $this->pdo->prepare('SELECT id
            FROM pessoa_enderecos
            WHERE pessoa_id = :pessoa_id
              AND COALESCE(cep, \'\') = :cep
              AND COALESCE(UPPER(logradouro), \'\') = :logradouro
              AND COALESCE(numero, \'\') = :numero
            LIMIT 1');
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->bindValue('cep', $cep ?? '');
        $stmt->bindValue('logradouro', mb_strtoupper((string) ($logradouro ?? '')));
        $stmt->bindValue('numero', (string) ($numero ?? ''));
        $stmt->execute();
        return (bool) $stmt->fetchColumn();
    }

    /** @param mixed $value @return list<array<string,mixed>> */
    private function listaArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, static fn ($item): bool => is_array($item)));
    }

    private function statusContatoAcme(string $alias, string $codigo): string
    {
        $codigo = strtoupper(trim($codigo));
        $base = mb_strtolower(trim($alias !== '' ? $alias : (AcmeCatalogoContato::PADRAO['status'][$codigo] ?? $codigo)));
        $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base;
        // Achado 2026-10-06: "incorreto" contém "correto" e era testado
        // depois - número marcado Incorreto na ACME entrava como correto no CRM.
        return match (true) {
            str_contains($base, 'incorreto') => 'incorreto',
            str_contains($base, 'hot') => 'hot',
            str_contains($base, 'nao') && str_contains($base, 'confirmado') => 'nao_confirmado',
            str_contains($base, 'correto') => 'correto',
            str_contains($base, 'desconhecido') => 'desconhecido',
            default => 'novo',
        };
    }

    /**
     * Status da ACME sem equivalente no CRM (ex.: um status novo criado por
     * ela): o contato entra como "novo", mas fica marcado com o código e a
     * descrição originais pra decidirmos o de/para.
     *
     * @return array<string,bool>
     */
    private function marcaStatusSemEquivalente(string $status, string $codigo): array
    {
        if ($status !== 'novo' || trim($codigo) === '') {
            return [];
        }
        error_log('[acme-catalogo] status de contato da ACME sem equivalente no CRM: ' . $codigo);
        return ['acme_status_sem_equivalente' => true];
    }

    private function categoriaContatoAcme(string $statusAlias): ?string
    {
        $base = mb_strtolower(trim($statusAlias));
        $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base;
        if (str_contains($base, 'hot')) {
            return 'hot';
        }
        return null;
    }

    private function categoriaEnderecoAcme(string $tipoAlias, string $statusAlias): string
    {
        $status = mb_strtolower(trim($statusAlias));
        $status = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $status) ?: $status;
        if (str_contains($status, 'hot')) {
            return 'hot';
        }

        $tipo = mb_strtolower(trim($tipoAlias));
        $tipo = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $tipo) ?: $tipo;
        return match (true) {
            str_contains($tipo, 'resid') => 'residencial',
            str_contains($tipo, 'comerc') => 'comercial',
            str_contains($tipo, 'cobr') => 'cobranca',
            str_contains($tipo, 'correspond') => 'correspondencia',
            default => 'outro',
        };
    }

    /** @return array<string,string> */
    private function extraSelectContrato(): array
    {
        $acordosAtivos = $this->tabelaExiste('acordos_operacionais')
            ? "(SELECT COUNT(*) FROM acordos_operacionais ao_count WHERE ao_count.contrato_id = c.id AND ao_count.status_operacional IN ('pendente','formalizado'))"
            : '0';

        $ultimoAtendimento = $this->tabelaExiste('atendimentos_historico')
            ? '(SELECT MAX(ah_count.criado_em) FROM atendimentos_historico ah_count WHERE ah_count.contrato_id = c.id OR (ah_count.contrato_id IS NULL AND ah_count.pessoa_id = p.id))'
            : 'NULL';

        // Durante uma implantacao em que o codigo foi copiado antes da migration,
        // a consulta continua segura: nenhuma carga e usada para retirada ate a
        // coluna de conclusao operacional existir.
        if (!$this->colunaExiste('cargas_arquivos', 'aplicacao_finalizada_em')) {
            return [
                'acordos_ativos' => $acordosAtivos,
                'ultimo_atendimento' => $ultimoAtendimento,
                'ultima_carga_escopo_id' => 'NULL',
                'ultima_carga_escopo_data' => 'NULL',
                'ultima_carga_escopo_finalizado' => 'NULL',
                'retirado_credor' => '0',
            ];
        }

        // A retirada do credor é calculada pela data de negócio da carga. IDs
        // diferentes do mesmo dia podem representar partes do mesmo lote e não
        // devem retirar contratos uns dos outros. A carga somente vira referência
        // depois que o merge canônico termina sem erros. Normalização, upload e
        // aplicação parcial nunca alteram o status operacional dos contratos.
        $cargaEscopoWhere = "ca_scope.credor_id = c.credor_id
            AND (ca_scope.carteira_id <=> c.carteira_id)
            AND (ca_ult.layout_id IS NULL OR ca_scope.layout_id <=> ca_ult.layout_id)
            AND ca_scope.aplicacao_finalizada_em IS NOT NULL";
        $dataNegocio = 'COALESCE(ca_scope.data_referencia, DATE(ca_scope.aplicacao_finalizada_em))';
        $dataNegocioContrato = 'COALESCE(ca_ult.data_referencia, DATE(ca_ult.finalizado_em), DATE(ca_ult.atualizado_em), DATE(ca_ult.criado_em))';
        $ordemCargaEscopo = $dataNegocio . ' DESC, ca_scope.aplicacao_finalizada_em DESC, ca_scope.id DESC';

        $ultimaCargaEscopoId = '(SELECT ca_scope.id FROM cargas_arquivos ca_scope WHERE ' . $cargaEscopoWhere . ' ORDER BY ' . $ordemCargaEscopo . ' LIMIT 1)';
        $ultimaCargaEscopoData = '(SELECT ' . $dataNegocio . ' FROM cargas_arquivos ca_scope WHERE ' . $cargaEscopoWhere . ' ORDER BY ' . $ordemCargaEscopo . ' LIMIT 1)';
        $ultimaCargaEscopoFinalizado = '(SELECT ca_scope.aplicacao_finalizada_em FROM cargas_arquivos ca_scope WHERE ' . $cargaEscopoWhere . ' ORDER BY ' . $ordemCargaEscopo . ' LIMIT 1)';
        $retiradoCredor = "CASE
            WHEN c.status_contrato = 'retirado_pelo_credor' THEN 1
            WHEN c.ultima_carga_id IS NOT NULL
             AND " . $ultimaCargaEscopoData . " IS NOT NULL
             AND " . $dataNegocioContrato . " IS NOT NULL
             AND " . $ultimaCargaEscopoData . " > " . $dataNegocioContrato . "
            THEN 1 ELSE 0 END";

        return [
            'acordos_ativos' => $acordosAtivos,
            'ultimo_atendimento' => $ultimoAtendimento,
            'ultima_carga_escopo_id' => $ultimaCargaEscopoId,
            'ultima_carga_escopo_data' => $ultimaCargaEscopoData,
            'ultima_carga_escopo_finalizado' => $ultimaCargaEscopoFinalizado,
            'retirado_credor' => $retiradoCredor,
        ];
    }

    private function documentoProvavel(string $digitos): bool
    {
        return strlen($digitos) === 11 || strlen($digitos) === 14;
    }

    private function limparPrincipalEnderecos(int $pessoaId): void
    {
        $stmt = $this->pdo->prepare('UPDATE pessoa_enderecos SET principal = 0 WHERE pessoa_id = :pessoa_id');
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function textoCurto(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max);
        }

        return $text;
    }

    private function bindNullableString(\PDOStatement $stmt, string $key, ?string $value): void
    {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($key, $value);
    }

    /** @return array<string,mixed> */
    private function decodeJsonObject(mixed $payload): array
    {
        if (!is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function tabelaExiste(string $tabela): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT 1
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :tabela
                LIMIT 1');
            $stmt->execute(['tabela' => $tabela]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function colunaExiste(string $tabela, string $coluna): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT 1
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :tabela
                  AND COLUMN_NAME = :coluna
                LIMIT 1');
            $stmt->execute(['tabela' => $tabela, 'coluna' => $coluna]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
