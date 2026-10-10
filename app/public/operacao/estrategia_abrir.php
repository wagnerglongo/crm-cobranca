<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\EstrategiaRepository;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('estrategias.visualizar');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['estrategia_flash_erro'] = 'Use o botão Abrir no Pactua na página de estratégias.';
    header('Location: /operacao/estrategias.php');
    exit;
}

try {
    validarCsrf();
    $itemId = isset($_POST['item_id']) && ctype_digit((string) $_POST['item_id']) ? (int) $_POST['item_id'] : 0;
    if ($itemId <= 0) {
        throw new InvalidArgumentException('Item de estratégia inválido.');
    }
    $repository = new EstrategiaRepository(operacaoPdo());
    $item = $repository->reservarEAbrir($itemId, usuarioAtualId() ?? 0, usuarioEscopoCredoresAcao('operar'));
    registrarAuditoriaSistema(
        'estrategia.contrato_aberto',
        'estrategia_contrato',
        $itemId,
        'Contrato reservado pela estratégia e aberto no Pactua.',
        null,
        ['estrategia_id' => (int) $item['estrategia_id'], 'contrato_id' => (int) $item['contrato_id']]
    );
    header('Location: ' . worksAtendimentoUrl((int) $item['contrato_id'], (int) $item['pessoa_id'], 'acionamento', [
        'estrategia_item_id' => $itemId,
        'origem' => 'estrategia',
    ]));
    exit;
} catch (Throwable $e) {
    $_SESSION['estrategia_flash_erro'] = $e->getMessage();
    header('Location: /operacao/estrategias.php');
    exit;
}
