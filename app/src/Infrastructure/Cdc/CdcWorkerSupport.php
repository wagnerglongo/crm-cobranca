<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Cdc;

use CRM\Config\Database;
use Throwable;

final class CdcWorkerSupport
{
    private const MAX_AUTO_RECOVERIES = 3;

    public static function newProcessor(): CdcProcessor
    {
        $processor = new CdcProcessor(Database::pdo());
        $processor->initialize();
        return $processor;
    }

    public static function isMysqlConnectionLost(Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if (self::messageIsMysqlConnectionLost((string) $current->getCode(), $current->getMessage())) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $job */
    public static function isAutomaticallyRecoverableJob(array $job): bool
    {
        $error = (string) ($job['last_error'] ?? '');
        $errorLower = strtolower($error);
        $topic = strtolower((string) ($job['topic'] ?? ''));

        if (self::messageIsMysqlConnectionLost('', $error)) {
            return true;
        }

        // Eventos antigos de usuarios que falharam por HY093 podem ser recuperados
        // automaticamente porque o prepared statement atual ja nao reutiliza
        // placeholders nomeados.
        if (str_ends_with($topic, '.usuarios')
            && (str_contains($errorLower, 'sqlstate[hy093]')
                || str_contains($errorLower, 'invalid parameter number'))
        ) {
            return true;
        }

        // Falhas HTTP temporarias do OpenSearch nao exigem intervencao humana.
        if (str_contains($errorLower, 'opensearch')
            && preg_match('/\b(?:429|502|503|504)\b/', $errorLower) === 1
        ) {
            return true;
        }

        return false;
    }

    /**
     * Prepara um item da dead-letter para nova tentativa automatica.
     * Retorna null quando o erro deve continuar aguardando analise humana.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>|null
     */
    public static function prepareAutomaticRetry(array $job): ?array
    {
        if (!is_array($job['event'] ?? null) || !self::isAutomaticallyRecoverableJob($job)) {
            return null;
        }

        $recoveries = max(0, (int) ($job['auto_recoveries'] ?? 0));
        if ($recoveries >= self::MAX_AUTO_RECOVERIES) {
            return null;
        }

        $previousError = (string) ($job['last_error'] ?? '');
        $previousErrorLower = strtolower($previousError);
        $topic = strtolower((string) ($job['topic'] ?? ''));

        // Os erros tratados aqui acontecem na etapa de projecao depois do lote
        // analitico ter sido gravado. Evita duplicar o mesmo evento no ClickHouse
        // ao recuperar jobs antigos que ainda nao carregavam analytics_done.
        if (!array_key_exists('analytics_done', $job)
            && (self::messageIsMysqlConnectionLost('', $previousError)
                || (str_ends_with($topic, '.usuarios')
                    && (str_contains($previousErrorLower, 'sqlstate[hy093]')
                        || str_contains($previousErrorLower, 'invalid parameter number'))))
        ) {
            $job['analytics_done'] = true;
        }

        $job['previous_error'] = $previousError;
        $job['attempts'] = 0;
        $job['last_error'] = null;
        $job['auto_recoveries'] = $recoveries + 1;
        $job['auto_requeued_at'] = date(DATE_ATOM);

        return $job;
    }

    private static function messageIsMysqlConnectionLost(string $code, string $message): bool
    {
        $message = strtolower($message);

        if (in_array($code, ['2006', '2013'], true)) {
            return true;
        }

        return str_contains($message, 'mysql server has gone away')
            || str_contains($message, 'server has gone away')
            || str_contains($message, 'lost connection to mysql server')
            || str_contains($message, 'lost connection during query')
            || preg_match('/(?:^|\D)(2006|2013)(?:\D|$)/', $message) === 1;
    }
}
