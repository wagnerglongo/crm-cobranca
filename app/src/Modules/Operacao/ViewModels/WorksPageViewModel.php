<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\ViewModels;

final class WorksPageViewModel
{
    /** @param list<array<string,mixed>> $resultados
     *  @param list<array<string,mixed>> $contatos
     *  @param list<array<string,mixed>> $enderecos
     *  @param list<array<string,mixed>> $historico
     *  @param list<array<string,mixed>> $parcelas
     *  @param array<string,mixed> $resumoParcelas
     *  @param list<array<string,mixed>> $acordos
     *  @param array<int,list<array<string,mixed>>> $parcelasAcordo
     *  @param list<array<string,mixed>> $resultadosAtendimento
     *  @param array<string,float> $timingsMs
     */
    public function __construct(
        public readonly array $resultados = [],
        public readonly ?array $contrato = null,
        public readonly array $contatos = [],
        public readonly array $enderecos = [],
        public readonly array $historico = [],
        public readonly array $parcelas = [],
        public readonly array $resumoParcelas = [
            'total_parcelas' => 0,
            'total_saldo_parcelas' => 0.0,
            'total_original' => 0.0,
            'primeiro_vencimento' => null,
            'ultimo_vencimento' => null,
        ],
        public readonly array $acordos = [],
        public readonly array $parcelasAcordo = [],
        public readonly array $resultadosAtendimento = [],
        public readonly ?array $estrategiaItem = null,
        public readonly int $estrategiaItemId = 0,
        public readonly ?string $estrategiaContextoErro = null,
        public readonly array $timingsMs = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'resultados' => $this->resultados,
            'contrato' => $this->contrato,
            'contatos' => $this->contatos,
            'enderecos' => $this->enderecos,
            'historico' => $this->historico,
            'parcelas' => $this->parcelas,
            'resumoParcelas' => $this->resumoParcelas,
            'acordos' => $this->acordos,
            'parcelasAcordo' => $this->parcelasAcordo,
            'resultadosAtendimento' => $this->resultadosAtendimento,
            'estrategiaItem' => $this->estrategiaItem,
            'estrategiaItemId' => $this->estrategiaItemId,
            'estrategiaContextoErro' => $this->estrategiaContextoErro,
            'worksQueryTimingsMs' => $this->timingsMs,
        ];
    }
}
