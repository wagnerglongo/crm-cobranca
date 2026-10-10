<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Adapters;

use CRM\Modules\Operacao\Contracts\IntegracaoSaidaAdapterInterface;
use CRM\Modules\Operacao\Services\AcmeApiClient;
use CRM\Modules\Operacao\Services\AcmeContatoMapeamento;
use InvalidArgumentException;

/**
 * Envia pra ACME o telefone/e-mail/endereço que o operador incluiu ou
 * corrigiu no CRM (requisito de 2026-10-06).
 *
 * O evento carrega o ESTADO DESEJADO, não um comando: aqui primeiro se
 * consulta o que a ACME já tem (returnphone/returnemail/returnaddress) e só
 * então se decide entre incluir, atualizar tipo/status ou não fazer nada.
 * Isso torna o reenvio seguro (uma tentativa repetida depois de timeout não
 * duplica o cadastro na ACME) e não sobrescreve na ACME o que o operador não
 * mexeu (só vai o campo marcado em "alteracoes").
 */
final class AcmeContatoAdapter implements IntegracaoSaidaAdapterInterface
{
    public function __construct(private readonly AcmeApiClient $client)
    {
    }

    public function codigo(): string
    {
        return 'ACME_CONTATOS';
    }

    public function configurado(): bool
    {
        return $this->client->configurado();
    }

    /** @param array<string,mixed> $evento @return array<string,mixed> */
    public function preparar(array $evento): array
    {
        $payload = $this->arr($evento['payload_array'] ?? null);
        if ($payload === []) {
            $decoded = json_decode((string) ($evento['payload'] ?? ''), true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $documento = preg_replace('/\D+/', '', (string) ($this->arr($payload['pessoa'] ?? null)['documento'] ?? '')) ?? '';
        if (!in_array(strlen($documento), [11, 14], true)) {
            throw new InvalidArgumentException('CPF/CNPJ inválido no evento de contato ACME.');
        }

        $contato = $this->arr($payload['contato'] ?? null);
        $entidade = (string) ($contato['entidade'] ?? '');
        if (!in_array($entidade, ['telefone', 'email', 'endereco'], true)) {
            throw new InvalidArgumentException('Tipo de contato inválido no evento ACME: ' . $entidade);
        }
        if ($entidade === 'telefone' && AcmeContatoMapeamento::telefone((string) ($contato['ddd'] ?? ''), (string) ($contato['numero'] ?? '')) === null) {
            throw new InvalidArgumentException('Telefone sem DDD válido no evento de contato ACME.');
        }
        if ($entidade === 'email' && filter_var((string) ($contato['email'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('E-mail inválido no evento de contato ACME.');
        }

        $alteracoes = $this->arr($payload['alteracoes'] ?? null);

        return [
            'documento' => $documento,
            'contato' => $contato,
            'alteracoes' => [
                'status' => (bool) ($alteracoes['status'] ?? false),
                'tipo' => (bool) ($alteracoes['tipo'] ?? false),
            ],
        ];
    }

    /** @param array<string,mixed> $requisicao @return array{response:array<string,mixed>,http_status:int,protocolo:?string} */
    public function enviar(array $requisicao): array
    {
        $documento = (string) $requisicao['documento'];
        $contato = $this->arr($requisicao['contato'] ?? null);
        $alteracoes = $this->arr($requisicao['alteracoes'] ?? null);

        $resultado = match ((string) ($contato['entidade'] ?? '')) {
            'telefone' => $this->telefone($documento, $contato, $alteracoes),
            'email' => $this->email($documento, $contato, $alteracoes),
            'endereco' => $this->endereco($documento, $contato, $alteracoes),
            default => throw new InvalidArgumentException('Tipo de contato inválido no evento ACME.'),
        };

        return [
            'response' => $resultado,
            'http_status' => $this->client->ultimoHttpStatus(),
            'protocolo' => isset($resultado['id_acme']) && $resultado['id_acme'] !== null
                ? $resultado['acao'] . ' #' . $resultado['id_acme']
                : (string) $resultado['acao'],
        ];
    }

    /** @param array<string,mixed> $c @param array<string,mixed> $alt @return array<string,mixed> */
    private function telefone(string $documento, array $c, array $alt): array
    {
        $desejado = AcmeContatoMapeamento::telefone((string) ($c['ddd'] ?? ''), (string) ($c['numero'] ?? ''));
        $lista = $this->client->listarTelefones($documento);

        $recordId = null;
        foreach ($lista as $item) {
            $recordId ??= $this->intOuNull($item['RecordID'] ?? null);
            $acme = AcmeContatoMapeamento::telefone((string) ($item['DDD'] ?? ''), (string) ($item['PhoneNumber'] ?? ''));
            if ($acme === null || $desejado === null || $acme !== $desejado) {
                continue;
            }

            $phoneId = (int) ($item['PhoneID'] ?? 0);
            $statusAtual = strtoupper(trim((string) ($item['Status'] ?? '')));
            $tipoAtual = (int) ($item['PhoneType'] ?? 0);
            $status = ($alt['status'] ?? false) && ($c['status_acme'] ?? null) !== null ? (string) $c['status_acme'] : $statusAtual;
            $tipo = ($alt['tipo'] ?? false) && ($c['tipo_acme'] ?? null) !== null ? (int) $c['tipo_acme'] : $tipoAtual;
            $status = $status !== '' ? $status : (string) ($c['status_inclusao'] ?? AcmeContatoMapeamento::STATUS_NAO_CONFIRMADO);
            $tipo = $tipo > 0 ? $tipo : (int) ($c['tipo_inclusao'] ?? AcmeContatoMapeamento::TELEFONE_NAO_DEFINIDO);

            if ($status === $statusAtual && $tipo === $tipoAtual) {
                return $this->resultado('sem_mudanca', $phoneId, 'Telefone já está na ACME com o mesmo tipo/status.');
            }
            $resposta = $this->client->atualizarStatusTelefone($documento, $phoneId, $tipo, $status);
            return $this->resultado('status_atualizado', $phoneId, sprintf('Tipo %d→%d, status %s→%s.', $tipoAtual, $tipo, $statusAtual ?: '-', $status), $resposta);
        }

        if (!(bool) ($c['permitir_inclusao'] ?? false)) {
            return $this->resultado('ignorado', null, 'Telefone não está na ACME e não foi cadastrado por operador - não é incluído.');
        }

        $corpo = [
            'DocNumber' => $documento,
            'DDI' => 55,
            'DDD' => (int) $desejado['ddd'],
            'PhoneNumber' => $desejado['numero'],
            'PhoneType' => (int) ($c['tipo_inclusao'] ?? AcmeContatoMapeamento::TELEFONE_NAO_DEFINIDO),
            'Status' => (string) ($c['status_inclusao'] ?? AcmeContatoMapeamento::STATUS_NAO_CONFIRMADO),
        ];
        if ($recordId !== null) {
            $corpo['RecordID'] = $recordId;
        }
        $resposta = $this->client->inserirTelefone($corpo);
        return $this->resultado('incluido', $this->intOuNull($resposta['PhoneID'] ?? null), 'Telefone incluído na ACME.', $resposta);
    }

    /** @param array<string,mixed> $c @param array<string,mixed> $alt @return array<string,mixed> */
    private function email(string $documento, array $c, array $alt): array
    {
        $email = mb_strtolower(trim((string) ($c['email'] ?? '')));
        $lista = $this->client->listarEmails($documento);

        $recordId = null;
        foreach ($lista as $item) {
            $recordId ??= $this->intOuNull($item['RecordID'] ?? null);
            if (mb_strtolower(trim((string) ($item['MailAddress'] ?? ''))) !== $email) {
                continue;
            }

            $mailId = (int) ($item['MailID'] ?? 0);
            $statusAtual = strtoupper(trim((string) ($item['Status'] ?? '')));
            $status = ($alt['status'] ?? false) && ($c['status_acme'] ?? null) !== null ? (string) $c['status_acme'] : $statusAtual;
            if ($status === '' || $status === $statusAtual) {
                return $this->resultado('sem_mudanca', $mailId, 'E-mail já está na ACME com o mesmo status.');
            }
            $resposta = $this->client->atualizarStatusEmail($documento, $mailId, $status);
            return $this->resultado('status_atualizado', $mailId, sprintf('Status %s→%s.', $statusAtual ?: '-', $status), $resposta);
        }

        if (!(bool) ($c['permitir_inclusao'] ?? false)) {
            return $this->resultado('ignorado', null, 'E-mail não está na ACME e não foi cadastrado por operador - não é incluído.');
        }

        $corpo = [
            'DocNumber' => $documento,
            'MailAddress' => $email,
            'Status' => (string) ($c['status_inclusao'] ?? AcmeContatoMapeamento::STATUS_NAO_CONFIRMADO),
            'EmailType' => (int) ($c['tipo_inclusao'] ?? AcmeContatoMapeamento::EMAIL_FINANCIADO),
        ];
        if ($recordId !== null) {
            $corpo['RecordID'] = $recordId;
        }
        $resposta = $this->client->inserirEmail($corpo);
        return $this->resultado('incluido', $this->intOuNull($resposta['MailID'] ?? null), 'E-mail incluído na ACME.', $resposta);
    }

    /** @param array<string,mixed> $c @param array<string,mixed> $alt @return array<string,mixed> */
    private function endereco(string $documento, array $c, array $alt): array
    {
        $cep = preg_replace('/\D+/', '', (string) ($c['cep'] ?? '')) ?? '';
        $numero = (int) ($c['numero'] ?? 0);
        $logradouro = $this->textoComparavel((string) ($c['logradouro'] ?? ''));
        $lista = $this->client->listarEnderecos($documento);

        $recordId = null;
        foreach ($lista as $item) {
            $recordId ??= $this->intOuNull($item['RecordID'] ?? null);
            $cepAcme = preg_replace('/\D+/', '', (string) ($item['CEP'] ?? '')) ?? '';
            $mesmoCep = $cep !== '' && $cepAcme === $cep;
            $mesmoNumero = $numero > 0 && (int) ($item['Number'] ?? 0) === $numero;
            $mesmaRua = $logradouro !== '' && $this->textoComparavel((string) ($item['Locale'] ?? '')) === $logradouro;
            if (!$mesmoCep || (!$mesmoNumero && !$mesmaRua)) {
                continue;
            }

            $addressId = (int) ($item['AddressID'] ?? 0);
            $tipoAtual = strtoupper(trim((string) ($item['AddressType'] ?? '')));
            $statusAtual = strtoupper(trim((string) ($item['AddressesStatus'] ?? '')));
            $tipo = ($alt['tipo'] ?? false) && ($c['tipo_acme'] ?? null) !== null ? (string) $c['tipo_acme'] : $tipoAtual;
            if ($tipo === '' || $tipo === $tipoAtual) {
                return $this->resultado('sem_mudanca', $addressId, 'Endereço já está na ACME com o mesmo tipo.');
            }
            $status = $statusAtual !== '' ? $statusAtual : (string) ($c['status_inclusao'] ?? AcmeContatoMapeamento::STATUS_NAO_CONFIRMADO);
            $resposta = $this->client->atualizarStatusEndereco($documento, $addressId, $tipo, $status);
            return $this->resultado('status_atualizado', $addressId, sprintf('Tipo %s→%s.', $tipoAtual ?: '-', $tipo), $resposta);
        }

        if (!(bool) ($c['permitir_inclusao'] ?? false)) {
            return $this->resultado('ignorado', null, 'Endereço não está na ACME e não foi cadastrado por operador - não é incluído.');
        }

        $corpo = [
            'DocNumber' => $documento,
            'AddressType' => (string) ($c['tipo_inclusao'] ?? AcmeContatoMapeamento::ENDERECO_NAO_DEFINIDO),
            'Locale' => (string) ($c['logradouro'] ?? ''),
            'Number' => $numero,
            'Complement' => (string) ($c['complemento'] ?? ''),
            'Region' => (string) ($c['bairro'] ?? ''),
            'City' => (string) ($c['cidade'] ?? ''),
            'State' => (string) ($c['uf'] ?? ''),
            'CEP' => $cep,
            'AddressesStatus' => (string) ($c['status_inclusao'] ?? AcmeContatoMapeamento::STATUS_NAO_CONFIRMADO),
        ];
        if ($recordId !== null) {
            $corpo['RecordID'] = $recordId;
        }
        $resposta = $this->client->inserirEndereco($corpo);
        return $this->resultado('incluido', $this->intOuNull($resposta['AddressID'] ?? null), 'Endereço incluído na ACME.', $resposta);
    }

    /** @param array<string,mixed>|null $respostaAcme @return array<string,mixed> */
    private function resultado(string $acao, ?int $idAcme, string $mensagem, ?array $respostaAcme = null): array
    {
        return [
            'acao' => $acao,
            'id_acme' => $idAcme,
            'mensagem' => $mensagem,
            'resposta_acme' => $respostaAcme,
        ];
    }

    private function textoComparavel(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));
        $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto;
        return preg_replace('/[^a-z0-9]+/', '', $texto) ?? '';
    }

    private function intOuNull(mixed $valor): ?int
    {
        $int = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int === false ? null : (int) $int;
    }

    /** @return array<string,mixed> */
    private function arr(mixed $valor): array
    {
        return is_array($valor) ? $valor : [];
    }
}
