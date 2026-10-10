<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Alertas\AlertaEmailService;
use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use CRM\Modules\Operacao\Repositories\AcordoSincronizacaoRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Repositories\CarteiraAcordosRepository;
use CRM\Modules\Operacao\Repositories\CredorConfiguracaoRepository;
use CRM\Modules\Operacao\Services\CredorAdapterFactory;

require_once __DIR__ . '/../src/Config/Database.php';

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'CRM\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relativeClass = substr($class, strlen($prefix));
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

/**
 * Traduz o código interno de retorno de sincronizarAcordoAcme() numa
 * mensagem legível - achado 2026-09-23 (a operação perguntou o que
 * "divergência" significava): o texto cru (ex.: "sem_acordo_retornado")
 * estava indo direto pro card de Controle de Acordos sem explicação.
 *
 * @param array<string,mixed> $conciliacao
 */
function mensagemConciliacaoAcme(string $statusConciliado, array $conciliacao): string
{
    if ($statusConciliado === 'sem_acordo_retornado') {
        return 'O CRM considera este acordo ativo, mas a ACME não retornou dados dele agora - vale conferir manualmente.';
    }
    if ($statusConciliado === 'sem_acordo_local_para_conciliar') {
        return 'A ACME retornou um acordo detalhado, mas não achou o registro local compatível (valor/parcelas) para conciliar automaticamente.';
    }
    if ($statusConciliado === 'nao_registrado_sem_valor') {
        return 'A ACME retornou o acordo, mas sem valor válido para registrar automaticamente.';
    }
    if ($statusConciliado === 'contrato_invalido') {
        return 'Contrato local inválido para conciliação com a ACME.';
    }
    if ($statusConciliado === 'nao_sincronizado') {
        return 'Falha ao conciliar com a ACME: ' . (string) ($conciliacao['acordo_crm_erro'] ?? 'erro desconhecido.');
    }

    return (string) ($conciliacao['status_motivo'] ?? $statusConciliado);
}

/**
 * Achado (2026-09-22): a quebra automática de acordo por carência
 * só usava dado local ou o payload da ACME cacheado numa sincronização
 * anterior (às vezes com dias de defasagem) e derrubava acordos que já
 * tinham sido pagos de verdade, porque ninguém tinha ido no CRM marcar a
 * parcela como paga. Esta função roda ANTES da reconciliação local: para
 * cada acordo batendo hoje um dia-gatilho de carência (vencimento, D+1,
 * limite da carência, D+1 do limite) e ainda não validado hoje, consulta o
 * credor ao vivo e persiste o status real antes de deixar
 * recalcularQuebrasPorCarencia() decidir se quebra.
 *
 * Quando o credor não tem adapter de integração ao vivo configurado (hoje
 * só a ACME tem), não há como validar - carimba validado_credor_em mesmo
 * assim pra não travar pra sempre a quebra automática desses acordos, só
 * documentando que a checagem não foi possível.
 *
 * @return array{elegiveis:int,validados:int,sem_adapter:int,erros:int}
 */
function validarPagamentosAoVivoComCredor(
    PDO $pdo,
    AcordoOperacionalRepository $acordos,
    int $limite,
    int $sleepMs
): array {
    $resultado = ['elegiveis' => 0, 'validados' => 0, 'sem_adapter' => 0, 'erros' => 0];

    $elegiveis = $acordos->acordosElegiveisParaValidacaoCredor(null, null, $limite);
    $resultado['elegiveis'] = count($elegiveis);
    if ($elegiveis === []) {
        return $resultado;
    }

    $adapterFactory = new CredorAdapterFactory($pdo, new CredorConfiguracaoRepository($pdo));
    $sincronizacaoRepository = new AcordoSincronizacaoRepository($pdo);
    $atendimentoAdaptor = new AtendimentoAdaptorRepository($pdo);

    foreach ($elegiveis as $acordoId) {
        $detalhe = $acordos->buscarDetalhe($acordoId);
        if ($detalhe === null) {
            continue;
        }

        $adapter = $adapterFactory->paraContrato($detalhe);
        if ($adapter === null || !$adapter->configurado()) {
            $resultado['sem_adapter']++;
            $acordos->registrarValidacaoCredor($acordoId);
            $sincronizacaoRepository->registrar(
                $acordoId,
                !empty($detalhe['contrato_id']) ? (int) $detalhe['contrato_id'] : null,
                !empty($detalhe['credor_id']) ? (int) $detalhe['credor_id'] : null,
                (string) ($detalhe['credor_nome'] ?? 'credor'),
                'nao_suportado',
                'Dia-gatilho de carência sem adapter de validação ao vivo para este credor; quebra automática segue usando apenas a regra local.',
                [],
                null,
                'Cron carência'
            );
            continue;
        }

        try {
            $payload = $adapter->consultarAcordo(
                (string) ($detalhe['pessoa_documento'] ?? ''),
                (string) ($detalhe['codigo_contrato'] ?? '')
            );

            if ((string) $adapter->codigo() === 'ACME' && !empty($detalhe['contrato_id'])) {
                $atendimentoAdaptor->atualizarContratoAcordoAcme((int) $detalhe['contrato_id'], $payload);
            }

            $conciliacao = $acordos->sincronizarAcordoAcme($detalhe, $payload);
            $acordos->registrarValidacaoCredor($acordoId);
            $resultado['validados']++;

            $statusConciliado = (string) ($conciliacao['acordo_crm'] ?? '');
            // Só chegam aqui acordos elegíveis, ou seja, que o CRM considera
            // ativos (pendente/formalizado) hoje - então qualquer retorno que
            // não seja um "atualizado/registrado" limpo é uma divergência de
            // verdade (CRM acha que tá ativo, credor não confirma), não um
            // "sem dado" inofensivo como no script de reconciliação em massa.
            $sincronizacaoRepository->registrar(
                $acordoId,
                !empty($detalhe['contrato_id']) ? (int) $detalhe['contrato_id'] : null,
                !empty($detalhe['credor_id']) ? (int) $detalhe['credor_id'] : null,
                $adapter->codigo(),
                in_array($statusConciliado, ['atualizado', 'registrado'], true) ? 'ok' : 'divergencia',
                'Validação automática de pagamento antes da quebra por carência (cron). '
                    . mensagemConciliacaoAcme($statusConciliado, $conciliacao),
                $payload,
                null,
                'Cron carência'
            );
        } catch (Throwable $e) {
            $resultado['erros']++;
            fwrite(STDERR, "Erro ao validar acordo #{$acordoId} com o credor antes da quebra: " . $e->getMessage() . PHP_EOL);
        }

        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }

    return $resultado;
}

$options = getopt('', ['limit::', 'acordo::', 'validacao-limit::', 'validacao-sleep-ms::', 'sem-validacao-credor', 'help']);
if (isset($options['help'])) {
    echo "Atualiza a carteira local de acordos.\n\n";
    echo "Uso:\n";
    echo "  php console/atualizar_carteira_acordos.php\n";
    echo "  php console/atualizar_carteira_acordos.php --limit=5000\n";
    echo "  php console/atualizar_carteira_acordos.php --acordo=123\n";
    echo "  php console/atualizar_carteira_acordos.php --validacao-limit=200 --validacao-sleep-ms=400\n";
    echo "  php console/atualizar_carteira_acordos.php --sem-validacao-credor\n\n";
    echo "A rotina primeiro valida ao vivo com o credor os acordos que batem hoje um dia-gatilho de\n";
    echo "carência (vencimento, D+1, limite da carência, D+1 do limite) e ainda não foram validados\n";
    echo "hoje - pra nunca quebrar acordo que já foi pago sem confirmar antes. Só depois marca\n";
    echo "parcelas vencidas, aplica carência, quebra acordos, quita acordos com todas as parcelas\n";
    echo "pagas e atualiza a próxima ação.\n";
    echo "--sem-validacao-credor pula a etapa de validação ao vivo (usar só em emergência).\n";
    exit(0);
}

$limit = isset($options['limit']) && is_numeric($options['limit']) ? (int) $options['limit'] : 5000;
$acordoId = isset($options['acordo']) && is_numeric($options['acordo']) ? (int) $options['acordo'] : null;
$limit = max(1, min($limit, 20000));

$validacaoLimit = isset($options['validacao-limit']) && is_numeric($options['validacao-limit'])
    ? (int) $options['validacao-limit'] : 500;
$validacaoLimit = max(1, min($validacaoLimit, 5000));
$validacaoSleepMs = isset($options['validacao-sleep-ms']) && is_numeric($options['validacao-sleep-ms'])
    ? (int) $options['validacao-sleep-ms'] : 400;
$validacaoSleepMs = max(0, min($validacaoSleepMs, 10000));
$semValidacaoCredor = isset($options['sem-validacao-credor']);

try {
    $pdo = Database::pdo();
    $acordos = new AcordoOperacionalRepository($pdo);
    $carteira = new CarteiraAcordosRepository($pdo, $acordos);

    if (!$acordos->moduloDisponivel()) {
        throw new RuntimeException('Módulo de acordos indisponível. Execute as migrations anteriores do módulo.');
    }
    if (!$carteira->moduloDisponivel()) {
        throw new RuntimeException('Carteira indisponível. Execute app/database/migrations/075_carteira_acordos.sql.');
    }

    $inicio = microtime(true);

    if ($semValidacaoCredor) {
        echo "Validação ao vivo com o credor PULADA (--sem-validacao-credor).\n";
    } else {
        $validacao = validarPagamentosAoVivoComCredor($pdo, $acordos, $validacaoLimit, $validacaoSleepMs);
        echo "Validação ao vivo com o credor (dias-gatilho de carência):\n";
        echo "  Elegíveis: {$validacao['elegiveis']}\n";
        echo "  Validados agora: {$validacao['validados']}\n";
        echo "  Sem adapter de validação: {$validacao['sem_adapter']}\n";
        echo "  Erros: {$validacao['erros']}\n";
    }

    $resultado = $carteira->atualizarCarteiraGeral($limit, $acordoId);
    $tempo = number_format(microtime(true) - $inicio, 3, ',', '.');

    echo "Carteira de acordos atualizada.\n";
    echo "Processados: {$resultado['processados']}\n";
    echo "Parcelas marcadas vencidas: {$resultado['vencidas']}\n";
    echo "Acordos quitados: {$resultado['quitados']}\n";
    echo "Acordos quebrados: {$resultado['quebrados']}\n";
    echo "Erros: {$resultado['erros']}\n";
    echo "Tempo: {$tempo}s\n";

    if ($resultado['erros'] > 0) {
        AlertaEmailService::fromEnv()?->enviar(
            '[CRM] atualizar_carteira_acordos.php terminou com erros',
            "A rotina de reconciliação da carteira de acordos (cron a cada 15min) terminou com {$resultado['erros']} erro(s).\n"
            . "Processados: {$resultado['processados']}, quitados: {$resultado['quitados']}, quebrados: {$resultado['quebrados']}.\n"
            . 'Ver /var/log/atualizar-carteira-acordos.log pra detalhes.'
        );
    }

    exit($resultado['erros'] > 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro ao atualizar carteira: ' . $e->getMessage() . PHP_EOL);
    AlertaEmailService::fromEnv()?->enviar(
        '[CRM] atualizar_carteira_acordos.php falhou',
        "A rotina de reconciliação da carteira de acordos (cron a cada 15min) lançou uma exceção e não completou:\n"
        . $e->getMessage()
    );
    exit(1);
}
