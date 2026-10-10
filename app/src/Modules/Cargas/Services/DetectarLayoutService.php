<?php

declare(strict_types=1);

namespace CRM\Modules\Cargas\Services;

use CRM\Modules\Cargas\Parsers\AcmePipeParser;
use CRM\Modules\Cargas\Repositories\CargaRepository;
use RuntimeException;
use SplFileObject;

final class DetectarLayoutService
{
    public function __construct(
        private readonly CargaRepository $repository,
        private readonly AcmePipeParser $parser,
    ) {
    }

    /**
     * @return array{credor_id:int,carteira_id:?int,layout_id:int,header:list<string>}
     */
    public function detectarAcme(int $cargaId, string $path): array
    {
        $this->repository->updateCargaArquivo($cargaId, ['status' => 'identificando_layout']);

        $credor = $this->repository->findCredorByCodigo('ACME');
        if ($credor === null) {
            throw new RuntimeException('Credor ACME não encontrado na tabela credores. Rode os seeds iniciais.');
        }

        $carteira = $this->repository->findCarteiraPadrao((int) $credor['id']);
        $layout = $this->repository->findLayout('ACME_COBRANCA_PIPE', (int) $credor['id']);
        if ($layout === null) {
            throw new RuntimeException('Layout ACME_COBRANCA_PIPE não encontrado na tabela carga_layouts. Rode os seeds iniciais.');
        }

        $file = new SplFileObject($path, 'r');
        $headerLine = $file->fgets();
        if ($headerLine === false || trim($headerLine) === '') {
            $this->repository->updateCargaArquivo($cargaId, [
                'credor_id' => (int) $credor['id'],
                'carteira_id' => $carteira['id'] ?? null,
                'layout_id' => (int) $layout['id'],
                'status' => 'layout_nao_identificado',
                'erro_resumo' => 'Arquivo vazio ou sem cabeçalho.',
            ]);

            $this->repository->insertErrorsBatch([[ 
                'carga_arquivo_id' => $cargaId,
                'etapa' => 'layout',
                'codigo_erro' => 'ACME_HEADER_AUSENTE',
                'mensagem' => 'Arquivo ACME vazio ou sem cabeçalho.',
                'severidade' => 'critico',
                'payload_contexto' => ['path' => $path],
            ]]);

            throw new RuntimeException('Arquivo ACME vazio ou sem cabeçalho.');
        }

        $header = $this->parser->parseHeader($headerLine);
        $errors = $this->parser->validateHeader($header);

        $this->repository->updateCargaArquivo($cargaId, [
            'credor_id' => (int) $credor['id'],
            'carteira_id' => $carteira['id'] ?? null,
            'layout_id' => (int) $layout['id'],
            'payload_amostra' => [
                'header' => $header,
                'qtd_colunas_header' => count($header),
            ],
        ]);

        if ($errors !== []) {
            $rows = [];
            foreach ($errors as $error) {
                $rows[] = [
                    'carga_arquivo_id' => $cargaId,
                    'etapa' => 'layout',
                    'codigo_erro' => $error['codigo'],
                    'mensagem' => $error['mensagem'],
                    'campo_origem' => $error['campo_origem'],
                    'severidade' => 'critico',
                    'valor_original' => $error['valor_original'],
                    'payload_contexto' => ['header' => $header],
                ];
            }

            $this->repository->insertErrorsBatch($rows);
            $this->repository->updateCargaArquivo($cargaId, [
                'status' => 'layout_nao_identificado',
                'erro_resumo' => 'Cabeçalho ACME inválido. Veja cargas_erros.',
            ]);

            throw new RuntimeException('Cabeçalho ACME inválido. Veja cargas_erros.');
        }

        $this->repository->updateCargaArquivo($cargaId, [
            'status' => 'aguardando_processamento',
        ]);

        return [
            'credor_id' => (int) $credor['id'],
            'carteira_id' => isset($carteira['id']) ? (int) $carteira['id'] : null,
            'layout_id' => (int) $layout['id'],
            'header' => $header,
        ];
    }
}
