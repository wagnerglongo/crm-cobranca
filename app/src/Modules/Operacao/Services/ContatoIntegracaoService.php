<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Services;

use CRM\Modules\Operacao\Repositories\IntegracaoEventoSaidaRepository;
use PDO;
use Throwable;

/**
 * Requisito (2026-10-06): o que o operador inclui/corrige de
 * telefone, e-mail ou endereço no CRM passa a ir pro credor (hoje só a ACME
 * tem a integração "ACME - Contatos", migration 098).
 *
 * Regras:
 *  - só reage a ação de OPERADOR (as telas chamam via repositório); carga
 *    diária e Localização ACME (que vêm DA ACME) nunca passam por aqui;
 *  - contato/endereço novo do operador vai inteiro (inclusão);
 *  - em contato que já existia vai só o que mudou (status e/ou tipo) - pra
 *    não sobrescrever na ACME, com dado velho do CRM, o que ninguém mexeu;
 *  - só INCLUI na ACME o que foi cadastrado por operador (origem manual);
 *    o que veio da ACME só tem status/tipo atualizado;
 *  - um evento por contato (estado desejado): nova alteração antes do envio
 *    junta as mudanças no mesmo evento.
 *
 * Nunca lança: uma falha aqui não pode impedir o operador de salvar.
 */
final class ContatoIntegracaoService
{
    public const EVENTO_TIPO = 'contato.sincronizar';

    private readonly IntegracaoEventoSaidaRepository $eventos;

    public function __construct(private readonly PDO $pdo)
    {
        $this->eventos = new IntegracaoEventoSaidaRepository($pdo);
    }

    /**
     * @param array<string,mixed>|null $antes linha de pessoa_contatos antes da alteração (null = contato novo)
     * @return list<int> ids dos eventos enfileirados
     */
    public function contatoAlterado(int $contatoId, ?array $antes, ?int $contratoId = null): array
    {
        try {
            return $this->processarContato($contatoId, $antes, $contratoId);
        } catch (Throwable $e) {
            error_log('[contato-integracao] contato #' . $contatoId . ': ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @param array<string,mixed>|null $antes linha de pessoa_enderecos antes da alteração (null = endereço novo)
     * @return list<int>
     */
    public function enderecoAlterado(int $enderecoId, ?array $antes, ?int $contratoId = null): array
    {
        try {
            return $this->processarEndereco($enderecoId, $antes, $contratoId);
        } catch (Throwable $e) {
            error_log('[contato-integracao] endereco #' . $enderecoId . ': ' . $e->getMessage());
            return [];
        }
    }

    /** @param array<string,mixed>|null $antes @return list<int> */
    private function processarContato(int $contatoId, ?array $antes, ?int $contratoId): array
    {
        $stmt = $this->pdo->prepare('SELECT pc.*, p.documento, p.nome AS pessoa_nome
            FROM pessoa_contatos pc INNER JOIN pessoas p ON p.id = pc.pessoa_id
            WHERE pc.id = :id LIMIT 1');
        $stmt->execute(['id' => $contatoId]);
        $contato = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($contato === false) {
            return [];
        }

        $tipoCrm = strtolower((string) ($contato['tipo'] ?? ''));
        $situacao = $this->situacao($contato);
        $status = AcmeContatoMapeamento::statusContato($contato['status'] ?? null, $situacao);
        $criado = $antes === null;

        if (in_array($tipoCrm, ['telefone', 'whatsapp'], true)) {
            $telefone = AcmeContatoMapeamento::telefone($contato['ddd'] ?? null, (string) ($contato['telefone'] ?: $contato['valor_normalizado']));
            if ($telefone === null) {
                return [];
            }
            $tipo = AcmeContatoMapeamento::tipoTelefone($tipoCrm, $contato['subtipo'] ?? null);
            $dados = [
                'entidade' => 'telefone',
                'descricao' => sprintf('Telefone (%s) %s', $telefone['ddd'], $telefone['numero']),
                'ddd' => $telefone['ddd'],
                'numero' => $telefone['numero'],
                'tipo_acme' => $tipo,
                'tipo_inclusao' => AcmeContatoMapeamento::tipoTelefoneInclusao($tipoCrm, $contato['subtipo'] ?? null, $telefone['numero']),
            ];
            $tipoAntes = $criado ? null : AcmeContatoMapeamento::tipoTelefone($antes['tipo'] ?? null, $antes['subtipo'] ?? null);
            $mudouTipo = $tipo !== null && ($criado || $tipo !== $tipoAntes);
        } elseif ($tipoCrm === 'email') {
            $email = mb_strtolower(trim((string) ($contato['email'] ?: $contato['valor_normalizado'])));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return [];
            }
            $dados = [
                'entidade' => 'email',
                'descricao' => 'E-mail ' . $email,
                'email' => $email,
                'tipo_acme' => null,
                'tipo_inclusao' => AcmeContatoMapeamento::tipoEmailInclusao($contato['subtipo'] ?? null),
            ];
            $mudouTipo = false;
        } else {
            return [];
        }

        $statusAntes = $criado ? null : AcmeContatoMapeamento::statusContato($antes['status'] ?? null, $this->situacao($antes));
        $mudouStatus = $status !== null && ($criado || $status !== $statusAntes);
        if (!$criado && !$mudouStatus && !$mudouTipo) {
            return [];
        }

        $dados += [
            'status_acme' => $status,
            'status_inclusao' => $status ?? AcmeContatoMapeamento::STATUS_NAO_CONFIRMADO,
            'permitir_inclusao' => strtolower((string) ($contato['origem'] ?? '')) === 'manual',
            'crm' => [
                'contato_id' => $contatoId,
                'status' => $contato['status'] ?? null,
                'situacao' => $situacao,
                'subtipo' => $contato['subtipo'] ?? null,
                'origem' => $contato['origem'] ?? null,
            ],
        ];

        return $this->enfileirar($contato, 'pessoa_contatos', $contatoId, $dados, $mudouStatus, $mudouTipo, $criado, $contratoId);
    }

    /** @param array<string,mixed>|null $antes @return list<int> */
    private function processarEndereco(int $enderecoId, ?array $antes, ?int $contratoId): array
    {
        $stmt = $this->pdo->prepare('SELECT pe.*, p.documento, p.nome AS pessoa_nome
            FROM pessoa_enderecos pe INNER JOIN pessoas p ON p.id = pe.pessoa_id
            WHERE pe.id = :id LIMIT 1');
        $stmt->execute(['id' => $enderecoId]);
        $endereco = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($endereco === false) {
            return [];
        }

        $criado = $antes === null;
        $tipo = AcmeContatoMapeamento::tipoEndereco($endereco['tipo'] ?? null);
        $mudouTipo = $tipo !== null && ($criado || $tipo !== AcmeContatoMapeamento::tipoEndereco($antes['tipo'] ?? null));
        if (!$criado && !$mudouTipo) {
            return [];
        }

        $numero = AcmeContatoMapeamento::numeroEndereco($endereco['numero'] ?? null);
        $complemento = trim(implode(' - ', array_filter([
            trim((string) ($endereco['complemento'] ?? '')),
            (string) ($numero['complemento_extra'] ?? ''),
        ], static fn (string $parte): bool => $parte !== '')));
        $cep = preg_replace('/\D+/', '', (string) ($endereco['cep'] ?? '')) ?? '';
        $logradouro = trim((string) ($endereco['logradouro'] ?? ''));
        $cidade = trim((string) ($endereco['cidade'] ?? ''));
        $uf = strtoupper(trim((string) ($endereco['uf'] ?? '')));

        $dados = [
            'entidade' => 'endereco',
            'descricao' => 'Endereço ' . trim($logradouro . ', ' . ($endereco['numero'] ?? 'S/N') . ' - ' . $cidade . '/' . $uf . ' ' . $cep),
            'cep' => $cep,
            'logradouro' => $logradouro,
            'numero' => $numero['numero'],
            'complemento' => $complemento,
            'bairro' => trim((string) ($endereco['bairro'] ?? '')),
            'cidade' => $cidade,
            'uf' => $uf,
            'tipo_acme' => $tipo,
            'tipo_inclusao' => $tipo ?? AcmeContatoMapeamento::ENDERECO_NAO_DEFINIDO,
            'status_acme' => null,
            'status_inclusao' => AcmeContatoMapeamento::STATUS_NAO_CONFIRMADO,
            'permitir_inclusao' => strtolower((string) ($endereco['origem'] ?? '')) === 'manual',
            'crm' => [
                'endereco_id' => $enderecoId,
                'tipo' => $endereco['tipo'] ?? null,
                'origem' => $endereco['origem'] ?? null,
            ],
        ];

        return $this->enfileirar($endereco, 'pessoa_enderecos', $enderecoId, $dados, false, $mudouTipo, $criado, $contratoId);
    }

    /**
     * @param array<string,mixed> $registro linha com pessoa_id, documento, pessoa_nome
     * @param array<string,mixed> $dados
     * @return list<int>
     */
    private function enfileirar(array $registro, string $origemTabela, int $origemId, array $dados, bool $mudouStatus, bool $mudouTipo, bool $criado, ?int $contratoId): array
    {
        $documento = preg_replace('/\D+/', '', (string) ($registro['documento'] ?? '')) ?? '';
        if (!in_array(strlen($documento), [11, 14], true)) {
            return [];
        }

        $pessoaId = (int) $registro['pessoa_id'];
        $operador = function_exists('usuarioAtual') ? (usuarioAtual() ?? []) : [];
        $ids = [];

        foreach ($this->integracoesDaPessoa($pessoaId) as $integracao) {
            $alteracoes = ['status' => $mudouStatus, 'tipo' => $mudouTipo, 'criado' => $criado];

            // Mudança ainda não enviada do mesmo contato: junta as duas.
            $existente = $this->eventos->buscarPorOrigem((int) $integracao['id'], self::EVENTO_TIPO, $origemTabela, $origemId);
            if ($existente !== null && in_array((string) $existente['status'], ['pendente', 'erro_retry', 'processando'], true)) {
                $anteriores = (array) ($existente['payload_array']['alteracoes'] ?? []);
                foreach ($alteracoes as $chave => $valor) {
                    $alteracoes[$chave] = $valor || (bool) ($anteriores[$chave] ?? false);
                }
            }

            $eventoUuid = $this->uuidV4();
            $payload = [
                'schema_version' => 1,
                'event_id' => $eventoUuid,
                'evento_tipo' => self::EVENTO_TIPO,
                'origem' => [
                    'sistema' => 'Pactua CRM',
                    'tabela' => $origemTabela,
                    'id' => $origemId,
                    'usuario_id' => isset($operador['id']) ? (int) $operador['id'] : null,
                    'operador_nome' => (string) ($operador['nome'] ?? ''),
                    'registrado_em' => date('c'),
                ],
                'credor' => [
                    'id' => (int) $integracao['credor_id'],
                    'codigo' => (string) $integracao['credor_codigo'],
                ],
                'integracao' => [
                    'id' => (int) $integracao['id'],
                    'codigo' => (string) $integracao['codigo'],
                    'adapter' => (string) $integracao['adapter'],
                ],
                'pessoa' => [
                    'id' => $pessoaId,
                    'nome' => (string) ($registro['pessoa_nome'] ?? ''),
                    'documento' => $documento,
                ],
                'contato' => $dados,
                'alteracoes' => $alteracoes,
            ];

            $id = $this->eventos->enfileirarEstado([
                'integracao_id' => (int) $integracao['id'],
                'credor_id' => (int) $integracao['credor_id'],
                'evento_tipo' => self::EVENTO_TIPO,
                'pessoa_id' => $pessoaId,
                'contrato_id' => $this->contratoDoCredor($pessoaId, (int) $integracao['credor_id'], $contratoId),
                'max_tentativas' => (int) ($integracao['max_tentativas'] ?? 8),
            ], $payload, $eventoUuid, $origemTabela, $origemId);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** Integrações de contato ativas dos credores com quem a pessoa tem contrato. @return list<array<string,mixed>> */
    private function integracoesDaPessoa(int $pessoaId): array
    {
        $stmt = $this->pdo->prepare('SELECT ci.id, ci.credor_id, ci.codigo, ci.adapter, ci.max_tentativas, cr.codigo AS credor_codigo
            FROM credor_integracoes ci
            INNER JOIN credores cr ON cr.id = ci.credor_id
            WHERE ci.evento_tipo = :evento_tipo
              AND ci.ativo = 1
              AND EXISTS (SELECT 1 FROM contratos c WHERE c.pessoa_id = :pessoa_id AND c.credor_id = ci.credor_id)
            ORDER BY ci.id');
        $stmt->execute(['evento_tipo' => self::EVENTO_TIPO, 'pessoa_id' => $pessoaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Contrato do contexto (tela Pactua) se for desse credor; senão o primeiro da pessoa com ele. */
    private function contratoDoCredor(int $pessoaId, int $credorId, ?int $contratoId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM contratos
            WHERE pessoa_id = :pessoa_id AND credor_id = :credor_id
            ORDER BY (id = :contrato_id) DESC, id ASC
            LIMIT 1');
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
        $stmt->bindValue('contrato_id', (int) ($contratoId ?? 0), PDO::PARAM_INT);
        $stmt->execute();
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** "Situação" da tela Pactua (falta_validar/negativado/validado), guardada no payload_extra. */
    private function situacao(?array $contato): ?string
    {
        $payload = json_decode((string) ($contato['payload_extra'] ?? ''), true);
        $situacao = is_array($payload) ? trim((string) ($payload['situacao_validacao'] ?? '')) : '';
        return $situacao !== '' ? $situacao : null;
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
