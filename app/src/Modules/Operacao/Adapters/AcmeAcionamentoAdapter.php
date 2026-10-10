<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Adapters;

use CRM\Modules\Operacao\Contracts\IntegracaoSaidaAdapterInterface;
use CRM\Modules\Operacao\Services\AcmeApiClient;
use InvalidArgumentException;

final class AcmeAcionamentoAdapter implements IntegracaoSaidaAdapterInterface
{
    public function __construct(private readonly AcmeApiClient $client)
    {
    }

    public function codigo(): string
    {
        return 'ACME_ACIONAMENTOS';
    }

    public function configurado(): bool
    {
        return $this->client->configurado();
    }

    /** @param array<string,mixed> $evento @return array<string,mixed> */
    public function preparar(array $evento): array
    {
        $payload = $this->payload($evento['payload_array'] ?? $evento['payload'] ?? null);

        // O evento canonico do CRM usa nomes em portugues. As chaves em ingles
        // permanecem aceitas para facilitar reprocessamento de rascunhos anteriores.
        $pessoa = $this->subArray($payload, 'pessoa', 'person');
        $contrato = $this->subArray($payload, 'contrato', 'contract');
        $resultado = $this->subArray($payload, 'resultado', 'result');
        $mapeamento = $this->subArray($payload, 'mapeamento', 'mapping');
        $acionamento = $this->subArray($payload, 'acionamento', 'action');

        $docNumber = preg_replace('/\D+/', '', (string) ($pessoa['documento'] ?? $pessoa['document'] ?? '')) ?? '';
        $contractCode = trim((string) ($contrato['codigo'] ?? $contrato['code'] ?? ''));
        $actionIdRaw = trim((string) (
            $mapeamento['codigo_externo']
            ?? $mapeamento['external_code']
            ?? $acionamento['codigo_externo']
            ?? $acionamento['external_code']
            ?? ''
        ));
        $creationDate = trim((string) (
            $acionamento['ocorrido_em_utc']
            ?? $acionamento['occurred_at_utc']
            ?? $payload['occurred_at']
            ?? ''
        ));
        $notes = trim((string) ($acionamento['observacao'] ?? $acionamento['notes'] ?? ''));
        $proximoAcionamento = trim((string) ($acionamento['proximo_acionamento'] ?? ''));

        if (!in_array(strlen($docNumber), [11, 14], true)) {
            throw new InvalidArgumentException('CPF/CNPJ inválido no evento de acionamento ACME.');
        }
        if ($contractCode === '') {
            throw new InvalidArgumentException('Contrato ausente no evento de acionamento ACME.');
        }
        if (!ctype_digit($actionIdRaw) || (int) $actionIdRaw <= 0) {
            throw new InvalidArgumentException('ActionID ACME inválido. Revise o de/para da tabulação.');
        }
        if ($creationDate === '') {
            throw new InvalidArgumentException('Data do acionamento ausente no evento ACME.');
        }
        if ($notes === '') {
            $notes = trim((string) ($resultado['nome'] ?? $resultado['name'] ?? ''))
                ?: 'Acionamento registrado no Pactua CRM.';
        }

        // O array abaixo representa exatamente o JSON exigido pela ACME.
        $requisicao = [
            'Contract' => $contractCode,
            'Notes' => mb_substr($notes, 0, 3000),
            'DocNumber' => $docNumber,
            'ActionID' => (int) $actionIdRaw,
            'CreationDate' => $creationDate,
        ];
        // Achado 2026-09-28: pra tabulações de reagendamento (ex.: ActionID
        // 4 "Recado - Terceiros") a ACME exige o campo ScheduleDateTime
        // ("Para Recado deve informar Data e Hora!"), que nunca era enviado
        // - todo acionamento desse tipo sem "próximo acionamento" preenchido
        // falhava (500) e esgotava as tentativas sem nunca sincronizar.
        if ($proximoAcionamento !== '') {
            $requisicao['ScheduleDateTime'] = $this->paraUtc($proximoAcionamento);
        }
        return $requisicao;
    }

    private function paraUtc(string $dataHoraLocal): string
    {
        try {
            $local = new \DateTimeImmutable($dataHoraLocal, new \DateTimeZone('America/Sao_Paulo'));
        } catch (\Throwable) {
            return $dataHoraLocal;
        }
        return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** @param array<string,mixed> $requisicao @return array{response:array<string,mixed>,http_status:int,protocolo:?string} */
    public function enviar(array $requisicao): array
    {
        $response = $this->client->inserirAcionamento($requisicao);
        return [
            'response' => $response,
            'http_status' => $this->client->ultimoHttpStatus(),
            'protocolo' => $this->protocolo($response),
        ];
    }

    /** @return array<string,mixed> */
    private function payload(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) ($value ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function subArray(array $payload, string $principal, string $compatibilidade): array
    {
        $value = $payload[$principal] ?? $payload[$compatibilidade] ?? [];
        return is_array($value) ? $value : [];
    }

    /** @param array<string,mixed> $response */
    private function protocolo(array $response): ?string
    {
        foreach (['TrackingID', 'trackingId', 'tracking_id', 'Protocol', 'protocol', 'Protocolo', 'protocolo', 'id', 'ID'] as $key) {
            $value = trim((string) ($response[$key] ?? ''));
            if ($value !== '') {
                return mb_substr($value, 0, 180);
            }
        }

        foreach (['Data', 'data', 'Result', 'result'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                $nested = $this->protocolo($response[$key]);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }
}