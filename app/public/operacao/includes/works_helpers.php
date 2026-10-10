<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Repositories\ConsultaClienteRepository;
use CRM\Modules\Operacao\Repositories\CredorConfiguracaoRepository;

function worksPodeAtribuirAcordo(): bool
{
    return usuarioEhAdminOperacao()
        || usuarioPode('acordos.atribuir')
        || usuarioPode('acordos.gerenciar')
        || usuarioPode('supervisao.visualizar');
}

/** @return list<array{id:int,nome:string,email:string}> */
function worksUsuariosAtribuiveis(PDO $pdo): array
{
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM usuarios')->fetchAll(PDO::FETCH_ASSOC);
        $fields = array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $cols ?: []);
        if (!in_array('id', $fields, true)) {
            return [];
        }

        $nomeCol = null;
        foreach (['nome', 'name', 'usuario', 'login', 'email'] as $candidate) {
            if (in_array($candidate, $fields, true)) {
                $nomeCol = $candidate;
                break;
            }
        }
        if ($nomeCol === null) {
            return [];
        }

        $emailCol = in_array('email', $fields, true) ? 'email' : null;
        $where = [];
        if (in_array('status', $fields, true)) {
            $where[] = "status = 'ativo'";
        } elseif (in_array('ativo', $fields, true)) {
            $where[] = 'ativo = 1';
        }

        $sql = 'SELECT id, `' . $nomeCol . '` AS nome'
            . ($emailCol !== null ? ', `' . $emailCol . '` AS email' : ', NULL AS email')
            . ' FROM usuarios'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY `' . $nomeCol . '` ASC LIMIT 300';

        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $usuarios = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $nome = trim((string) ($row['nome'] ?? ''));
            if ($id <= 0 || $nome === '') {
                continue;
            }
            $usuarios[] = [
                'id' => $id,
                'nome' => $nome,
                'email' => trim((string) ($row['email'] ?? '')),
            ];
        }
        return $usuarios;
    } catch (Throwable) {
        return [];
    }
}

/** @param list<array{id:int,nome:string,email:string}> $usuarios */
function worksResolverResponsavelAcordo(array $usuarios, int $idSolicitado, int $fallbackId, string $fallbackNome): array
{
    if ($idSolicitado > 0) {
        foreach ($usuarios as $usuario) {
            if ((int) $usuario['id'] === $idSolicitado) {
                return ['id' => (int) $usuario['id'], 'nome' => (string) $usuario['nome']];
            }
        }
    }

    return ['id' => $fallbackId, 'nome' => $fallbackNome];
}

function adaptorInt(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? 0 : (int) $id;
}

function adaptorStatusAcordoLabel(?string $status): string
{
    return AcordoOperacionalRepository::STATUS_OPERACIONAL[(string) $status] ?? ucfirst((string) ($status ?: 'sem status'));
}

function adaptorFormaPagamentoLabel(?string $forma): string
{
    return AcordoOperacionalRepository::FORMAS_PAGAMENTO[(string) $forma] ?? ucfirst((string) ($forma ?: 'não informado'));
}

function adaptorPeriodicidadeLabel(?string $periodicidade): string
{
    return AcordoOperacionalRepository::PERIODICIDADES[(string) $periodicidade] ?? ucfirst((string) ($periodicidade ?: 'mensal'));
}

function adaptorContatoLabel(array $contato): string
{
    $tipo = ConsultaClienteRepository::contatoTipoLabel((string) ($contato['tipo'] ?? 'outro'));
    $valor = (string) ($contato['valor'] ?? '');
    return trim($tipo . ' - ' . $valor);
}

function adaptorEnderecoCompleto(array $endereco): string
{
    $partes = [];
    foreach (['logradouro','numero','complemento','bairro','cidade','uf','cep'] as $campo) {
        $valor = trim((string) ($endereco[$campo] ?? ''));
        if ($valor !== '') {
            $partes[] = $valor;
        }
    }

    return $partes === [] ? '-' : implode(', ', $partes);
}

/** @return array<string,mixed> */
function adaptorPayloadArray(mixed $payload): array
{
    if (!is_string($payload) || trim($payload) === '') {
        return [];
    }

    $decoded = json_decode($payload, true);
    return is_array($decoded) ? $decoded : [];
}


/** @return array{nivel:int,label:string,classe:string} */
function worksContatoPrioridade(array $contato): array
{
    $categoria = adaptorContatoCategoriaAtual($contato);
    $situacao = adaptorContatoSituacaoAtual($contato);
    $status = mb_strtolower((string) ($contato['status'] ?? ''));
    $principal = ((int) ($contato['principal'] ?? 0)) === 1;

    if ($categoria === 'hot' || $status === 'hot' || $status === 'h') {
        return ['nivel' => 1, 'label' => 'HOT', 'classe' => 'hot'];
    }
    if ($situacao === 'validado' || in_array($status, ['correto', 'o', 'ok'], true)) {
        return ['nivel' => 2, 'label' => 'Validado', 'classe' => 'valid'];
    }
    if ($principal) {
        return ['nivel' => 3, 'label' => 'Principal', 'classe' => 'primary'];
    }
    if ($situacao === 'negativado' || in_array($status, ['incorreto', 'negativado', 'ruim', 'n'], true)) {
        return ['nivel' => 9, 'label' => 'Negativado', 'classe' => 'bad'];
    }
    if ($situacao === 'falta_validar') {
        return ['nivel' => 5, 'label' => 'Falta validar', 'classe' => 'pending'];
    }

    return ['nivel' => 6, 'label' => 'Sem validação', 'classe' => 'neutral'];
}

function worksBoletoAcaoLabel(string $acao): string
{
    return match ($acao) {
        'download_pdf' => 'Boleto baixado',
        'copiar_linha_digitavel' => 'Linha digitável copiada',
        'cache_atualizado' => 'Boleto consultado/salvo',
        default => ucfirst(str_replace('_', ' ', $acao)),
    };
}

/** @return list<array{quando:string,tipo:string,titulo:string,detalhe:string,usuario:string,classe:string}> */
function worksMontarHistoricoFinanceiro(?array $contrato, array $acordos, array $parcelasAcordo): array
{
    $eventos = [];

    foreach ($acordos as $acordo) {
        $payload = adaptorPayloadArray($acordo['payload_extra'] ?? null);
        $fechamento = is_array($payload['operador_fechamento'] ?? null) ? $payload['operador_fechamento'] : [];
        $responsavel = is_array($payload['operador_responsavel'] ?? null) ? $payload['operador_responsavel'] : [];
        $quando = (string) ($acordo['criado_em'] ?? $acordo['atualizado_em'] ?? $acordo['data_promessa_pagamento'] ?? '');
        $eventos[] = [
            'quando' => $quando,
            'tipo' => 'Acordo',
            'titulo' => 'Promessa/acordo #' . (int) ($acordo['id'] ?? 0) . ' - ' . adaptorStatusAcordoLabel((string) ($acordo['status_operacional'] ?? '')),
            'detalhe' => 'Valor ' . dinheiro($acordo['valor_acordo'] ?? 0) . ' - vencimento ' . dataBr($acordo['data_promessa_pagamento'] ?? null) . ' - responsável ' . (string) ($responsavel['nome'] ?? 'Não definido'),
            'usuario' => (string) ($fechamento['nome'] ?? ($acordo['operador_nome'] ?? 'Não definido')),
            'classe' => 'agreement',
        ];

        $histResp = is_array($payload['historico_responsavel'] ?? null) ? $payload['historico_responsavel'] : [];
        foreach ($histResp as $hist) {
            if (!is_array($hist)) { continue; }
            $depois = is_array($hist['depois'] ?? null) ? $hist['depois'] : [];
            $eventos[] = [
                'quando' => (string) ($depois['atribuido_em'] ?? ''),
                'tipo' => 'Responsável',
                'titulo' => 'Responsável do acordo alterado',
                'detalhe' => 'Novo responsável: ' . (string) ($depois['nome'] ?? 'Não definido') . (trim((string) ($hist['observacao'] ?? '')) !== '' ? ' - ' . trim((string) $hist['observacao']) : ''),
                'usuario' => (string) ($depois['atribuido_por_nome'] ?? 'Não definido'),
                'classe' => 'owner',
            ];
        }

        foreach ($parcelasAcordo[(int) ($acordo['id'] ?? 0)] ?? [] as $parcela) {
            $status = (string) ($parcela['status'] ?? '');
            if ($status === 'paga' || $status === 'vencida') {
                $eventos[] = [
                    'quando' => (string) ($parcela['data_pagamento'] ?? $parcela['data_vencimento'] ?? ''),
                    'tipo' => 'Parcela',
                    'titulo' => 'Parcela #' . (int) ($parcela['numero_parcela'] ?? 0) . ' ' . AcordoOperacionalRepository::statusParcelaLabel($status),
                    'detalhe' => 'Valor ' . dinheiro($parcela['valor_parcela'] ?? 0) . ' - vencimento ' . dataBr($parcela['data_vencimento'] ?? null),
                    'usuario' => 'Sistema',
                    'classe' => $status === 'paga' ? 'paid' : 'late',
                ];
            }
        }
    }

    $payloadContrato = adaptorPayloadArray($contrato['payload_extra'] ?? null);
    $boletos = is_array($payloadContrato['acme_boletos'] ?? null) ? $payloadContrato['acme_boletos'] : [];
    foreach ($boletos as $installmentId => $boleto) {
        if (!is_array($boleto)) { continue; }
        $acoes = is_array($boleto['acoes'] ?? null) ? $boleto['acoes'] : [];
        foreach ($acoes as $acao) {
            if (!is_array($acao)) { continue; }
            $acaoCodigo = (string) ($acao['acao'] ?? '');
            $eventos[] = [
                'quando' => (string) ($acao['criado_em'] ?? ($boleto['atualizado_em'] ?? '')),
                'tipo' => 'Boleto',
                'titulo' => worksBoletoAcaoLabel($acaoCodigo),
                'detalhe' => 'Parcela ACME ' . (string) ($boleto['installment_id'] ?? $installmentId) . (!empty($boleto['linha_digitavel']) ? ' - linha digitável disponível em cache' : ''),
                'usuario' => (string) ($acao['usuario_nome'] ?? 'Sistema'),
                'classe' => $acaoCodigo === 'download_pdf' ? 'download' : 'slip',
            ];
        }
    }

    usort($eventos, static function (array $a, array $b): int {
        return strcmp((string) ($b['quando'] ?? ''), (string) ($a['quando'] ?? ''));
    });

    return array_slice($eventos, 0, 12);
}

function adaptorSlugSimples(?string $valor): string
{
    $valor = mb_strtolower(trim((string) $valor));
    $valor = strtr($valor, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', ' ' => '_', '-' => '_', '/' => '_',
    ]);
    return preg_replace('/[^a-z0-9_]+/', '', $valor) ?? '';
}

function adaptorContatoCategoriaAtual(array $contato): string
{
    $raw = adaptorSlugSimples((string) ($contato['subtipo'] ?? ''));
    if ($raw !== '' && array_key_exists($raw, AtendimentoAdaptorRepository::CONTATO_CATEGORIAS)) {
        return $raw;
    }

    $tipo = (string) ($contato['tipo'] ?? '');
    $status = (string) ($contato['status'] ?? '');
    if ($status === 'hot' || str_contains($raw, 'hot')) {
        return 'hot';
    }
    if ($tipo === 'email' || str_contains($raw, 'email')) {
        return 'email';
    }
    if ($tipo === 'whatsapp' || str_contains($raw, 'whatsapp') || str_contains($raw, 'wpp')) {
        return 'whatsapp';
    }
    if (str_contains($raw, 'comercial') || str_contains($raw, 'trabalho')) {
        return 'comercial';
    }
    if (str_contains($raw, 'residencial') || str_contains($raw, 'fixo')) {
        return 'residencial';
    }
    if ($tipo === 'telefone' || str_contains($raw, 'celular') || str_contains($raw, 'fone')) {
        return 'celular';
    }

    return '';
}

function adaptorContatoSituacaoAtual(array $contato): string
{
    $payload = adaptorPayloadArray($contato['payload_extra'] ?? null);
    $situacao = (string) ($payload['situacao_validacao'] ?? '');
    return array_key_exists($situacao, AtendimentoAdaptorRepository::CONTATO_SITUACOES) ? $situacao : '';
}

/**
 * Regra de negócio (2026-09-23): contato negativado não pode aparecer como
 * opção pra fechar acordo nem pra acionamento.
 */
function adaptorContatoNegativado(array $contato): bool
{
    return adaptorContatoSituacaoAtual($contato) === 'negativado';
}

function adaptorEnderecoCategoriaAtual(array $endereco): string
{
    $raw = adaptorSlugSimples((string) ($endereco['tipo'] ?? ''));
    return array_key_exists($raw, AtendimentoAdaptorRepository::ENDERECO_CATEGORIAS) ? $raw : '';
}

function adaptorOrigemContatoLabel(array $contato): string
{
    $origem = trim((string) ($contato['ultima_carga_nome'] ?? ''));
    if ($origem === '') {
        $origem = trim((string) ($contato['origem_carga_nome'] ?? ''));
    }
    if ($origem === '') {
        $origem = trim((string) ($contato['origem_descricao'] ?? $contato['origem'] ?? ''));
    }

    return $origem !== '' ? $origem : 'manual';
}

function adaptorTabUrl(int $contratoId, string $tab, string $termoBusca, string $tipoBusca, ?int $estrategiaItemId = null): string
{
    if ($estrategiaItemId === null) {
        $estrategiaItemId = (int) ($GLOBALS['estrategiaItemId'] ?? 0);
    }

    return '/operacao/works.php' . queryString([
        'contrato_id' => $contratoId,
        'tab' => $tab,
        'q' => $termoBusca,
        'tipo' => $tipoBusca,
        'estrategia_item_id' => $estrategiaItemId > 0 ? $estrategiaItemId : null,
        'origem' => $estrategiaItemId > 0 ? 'estrategia' : null,
    ]);
}

function adaptorVoltarUrl(string $termoBusca, string $tipoBusca): string
{
    return '/operacao/works.php' . queryString(['q' => $termoBusca, 'tipo' => $tipoBusca]);
}

function adaptorBadgeClass(?string $status): string
{
    $status = mb_strtolower(trim((string) $status));
    return match ($status) {
        'pendente', 'formalizado', 'ativo', 'ativa', '(a) - ativa' => 'adaptor-badge-green',
        'quebrado', 'cancelado', 'incorreto', 'bloqueado' => 'adaptor-badge-red',
        'cumprido', 'correto', 'hot', 'liquidada', 'liquidado', 'quitada', 'quitado', 'paga', 'pago' => 'adaptor-badge-blue',
        default => 'adaptor-badge-gray',
    };
}




/** @return array<string,mixed> */
function adaptorAcordoAcmeCacheContrato(array $contrato): array
{
    $payload = adaptorPayloadArray($contrato['payload_extra'] ?? null);
    $acordo = $payload['acme_acordo'] ?? [];
    return is_array($acordo) ? $acordo : [];
}

/** @return array<string,mixed> */
function adaptorAcordoAcmeAgreementContrato(array $contrato): array
{
    $cache = adaptorAcordoAcmeCacheContrato($contrato);
    $agreement = is_array($cache['agreement'] ?? null) ? $cache['agreement'] : [];
    if ($agreement === [] && is_array($cache['raw']['Agreement'] ?? null)) {
        $agreement = $cache['raw']['Agreement'];
    }
    if ($agreement === [] && is_array($cache['Agreement'] ?? null)) {
        $agreement = $cache['Agreement'];
    }

    return is_array($agreement) ? $agreement : [];
}

function adaptorAcordoAcmeDataCongelamento(array $contrato): ?DateTimeImmutable
{
    $agreement = adaptorAcordoAcmeAgreementContrato($contrato);
    $agreementId = trim((string) ($agreement['AgreementID'] ?? ''));
    $agreementDate = trim((string) ($agreement['AgreementDate'] ?? ''));
    if ($agreementId === '' || $agreementDate === '' || str_starts_with($agreementDate, '0001-01-01')) {
        return null;
    }

    try {
        return (new DateTimeImmutable($agreementDate))->setTime(0, 0);
    } catch (Throwable) {
        return null;
    }
}

function adaptorContratoRetiradoCredor(array $contrato): bool
{
    if ((int) ($contrato['retirado_credor'] ?? 0) === 1) {
        return true;
    }

    $status = mb_strtolower(trim((string) ($contrato['status_contrato'] ?? '')));
    $status = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $status) ?: $status;
    return str_contains($status, 'retirado')
        || str_contains($status, 'devolvido')
        || str_contains($status, 'fora da carga')
        || str_contains($status, 'fora_de_carga');
}

function adaptorContratoStatusOperacional(array $contrato): string
{
    if (adaptorContratoRetiradoCredor($contrato)) {
        return 'Retirado pelo credor';
    }

    return trim((string) ($contrato['status_contrato'] ?? '')) ?: 'Em Cobrança';
}

function adaptorContratoRetiradoNota(array $contrato): string
{
    if (!adaptorContratoRetiradoCredor($contrato)) {
        return '';
    }

    $ultima = trim((string) ($contrato['ultima_carga_data_referencia'] ?? ''));
    if ($ultima === '') {
        $ultima = trim((string) ($contrato['ultima_carga_finalizado_em'] ?? ''));
    }
    $escopo = trim((string) ($contrato['ultima_carga_escopo_data_referencia'] ?? ''));
    if ($escopo === '') {
        $escopo = trim((string) ($contrato['ultima_carga_escopo_finalizado_em'] ?? ''));
    }

    $texto = 'Contrato retirado pelo credor. Não negociar.';
    if ($ultima !== '') {
        $texto .= ' Última aparição: ' . dataBr(substr($ultima, 0, 10)) . '.';
    }
    if ($escopo !== '') {
        $texto .= ' Última carga do escopo: ' . dataBr(substr($escopo, 0, 10)) . '.';
    }
    return $texto;
}
/** @return array{base:?int,atual:?int,data_base:?string,dias_projetados:int,fonte:string,label:string,nota:string} */
function adaptorAtrasoContrato(array $contrato): array
{
    $baseRaw = $contrato['dias_atraso'] ?? null;
    $base = is_numeric($baseRaw) ? (int) $baseRaw : null;
    $dataBase = trim((string) ($contrato['ultima_carga_data_referencia'] ?? ''));
    if ($dataBase === '') {
        $dataBase = trim((string) ($contrato['ultima_carga_finalizado_em'] ?? ''));
    }
    if ($dataBase === '') {
        $dataBase = trim((string) ($contrato['atualizado_em'] ?? ''));
    }

    $dataBaseIso = null;
    $diasProjetados = 0;
    if ($base !== null && $dataBase !== '') {
        try {
            $baseDate = (new DateTimeImmutable($dataBase))->setTime(0, 0);
            $today = (new DateTimeImmutable('today'))->setTime(0, 0);
            $dataAcordo = adaptorAcordoAcmeDataCongelamento($contrato);
            $retiradoCredor = adaptorContratoRetiradoCredor($contrato);
            if ($dataAcordo !== null && $dataAcordo < $today) {
                $dataAlvo = $dataAcordo;
            } elseif ($retiradoCredor) {
                $dataAlvo = $baseDate;
            } else {
                $dataAlvo = $today;
            }
            $dataBaseIso = $baseDate->format('Y-m-d');
            if ($baseDate < $dataAlvo) {
                $diasProjetados = max(0, (int) $baseDate->diff($dataAlvo)->format('%a'));
            }
        } catch (Throwable) {
            $dataBaseIso = null;
            $diasProjetados = 0;
        }
    }

    $dataAcordoCongelamento = adaptorAcordoAcmeDataCongelamento($contrato);
    $atual = $base === null ? null : $base + $diasProjetados;
    $fonte = $dataAcordoCongelamento !== null ? 'acordo' : (adaptorContratoRetiradoCredor($contrato) ? 'retirado_credor' : ($diasProjetados > 0 ? 'projetado' : 'base'));
    $label = $atual === null ? '-' : (string) $atual;
    $nota = '';
    if ($base !== null) {
        if ($dataAcordoCongelamento !== null && $dataBaseIso !== null) {
            $nota = 'Atraso congelado no acordo de ' . dataBr($dataAcordoCongelamento->format('Y-m-d')) . ': base ' . $base . ' dia(s) na carga de ' . dataBr($dataBaseIso) . ' + ' . $diasProjetados . ' dia(s) até o acordo.';
        } elseif (adaptorContratoRetiradoCredor($contrato) && $dataBaseIso !== null) {
            $nota = 'Contrato retirado pelo credor. Atraso congelado na última carga em que apareceu: ' . dataBr($dataBaseIso) . '.';
        } elseif ($diasProjetados > 0 && $dataBaseIso !== null) {
            $nota = 'Base ' . $base . ' dia(s) na carga de ' . dataBr($dataBaseIso) . ' + ' . $diasProjetados . ' dia(s) sem carga nova.';
        } elseif ($dataBaseIso !== null) {
            $nota = 'Atraso conforme carga/base de ' . dataBr($dataBaseIso) . '.';
        } else {
            $nota = 'Atraso conforme base.';
        }
    }

    return [
        'base' => $base,
        'atual' => $atual,
        'data_base' => $dataBaseIso,
        'dias_projetados' => $diasProjetados,
        'fonte' => $fonte,
        'label' => $label,
        'nota' => $nota,
    ];
}

function adaptorFinanceiroPerfil(array $contrato, ?CredorConfiguracaoRepository $configuracoes = null): string
{
    $credorId = (int) ($contrato['credor_id'] ?? 0);
    $produto = trim((string) ($contrato['produto'] ?? ''));
    if ($configuracoes !== null && $credorId > 0) {
        $configurado = mb_strtolower(trim((string) ($configuracoes->valor($credorId, 'financeiro.perfil', $produto) ?? '')));
        if ($configurado !== '') {
            return $configurado;
        }
    }

    $credor = strtoupper(trim((string) ($contrato['credor_codigo'] ?? $contrato['credor_nome'] ?? '')));
    if ($credor === 'ACME') {
        return 'acme';
    }

    return 'padrao';
}

function adaptorDecimalEntrada(mixed $value): ?float
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    if (str_contains($raw, ',')) {
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    }

    $decimal = filter_var($raw, FILTER_VALIDATE_FLOAT);
    return $decimal === false ? null : round((float) $decimal, 2);
}

function adaptorValorPropostaDescricao(string $descricao): ?float
{
    if (!preg_match_all('/(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2}|\d+\.\d{2})/', $descricao, $matches) || empty($matches[1])) {
        return null;
    }

    return adaptorDecimalEntrada((string) end($matches[1]));
}

function adaptorParcelasPropostaDescricao(string $descricao): int
{
    if (preg_match('/a\s*vista|à\s*vista/iu', $descricao)) {
        return 1;
    }
    if (preg_match('/\b(\d+)\s*\+\s*(\d+)\b/u', $descricao, $m)) {
        return max(1, (int) $m[1] + (int) $m[2]);
    }
    if (preg_match('/\b(\d+)\s*x\b/iu', $descricao, $m)) {
        return max(1, (int) $m[1]);
    }

    return 1;
}

function adaptorDataIsoParaDate(mixed $value, string $fallback): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return $fallback;
    }

    try {
        return (new DateTimeImmutable($raw))->format('Y-m-d');
    } catch (Throwable) {
        $candidate = substr($raw, 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate) ? $candidate : $fallback;
    }
}

function adaptorFormaPagamentoCredor(string $tag): string
{
    $tag = strtoupper(trim($tag));
    return match (true) {
        str_contains($tag, 'BOLETO') => 'boleto',
        str_contains($tag, 'PIX') => 'pix',
        str_contains($tag, 'DEBIT') => 'debito_conta',
        str_contains($tag, 'CART') => 'cartao',
        str_contains($tag, 'TRANSF') => 'transferencia',
        default => 'nao_informado',
    };
}

/**
 * Achado (2026-10-01): acordo fechado normalmente pelo Pactua (via
 * proposta pré-gerada, SaveGenerateProposal) estava sendo tabulado/enviado
 * pra ACME como "ACORDO - EXCEÇÃO" (ACME_032, ActionID 32) em vez de
 * "ACORDO"/"Promessa de Acordo" (ACME_002, ActionID 2) - três tabulações
 * diferentes (ACME_032, ACME_185 "Pagamento Efetuado", ACME_002) compartilham
 * resultado_legacy='promessa_pagamento' na planilha real da ACME, e a função
 * só pegava a primeira por ordem (ACME_032, que tem a menor ordem) - sem
 * nenhuma relação com o que faz sentido pra um fechamento normal.
 *
 * Correção definitiva (migration 097): existe agora uma marcação explícita
 * `padrao_fechamento_acordo`, editável em Administração > Tabulações,
 * escopada por credor (`credor_id` - cada credor tem seu próprio catálogo
 * e pode ter um ActionID diferente como padrão). Prioridade de escolha:
 * 1) tabulação marcada como padrão para o credor do contrato;
 * 2) tabulação marcada como padrão "global" (credor_id nulo);
 * 3) comportamento antigo (primeira por ordem), só como última rede de
 *    segurança caso nenhuma esteja marcada ainda.
 *
 * @param list<array<string,mixed>> $resultados
 */
function adaptorResultadoPromessaId(array $resultados, ?int $credorId = null): ?int
{
    $candidatos = array_values(array_filter(
        $resultados,
        static fn (array $resultado): bool => (string) ($resultado['resultado_legacy'] ?? '') === 'promessa_pagamento'
    ));
    if ($candidatos === []) {
        return null;
    }

    $extrairId = static function (array $resultado): ?int {
        $id = filter_var($resultado['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : (int) $id;
    };

    $ehPadrao = static fn (array $r): bool => (int) ($r['padrao_fechamento_acordo'] ?? 0) === 1;

    if ($credorId !== null) {
        foreach ($candidatos as $resultado) {
            if ($ehPadrao($resultado) && (int) ($resultado['credor_id'] ?? 0) === $credorId) {
                return $extrairId($resultado);
            }
        }
    }

    foreach ($candidatos as $resultado) {
        if ($ehPadrao($resultado) && ($resultado['credor_id'] ?? null) === null) {
            return $extrairId($resultado);
        }
    }

    return $extrairId($candidatos[0]);
}


/** @return list<string> */
function worksConfigLista(?string $raw): array
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $items = $decoded;
    } else {
        $items = preg_split('/[\r\n,;|]+/u', $raw) ?: [];
    }

    $out = [];
    foreach ($items as $item) {
        $value = mb_strtolower(trim((string) $item));
        if ($value !== '') {
            $out[] = $value;
        }
    }

    return array_values(array_unique($out));
}

function worksResultadoContextoTexto(array $resultado): string
{
    return mb_strtolower(trim(implode(' ', [
        (string) ($resultado['id'] ?? ''),
        (string) ($resultado['resultado_legacy'] ?? ''),
        (string) ($resultado['nome'] ?? ''),
        (string) ($resultado['categoria'] ?? ''),
    ])));
}

function worksResultadoEhFinanceiro(array $resultado): bool
{
    $texto = worksResultadoContextoTexto($resultado);
    foreach (['acordo', 'promessa', 'negocia', 'boleto', 'parcel', 'pagamento'] as $needle) {
        if (str_contains($texto, $needle)) {
            return true;
        }
    }
    return false;
}

/**
 * @param list<array<string,mixed>> $resultados
 * @param array<string,mixed> $contrato
 * @return list<array<string,mixed>>
 */
function worksFiltrarResultadosContexto(
    array $resultados,
    array $contrato,
    bool $temAcordoAtivo,
    CredorConfiguracaoRepository $configuracoes
): array {
    $credorId = (int) ($contrato['credor_id'] ?? 0);
    $produto = trim((string) ($contrato['produto'] ?? ''));
    $permitidos = $credorId > 0
        ? worksConfigLista($configuracoes->valor($credorId, 'acionamento.resultados_permitidos', $produto))
        : [];
    $bloqueados = $credorId > 0
        ? worksConfigLista($configuracoes->valor($credorId, 'acionamento.resultados_bloqueados', $produto))
        : [];

    $retirado = adaptorContratoRetiradoCredor($contrato);
    $podeAcordo = usuarioPode('acordos.criar');

    $out = [];
    foreach ($resultados as $resultado) {
        $texto = worksResultadoContextoTexto($resultado);

        if (($retirado || $temAcordoAtivo || !$podeAcordo) && worksResultadoEhFinanceiro($resultado)) {
            continue;
        }

        $matchPermitido = $permitidos === [];
        foreach ($permitidos as $token) {
            if ($token !== '' && str_contains($texto, $token)) {
                $matchPermitido = true;
                break;
            }
        }
        if (!$matchPermitido) {
            continue;
        }

        $bloqueado = false;
        foreach ($bloqueados as $token) {
            if ($token !== '' && str_contains($texto, $token)) {
                $bloqueado = true;
                break;
            }
        }
        if ($bloqueado) {
            continue;
        }

        $out[] = $resultado;
    }

    return $out;
}

function worksResultadoRef(array $resultado): string
{
    return ($resultado['id'] ?? null) !== null
        ? 'id:' . (int) $resultado['id']
        : 'legacy:' . (string) ($resultado['resultado_legacy'] ?? '');
}

function worksResultadoRefPermitido(string $resultadoRef, array $resultados): bool
{
    foreach ($resultados as $resultado) {
        if (hash_equals(worksResultadoRef($resultado), $resultadoRef)) {
            return true;
        }
    }
    return false;
}

function adaptorContatoSelecionadoValor(array $contatos, string $valor): ?int
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }

    foreach ($contatos as $contato) {
        if (trim((string) ($contato['valor'] ?? '')) === $valor) {
            return (int) $contato['id'];
        }
    }

    return null;
}

$hoje = (new DateTimeImmutable('today'))->format('Y-m-d');
$proximaParcelaPadrao = (new DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d');

