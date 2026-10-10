<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Services;

use CRM\Modules\Operacao\Repositories\CredorIntegracaoRepository;
use CRM\Modules\Operacao\Repositories\IntegracaoEventoSaidaRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class AcionamentoIntegracaoService
{
    private readonly CredorIntegracaoRepository $integracoes;
    private readonly IntegracaoEventoSaidaRepository $eventos;

    public function __construct(PDO $pdo)
    {
        $this->integracoes = new CredorIntegracaoRepository($pdo);
        $this->eventos = new IntegracaoEventoSaidaRepository($pdo);
    }

    /**
     * Registra a outbox na mesma transacao do atendimento.
     * Sem de/para ativo, ou com "Enviar" desmarcado, nenhuma saida e criada.
     *
     * @return list<int>
     */
    public function enfileirarAtendimento(int $atendimentoId): array
    {
        if ($atendimentoId <= 0 || !$this->integracoes->moduloDisponivel() || !$this->eventos->moduloDisponivel()) {
            return [];
        }

        $ids = [];
        foreach ($this->integracoes->contextosParaAtendimento($atendimentoId) as $contexto) {
            if ((int) ($contexto['mapeamento_enviar'] ?? 0) !== 1) {
                continue;
            }
            $codigoExterno = trim((string) ($contexto['codigo_externo'] ?? ''));
            if ($codigoExterno === '') {
                continue;
            }

            $eventoUuid = $this->uuidV4();
            $payload = $this->payloadCanonico($contexto, $eventoUuid);
            $id = $this->eventos->enfileirar($contexto, $payload, $eventoUuid);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @param array<string,mixed> $contexto @return array<string,mixed> */
    private function payloadCanonico(array $contexto, string $eventoUuid): array
    {
        $ocorridoLocal = trim((string) ($contexto['atendimento_criado_em'] ?? ''));
        $observacao = trim((string) ($contexto['observacao'] ?? ''));
        if ($observacao === '') {
            $partes = array_values(array_filter([
                trim((string) ($contexto['resultado_nome'] ?? '')),
                trim((string) ($contexto['canal'] ?? '')) !== '' ? 'Canal: ' . trim((string) $contexto['canal']) : '',
                trim((string) ($contexto['contato_utilizado'] ?? '')) !== '' ? 'Contato: ' . trim((string) $contexto['contato_utilizado']) : '',
            ]));
            $observacao = $partes !== [] ? implode(' | ', $partes) : 'Acionamento registrado no Pactua CRM.';
        }

        return [
            'schema_version' => 1,
            'event_id' => $eventoUuid,
            'evento_tipo' => (string) ($contexto['evento_tipo'] ?? 'acionamento.registrado'),
            'origem' => [
                'sistema' => 'Pactua CRM',
                'tabela' => 'atendimentos_historico',
                'id' => (int) ($contexto['atendimento_id'] ?? 0),
                'usuario_id' => isset($contexto['usuario_id']) ? (int) $contexto['usuario_id'] : null,
                'operador_nome' => (string) ($contexto['operador_nome'] ?? ''),
            ],
            'credor' => [
                'id' => (int) ($contexto['credor_id'] ?? 0),
                'codigo' => (string) ($contexto['credor_codigo'] ?? ''),
                'nome' => (string) ($contexto['credor_nome'] ?? ''),
            ],
            'integracao' => [
                'id' => (int) ($contexto['integracao_id'] ?? 0),
                'codigo' => (string) ($contexto['integracao_codigo'] ?? ''),
                'nome' => (string) ($contexto['integracao_nome'] ?? ''),
                'adapter' => (string) ($contexto['adapter'] ?? ''),
                'transporte' => (string) ($contexto['tipo_transporte'] ?? ''),
                'configuracao' => $this->decodeJson($contexto['configuracao_json'] ?? null),
            ],
            'pessoa' => [
                'id' => (int) ($contexto['pessoa_id'] ?? 0),
                'nome' => (string) ($contexto['pessoa_nome'] ?? ''),
                'documento' => preg_replace('/\D+/', '', (string) ($contexto['documento'] ?? '')) ?? '',
                'tipo_documento' => (string) ($contexto['tipo_documento'] ?? ''),
            ],
            'contrato' => [
                'id' => (int) ($contexto['contrato_id'] ?? 0),
                'codigo' => trim((string) ($contexto['codigo_contrato'] ?? '')),
                'produto' => trim((string) ($contexto['produto'] ?? '')),
            ],
            'resultado' => [
                'id' => (int) ($contexto['resultado_id'] ?? 0),
                'codigo' => (string) ($contexto['resultado_codigo'] ?? ''),
                'nome' => (string) ($contexto['resultado_nome'] ?? ''),
            ],
            'mapeamento' => [
                'id' => (int) ($contexto['mapeamento_id'] ?? 0),
                'versao' => (int) ($contexto['mapeamento_versao'] ?? 1),
                'produto' => (string) ($contexto['mapeamento_produto'] ?? ''),
                'codigo_externo' => (string) ($contexto['codigo_externo'] ?? ''),
                'descricao_externa' => (string) ($contexto['descricao_externa'] ?? ''),
                'payload_extra' => $this->decodeJson($contexto['mapeamento_payload_extra'] ?? null),
            ],
            'acionamento' => [
                'canal' => (string) ($contexto['canal'] ?? ''),
                'contato_utilizado' => (string) ($contexto['contato_utilizado'] ?? ''),
                'observacao' => mb_substr($observacao, 0, 3000),
                'proximo_acionamento' => $contexto['proximo_acionamento'] ?? null,
                'ocorrido_em_local' => $ocorridoLocal,
                'ocorrido_em_utc' => $this->dataUtc($ocorridoLocal),
            ],
        ];
    }

    private function dataUtc(string $raw): string
    {
        try {
            $local = new DateTimeImmutable($raw !== '' ? $raw : 'now', new DateTimeZone('America/Sao_Paulo'));
            return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        } catch (Throwable) {
            return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        }
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) ($value ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
