<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AtendimentoResultadoRepository;
use CRM\Modules\Operacao\Repositories\CredorIntegracaoRepository;

require_once __DIR__ . '/_bootstrap.php';

/**
 * Substitui a lista de tabulações do CRM pela lista real de eventos da ACME
 * (requisito de 2026-09-22) e cadastra o de/para com a integração ACME
 * (ACIONAMENTOS/ACME_ACIONAMENTOS), usando o ActionID de cada evento como
 * codigo_externo - é o que AcmeApiClient::inserirAcionamento() manda pro
 * campo "ActionID" da API real.
 *
 * Nome de cada tabulação: usa a coluna "Evento Adaptor" quando ela veio
 * preenchida na planilha da ACME; cai pro nome padrão (ActionDescription)
 * quando não veio. Código interno sempre ACME_<ActionID> (nunca colide,
 * mesmo quando duas ActionDescription diferentes compartilham o mesmo
 * "Evento Adaptor" - ex.: ActionID 129 e 181 os dois viram "Desconhece
 * dívida"; ficam com nomes iguais na tela mas códigos e ActionIDs
 * distintos, sem risco de colisão no banco).
 *
 * resultado_legacy/categoria/considera_* são classificação interna,
 * não veio da planilha - dá pra ajustar depois pela tela Configurações >
 * Tabulações sem precisar rodar este script de novo.
 *
 * Uso: php console/seed_tabulacoes_acme.php [--confirmar]
 * Sem --confirmar, só mostra o que faria (dry run).
 */

function out(string $m): void
{
    echo $m . PHP_EOL;
}

/**
 * @var list<array{
 *   action_id:int, descricao:string, adaptor:?string,
 *   legacy:string, categoria:string, sucesso:int, efetivo:int, tel_ruim:int, reagenda:int
 * }>
 */
$eventos = [
    ['action_id' => 73,  'descricao' => 'Alega quitação', 'adaptor' => 'Alega Pagamento', 'legacy' => 'outro', 'categoria' => 'Negociação', 'sucesso' => 0, 'efetivo' => 1, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 180, 'descricao' => 'Campanha Email', 'adaptor' => 'ENVIO E-MAIL', 'legacy' => 'outro', 'categoria' => 'Campanha', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 179, 'descricao' => 'Campanha Whatsapp', 'adaptor' => 'Envio - WhatsApp', 'legacy' => 'outro', 'categoria' => 'Campanha', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 140, 'descricao' => 'Confirmação de parcela', 'adaptor' => 'Preventivo - Positivo', 'legacy' => 'atendeu', 'categoria' => 'Preventivo', 'sucesso' => 1, 'efetivo' => 1, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 181, 'descricao' => 'Desconhece a Dívida', 'adaptor' => 'Desconhece dívida', 'legacy' => 'outro', 'categoria' => 'Contato efetivo', 'sucesso' => 0, 'efetivo' => 1, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 187, 'descricao' => 'Desconhece o Cliente', 'adaptor' => 'Desconhece Cliente', 'legacy' => 'numero_invalido', 'categoria' => 'Telefone ruim', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 1, 'reagenda' => 0],
    ['action_id' => 10,  'descricao' => 'Devedor recusa pagamento', 'adaptor' => 'Recusa - Sem interesse em neg.', 'legacy' => 'outro', 'categoria' => 'Contato efetivo', 'sucesso' => 0, 'efetivo' => 1, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 6,   'descricao' => 'Em Negociação', 'adaptor' => 'Cliente vai estudar a proposta', 'legacy' => 'em_negociacao', 'categoria' => 'Negociação', 'sucesso' => 1, 'efetivo' => 1, 'tel_ruim' => 0, 'reagenda' => 1],
    ['action_id' => 177, 'descricao' => 'Envio Boleto Via Email', 'adaptor' => null, 'legacy' => 'outro', 'categoria' => 'Documentos', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 178, 'descricao' => 'Envio Boleto Via Whatsapp', 'adaptor' => null, 'legacy' => 'outro', 'categoria' => 'Documentos', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 141, 'descricao' => 'Envio de SMS', 'adaptor' => 'ENVIO SMS', 'legacy' => 'outro', 'categoria' => 'Campanha', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 133, 'descricao' => 'Estratégia - Levantamento de depósito judicial', 'adaptor' => null, 'legacy' => 'outro', 'categoria' => 'Estratégia', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 32,  'descricao' => 'Excecao de Acordo', 'adaptor' => 'ACORDO - EXCEÇÃO', 'legacy' => 'promessa_pagamento', 'categoria' => 'Acordo', 'sucesso' => 1, 'efetivo' => 1, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 182, 'descricao' => 'Muda CX Postal Ruido Mensagens Eletronicas', 'adaptor' => null, 'legacy' => 'outro', 'categoria' => 'Contato', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 183, 'descricao' => 'Nao Atende', 'adaptor' => null, 'legacy' => 'nao_atende', 'categoria' => 'Sem contato', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 184, 'descricao' => 'Nao Completa Nao Existe Ligacao Caiu', 'adaptor' => null, 'legacy' => 'numero_invalido', 'categoria' => 'Telefone ruim', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 1, 'reagenda' => 0],
    ['action_id' => 42,  'descricao' => 'Nao Localizado', 'adaptor' => null, 'legacy' => 'outro', 'categoria' => 'Sem contato', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 136, 'descricao' => 'Negociação via WhatsApp', 'adaptor' => 'WhatsApp - Em contato', 'legacy' => 'em_negociacao', 'categoria' => 'Negociação', 'sucesso' => 1, 'efetivo' => 1, 'tel_ruim' => 0, 'reagenda' => 1],
    ['action_id' => 55,  'descricao' => 'Notas', 'adaptor' => null, 'legacy' => 'outro', 'categoria' => 'Outros', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 185, 'descricao' => 'Pagamento Efetuado', 'adaptor' => null, 'legacy' => 'promessa_pagamento', 'categoria' => 'Pagamento', 'sucesso' => 1, 'efetivo' => 1, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 139, 'descricao' => 'Pendências de Pós Closing', 'adaptor' => null, 'legacy' => 'outro', 'categoria' => 'Pós-acordo', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 2,   'descricao' => 'Promessa de Acordo', 'adaptor' => 'ACORDO - EVENTO ADAPTOR', 'legacy' => 'promessa_pagamento', 'categoria' => 'Acordo', 'sucesso' => 1, 'efetivo' => 1, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 4,   'descricao' => 'Recado', 'adaptor' => 'Recado - Terceiros', 'legacy' => 'recado', 'categoria' => 'Contato indireto', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 1],
    ['action_id' => 186, 'descricao' => 'Reenvio de Boleto', 'adaptor' => 'Segunda via de boleto', 'legacy' => 'outro', 'categoria' => 'Documentos', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 49,  'descricao' => 'Registro Óbito', 'adaptor' => 'Obito', 'legacy' => 'outro', 'categoria' => 'Especial', 'sucesso' => 0, 'efetivo' => 1, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 188, 'descricao' => 'Retirado de Cobranca por Solicitacao', 'adaptor' => 'CLIENTE RETOMADO', 'legacy' => 'outro', 'categoria' => 'Especial', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 189, 'descricao' => 'Retornar Contato', 'adaptor' => 'Agendamento', 'legacy' => 'outro', 'categoria' => 'Reagendamento', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 1],
    ['action_id' => 99,  'descricao' => 'Sem Condições Acordo, Sem Previsão de Momento.', 'adaptor' => 'Recusa - Deseja não Informar', 'legacy' => 'outro', 'categoria' => 'Contato efetivo', 'sucesso' => 0, 'efetivo' => 1, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 129, 'descricao' => 'Suspeita de Fraude', 'adaptor' => 'Desconhece dívida', 'legacy' => 'outro', 'categoria' => 'Especial', 'sucesso' => 0, 'efetivo' => 1, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 190, 'descricao' => 'Telefone Nao Existe', 'adaptor' => null, 'legacy' => 'numero_invalido', 'categoria' => 'Telefone ruim', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 1, 'reagenda' => 0],
    ['action_id' => 191, 'descricao' => 'Telefone Ocupado', 'adaptor' => null, 'legacy' => 'ocupado', 'categoria' => 'Sem contato', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 3,   'descricao' => 'Tentativa sem Resultado', 'adaptor' => 'DISCADOR - TENTATIVA', 'legacy' => 'outro', 'categoria' => 'Discador', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 0],
    ['action_id' => 192, 'descricao' => 'URA', 'adaptor' => null, 'legacy' => 'outro', 'categoria' => 'Discador', 'sucesso' => 0, 'efetivo' => 0, 'tel_ruim' => 0, 'reagenda' => 0],
];

$confirmar = in_array('--confirmar', $argv, true);

$pdo = Database::pdo();
$resultados = new AtendimentoResultadoRepository($pdo);
$integracoes = new CredorIntegracaoRepository($pdo);

$stmtAcme = $pdo->prepare(
    "SELECT ci.id FROM credor_integracoes ci
     INNER JOIN credores c ON c.id = ci.credor_id
     WHERE c.codigo = 'ACME' AND UPPER(ci.adapter) = 'ACME_ACIONAMENTOS'
     LIMIT 1"
);
$stmtAcme->execute();
$integracaoAcmeId = $stmtAcme->fetchColumn();
if ($integracaoAcmeId === false) {
    fwrite(STDERR, "ERRO: integração ACME_ACIONAMENTOS da ACME não encontrada em credor_integracoes.\n");
    exit(1);
}
$integracaoAcmeId = (int) $integracaoAcmeId;

$atuais = $resultados->listarTodos();
out('Tabulações atuais no CRM: ' . count($atuais) . ' (serão removidas)');
foreach ($atuais as $r) {
    out('  - [' . $r['codigo'] . '] ' . $r['nome']);
}
out('');
out('Tabulações novas a criar (da ACME): ' . count($eventos));

if (!$confirmar) {
    out('');
    out('DRY RUN - nada foi alterado. Rode com --confirmar pra aplicar de verdade.');
    exit(0);
}

$pdo->beginTransaction();
try {
    foreach ($atuais as $r) {
        $pdo->prepare('DELETE FROM atendimento_resultados WHERE id = :id')->execute(['id' => $r['id']]);
    }

    $ordem = 10;
    foreach ($eventos as $ev) {
        $nome = $ev['adaptor'] !== null && trim($ev['adaptor']) !== '' ? trim($ev['adaptor']) : $ev['descricao'];
        $codigo = 'ACME_' . str_pad((string) $ev['action_id'], 3, '0', STR_PAD_LEFT);

        $resultadoId = $resultados->salvar([
            'codigo' => $codigo,
            'nome' => $nome,
            'categoria' => $ev['categoria'],
            'resultado_legacy' => $ev['legacy'],
            'descricao' => $ev['descricao'] . ' (ACME ActionID ' . $ev['action_id'] . ')',
            'ordem' => $ordem,
            'gera_reagendamento' => $ev['reagenda'],
            'considera_sucesso' => $ev['sucesso'],
            'considera_contato_efetivo' => $ev['efetivo'],
            'considera_telefone_ruim' => $ev['tel_ruim'],
            'ativo' => 1,
        ]);

        $integracoes->salvarMapeamento($resultadoId, [
            'integracao_id' => $integracaoAcmeId,
            'produto' => '',
            'enviar' => 1,
            'codigo_externo' => (string) $ev['action_id'],
            'descricao_externa' => $ev['descricao'],
            'ativo' => 1,
        ], null);

        out('OK [' . $codigo . '] ' . $nome . ' -> ActionID ' . $ev['action_id']);
        $ordem += 10;
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    exit(1);
}

out('');
out('Concluído: ' . count($eventos) . ' tabulações cadastradas e mapeadas pra ACME (ActionID = codigo_externo).');
