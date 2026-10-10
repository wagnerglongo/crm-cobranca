<?php

declare(strict_types=1);

namespace CredorSimulado;

use DateInterval;
use DateTimeImmutable;

/**
 * Regras do simulador do credor. Sem dependências: dá para rodar com o
 * servidor embutido do PHP e testar instanciando a classe direto.
 */
final class Simulador
{
    private const NEGADO = ['Message' => 'Authorization has been denied for this request.'];
    private const ACORDO_NAO_ENCONTRADO = ['Message' => 'Dados do acordo não encontrados.'];

    private const NOMES = ['Ana', 'Bruno', 'Carla', 'Diego', 'Elisa', 'Fábio', 'Gabriela', 'Heitor', 'Isabela', 'João', 'Larissa', 'Marcos', 'Natália', 'Otávio', 'Paula', 'Rafael', 'Sofia', 'Thiago', 'Vanessa', 'Yuri'];
    private const SOBRENOMES = ['Almeida', 'Barbosa', 'Cardoso', 'Duarte', 'Esteves', 'Ferraz', 'Gomes', 'Henriques', 'Lima', 'Moraes', 'Nogueira', 'Oliveira', 'Pereira', 'Queiroz', 'Rocha', 'Santana', 'Teixeira', 'Vieira'];
    private const RUAS = ['Rua das Acácias', 'Avenida Brasil', 'Rua XV de Novembro', 'Rua dos Ipês', 'Avenida das Nações', 'Rua Sete de Setembro', 'Travessa do Comércio'];
    private const CIDADES = [['São Paulo', 'SP', '11'], ['Campinas', 'SP', '19'], ['Belo Horizonte', 'MG', '31'], ['Curitiba', 'PR', '41'], ['Porto Alegre', 'RS', '51'], ['Recife', 'PE', '81'], ['Goiânia', 'GO', '62']];

    /** Tabelas de códigos de contato devolvidas pelos catálogos. */
    private const TIPOS_CONTATO = [
        1 => ['1' => 'Residencial', '2' => 'Comercial', '4' => 'Celular', '15' => 'Celular / Whats'],
        2 => ['R' => 'Residencial', 'C' => 'Comercial', 'E' => 'Endereço de Cobrança'],
        3 => ['1' => 'Financiado', '3' => 'Comercial'],
    ];
    private const STATUS_CONTATO = ['H' => 'Hot', 'O' => 'Correto', 'I' => 'Incorreto', 'P' => 'Não Confirmado', 'N' => 'Desconhecido'];

    public function __construct(
        private readonly string $arquivoEstado,
        private readonly string $usuario,
        private readonly string $senha,
        private readonly ?DateTimeImmutable $hoje = null,
    ) {
    }

    /** @return array{0:int,1:array<mixed>} */
    public function atender(string $metodo, string $caminho, string $corpo, string $contentType, string $autorizacao): array
    {
        $metodo = strtoupper($metodo);
        $caminho = '/' . trim($caminho, '/');

        if ($metodo === 'GET' && $caminho === '/health') {
            return [200, ['status' => 'ok']];
        }
        if ($metodo === 'POST' && $caminho === '/oauth/token') {
            return $this->token($this->ler($corpo, $contentType));
        }
        if (!hash_equals('Bearer ' . $this->tokenValido(), $autorizacao)) {
            return [401, self::NEGADO];
        }

        $dados = $this->ler($corpo, $contentType);
        $rotas = [
            ['GET', '#^/v1/devedores/(\d{11}|\d{14})$#', fn (array $m) => $this->devedor($m[1])],
            ['GET', '#^/v1/devedores/(\d{11}|\d{14})/(telefones|emails|enderecos)$#', fn (array $m) => [200, $this->contatos($m[1])[$m[2]]]],
            ['POST', '#^/v1/contatos/(telefones|emails|enderecos)$#', fn (array $m) => $this->incluirContato($m[1], $dados)],
            ['PUT', '#^/v1/devedores/(\d{11}|\d{14})/(telefones|emails|enderecos)/(\d+)/status/(.+)$#', fn (array $m) => $this->atualizarStatusContato($m[1], $m[2], (int) $m[3], explode('/', $m[4]))],
            ['GET', '#^/v1/catalogos/tipos-contato/([123])$#', fn (array $m) => [200, $this->tabela(self::TIPOS_CONTATO[(int) $m[1]], 'Type', 'TypeAlias')]],
            ['GET', '#^/v1/catalogos/status-contato$#', fn () => [200, $this->tabela(self::STATUS_CONTATO, 'Status', 'StatusAlias')]],
            ['GET', '#^/v1/devedores/(\d{11}|\d{14})/contratos/([A-Za-z0-9._-]+)/propostas$#', fn (array $m) => [200, $this->propostas($m[1], $m[2])]],
            ['GET', '#^/v1/devedores/(\d{11}|\d{14})/contratos/([A-Za-z0-9._-]+)/acordo$#', fn (array $m) => $this->acordo($m[1], $m[2])],
            ['POST', '#^/v1/acordos$#', fn () => $this->criarAcordo($dados)],
            ['GET', '#^/v1/parcelas/(\d+)/boleto$#', fn (array $m) => $this->boleto((int) $m[1])],
            ['POST', '#^/v1/acionamentos$#', fn () => $this->incluirAcionamento($dados)],
            ['GET', '#^/v1/devedores/(\d{11}|\d{14})/acionamentos$#', fn (array $m) => [200, $this->estado()['acionamentos'][$m[1]] ?? []]],
        ];

        foreach ($rotas as [$verbo, $padrao, $acao]) {
            if ($verbo === $metodo && preg_match($padrao, $caminho, $m) === 1) {
                return $acao($m);
            }
        }

        return [404, ['Message' => 'Recurso não encontrado no simulador: ' . $metodo . ' ' . $caminho]];
    }

    // ---- autenticação --------------------------------------------------------

    /** @param array<string,mixed> $dados @return array{0:int,1:array<mixed>} */
    private function token(array $dados): array
    {
        $ok = ($dados['grant_type'] ?? '') === 'password'
            && hash_equals($this->usuario, (string) ($dados['username'] ?? ''))
            && hash_equals($this->senha, (string) ($dados['password'] ?? ''));

        return $ok
            ? [200, ['access_token' => $this->tokenValido(), 'token_type' => 'bearer', 'expires_in' => 3600]]
            : [400, ['error' => 'invalid_grant', 'error_description' => 'Usuário ou senha inválidos.']];
    }

    private function tokenValido(): string
    {
        return 'sim-' . hash_hmac('sha256', $this->usuario, $this->senha);
    }

    // ---- devedor e contatos --------------------------------------------------

    /** @return array{0:int,1:array<mixed>} */
    private function devedor(string $documento): array
    {
        $contatos = $this->contatos($documento);
        return [200, [
            'DocNumber' => $documento,
            'Name' => $this->nome($documento),
            'RecordsPhones' => $contatos['telefones'],
            'RecordsMails' => $contatos['emails'],
            'Addresses' => $contatos['enderecos'],
        ]];
    }

    /** @return array{telefones:list<array<string,mixed>>,emails:list<array<string,mixed>>,enderecos:list<array<string,mixed>>} */
    private function contatos(string $documento): array
    {
        $salvos = $this->estado()['contatos'][$documento] ?? null;
        if (is_array($salvos)) {
            return $salvos;
        }

        $semente = $this->semente($documento);
        [$cidade, $uf, $ddd] = self::CIDADES[$semente % count(self::CIDADES)];
        $nome = $this->nome($documento);
        $usuarioEmail = $this->slug($nome);

        return [
            'telefones' => [
                $this->telefone($documento, 1, $ddd, '9' . str_pad((string) ($semente % 100000000), 8, '0', STR_PAD_LEFT), '15', 'H'),
                $this->telefone($documento, 2, $ddd, '3' . str_pad((string) (($semente >> 3) % 10000000), 7, '0', STR_PAD_LEFT), '1', 'P'),
            ],
            'emails' => [[
                'RecordID' => $documento . '-E1',
                'MailID' => 1,
                'MailAddress' => $usuarioEmail . '@exemplo.com.br',
                'EmailType' => '1',
                'EmailTypeAlias' => self::TIPOS_CONTATO[3]['1'],
                'Status' => 'O',
                'StatusAlias' => self::STATUS_CONTATO['O'],
            ]],
            'enderecos' => [[
                'RecordID' => $documento . '-A1',
                'AddressID' => 1,
                'CEP' => str_pad((string) ($semente % 99999999), 8, '0', STR_PAD_LEFT),
                'Locale' => self::RUAS[$semente % count(self::RUAS)],
                'Number' => (string) (10 + $semente % 1900),
                'Complement' => $semente % 3 === 0 ? 'Apto ' . (11 + $semente % 80) : '',
                'Region' => 'Centro',
                'City' => $cidade,
                'State' => $uf,
                'AddressType' => 'R',
                'AddressTypeAlias' => self::TIPOS_CONTATO[2]['R'],
                'AddressesStatus' => 'O',
                'AddressesStatusAlias' => self::STATUS_CONTATO['O'],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function telefone(string $documento, int $id, string $ddd, string $numero, string $tipo, string $status): array
    {
        return [
            'RecordID' => $documento . '-P' . $id,
            'PhoneID' => $id,
            'DDD' => $ddd,
            'PhoneNumber' => $numero,
            'PhoneType' => $tipo,
            'PhoneTypeAlias' => self::TIPOS_CONTATO[1][$tipo] ?? 'Não Definido',
            'Status' => $status,
            'StatusAlias' => self::STATUS_CONTATO[$status],
            'Priority' => $id,
        ];
    }

    /** @param array<string,mixed> $dados @return array{0:int,1:array<mixed>} */
    private function incluirContato(string $tipo, array $dados): array
    {
        $documento = preg_replace('/\D+/', '', (string) ($dados['DocNumber'] ?? '')) ?? '';
        if (!in_array(strlen($documento), [11, 14], true)) {
            return [400, ['Message' => 'DocNumber inválido.']];
        }

        $novoId = 0;
        $this->alterarEstado(function (array &$estado) use ($tipo, $dados, $documento, &$novoId): void {
            $contatos = $estado['contatos'][$documento] ?? $this->contatos($documento);
            $chaveId = ['telefones' => 'PhoneID', 'emails' => 'MailID', 'enderecos' => 'AddressID'][$tipo];
            $novoId = 1 + max([0, ...array_map(static fn (array $c): int => (int) ($c[$chaveId] ?? 0), $contatos[$tipo])]);
            $registro = array_merge($dados, [$chaveId => $novoId, 'RecordID' => $documento . '-' . strtoupper($tipo[0]) . $novoId]);
            unset($registro['DocNumber']);
            $contatos[$tipo][] = $registro;
            $estado['contatos'][$documento] = $contatos;
        });

        return [200, ['Message' => 'Registro incluído com sucesso.', 'ID' => $novoId]];
    }

    /** @param list<string> $valores tipo/status (telefone e endereço) ou só status (e-mail) @return array{0:int,1:array<mixed>} */
    private function atualizarStatusContato(string $documento, string $tipo, int $id, array $valores): array
    {
        $encontrado = false;
        $this->alterarEstado(function (array &$estado) use ($documento, $tipo, $id, $valores, &$encontrado): void {
            $contatos = $estado['contatos'][$documento] ?? $this->contatos($documento);
            $chaveId = ['telefones' => 'PhoneID', 'emails' => 'MailID', 'enderecos' => 'AddressID'][$tipo];
            $chaveStatus = $tipo === 'enderecos' ? 'AddressesStatus' : 'Status';
            foreach ($contatos[$tipo] as &$contato) {
                if ((int) ($contato[$chaveId] ?? 0) !== $id) {
                    continue;
                }
                $status = strtoupper((string) end($valores));
                $contato[$chaveStatus] = $status;
                $contato[$chaveStatus . 'Alias'] = self::STATUS_CONTATO[$status] ?? $status;
                if (count($valores) === 2) {
                    $contato[$tipo === 'telefones' ? 'PhoneType' : 'AddressType'] = $valores[0];
                }
                $encontrado = true;
            }
            unset($contato);
            $estado['contatos'][$documento] = $contatos;
        });

        return $encontrado
            ? [200, ['Message' => 'Status atualizado com sucesso.']]
            : [404, ['Message' => 'Contato não encontrado.']];
    }

    /** @param array<string,string> $tabela @return list<array<string,string>> */
    private function tabela(array $tabela, string $chave, string $alias): array
    {
        $itens = [];
        foreach ($tabela as $codigo => $descricao) {
            $itens[] = [$chave => (string) $codigo, $alias => $descricao];
        }
        return $itens;
    }

    // ---- negociação ----------------------------------------------------------

    /** @return array<string,mixed> */
    private function propostas(string $documento, string $contrato): array
    {
        $divida = $this->valorDivida($documento, $contrato);
        $opcoes = [];
        foreach ($this->planos($divida) as $id => [$parcelas, $valorParcela]) {
            $opcoes[] = [
                'ProposalTypeID' => $id,
                'ProposalTypeDescription' => $parcelas === 1
                    ? 'À vista de R$ ' . $this->moeda($valorParcela)
                    : $parcelas . 'x de R$ ' . $this->moeda($valorParcela),
                'CalculationType' => $parcelas === 1 ? 'avista' : 'x',
                'FirstInstallmentDate' => $this->diasUteis(1)->format('Y-m-d\T00:00:00'),
            ];
        }

        return [
            'DocNumber' => $documento,
            'Contract' => $contrato,
            'BindingID' => $contrato,
            'Amount' => $divida,
            'OwnerDescription' => 'ACME Financeira (simulador)',
            'StatusDescription' => $this->acordoSalvo($documento, $contrato) !== null ? 'Em acordo' : 'Em aberto',
            'DebtDate' => $this->hoje()->sub(new DateInterval('P' . (60 + $this->semente($contrato) % 600) . 'D'))->format('Y-m-d\T00:00:00'),
            'MoreDetail' => 'Proposta gerada pelo simulador local. Valores fictícios.',
            'CustomProposalTag' => '',
            'ListProposalType' => $opcoes,
            'ListInstallmentDate' => array_map(fn (int $d): string => $this->diasUteis($d)->format('Y-m-d\T00:00:00'), [1, 2, 3, 5, 7]),
            'ListAgreementType' => [['ValueID' => 1, 'DescriptionValue' => 'Acordo padrão']],
            'ListPaymentType' => [['PaymentTypeTag' => 'BOL', 'PaymentTypeDescription' => 'Boleto bancário']],
            'ListHowReceiveBankSlip' => [
                ['PaymentTypeTag' => 'BOL', 'HowReceiveBankSlipTag' => 'EMAIL', 'HowReceiveBankSlipDescription' => 'E-mail'],
                ['PaymentTypeTag' => 'BOL', 'HowReceiveBankSlipTag' => 'WHATS', 'HowReceiveBankSlipDescription' => 'WhatsApp'],
            ],
        ];
    }

    /**
     * Planos oferecidos: à vista com desconto e parcelados com acréscimo.
     * @return array<int,array{0:int,1:float}> id => [parcelas, valor da parcela]
     */
    private function planos(float $divida): array
    {
        return [
            1 => [1, round($divida * 0.60, 2)],
            2 => [3, round($divida * 0.75 / 3, 2)],
            3 => [6, round($divida * 0.85 / 6, 2)],
            4 => [10, round($divida * 0.95 / 10, 2)],
        ];
    }

    /** @return array{0:int,1:array<mixed>} */
    private function acordo(string $documento, string $contrato): array
    {
        $acordo = $this->acordoSalvo($documento, $contrato);
        if ($acordo === null) {
            return [404, self::ACORDO_NAO_ENCONTRADO];
        }

        $hoje = $this->hoje()->format('Y-m-d');
        $parcelas = $acordo['ListInstallments'];
        $pagas = 0;
        foreach ($parcelas as &$parcela) {
            // Para a demonstração ficar viva: parcela vencida há mais de 2 dias é dada como paga.
            $vencimento = substr((string) $parcela['InstallmentDate'], 0, 10);
            if ($parcela['PaymentDate'] === null && $vencimento < $this->hoje()->sub(new DateInterval('P2D'))->format('Y-m-d')) {
                $parcela['PaymentDate'] = $vencimento . 'T00:00:00';
                $parcela['Status'] = 'Pago';
            }
            if ($parcela['PaymentDate'] !== null) {
                $pagas++;
            } elseif ($vencimento < $hoje) {
                $parcela['Status'] = 'Vencida';
            }
        }
        unset($parcela);

        $total = count($parcelas);
        $acordo['ListInstallments'] = $parcelas;
        $acordo['TotalInstalments'] = $pagas . '/' . $total;
        $acordo['AgreementStatus'] = $pagas === $total ? 'Quitado' : 'Ativo';

        return [200, [
            'HasAgreementHistory' => true,
            'Debt' => [
                'Contract' => $contrato,
                'DebtAmount' => $this->valorDivida($documento, $contrato),
                'OwnerDescription' => 'ACME Financeira (simulador)',
                'DebtDate' => $acordo['AgreementDate'],
                'StatusDescription' => $pagas === $total ? 'Liquidada' : 'Em acordo',
            ],
            'Agreement' => $acordo,
        ]];
    }

    /** @param array<string,mixed> $dados @return array{0:int,1:array<mixed>} */
    private function criarAcordo(array $dados): array
    {
        $documento = preg_replace('/\D+/', '', (string) ($dados['DocNumber'] ?? '')) ?? '';
        $contrato = trim((string) ($dados['Contract'] ?? ''));
        $plano = (int) ($dados['NegotiationID'] ?? 0);
        $primeiroVencimento = substr(trim((string) ($dados['InstallmentDate'] ?? '')), 0, 10);

        $planos = $this->planos($this->valorDivida($documento, $contrato));
        if ($documento === '' || $contrato === '' || !isset($planos[$plano])) {
            return [400, ['Message' => 'Proposta inválida.']];
        }
        if (DateTimeImmutable::createFromFormat('!Y-m-d', $primeiroVencimento) === false) {
            return [400, ['Message' => 'InstallmentDate inválido.']];
        }
        if ($this->acordoSalvo($documento, $contrato) !== null) {
            return [409, ['Message' => 'Já existe acordo ativo para este contrato.']];
        }

        [$quantidade, $valorParcela] = $planos[$plano];
        $acordoId = 0;
        $this->alterarEstado(function (array &$estado) use ($documento, $contrato, $quantidade, $valorParcela, $primeiroVencimento, &$acordoId): void {
            $acordoId = $estado['sequencia'] = ($estado['sequencia'] ?? 1000) + 1;
            $primeiro = new DateTimeImmutable($primeiroVencimento);
            $parcelas = [];
            for ($n = 1; $n <= $quantidade; $n++) {
                $vencimento = $primeiro->add(new DateInterval('P' . ($n - 1) . 'M'));
                $parcelas[] = [
                    'InstallmentID' => $acordoId * 100 + $n,
                    'InstallmentNumber' => $n,
                    'InstallmentDescription' => $n . '/' . $quantidade,
                    'InvoiceNumber' => 'SIM' . $acordoId . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
                    'Amount' => $valorParcela,
                    'InstallmentDate' => $vencimento->format('Y-m-d\T00:00:00'),
                    'DeadlineDate' => $vencimento->add(new DateInterval('P5D'))->format('Y-m-d\T00:00:00'),
                    'PaymentDate' => null,
                    'Status' => 'Em aberto',
                ];
            }
            $estado['acordos'][$documento . '|' . $contrato] = [
                'AgreementID' => (string) $acordoId,
                'AgreementStatus' => 'Ativo',
                'AgreementDate' => $this->hoje()->format('Y-m-d\T00:00:00'),
                'AgreementAmount' => round($valorParcela * $quantidade, 2),
                'TotalInstalments' => '0/' . $quantidade,
                'ListInstallments' => $parcelas,
            ];
        });

        return [200, ['Message' => 'Proposta gravada com sucesso.', 'AgreementID' => (string) $acordoId]];
    }

    /** @return array{0:int,1:array<mixed>} */
    private function boleto(int $parcelaId): array
    {
        foreach ($this->estado()['acordos'] ?? [] as $acordo) {
            foreach ($acordo['ListInstallments'] as $parcela) {
                if ((int) $parcela['InstallmentID'] !== $parcelaId) {
                    continue;
                }
                $linha = $this->linhaDigitavel($parcelaId, (float) $parcela['Amount']);
                return [200, [
                    'InstallmentID' => $parcelaId,
                    'DigitableLine' => $linha,
                    'FileName' => 'boleto-' . $parcelaId . '.pdf',
                    'Base64' => base64_encode($this->pdfBoleto($parcela, $linha)),
                ]];
            }
        }
        return [404, ['Message' => 'Parcela não encontrada.']];
    }

    // ---- acionamentos --------------------------------------------------------

    /** @param array<string,mixed> $dados @return array{0:int,1:array<mixed>} */
    private function incluirAcionamento(array $dados): array
    {
        $documento = preg_replace('/\D+/', '', (string) ($dados['DocNumber'] ?? '')) ?? '';
        $acao = (int) ($dados['ActionID'] ?? 0);
        if (!in_array(strlen($documento), [11, 14], true) || $acao <= 0 || trim((string) ($dados['Contract'] ?? '')) === '') {
            return [400, ['Message' => 'DocNumber, Contract e ActionID são obrigatórios.']];
        }

        $rastreio = '';
        $this->alterarEstado(function (array &$estado) use ($dados, $documento, $acao, &$rastreio): void {
            $id = $estado['sequencia'] = ($estado['sequencia'] ?? 1000) + 1;
            $rastreio = 'TRK' . $id;
            $estado['acionamentos'][$documento][] = [
                'RecordID' => (string) $id,
                'TrackingID' => $rastreio,
                'DocNumber' => $documento,
                'Contract' => (string) $dados['Contract'],
                'ActionID' => $acao,
                'ActionDescription' => 'Ação ' . $acao,
                'Notes' => (string) ($dados['Notes'] ?? ''),
                'CreationDate' => (string) ($dados['CreationDate'] ?? $this->hoje()->format('Y-m-d\TH:i:s')),
                'ScheduleDateTime' => $dados['ScheduleDateTime'] ?? null,
            ];
        });

        return [200, ['Message' => 'Acionamento incluído com sucesso.', 'TrackingID' => $rastreio]];
    }

    // ---- utilitários ---------------------------------------------------------

    /** @return array<string,mixed> */
    private function ler(string $corpo, string $contentType): array
    {
        if ($corpo === '') {
            return [];
        }
        if (str_contains($contentType, 'json')) {
            $json = json_decode($corpo, true);
            return is_array($json) ? $json : [];
        }
        parse_str($corpo, $form);
        return $form;
    }

    /** @return array<string,mixed> */
    private function estado(): array
    {
        if (!is_file($this->arquivoEstado)) {
            return [];
        }
        $dados = json_decode((string) file_get_contents($this->arquivoEstado), true);
        return is_array($dados) ? $dados : [];
    }

    /** Lê, altera e grava o estado com lock exclusivo (o servidor embutido pode ter workers). */
    private function alterarEstado(callable $alteracao): void
    {
        $handle = fopen($this->arquivoEstado, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Não foi possível abrir o arquivo de estado do simulador.');
        }
        try {
            flock($handle, LOCK_EX);
            $atual = json_decode((string) stream_get_contents($handle), true);
            $estado = is_array($atual) ? $atual : [];
            $alteracao($estado);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string,mixed>|null */
    private function acordoSalvo(string $documento, string $contrato): ?array
    {
        $acordo = $this->estado()['acordos'][$documento . '|' . $contrato] ?? null;
        return is_array($acordo) ? $acordo : null;
    }

    private function semente(string $texto): int
    {
        return crc32($texto) & 0x7fffffff;
    }

    private function nome(string $documento): string
    {
        $s = $this->semente($documento);
        return self::NOMES[$s % count(self::NOMES)] . ' '
            . self::SOBRENOMES[($s >> 5) % count(self::SOBRENOMES)] . ' '
            . self::SOBRENOMES[($s >> 11) % count(self::SOBRENOMES)];
    }

    private function valorDivida(string $documento, string $contrato): float
    {
        return round(800 + $this->semente($documento . '|' . $contrato) % 1200000 / 100, 2);
    }

    private function slug(string $texto): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $texto) ?: $texto;
        return trim((string) preg_replace('/[^a-z]+/', '.', strtolower($ascii)), '.');
    }

    private function moeda(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }

    private function hoje(): DateTimeImmutable
    {
        return $this->hoje ?? new DateTimeImmutable('today');
    }

    private function diasUteis(int $quantidade): DateTimeImmutable
    {
        $data = $this->hoje();
        while ($quantidade > 0) {
            $data = $data->add(new DateInterval('P1D'));
            if ((int) $data->format('N') < 6) {
                $quantidade--;
            }
        }
        return $data;
    }

    /** Linha digitável de 47 dígitos, só para exibição (não é um boleto pagável). */
    private function linhaDigitavel(int $parcelaId, float $valor): string
    {
        $digitos = '00190' . str_pad((string) $parcelaId, 20, '0', STR_PAD_LEFT) . '9' . str_pad((string) (int) round($valor * 100), 21, '0', STR_PAD_LEFT);
        return substr($digitos, 0, 47);
    }

    /** PDF mínimo (uma página, texto) gerado à mão para não depender de biblioteca. */
    private function pdfBoleto(array $parcela, string $linha): string
    {
        $texto = [
            'BOLETO DE DEMONSTRACAO - SEM VALOR',
            'Parcela ' . $parcela['InstallmentDescription'] . ' - R$ ' . $this->moeda((float) $parcela['Amount']),
            'Vencimento: ' . (new DateTimeImmutable((string) $parcela['InstallmentDate']))->format('d/m/Y'),
            'Linha digitavel: ' . $linha,
        ];
        $conteudo = "BT /F1 12 Tf 50 780 Td 16 TL\n";
        foreach ($texto as $linhaTexto) {
            $conteudo .= '(' . addcslashes($linhaTexto, '()\\') . ") Tj T*\n";
        }
        $conteudo .= 'ET';

        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length ' . strlen($conteudo) . " >>\nstream\n" . $conteudo . "\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $posicoes = [];
        foreach ($objetos as $i => $objeto) {
            $posicoes[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $objeto . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objetos) + 1) . "\n0000000000 65535 f \n";
        foreach ($posicoes as $posicao) {
            $pdf .= sprintf("%010d 00000 n \n", $posicao);
        }
        return $pdf . 'trailer << /Size ' . (count($objetos) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}
