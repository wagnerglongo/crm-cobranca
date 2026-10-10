<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Services;

use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoRepository;
use CRM\Modules\Operacao\Repositories\EstrategiaRepository;
use CRM\Modules\Operacao\ViewModels\WorksPageViewModel;

final class WorksQueryService
{
    public function __construct(
        private readonly AtendimentoAdaptorRepository $repository,
        private readonly AtendimentoRepository $atendimentoRepository,
        private readonly AcordoOperacionalRepository $acordoRepository,
        private readonly EstrategiaRepository $estrategiaRepository,
    ) {
    }

    /**
     * Centraliza apenas as consultas de leitura da tela Pactua.
     * Regras de autorização continuam no entrypoint/controller e escritas continuam
     * no controlador de ações para evitar misturar leitura com comando.
     *
     * @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopoConsulta
     * @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopoOperacao
     */
    public function carregar(
        int $contratoId,
        int $pessoaIdBusca,
        string $termoBusca,
        string $tipoBusca,
        string $tab,
        ?array $escopoConsulta,
        ?array $escopoOperacao,
        int $estrategiaItemId,
        int $usuarioId,
    ): WorksPageViewModel {
        $resultados = [];
        $contrato = null;
        $contatos = [];
        $enderecos = [];
        $historico = [];
        $parcelas = [];
        $resumoParcelas = [
            'total_parcelas' => 0,
            'total_saldo_parcelas' => 0.0,
            'total_original' => 0.0,
            'primeiro_vencimento' => null,
            'ultimo_vencimento' => null,
        ];
        $acordos = [];
        $parcelasAcordo = [];
        $resultadosAtendimento = [];
        $estrategiaItem = null;
        $estrategiaContextoErro = null;
        $timings = [];

        if ($contratoId > 0) {
            $this->measure($timings, 'carencia', fn () => $this->acordoRepository->recalcularQuebrasPorCarencia($escopoConsulta, $contratoId));
            $contrato = $this->measure($timings, 'contrato', fn () => $this->repository->buscarContrato($contratoId, $escopoConsulta));

            if ($contrato !== null) {
                $pessoaId = (int) ($contrato['pessoa_id'] ?? 0);
                $contatos = $this->measure($timings, 'contatos', fn () => $this->repository->listarContatos($pessoaId));
                $acordos = $this->measure($timings, 'acordos', fn () => $this->repository->listarAcordosContrato($contratoId, 50));

                if ($tab === 'acionamento') {
                    $historico = $this->measure($timings, 'historico', fn () => $this->repository->listarHistorico($pessoaId, $contratoId, 45));
                    $resultadosAtendimento = $this->measure($timings, 'tabulacoes', fn () => $this->atendimentoRepository->listarResultadosAtivos());
                } elseif ($tab === 'localizacao') {
                    $enderecos = $this->measure($timings, 'enderecos', fn () => $this->repository->listarEnderecos($pessoaId));
                } elseif ($tab === 'financeiro') {
                    $parcelas = $this->measure($timings, 'parcelas', fn () => $this->repository->listarParcelasContrato($contratoId, 300));
                    $resumoParcelas = $this->measure($timings, 'resumo_financeiro', fn () => $this->repository->resumoParcelasContrato($contratoId));
                    $idsAcordos = array_map(static fn (array $a): int => (int) ($a['id'] ?? 0), $acordos);
                    $idsAcordos = array_values(array_filter($idsAcordos, static fn (int $id): bool => $id > 0));
                    if ($idsAcordos !== []) {
                        $parcelasAcordo = $this->measure($timings, 'parcelas_acordo', fn () => $this->repository->listarParcelasAcordos($idsAcordos));
                    }
                }

                if ($estrategiaItemId > 0) {
                    $estrategiaItem = $this->measure(
                        $timings,
                        'estrategia',
                        fn () => $this->estrategiaRepository->buscarItem($estrategiaItemId, $usuarioId, $escopoOperacao)
                    );
                    if ($estrategiaItem === null || (int) ($estrategiaItem['contrato_id'] ?? 0) !== $contratoId) {
                        $estrategiaContextoErro = 'O vínculo com a estratégia não foi encontrado para este contrato ou está fora do seu escopo.';
                        $estrategiaItem = null;
                        $estrategiaItemId = 0;
                    } elseif ((string) ($estrategiaItem['status_operacional'] ?? '') === 'trabalhado') {
                        $estrategiaItemId = 0;
                    } elseif ((string) ($estrategiaItem['estrategia_status'] ?? '') !== 'ativa'
                        || (string) ($estrategiaItem['status_operacional'] ?? '') !== 'em_atendimento'
                        || (int) ($estrategiaItem['reservado_por_usuario_id'] ?? 0) !== $usuarioId) {
                        $estrategiaContextoErro = 'Este contrato não está reservado para o seu usuário em uma estratégia ativa. Abra-o pela página Estratégia antes de continuar.';
                        $estrategiaItem = null;
                        $estrategiaItemId = 0;
                    }
                }
            }
        } elseif ($pessoaIdBusca > 0) {
            $resultados = $this->measure($timings, 'busca_pessoa', fn () => $this->repository->listarContratosPessoa($pessoaIdBusca, $escopoConsulta, 500));
        } elseif ($termoBusca !== '') {
            $resultados = $this->measure($timings, 'busca', fn () => $this->repository->buscarContratos($termoBusca, $tipoBusca, $escopoConsulta, 500));
        }

        return new WorksPageViewModel(
            resultados: $resultados,
            contrato: $contrato,
            contatos: $contatos,
            enderecos: $enderecos,
            historico: $historico,
            parcelas: $parcelas,
            resumoParcelas: $resumoParcelas,
            acordos: $acordos,
            parcelasAcordo: $parcelasAcordo,
            resultadosAtendimento: $resultadosAtendimento,
            estrategiaItem: $estrategiaItem,
            estrategiaItemId: $estrategiaItemId,
            estrategiaContextoErro: $estrategiaContextoErro,
            timingsMs: $timings,
        );
    }

    /** @template T @param array<string,float> $timings @param callable():T $callback @return T */
    private function measure(array &$timings, string $name, callable $callback): mixed
    {
        $started = microtime(true);
        try {
            return $callback();
        } finally {
            $timings[$name] = round((microtime(true) - $started) * 1000, 2);
        }
    }
}
