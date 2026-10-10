<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use CRM\Modules\Operacao\Support\EscopoCredorSql;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Funil operacional solicitado pela operação (2026-09-23): clientes contactados
 * -> eventos "Alô" (ligação atendida por alguém) -> eventos CPC (contato
 * efetivo com a pessoa certa) -> acordos fechados no período, com filtro
 * de credor e data, e detalhamento (drill-down) por estágio.
 *
 * "Alô" e "CPC" usam a classificação já existente em
 * atendimento_resultados: CPC = considera_contato_efetivo = 1 (é
 * exatamente a definição de contato com a pessoa certa que já foi
 * modelada na troca das tabulações pela lista real da ACME). Alô = ligação
 * cuja tabulação tem considera_alo = 1 (alguém atendeu; superconjunto de
 * CPC, inclui recado com terceiro, por exemplo). Editável ajustando os
 * flags de cada tabulação em Configurações > Tabulações.
 *
 * Achado (2026-10-01): acordo fechado = o operador falou com o
 * cliente (é até obrigatório informar número e plataforma), então todo
 * acordo tem que percorrer o funil inteiro tentativa > alô > CPC > acordo.
 * Antes, alô/CPC exigiam canal 'ligacao', e o acordo fechado por WhatsApp
 * ficava de fora. Agora:
 * 1) acionamento com tabulação de acordo (resultado_legacy
 *    'promessa_pagamento') conta como Alô e CPC em qualquer canal;
 * 2) acordo do dashboard sem nenhum acionamento de acordo da mesma pessoa,
 *    mesmo credor e mesmo dia (ex.: exceção que veio da ACME e foi atribuída
 *    a um operador) conta ele mesmo como um evento Alô e CPC, e a pessoa
 *    entra em "contactados" se ainda não estava. Quando o acionamento
 *    existe, quem conta é ele - o acordo não conta de novo.
 */
final class DashboardOperacionalRepository
{
    private const SQL_ACIONAMENTO_DE_ACORDO = "COALESCE(ar.resultado_legacy, ah.resultado) = 'promessa_pagamento'";

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function moduloDisponivel(): bool
    {
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'atendimentos_historico'");
            return $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $filtros @return array{data_inicio:string,data_fim:string,data_fim_exclusivo:string,credor_id:?int,lote:?string} */
    public function normalizarFiltros(array $filtros): array
    {
        $hoje = new DateTimeImmutable('today');
        $inicio = $this->parseData((string) ($filtros['data_inicio'] ?? '')) ?? $hoje;
        $fim = $this->parseData((string) ($filtros['data_fim'] ?? '')) ?? $hoje;

        if ($fim < $inicio) {
            throw new RuntimeException('A data final não pode ser menor que a data inicial.');
        }

        $dias = (int) $inicio->diff($fim)->format('%a') + 1;
        if ($dias > 366) {
            throw new RuntimeException('Período muito grande. O limite é de 366 dias.');
        }

        $credorId = filter_var($filtros['credor_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $lote = trim((string) ($filtros['lote'] ?? ''));

        return [
            'data_inicio' => $inicio->format('Y-m-d 00:00:00'),
            'data_fim' => $fim->format('Y-m-d'),
            'data_fim_exclusivo' => $fim->modify('+1 day')->format('Y-m-d 00:00:00'),
            'credor_id' => $credorId === false ? null : $credorId,
            'lote' => $lote !== '' ? mb_substr($lote, 0, 180) : null,
        ];
    }

    /**
     * Lista os lotes distintos disponíveis pro filtro, respeitando o
     * escopo de credor do usuário.
     *
     * @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopo
     * @return list<string>
     */
    public function listarLotes(?array $escopo): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $where = ["c.lote IS NOT NULL", "c.lote <> ''"];
        $params = [];

        $escopoSql = EscopoCredorSql::contrato($escopo, 'c', 'dash_lotes');
        $where[] = $escopoSql['sql'];
        $params = $escopoSql['params'];

        $sql = 'SELECT DISTINCT c.lote FROM contratos c WHERE ' . implode(' AND ', $where) . ' ORDER BY c.lote ASC';
        $stmt = $this->pdo->prepare($sql);
        $this->bindar($stmt, $params);
        $stmt->execute();

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * @param array<string,mixed> $filtros
     * @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopo
     * @return array{contactados:int,alo:int,cpc:int,acordos:int}
     */
    public function funil(array $filtros, ?array $escopo): array
    {
        if (!$this->moduloDisponivel()) {
            return ['contactados' => 0, 'alo' => 0, 'cpc' => 0, 'acordos' => 0];
        }

        $f = $this->normalizarFiltros($filtros);
        $acordos = $this->buscarAcordosDoCrm($f, $escopo);
        $acordosSemAcionamento = $this->acordosSemAcionamentoDeAcordo($acordos);

        return [
            'contactados' => $this->contarContactados($f, $escopo) + count($this->acordosDePessoasNaoContactadas($f, $escopo, $acordosSemAcionamento)),
            'alo' => $this->contarEventos($f, $escopo, 'alo') + count($acordosSemAcionamento),
            'cpc' => $this->contarEventos($f, $escopo, 'cpc') + count($acordosSemAcionamento),
            'acordos' => count($acordos),
        ];
    }

    /**
     * Achado (2026-09-23): "coloca uma visão por lote" - mesmo
     * funil (contactados/alô/CPC/acordos), quebrado por `contratos.lote`
     * (o mesmo campo de lote já usado em Dados Gerais / Mailing). Ignora um
     * eventual filtro de lote já aplicado em $filtros - a visão é sempre
     * "todos os lotes lado a lado" dentro do período/credor selecionados.
     *
     * @param array<string,mixed> $filtros
     * @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopo
     * @return list<array{lote:string,contactados:int,alo:int,cpc:int,acordos:int}>
     */
    public function funilPorLote(array $filtros, ?array $escopo): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $f = $this->normalizarFiltros($filtros);
        $f['lote'] = null;

        $porLote = [];
        $garantirLinha = function (string $lote) use (&$porLote): void {
            if (!isset($porLote[$lote])) {
                $porLote[$lote] = ['lote' => $lote, 'contactados' => 0, 'alo' => 0, 'cpc' => 0, 'acordos' => 0];
            }
        };

        [$sql, $params] = $this->montarBaseAcionamentos($f, $escopo, "COALESCE(NULLIF(c.lote, ''), 'Sem lote') AS lote, COUNT(DISTINCT ah.pessoa_id) AS total", null, 'lote');
        $stmt = $this->pdo->prepare($sql);
        $this->bindar($stmt, $params);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $lote = (string) $row['lote'];
            $garantirLinha($lote);
            $porLote[$lote]['contactados'] = (int) $row['total'];
        }

        foreach (['alo', 'cpc'] as $estagio) {
            [$sql, $params] = $this->montarBaseAcionamentos($f, $escopo, "COALESCE(NULLIF(c.lote, ''), 'Sem lote') AS lote, COUNT(*) AS total", $estagio, 'lote');
            $stmt = $this->pdo->prepare($sql);
            $this->bindar($stmt, $params);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $lote = (string) $row['lote'];
                $garantirLinha($lote);
                $porLote[$lote][$estagio] = (int) $row['total'];
            }
        }

        $acordos = $this->buscarAcordosDoCrm($f, $escopo);
        foreach ($acordos as $acordo) {
            $lote = self::loteDoRegistro($acordo);
            $garantirLinha($lote);
            $porLote[$lote]['acordos']++;
        }

        $acordosSemAcionamento = $this->acordosSemAcionamentoDeAcordo($acordos);
        foreach ($acordosSemAcionamento as $acordo) {
            $lote = self::loteDoRegistro($acordo);
            $porLote[$lote]['alo']++;
            $porLote[$lote]['cpc']++;
        }
        foreach ($this->acordosDePessoasNaoContactadas($f, $escopo, $acordosSemAcionamento, true) as $acordo) {
            $porLote[self::loteDoRegistro($acordo)]['contactados']++;
        }

        $linhas = array_values($porLote);
        usort($linhas, static fn (array $a, array $b): int => $b['contactados'] <=> $a['contactados']);

        return $linhas;
    }

    /** @param array{data_inicio:string,data_fim_exclusivo:string,credor_id:?int} $f */
    private function contarContactados(array $f, ?array $escopo): int
    {
        [$sql, $params] = $this->montarBaseAcionamentos($f, $escopo, 'COUNT(DISTINCT ah.pessoa_id)');
        $stmt = $this->pdo->prepare($sql);
        $this->bindar($stmt, $params);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /** @param array{data_inicio:string,data_fim_exclusivo:string,credor_id:?int} $f */
    private function contarEventos(array $f, ?array $escopo, string $estagio): int
    {
        [$sql, $params] = $this->montarBaseAcionamentos($f, $escopo, 'COUNT(*)', $estagio);
        $stmt = $this->pdo->prepare($sql);
        $this->bindar($stmt, $params);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Achado (2026-09-23): "os acordos do dashboard só deve
     * contabilizar os feitos pelo CRM" - acordos_operacionais também
     * recebe registros criados/atualizados automaticamente pela
     * conciliação com a ACME (cron de carência, reconciliação, botão
     * "Sincronizar"), que não são um fechamento real feito por um
     * operador aqui dentro. Reaproveita a mesma classificação de origem
     * já usada em Controle de Acordos (AcordoOperacionalRepository::
     * classificarOrigemOperacional()) e conta só os que dão 'works'.
     *
     * Conta o acordo fechado no CRM (origem 'works') OU com responsável
     * atribuído em Controle de Acordos (2026-09-25: exceção aprovada que
     * veio por API/carga e foi atribuída a um operador).
     *
     * Busca todos os acordos do período/credor/escopo (até um teto de
     * 20.000 - suficiente pra qualquer período coerente de dashboard) e já
     * filtra pra só os feitos pelo CRM. Usado tanto pra contar quanto pra
     * detalhar (drill-down), garantindo que os dois usam exatamente o
     * mesmo universo de acordos.
     *
     * @param array{data_inicio:string,data_fim_exclusivo:string,credor_id:?int} $f
     * @return list<array<string,mixed>>
     */
    private function buscarAcordosDoCrm(array $f, ?array $escopo): array
    {
        $where = ['ao.criado_em >= :data_inicio', 'ao.criado_em < :data_fim_exclusivo'];
        $params = ['data_inicio' => $f['data_inicio'], 'data_fim_exclusivo' => $f['data_fim_exclusivo']];

        if ($f['credor_id'] !== null) {
            $where[] = 'COALESCE(ao.credor_id, c.credor_id) = :credor_id';
            $params['credor_id'] = $f['credor_id'];
        }

        if (!empty($f['lote'])) {
            $where[] = 'c.lote = :lote';
            $params['lote'] = $f['lote'];
        }

        $escopoSql = EscopoCredorSql::acordo($escopo, 'ao', 'c', 'dash_acordo');
        $where[] = $escopoSql['sql'];
        $params = array_merge($params, $escopoSql['params']);

        $sql = 'SELECT ao.id, ao.criado_em, ao.contrato_id, ao.pessoa_id, ao.origem, ao.payload_extra,
                COALESCE(ao.credor_id, c.credor_id) AS credor_id,
                pe.nome AS pessoa_nome, pe.documento AS pessoa_documento,
                c.codigo_contrato, c.lote, cr.nome AS credor_nome, ao.valor_acordo, ao.quantidade_parcelas,
                ao.status_operacional, ao.operador_nome
            FROM acordos_operacionais ao
            INNER JOIN pessoas pe ON pe.id = ao.pessoa_id
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            LEFT JOIN credores cr ON cr.id = COALESCE(ao.credor_id, c.credor_id)
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ao.criado_em DESC
            LIMIT 20000';

        $stmt = $this->pdo->prepare($sql);
        $this->bindar($stmt, $params);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => AcordoOperacionalRepository::contabilizaNoDashboard($row)
        ));
    }

    /**
     * @param array{data_inicio:string,data_fim_exclusivo:string,credor_id:?int} $f
     * @return array{0:string,1:array<string,mixed>}
     */
    private function montarBaseAcionamentos(array $f, ?array $escopo, string $selectExpr, ?string $estagio = null, ?string $groupBy = null): array
    {
        $where = ['ah.criado_em >= :data_inicio', 'ah.criado_em < :data_fim_exclusivo'];
        $params = ['data_inicio' => $f['data_inicio'], 'data_fim_exclusivo' => $f['data_fim_exclusivo']];

        $joins = ['LEFT JOIN contratos c ON c.id = ah.contrato_id'];

        if ($estagio !== null) {
            $joins[] = 'LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id';
            $where[] = self::sqlEstagio($estagio);
        }

        if ($f['credor_id'] !== null) {
            $where[] = 'c.credor_id = :credor_id';
            $params['credor_id'] = $f['credor_id'];
        }

        if (!empty($f['lote'])) {
            $where[] = 'c.lote = :lote';
            $params['lote'] = $f['lote'];
        }

        $escopoSql = EscopoCredorSql::contrato($escopo, 'c', 'dash_atd');
        $where[] = $escopoSql['sql'];
        $params = array_merge($params, $escopoSql['params']);

        $sql = 'SELECT ' . $selectExpr . ' FROM atendimentos_historico ah ' . implode(' ', $joins)
            . ' WHERE ' . implode(' AND ', $where)
            . ($groupBy !== null ? ' GROUP BY ' . $groupBy : '');

        return [$sql, $params];
    }

    /**
     * Drill-down: lista os registros por trás de um estágio do funil.
     *
     * @param array<string,mixed> $filtros
     * @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopo
     * @return list<array<string,mixed>>
     */
    public function detalhar(string $estagio, array $filtros, ?array $escopo, int $limite = 300): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $limite = max(1, min($limite, 1000));
        $f = $this->normalizarFiltros($filtros);

        if ($estagio === 'acordos') {
            return $this->detalharAcordos($f, $escopo, $limite);
        }

        if (!in_array($estagio, ['contactados', 'alo', 'cpc'], true)) {
            throw new RuntimeException('Estágio de funil inválido.');
        }

        $where = ['ah.criado_em >= :data_inicio', 'ah.criado_em < :data_fim_exclusivo'];
        $params = ['data_inicio' => $f['data_inicio'], 'data_fim_exclusivo' => $f['data_fim_exclusivo']];
        $joins = [
            'INNER JOIN pessoas pe ON pe.id = ah.pessoa_id',
            'LEFT JOIN contratos c ON c.id = ah.contrato_id',
            'LEFT JOIN credores cr ON cr.id = c.credor_id',
            'LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id',
        ];

        if ($estagio !== 'contactados') {
            $where[] = self::sqlEstagio($estagio);
        }

        if ($f['credor_id'] !== null) {
            $where[] = 'c.credor_id = :credor_id';
            $params['credor_id'] = $f['credor_id'];
        }

        if (!empty($f['lote'])) {
            $where[] = 'c.lote = :lote';
            $params['lote'] = $f['lote'];
        }

        $escopoSql = EscopoCredorSql::contrato($escopo, 'c', 'dash_det');
        $where[] = $escopoSql['sql'];
        $params = array_merge($params, $escopoSql['params']);

        $sql = 'SELECT ah.id, ah.criado_em, pe.nome AS pessoa_nome, pe.documento AS pessoa_documento,
                c.codigo_contrato, cr.nome AS credor_nome, ah.canal,
                COALESCE(ar.nome, ah.resultado) AS resultado, ah.operador_nome, ah.contato_utilizado
            FROM atendimentos_historico ah ' . implode(' ', $joins) . '
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ah.criado_em DESC
            LIMIT ' . $limite;

        $stmt = $this->pdo->prepare($sql);
        $this->bindar($stmt, $params);
        $stmt->execute();
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $acordosSemAcionamento = $this->acordosSemAcionamentoDeAcordo($this->buscarAcordosDoCrm($f, $escopo));
        if ($estagio === 'contactados') {
            $acordosSemAcionamento = $this->acordosDePessoasNaoContactadas($f, $escopo, $acordosSemAcionamento);
        }
        if ($acordosSemAcionamento === []) {
            return $linhas;
        }

        foreach ($acordosSemAcionamento as $acordo) {
            $linhas[] = [
                'id' => null,
                'criado_em' => $acordo['criado_em'],
                'pessoa_nome' => $acordo['pessoa_nome'],
                'pessoa_documento' => $acordo['pessoa_documento'],
                'codigo_contrato' => $acordo['codigo_contrato'],
                'credor_nome' => $acordo['credor_nome'],
                'canal' => 'acordo',
                'resultado' => 'Acordo fechado (sem acionamento de acordo registrado no dia)',
                'operador_nome' => AcordoOperacionalRepository::responsavelAtribuido($acordo)['nome'] ?? $acordo['operador_nome'],
                'contato_utilizado' => null,
            ];
        }
        usort($linhas, static fn (array $a, array $b): int => strcmp((string) $b['criado_em'], (string) $a['criado_em']));

        return array_slice($linhas, 0, $limite);
    }

    private static function sqlEstagio(string $estagio): string
    {
        $flag = $estagio === 'cpc' ? 'ar.considera_contato_efetivo' : 'ar.considera_alo';
        return "((ah.canal = 'ligacao' AND {$flag} = 1) OR " . self::SQL_ACIONAMENTO_DE_ACORDO . ')';
    }

    /** @param array<string,mixed> $registro */
    private static function loteDoRegistro(array $registro): string
    {
        return trim((string) ($registro['lote'] ?? '')) !== '' ? (string) $registro['lote'] : 'Sem lote';
    }

    /**
     * Dos acordos informados, devolve os que NÃO têm acionamento com
     * tabulação de acordo da mesma pessoa, no mesmo dia e do mesmo credor
     * (ou sem contrato) - esses contam eles mesmos como evento Alô/CPC.
     * Público porque a projeção do OpenSearch usa exatamente a mesma regra.
     *
     * @param list<array<string,mixed>> $acordos linhas com id, pessoa_id, credor_id, criado_em
     * @return list<array<string,mixed>>
     */
    public function acordosSemAcionamentoDeAcordo(array $acordos): array
    {
        if ($acordos === []) {
            return [];
        }

        $pessoaIds = array_values(array_unique(array_map(static fn (array $a): int => (int) $a['pessoa_id'], $acordos)));
        $dias = array_map(static fn (array $a): string => substr((string) $a['criado_em'], 0, 10), $acordos);
        $holders = implode(',', array_fill(0, count($pessoaIds), '?'));

        $stmt = $this->pdo->prepare("SELECT DISTINCT ah.pessoa_id, DATE(ah.criado_em) AS dia, c.credor_id
            FROM atendimentos_historico ah
            LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id
            LEFT JOIN contratos c ON c.id = ah.contrato_id
            WHERE ah.pessoa_id IN ($holders)
              AND ah.criado_em >= ? AND ah.criado_em < DATE_ADD(?, INTERVAL 1 DAY)
              AND " . self::SQL_ACIONAMENTO_DE_ACORDO);
        $stmt->execute([...$pessoaIds, min($dias), max($dias)]);

        $comAcionamento = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $comAcionamento[$row['pessoa_id'] . '|' . $row['dia'] . '|' . ($row['credor_id'] ?? '')] = true;
        }

        return array_values(array_filter($acordos, static function (array $acordo) use ($comAcionamento): bool {
            $chave = $acordo['pessoa_id'] . '|' . substr((string) $acordo['criado_em'], 0, 10) . '|';
            return !isset($comAcionamento[$chave]) && !isset($comAcionamento[$chave . ($acordo['credor_id'] ?? '')]);
        }));
    }

    /**
     * Dos acordos sem acionamento, devolve um por pessoa que ainda não está
     * entre os contactados do período (por lote, se $porLote).
     *
     * @param list<array<string,mixed>> $acordos
     * @return list<array<string,mixed>>
     */
    private function acordosDePessoasNaoContactadas(array $f, ?array $escopo, array $acordos, bool $porLote = false): array
    {
        if ($acordos === []) {
            return [];
        }

        [$sql, $params] = $this->montarBaseAcionamentos($f, $escopo, "DISTINCT ah.pessoa_id, COALESCE(NULLIF(c.lote, ''), 'Sem lote') AS lote");
        $stmt = $this->pdo->prepare($sql);
        $this->bindar($stmt, $params);
        $stmt->execute();

        $vistos = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $vistos[$porLote ? $row['lote'] . '|' . $row['pessoa_id'] : (string) $row['pessoa_id']] = true;
        }

        $novos = [];
        foreach ($acordos as $acordo) {
            $chave = $porLote ? self::loteDoRegistro($acordo) . '|' . $acordo['pessoa_id'] : (string) $acordo['pessoa_id'];
            if (!isset($vistos[$chave])) {
                $vistos[$chave] = true;
                $novos[] = $acordo;
            }
        }
        return $novos;
    }

    /** @param array{data_inicio:string,data_fim_exclusivo:string,credor_id:?int} $f @return list<array<string,mixed>> */
    private function detalharAcordos(array $f, ?array $escopo, int $limite): array
    {
        return array_slice($this->buscarAcordosDoCrm($f, $escopo), 0, $limite);
    }

    private function parseData(string $valor): ?DateTimeImmutable
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($valor))->setTime(0, 0);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $params */
    private function bindar(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $chave => $valor) {
            $stmt->bindValue($chave, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
