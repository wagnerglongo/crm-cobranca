<?php

declare(strict_types=1);

namespace CRM\Modules\Cargas\Services;

use CRM\Infrastructure\Alertas\AlertaEmailService;
use CRM\Modules\Cargas\Repositories\RotinaCargaSftpRepository;
use phpseclib3\Net\SFTP;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Executa uma rotina de coleta de carga: conecta no SFTP do credor,
 * baixa os arquivos novos pra pasta inbox correta e, se houver arquivo
 * novo e o credor tiver um comando de processamento diario conhecido
 * (hoje, ACME), dispara esse comando na sequencia (import+aplicar).
 * Lock nomeado por rotina evita rodar a mesma rotina duas vezes ao
 * mesmo tempo (agendador automatico e "Executar agora" clicado juntos).
 */
final class ExecutarRotinaCargaSftpService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RotinaCargaSftpRepository $repository,
        private readonly string $consoleDir,
        private readonly ?AlertaEmailService $alertaEmail = null
    ) {
    }

    /** @return array{status:string,total_arquivos:int,mensagem:string} */
    public function executar(int $rotinaId, ?int $usuarioId): array
    {
        $lockName = 'crm:rotina_carga_sftp:' . $rotinaId;
        $lockStmt = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $lockStmt->bindValue('name', $lockName);
        $lockStmt->execute();
        if ((int) $lockStmt->fetchColumn() !== 1) {
            $resultado = ['status' => 'bloqueada', 'total_arquivos' => 0, 'mensagem' => 'Já existe uma execução desta rotina em andamento.'];
            $this->repository->registrarExecucao($rotinaId, 'bloqueada', 0, $resultado['mensagem'], $usuarioId);
            return $resultado;
        }

        try {
            $resultado = $this->executarSemLock($rotinaId);
            $this->repository->registrarExecucao($rotinaId, 'sucesso', $resultado['total_arquivos'], $resultado['mensagem'], $usuarioId);
            return ['status' => 'sucesso'] + $resultado;
        } catch (Throwable $e) {
            $mensagem = $e->getMessage();
            $this->repository->registrarExecucao($rotinaId, 'falha', 0, $mensagem, $usuarioId);
            $this->alertaEmail?->enviar(
                'Falha na coleta automática de carga (rotina #' . $rotinaId . ')',
                'A rotina de coleta de carga via SFTP falhou.' . "\n\nDetalhe: " . $mensagem
                . "\n\nA próxima tentativa acontece no horário agendado normalmente."
            );
            return ['status' => 'falha', 'total_arquivos' => 0, 'mensagem' => $mensagem];
        } finally {
            $unlockStmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $unlockStmt->bindValue('name', $lockName);
            $unlockStmt->execute();
        }
    }

    /** @return array{total_arquivos:int,mensagem:string} */
    private function executarSemLock(int $rotinaId): array
    {
        $cred = $this->repository->credenciaisParaExecucao($rotinaId);
        $destino = $this->repository->destinoPara($cred['credor_codigo']);

        if (!is_dir($destino) && !mkdir($destino, 0770, true) && !is_dir($destino)) {
            throw new RuntimeException('Não foi possível criar a pasta de destino: ' . $destino);
        }

        $sftp = new SFTP($cred['origem_host'], $cred['origem_porta'], 15);
        if (!$sftp->login($cred['usuario'], $cred['senha'])) {
            throw new RuntimeException('Falha ao autenticar no servidor SFTP (' . $cred['origem_host'] . ').');
        }

        $listagem = $sftp->nlist($cred['origem_caminho']);
        if ($listagem === false) {
            throw new RuntimeException('Não foi possível listar a pasta de origem: ' . $cred['origem_caminho']);
        }

        $baixados = 0;
        foreach ($listagem as $nomeArquivo) {
            if ($nomeArquivo === '.' || $nomeArquivo === '..') {
                continue;
            }
            if ($cred['padrao_arquivo'] !== null && preg_match('/' . $cred['padrao_arquivo'] . '/', $nomeArquivo) !== 1) {
                continue;
            }

            $localFinal = $destino . '/' . basename($nomeArquivo);
            if (file_exists($localFinal)) {
                continue;
            }

            $remoto = rtrim($cred['origem_caminho'], '/') . '/' . $nomeArquivo;
            $localTemp = $localFinal . '.baixando';
            if (!$sftp->get($remoto, $localTemp)) {
                @unlink($localTemp);
                continue;
            }
            rename($localTemp, $localFinal);
            $baixados++;
        }
        $sftp->disconnect();

        $mensagem = $baixados . ' arquivo(s) novo(s) baixado(s) de ' . $cred['origem_host'] . '.';
        $comando = $this->repository->comandoProcessamentoPara($cred['credor_codigo']);
        if ($baixados > 0 && $comando !== null) {
            $resultadoProcessamento = $this->dispararProcessamento($comando);
            $mensagem .= $resultadoProcessamento['codigo_saida'] === 0
                ? ' Processamento automático concluído.'
                : ' Processamento automático retornou código ' . $resultadoProcessamento['codigo_saida'] . '.';
        }

        return ['total_arquivos' => $baixados, 'mensagem' => $mensagem];
    }

    /** @return array{saida:?string,erro:?string,codigo_saida:int} */
    private function dispararProcessamento(string $comando): array
    {
        $script = $this->consoleDir . '/' . $comando;
        if (!is_file($script)) {
            return ['saida' => null, 'erro' => 'Comando não encontrado: ' . $script, 'codigo_saida' => -1];
        }

        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $processo = proc_open(['php', $script], $descriptorSpec, $pipes);
        if ($processo === false) {
            return ['saida' => null, 'erro' => 'Não foi possível iniciar o subprocesso de processamento.', 'codigo_saida' => -1];
        }

        fclose($pipes[0]);
        $saida = stream_get_contents($pipes[1]) ?: '';
        $erro = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $codigoSaida = proc_close($processo);

        return [
            'saida' => trim($saida),
            'erro' => trim($erro) !== '' ? trim($erro) : null,
            'codigo_saida' => $codigoSaida,
        ];
    }
}
