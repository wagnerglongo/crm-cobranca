<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Services;

use CRM\Config\Database;
use CRM\Infrastructure\Http\CircuitBreaker;
use CRM\Infrastructure\Redis\RedisCache;
use RuntimeException;

final class AcmeApiClient
{
    private const ENDPOINT_TOKEN = '/oauth/token';
    private const ENDPOINT_INSERIR_ACIONAMENTO = '/v1/acionamentos';
    private const ENDPOINT_CONSULTAR_ACIONAMENTOS = '/v1/devedores/{docNumber}/acionamentos';
    // Contatos do devedor (contrato documentado em docs/CREDOR_API.md).
    private const ENDPOINT_LISTAR_TELEFONES = '/v1/devedores/{docNumber}/telefones';
    private const ENDPOINT_LISTAR_EMAILS = '/v1/devedores/{docNumber}/emails';
    private const ENDPOINT_LISTAR_ENDERECOS = '/v1/devedores/{docNumber}/enderecos';
    private const ENDPOINT_INSERIR_TELEFONE = '/v1/contatos/telefones';
    private const ENDPOINT_INSERIR_EMAIL = '/v1/contatos/emails';
    private const ENDPOINT_INSERIR_ENDERECO = '/v1/contatos/enderecos';
    private const ENDPOINT_STATUS_TELEFONE = '/v1/devedores/{docNumber}/telefones/{phoneID}/status/{phoneType}/{phoneStatus}';
    private const ENDPOINT_STATUS_EMAIL = '/v1/devedores/{docNumber}/emails/{mailID}/status/{mailStatus}';
    private const ENDPOINT_STATUS_ENDERECO = '/v1/devedores/{docNumber}/enderecos/{addressID}/status/{addressType}/{addressStatus}';
    // Tabelas de codigos (consultadas so quando chega um codigo desconhecido - AcmeCatalogoContato).
    private const ENDPOINT_TIPOS_CONTATO = '/v1/catalogos/tipos-contato/{typeContact}';
    private const ENDPOINT_STATUS_CONTATO = '/v1/catalogos/status-contato';

    private string $baseUrl;
    private string $tokenBaseUrl;
    private string $username;
    private string $password;
    private int $timeout;
    private string $cacheFile;
    private string $logFile;
    private string $structuredLogFile;
    private int $structuredLogMaxBytes;
    private RedisCache $redisCache;
    private string $tokenCacheContext;
    private int $lastHttpStatus = 0;
    private readonly CircuitBreaker $breaker;
    /** @var (callable(string,string,list<string>,?string):array{0:string|false|null,1:int,2:string})|null */
    private $transport;
    private string $circuitName;

    /**
     * @param (callable(string $url, string $method, list<string> $headers, ?string $content):array{0:string|false|null,1:int,2:string})|null $transport
     *        Substitui a chamada de rede real (curl/stream); devolve [corpo, status HTTP, erro]. So pra teste.
     * @param string $circuitName Nome do circuito no Redis (padrao 'acme', compartilhado com a producao);
     *        testes usam um nome unico pra nunca abrir o circuito real.
     */
    public function __construct(?string $cacheFile = null, ?int $timeoutOverride = null, ?callable $transport = null, string $circuitName = 'acme')
    {
        $this->transport = $transport;
        $this->circuitName = $circuitName;
        $this->baseUrl = rtrim((string) Database::env('ACME_API_BASE_URL', 'http://credor-simulado:8080'), '/');
        $this->tokenBaseUrl = rtrim((string) Database::env('ACME_API_TOKEN_BASE_URL', 'http://credor-simulado:8080'), '/');
        $this->username = (string) Database::env('ACME_API_USERNAME', '');
        $this->password = (string) Database::env('ACME_API_PASSWORD', '');
        $this->timeout = max(3, min(120, $timeoutOverride ?? (int) Database::env('ACME_API_TIMEOUT', '45')));
        $defaultCache = dirname(__DIR__, 5) . '/storage/cache/acme_api_token.json';
        $this->cacheFile = $cacheFile ?? (string) Database::env('ACME_API_TOKEN_CACHE', $defaultCache);
        $this->logFile = (string) Database::env('ACME_API_LOG_FILE', dirname(__DIR__, 5) . '/storage/logs/acme_api.log');
        $this->structuredLogFile = (string) Database::env('ACME_API_STRUCTURED_LOG_FILE', dirname(__DIR__, 5) . '/storage/logs/integracoes_acme.jsonl');
        $this->structuredLogMaxBytes = max(1048576, (int) Database::env('ACME_API_STRUCTURED_LOG_MAX_BYTES', '5242880'));
        $this->redisCache = RedisCache::default();
        $this->tokenCacheContext = hash('sha256', $this->tokenBaseUrl . '|' . mb_strtolower($this->username));
        $this->breaker = new CircuitBreaker();
    }

    public function configurado(): bool
    {
        return $this->baseUrl !== '' && $this->tokenBaseUrl !== '' && $this->username !== '' && $this->password !== '';
    }

    public function ultimoHttpStatus(): int
    {
        return $this->lastHttpStatus;
    }

    /** @param array<string,mixed> $dados @return array<string,mixed> */
    public function inserirAcionamento(array $dados): array
    {
        if (!$this->configurado()) {
            throw new RuntimeException('API ACME não configurada. Configure ACME_API_USERNAME e ACME_API_PASSWORD no .env.local.');
        }

        $documento = preg_replace('/\D+/', '', (string) ($dados['DocNumber'] ?? '')) ?? '';
        $contrato = trim((string) ($dados['Contract'] ?? ''));
        $contrato = mb_substr($contrato, 0, 120);
        $actionId = filter_var($dados['ActionID'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $notes = mb_substr(trim((string) ($dados['Notes'] ?? '')), 0, 3000);
        $creationDate = trim((string) ($dados['CreationDate'] ?? ''));
        $scheduleDateTime = trim((string) ($dados['ScheduleDateTime'] ?? ''));

        if (!in_array(strlen($documento), [11, 14], true)
            || $contrato === ''
            || preg_match('/[\x00-\x1F\x7F]/u', $contrato) === 1
        ) {
            throw new RuntimeException('CPF/CNPJ ou contrato inválido para inserir acionamento ACME.');
        }
        if ($actionId <= 0) {
            throw new RuntimeException('ActionID ACME inválido. Revise o de/para da tabulação.');
        }
        if ($creationDate === '') {
            throw new RuntimeException('CreationDate não informado para o acionamento ACME.');
        }

        $body = [
            'Contract' => $contrato,
            'Notes' => $notes,
            'DocNumber' => $documento,
            'ActionID' => $actionId,
            'CreationDate' => $creationDate,
        ];
        // Achado 2026-10-06: o adapter passou a mandar ScheduleDateTime em
        // 28/09 (a ACME exige pra "Recado"), mas este corpo era montado so com
        // os 5 campos acima e descartava a data - o reagendamento nunca
        // chegava na ACME e todo Recado continuaria falhando.
        if ($scheduleDateTime !== '' && preg_match('/[\x00-\x1F\x7F]/u', $scheduleDateTime) !== 1) {
            $body['ScheduleDateTime'] = mb_substr($scheduleDateTime, 0, 40);
        }
        $path = self::ENDPOINT_INSERIR_ACIONAMENTO;
        $response = $this->requestJson('POST', $path, $body, $this->token(false), true, [], 'json');

        if ($this->authorizationDenied($response)) {
            $response = $this->requestJson('POST', $path, $body, $this->token(true), true, [], 'json');
        }
        if ($this->authorizationDenied($response)) {
            throw new AcmeApiException(
                'Autorização negada pela API ACME ao inserir acionamento após uma renovação de token.',
                401,
                false,
                $response
            );
        }

        return $response;
    }

    /** @return list<array<string,mixed>> */
    public function consultarAcionamentos(string $documento): array
    {
        if (!$this->configurado()) {
            throw new RuntimeException('API ACME não configurada. Configure ACME_API_USERNAME e ACME_API_PASSWORD no .env.local.');
        }

        $documento = preg_replace('/\D+/', '', $documento) ?? '';
        if (!in_array(strlen($documento), [11, 14], true)) {
            throw new RuntimeException('CPF/CNPJ inválido para consultar acionamentos ACME.');
        }

        $template = self::ENDPOINT_CONSULTAR_ACIONAMENTOS;
        $path = str_replace('{docNumber}', rawurlencode($documento), $template);
        $response = $this->requestJson('GET', $path, null, $this->token(false), true);

        if ($this->authorizationDenied($response)) {
            $response = $this->requestJson('GET', $path, null, $this->token(true), true);
        }
        if ($this->authorizationDenied($response)) {
            throw new AcmeApiException(
                'Autorização negada pela API ACME ao consultar acionamentos após uma renovação de token.',
                401,
                false,
                $response
            );
        }

        if (array_is_list($response)) {
            return array_values(array_filter($response, static fn (mixed $item): bool => is_array($item)));
        }

        foreach (['Data', 'data', 'Items', 'items', 'Result', 'result'] as $key) {
            $items = $response[$key] ?? null;
            if (is_array($items) && array_is_list($items)) {
                return array_values(array_filter($items, static fn (mixed $item): bool => is_array($item)));
            }
        }

        return [];
    }

    /** @return list<array<string,mixed>> RecordPhoneResponse da ACME (PhoneID, DDD, PhoneNumber, PhoneType, Status...) */
    public function listarTelefones(string $documento): array
    {
        return $this->listarContatoDevedor(self::ENDPOINT_LISTAR_TELEFONES, $documento, 'telefones');
    }

    /** @return list<array<string,mixed>> RecordEmailResponse da ACME (MailID, MailAddress, Status...) */
    public function listarEmails(string $documento): array
    {
        return $this->listarContatoDevedor(self::ENDPOINT_LISTAR_EMAILS, $documento, 'e-mails');
    }

    /** @return list<array<string,mixed>> AddressResponse da ACME (AddressID, CEP, Number, AddressType...) */
    public function listarEnderecos(string $documento): array
    {
        return $this->listarContatoDevedor(self::ENDPOINT_LISTAR_ENDERECOS, $documento, 'endereços');
    }

    /** @param array<string,mixed> $dados corpo RecordPhoneResponse @return array<string,mixed> */
    public function inserirTelefone(array $dados): array
    {
        return $this->chamarComToken('POST', self::ENDPOINT_INSERIR_TELEFONE, $this->corpoContato($dados), 'json', 'inserir telefone');
    }

    /** @param array<string,mixed> $dados corpo RecordEmailResponse @return array<string,mixed> */
    public function inserirEmail(array $dados): array
    {
        return $this->chamarComToken('POST', self::ENDPOINT_INSERIR_EMAIL, $this->corpoContato($dados), 'json', 'inserir e-mail');
    }

    /** @param array<string,mixed> $dados corpo AddressResponse @return array<string,mixed> */
    public function inserirEndereco(array $dados): array
    {
        return $this->chamarComToken('POST', self::ENDPOINT_INSERIR_ENDERECO, $this->corpoContato($dados), 'json', 'inserir endereço');
    }

    /** Tipo e status vão juntos: pra mudar só um, a ACME pede o valor atual do outro. @return array<string,mixed> */
    public function atualizarStatusTelefone(string $documento, int $phoneId, int $phoneType, string $phoneStatus): array
    {
        $path = strtr(self::ENDPOINT_STATUS_TELEFONE, [
            '{docNumber}' => rawurlencode($this->documentoContato($documento)),
            '{phoneID}' => (string) $this->idPositivo($phoneId, 'PhoneID'),
            '{phoneType}' => (string) $this->idPositivo($phoneType, 'PhoneType'),
            '{phoneStatus}' => rawurlencode($this->codigoStatus($phoneStatus)),
        ]);
        return $this->chamarComToken('PUT', $path, [], 'form', 'atualizar status do telefone');
    }

    /** @return array<string,mixed> */
    public function atualizarStatusEmail(string $documento, int $mailId, string $mailStatus): array
    {
        $path = strtr(self::ENDPOINT_STATUS_EMAIL, [
            '{docNumber}' => rawurlencode($this->documentoContato($documento)),
            '{mailID}' => (string) $this->idPositivo($mailId, 'MailID'),
            '{mailStatus}' => rawurlencode($this->codigoStatus($mailStatus)),
        ]);
        return $this->chamarComToken('PUT', $path, [], 'form', 'atualizar status do e-mail');
    }

    /** @return array<string,mixed> */
    public function atualizarStatusEndereco(string $documento, int $addressId, string $addressType, string $addressStatus): array
    {
        $path = strtr(self::ENDPOINT_STATUS_ENDERECO, [
            '{docNumber}' => rawurlencode($this->documentoContato($documento)),
            '{addressID}' => (string) $this->idPositivo($addressId, 'AddressID'),
            '{addressType}' => rawurlencode($this->codigoStatus($addressType)),
            '{addressStatus}' => rawurlencode($this->codigoStatus($addressStatus)),
        ]);
        return $this->chamarComToken('PUT', $path, [], 'form', 'atualizar status do endereço');
    }

    /** Tabela de tipos de contato da ACME (1 telefone, 2 endereço, 3 e-mail). @return list<array<string,mixed>> itens {Type, TypeAlias} */
    public function listarTiposContato(int $tipoContato): array
    {
        $path = str_replace('{typeContact}', (string) $this->idPositivo($tipoContato, 'typeContact'), self::ENDPOINT_TIPOS_CONTATO);
        return $this->itensDaResposta($this->chamarComToken('GET', $path, null, 'form', 'consultar tipos de contato'));
    }

    /** Tabela de status de contato da ACME. @return list<array<string,mixed>> itens {Status, StatusAlias} */
    public function listarStatusContato(): array
    {
        return $this->itensDaResposta($this->chamarComToken('GET', self::ENDPOINT_STATUS_CONTATO, null, 'form', 'consultar status de contato'));
    }

    /** @return array<string,mixed> */
    public function gerarPropostasPortal(string $documento, string $contrato): array
    {
        if (!$this->configurado()) {
            throw new RuntimeException('API ACME não configurada. Configure ACME_API_USERNAME e ACME_API_PASSWORD no .env.local.');
        }

        $documento = preg_replace('/\D+/', '', $documento) ?? '';
        $contrato = trim($contrato);
        $contrato = preg_replace('/^[\'"\s]+|[\'"\s]+$/u', '', $contrato) ?? $contrato;
        $contratoSanitizado = preg_replace('/[^A-Za-z0-9._-]+/', '', $contrato) ?? '';
        if ($documento === '' || $contratoSanitizado === '') {
            throw new RuntimeException('Documento ou contrato inválido para consulta ACME.');
        }

        $path = '/v1/devedores/' . rawurlencode($documento) . '/contratos/' . rawurlencode($contratoSanitizado) . '/propostas';
        $response = $this->requestJson('GET', $path, null, $this->token(false));

        if ($this->authorizationDenied($response)) {
            $response = $this->requestJson('GET', $path, null, $this->token(true));
        }

        if ($this->authorizationDenied($response)) {
            throw new RuntimeException('Autorização negada pela API ACME após uma renovação de token.');
        }

        return $response;
    }


    /** @return array<string,mixed> */
    public function buscarDevedorPorDocumento(string $documento): array
    {
        if (!$this->configurado()) {
            throw new RuntimeException('API ACME não configurada. Configure ACME_API_USERNAME e ACME_API_PASSWORD no .env.local.');
        }

        $documento = preg_replace('/\D+/', '', $documento) ?? '';
        if ($documento === '') {
            throw new RuntimeException('Documento inválido para consulta de localização ACME.');
        }

        $path = '/v1/devedores/' . rawurlencode($documento);
        try {
            $response = $this->requestJson('GET', $path, null, $this->token(false));

            if ($this->authorizationDenied($response)) {
                $response = $this->requestJson('GET', $path, null, $this->token(true));
            }

            if ($this->authorizationDenied($response)) {
                throw new RuntimeException('Autorização negada pela API ACME após uma renovação de token.');
            }

            return $response;
        } catch (AcmeApiException $e) {
            if ($e->httpStatus() === 404) {
                throw new AcmeApiException(
                    'Endpoint de localização ACME indisponível no ambiente selecionado (HTTP 404).',
                    404,
                    false,
                    $e->response(),
                    $e
                );
            }
            throw $e;
        }
    }


    /** @param array<string,mixed> $dados @return array<string,mixed> */
    public function salvarPropostaGerada(array $dados): array
    {
        if (!$this->configurado()) {
            throw new RuntimeException('API ACME não configurada. Configure ACME_API_USERNAME e ACME_API_PASSWORD no .env.local.');
        }

        $docNumber = preg_replace('/\D+/', '', (string) ($dados['DocNumber'] ?? '')) ?? '';
        $contract = trim((string) ($dados['Contract'] ?? ''));
        $contract = preg_replace('/^[\'"\s]+|[\'"\s]+$/u', '', $contract) ?? $contract;
        $contract = preg_replace('/[^A-Za-z0-9._-]+/', '', $contract) ?? '';
        $ownerDescription = trim((string) ($dados['OwnerDescription'] ?? ''));
        $negotiationId = (int) ($dados['NegotiationID'] ?? 0);
        $installmentDate = trim((string) ($dados['InstallmentDate'] ?? ''));

        if ($docNumber === '') {
            throw new RuntimeException('CPF/documento inválido para salvar proposta ACME.');
        }
        if ($contract === '') {
            throw new RuntimeException('Contrato inválido para salvar proposta ACME.');
        }
        if ($ownerDescription === '') {
            throw new RuntimeException('OwnerDescription não encontrado para salvar proposta ACME. Reabra o contrato pela busca para atualizar as propostas.');
        }
        if ($negotiationId <= 0) {
            throw new RuntimeException('Proposta ACME inválida. Selecione uma proposta retornada pela API.');
        }
        if ($installmentDate === '') {
            throw new RuntimeException('Data de vencimento inválida para salvar proposta ACME.');
        }

        $body = [
            'UserID' => (string) ((int) ($dados['UserID'] ?? 0)),
            'DocNumber' => $docNumber,
            'OwnerDescription' => $ownerDescription,
            'Contract' => $contract,
            'NegotiationID' => (string) $negotiationId,
            'PhoneID' => (string) ((int) ($dados['PhoneID'] ?? 0)),
            'InstallmentDate' => $installmentDate,
        ];

        $response = $this->requestJson('POST', '/v1/acordos', $body, $this->token(false), true);

        if ($this->authorizationDenied($response)) {
            $response = $this->requestJson('POST', '/v1/acordos', $body, $this->token(true), true);
        }

        if ($this->authorizationDenied($response)) {
            throw new RuntimeException('Autorização negada pela API ACME ao salvar proposta após uma renovação de token.');
        }

        return $response;
    }



    /** @return array<string,mixed> */
    public function consultarAcordo(string $documento, string $contrato): array
    {
        if (!$this->configurado()) {
            throw new RuntimeException('API ACME não configurada. Configure ACME_API_USERNAME e ACME_API_PASSWORD no .env.local.');
        }

        $documento = preg_replace('/\D+/', '', $documento) ?? '';
        $contrato = trim($contrato);
        $contrato = preg_replace('/^[\'"\s]+|[\'"\s]+$/u', '', $contrato) ?? $contrato;
        $contratoSanitizado = preg_replace('/[^A-Za-z0-9._-]+/', '', $contrato) ?? '';
        if ($documento === '' || $contratoSanitizado === '') {
            throw new RuntimeException('Documento ou contrato inválido para consulta de acordo ACME.');
        }

        $path = '/v1/devedores/' . rawurlencode($documento) . '/contratos/' . rawurlencode($contratoSanitizado) . '/acordo';
        $response = $this->requestJson('GET', $path, null, $this->token(false), false, ['Dados do acordo não encontrados.']);

        if ($this->authorizationDenied($response)) {
            $response = $this->requestJson('GET', $path, null, $this->token(true), false, ['Dados do acordo não encontrados.']);
        }

        if ($this->authorizationDenied($response)) {
            throw new RuntimeException('Autorização negada pela API ACME ao consultar acordo após uma renovação de token.');
        }

        if ($this->acordoNaoEncontrado($response)) {
            return $this->respostaAcordoNaoEncontrado($documento, $contratoSanitizado, $response);
        }

        return $response;
    }

    /** @return array<string,mixed> */
    public function baixarBoletoParcela(int $installmentId): array
    {
        if (!$this->configurado()) {
            throw new RuntimeException('API ACME não configurada. Configure ACME_API_USERNAME e ACME_API_PASSWORD no .env.local.');
        }

        if ($installmentId <= 0) {
            throw new RuntimeException('InstallmentID inválido para baixar boleto ACME.');
        }

        $path = '/v1/parcelas/' . rawurlencode((string) $installmentId) . '/boleto';
        $response = $this->requestJson('GET', $path, null, $this->token(false));

        if ($this->authorizationDenied($response)) {
            $response = $this->requestJson('GET', $path, null, $this->token(true));
        }

        if ($this->authorizationDenied($response)) {
            throw new RuntimeException('Autorização negada pela API ACME ao baixar boleto após uma renovação de token.');
        }

        return $response;
    }

    private function token(bool $forceRefresh): string
    {
        if (!$forceRefresh) {
            $cached = $this->readTokenCache();
            if ($cached !== null) {
                return $cached;
            }
        }

        $lockFile = $this->cacheFile . '.lock';
        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }
        $lock = @fopen($lockFile, 'c+');
        if ($lock === false) {
            return $this->renewToken();
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                return $this->renewToken();
            }

            // Outra requisição pode ter renovado enquanto aguardávamos o lock.
            if (!$forceRefresh) {
                $cached = $this->readTokenCache();
                if ($cached !== null) {
                    return $cached;
                }
            }

            return $this->renewToken();
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private function renewToken(): string
    {
        $tokenPayload = $this->requestJsonAtBase($this->tokenBaseUrl, 'POST', self::ENDPOINT_TOKEN, [
            'grant_type' => 'password',
            'username' => $this->username,
            'password' => $this->password,
        ], null);

        $accessToken = (string) ($tokenPayload['access_token'] ?? '');
        $expiresIn = (int) ($tokenPayload['expires_in'] ?? 0);
        if ($accessToken === '' || $expiresIn <= 0) {
            throw new RuntimeException('Token inválido retornado pela API ACME.');
        }

        // Mantemos uma margem de 120 s para nunca iniciar uma chamada com token quase expirando.
        $this->writeTokenCache($accessToken, time() + max(60, $expiresIn - 120));
        return $accessToken;
    }

    private function readTokenCache(): ?string
    {
        $redisPayload = $this->redisCache->getJson($this->redisTokenCacheKey());
        $redisToken = $this->validTokenFromPayload($redisPayload);
        if ($redisToken !== null) {
            return $redisToken;
        }

        if (!is_file($this->cacheFile) || !is_readable($this->cacheFile)) {
            return null;
        }

        $raw = file_get_contents($this->cacheFile);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return null;
        }

        $token = $this->validTokenFromPayload($payload);
        if ($token !== null) {
            $expiresAt = (int) ($payload['expires_at'] ?? 0);
            $this->redisCache->setJson($this->redisTokenCacheKey(), $payload, max(30, $expiresAt - time()));
        }
        return $token;
    }

    private function writeTokenCache(string $token, int $expiresAt): void
    {
        $payloadData = [
            'access_token' => $token,
            'expires_at' => $expiresAt,
            'created_at' => date('c'),
            'context' => $this->tokenCacheContext,
        ];
        $this->redisCache->setJson($this->redisTokenCacheKey(), $payloadData, max(30, $expiresAt - time()));

        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }

        $fp = @fopen($this->cacheFile, 'c+');
        if ($fp === false) {
            return;
        }

        try {
            if (flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                fwrite($fp, $payload);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }



    /** @param array<string,mixed>|null $payload */
    private function validTokenFromPayload(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }
        if ((string) ($payload['context'] ?? '') !== $this->tokenCacheContext) {
            return null;
        }
        $token = (string) ($payload['access_token'] ?? '');
        $expiresAt = (int) ($payload['expires_at'] ?? 0);
        if ($token === '' || $expiresAt <= time() + 30) {
            return null;
        }
        return $token;
    }

    private function redisTokenCacheKey(): string
    {
        return 'acme:token:' . $this->tokenCacheContext;
    }

    /** @param array<string,mixed>|null $body @param list<string> $allowedErrorMessages @return array<string,mixed> */
    private function requestJson(string $method, string $path, ?array $body, ?string $bearerToken, bool $allowEmptySuccess = false, array $allowedErrorMessages = [], string $bodyEncoding = 'form'): array
    {
        return $this->requestJsonAtBase($this->baseUrl, $method, $path, $body, $bearerToken, $allowEmptySuccess, $allowedErrorMessages, $bodyEncoding);
    }

    /** @param array<string,mixed>|null $body @param list<string> $allowedErrorMessages @return array<string,mixed> */
    private function requestJsonAtBase(string $baseUrl, string $method, string $path, ?array $body, ?string $bearerToken, bool $allowEmptySuccess = false, array $allowedErrorMessages = [], string $bodyEncoding = 'form'): array
    {
        $this->breaker->assertFechado($this->circuitName);
        try {
            $resultado = $this->requestJsonAtBaseSemCircuito($baseUrl, $method, $path, $body, $bearerToken, $allowEmptySuccess, $allowedErrorMessages, $bodyEncoding);
            $this->breaker->registrarSucesso($this->circuitName);
            return $resultado;
        } catch (AcmeApiException $e) {
            if ($e->retryable()) {
                $this->breaker->registrarFalha($this->circuitName);
            }
            throw $e;
        }
    }

    /** @param array<string,mixed>|null $body @param list<string> $allowedErrorMessages @return array<string,mixed> */
    private function requestJsonAtBaseSemCircuito(string $baseUrl, string $method, string $path, ?array $body, ?string $bearerToken, bool $allowEmptySuccess = false, array $allowedErrorMessages = [], string $bodyEncoding = 'form'): array
    {
        $startedAt = microtime(true);
        $requestId = bin2hex(random_bytes(6));
        $url = rtrim($baseUrl, '/') . $path;
        $headers = ['Accept: application/json'];
        $content = null;

        if ($bearerToken !== null && $bearerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
        }

        if ($body !== null) {
            if ($bodyEncoding === 'json') {
                $content = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $headers[] = 'Content-Type: application/json; charset=utf-8';
            } else {
                $content = http_build_query($body, '', '&');
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }

        if ($this->transport !== null) {
            [$raw, $status, $error] = ($this->transport)($url, $method, $headers, $content);
        } elseif (function_exists('curl_init')) {
            [$raw, $status, $error] = $this->requestJsonCurl($url, $method, $headers, $content);
        } else {
            [$raw, $status, $error] = $this->requestJsonStream($url, $method, $headers, $content);
        }
        $this->lastHttpStatus = $status;

        if ($raw === false || $raw === null) {
            $message = $error ?: 'sem resposta';
            $this->registrarFalha($method, $path, $status, $message);
            $this->registrarChamada($requestId, $method, $path, $status, 'erro', $message, $startedAt);
            $detalhe = $error !== '' ? ' Detalhe: ' . $error : '';
            throw new AcmeApiException('Falha de comunicação com a API ACME.' . $detalhe, $status, true);
        }

        if ($status === 0) {
            $message = $error !== '' ? $error : 'A API ACME não retornou um status HTTP válido.';
            $this->registrarFalha($method, $path, $status, $message);
            $this->registrarChamada($requestId, $method, $path, $status, 'erro', $message, $startedAt);
            throw new AcmeApiException('Falha de comunicação com a API ACME. ' . $message, 0, true);
        }

        $rawTrimmed = trim((string) $raw);
        if ($rawTrimmed === '' && $allowEmptySuccess && $status >= 200 && $status < 300) {
            $this->registrarChamada($requestId, $method, $path, $status, 'ok', 'Resposta vazia com sucesso HTTP.', $startedAt);
            return ['ok' => true, 'http_status' => $status, 'message' => 'Resposta vazia com sucesso HTTP.'];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            if (in_array($status, [401, 403], true)) {
                $this->registrarChamada($requestId, $method, $path, $status, 'autorizacao', 'Token recusado pela API.', $startedAt);
                return ['Message' => 'Authorization has been denied for this request.', 'http_status' => $status];
            }
            if ($allowEmptySuccess && $status >= 200 && $status < 300) {
                $this->registrarChamada($requestId, $method, $path, $status, 'ok', 'Resposta HTTP de sucesso não JSON.', $startedAt);
                return ['ok' => true, 'http_status' => $status, 'raw' => mb_substr((string) $raw, 0, 500)];
            }

            $rawExcerpt = mb_substr($rawTrimmed, 0, 300);
            $logMessage = ($status >= 300 ? 'Resposta HTTP não JSON: ' : 'JSON inválido: ') . $rawExcerpt;
            $this->registrarFalha($method, $path, $status, $logMessage);
            $this->registrarChamada($requestId, $method, $path, $status, 'erro', $logMessage, $startedAt);

            if ($status >= 300) {
                $retryable = $status === 408 || $status === 425 || $status === 429 || $status >= 500;
                throw new AcmeApiException('Erro HTTP ' . $status . ' na API ACME.', $status, $retryable);
            }

            throw new AcmeApiException('Resposta inválida da API ACME. HTTP ' . ($status ?: 'sem status') . '.', $status, $status === 0 || $status >= 500);
        }

        if (in_array($status, [401, 403], true)) {
            $authPayload = array_is_list($decoded)
                ? [
                    'Message' => 'Authorization has been denied for this request.',
                    'http_status' => $status,
                    'response' => $decoded,
                ]
                : $decoded;
            $authPayload['http_status'] = $status;
            $this->registrarChamada(
                $requestId,
                $method,
                $path,
                $status,
                'autorizacao',
                'Token recusado pela API.',
                $startedAt
            );
            return $authPayload;
        }

        if (!array_is_list($decoded)) {
            $decoded['http_status'] = $status;
        }
        if ($this->authorizationDenied($decoded)) {
            $this->registrarChamada(
                $requestId,
                $method,
                $path,
                $status,
                'autorizacao',
                'Token recusado pela API.',
                $startedAt
            );
            return $decoded;
        }
        if ($status >= 300) {
            $message = (string) ($decoded['Message'] ?? $decoded['message'] ?? 'Erro HTTP ' . $status . ' na API ACME.');
            if ($this->mensagemPermitida($message, $allowedErrorMessages)) {
                $decoded['http_status'] = $status;
                $this->registrarChamada($requestId, $method, $path, $status, 'ok', $message, $startedAt);
                return $decoded;
            }
            $this->registrarFalha($method, $path, $status, $message);
            $this->registrarChamada($requestId, $method, $path, $status, 'erro', $message, $startedAt);
            $retryable = $status === 0 || $status === 408 || $status === 425 || $status === 429 || $status >= 500;
            throw new AcmeApiException($message, $status, $retryable, $decoded);
        }

        if (!array_is_list($decoded) && !array_key_exists('http_status', $decoded)) {
            $decoded['http_status'] = $status;
        }
        $this->registrarChamada($requestId, $method, $path, $status, 'ok', '', $startedAt);
        return $decoded;
    }

    /** @param list<string> $headers @return array{0:string|false|null,1:int,2:string} */
    private function requestJsonCurl(string $url, string $method, array $headers, ?string $content): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [false, 0, 'curl_init falhou'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $this->timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_FAILONERROR => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'CRM-Cobranca/1.0',
        ]);

        if ($content !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch) ? curl_error($ch) : '';
        curl_close($ch);

        return [$raw, $status, $error];
    }

    /** @param list<string> $headers @return array{0:string|false|null,1:int,2:string} */
    private function requestJsonStream(string $url, string $method, array $headers, ?string $content): array
    {
        $http = [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => $this->timeout,
            'ignore_errors' => true,
        ];
        if ($content !== null) {
            $http['content'] = $content;
        }

        $context = stream_context_create(['http' => $http]);
        $raw = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string) $header, $m)) {
                    $status = (int) $m[1];
                    break;
                }
            }
        }
        $lastError = error_get_last();
        $error = is_array($lastError) ? (string) ($lastError['message'] ?? '') : '';

        return [$raw, $status, $error];
    }

    /** @param array<string,mixed> $payload */
    private function acordoNaoEncontrado(array $payload): bool
    {
        $message = trim((string) ($payload['Message'] ?? $payload['message'] ?? ''));
        return $this->mensagemPermitida($message, ['Dados do acordo não encontrados.']);
    }

    /** @param array<string,mixed> $rawResponse @return array<string,mixed> */
    private function respostaAcordoNaoEncontrado(string $documento, string $contrato, array $rawResponse): array
    {
        return [
            'HasAgreementHistory' => false,
            'Debt' => [
                'Contract' => $contrato,
            ],
            'Agreement' => [],
            'Message' => (string) ($rawResponse['Message'] ?? $rawResponse['message'] ?? 'Dados do acordo não encontrados.'),
            'agreement_not_found' => true,
            'DocNumber' => $documento,
            'raw_message' => $rawResponse,
        ];
    }

    /** @param list<string> $allowedMessages */
    private function mensagemPermitida(string $message, array $allowedMessages): bool
    {
        $message = $this->normalizarMensagem($message);
        if ($message === '') {
            return false;
        }

        foreach ($allowedMessages as $allowed) {
            if ($message === $this->normalizarMensagem($allowed)) {
                return true;
            }
        }

        return false;
    }

    private function normalizarMensagem(string $message): string
    {
        $message = trim($message);
        $message = preg_replace('/\s+/u', ' ', $message) ?? $message;
        $message = rtrim($message, ". \t\n\r\0\x0B");
        return mb_strtolower($message);
    }

    private function registrarFalha(string $method, string $path, int $status, string $message): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = json_encode([
            'time' => date('c'),
            'method' => $method,
            'path' => $this->sanitizarPathLog($path),
            'status' => $status,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line !== false) {
            @file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    private function registrarChamada(
        string $requestId,
        string $method,
        string $path,
        int $status,
        string $resultado,
        string $mensagem,
        float $startedAt
    ): void {
        $dir = dirname($this->structuredLogFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = json_encode([
            'timestamp' => date('c'),
            'request_id' => $requestId,
            'integracao' => 'ACME',
            'method' => strtoupper($method),
            'path' => $this->sanitizarPathLog($path),
            'status' => $status,
            'resultado' => $resultado,
            'duracao_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
            'mensagem' => mb_substr(trim($mensagem), 0, 500),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line !== false) {
            $this->rotacionarLogEstruturadoSeNecessario();
            @file_put_contents($this->structuredLogFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    private function sanitizarPathLog(string $path): string
    {
        return preg_replace(
            '/(?<!\d)(?:\d{11}|\d{14})(?!\d)/',
            '[documento-mascarado]',
            $path
        ) ?? $path;
    }

    private function rotacionarLogEstruturadoSeNecessario(): void
    {
        clearstatcache(true, $this->structuredLogFile);
        $size = is_file($this->structuredLogFile) ? @filesize($this->structuredLogFile) : false;
        if ($size === false || $size < $this->structuredLogMaxBytes) {
            return;
        }

        $rotated = $this->structuredLogFile . '.1';
        if (is_file($rotated)) {
            @unlink($rotated);
        }
        @rename($this->structuredLogFile, $rotated);
    }

    /** @return list<array<string,mixed>> */
    private function listarContatoDevedor(string $template, string $documento, string $rotulo): array
    {
        $path = str_replace('{docNumber}', rawurlencode($this->documentoContato($documento)), $template);
        try {
            $response = $this->chamarComToken('GET', $path, null, 'form', 'consultar ' . $rotulo);
        } catch (AcmeApiException $e) {
            // Devedor sem nenhum registro desse tipo: a lista vazia deixa a
            // inclusao seguir (se o devedor nao existir, a inclusao e que falha).
            if ($e->httpStatus() === 404) {
                return [];
            }
            throw $e;
        }

        return $this->itensDaResposta($response);
    }

    /** @param array<string,mixed> $response @return list<array<string,mixed>> */
    private function itensDaResposta(array $response): array
    {
        if (array_is_list($response)) {
            return array_values(array_filter($response, static fn (mixed $item): bool => is_array($item)));
        }
        foreach (['Data', 'data', 'Items', 'items', 'Result', 'result'] as $key) {
            $items = $response[$key] ?? null;
            if (is_array($items) && array_is_list($items)) {
                return array_values(array_filter($items, static fn (mixed $item): bool => is_array($item)));
            }
        }

        return [];
    }

    /**
     * Mesmo fluxo das demais chamadas: token em cache, uma renovação se a ACME
     * recusar, circuit breaker no requestJson.
     *
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function chamarComToken(string $method, string $path, ?array $body, string $bodyEncoding, string $rotulo): array
    {
        if (!$this->configurado()) {
            throw new RuntimeException('API ACME não configurada. Configure ACME_API_USERNAME e ACME_API_PASSWORD no .env.local.');
        }

        $response = $this->requestJson($method, $path, $body, $this->token(false), true, [], $bodyEncoding);
        if ($this->authorizationDenied($response)) {
            $response = $this->requestJson($method, $path, $body, $this->token(true), true, [], $bodyEncoding);
        }
        if ($this->authorizationDenied($response)) {
            throw new AcmeApiException(
                'Autorização negada pela API ACME ao ' . $rotulo . ' após uma renovação de token.',
                401,
                false,
                $response
            );
        }

        return $response;
    }

    private function documentoContato(string $documento): string
    {
        $documento = preg_replace('/\D+/', '', $documento) ?? '';
        if (!in_array(strlen($documento), [11, 14], true)) {
            throw new RuntimeException('CPF/CNPJ inválido para contato ACME.');
        }
        return $documento;
    }

    /** @param array<string,mixed> $dados @return array<string,mixed> */
    private function corpoContato(array $dados): array
    {
        $dados['DocNumber'] = $this->documentoContato((string) ($dados['DocNumber'] ?? ''));
        return $dados;
    }

    private function idPositivo(int $valor, string $campo): int
    {
        if ($valor <= 0) {
            throw new RuntimeException($campo . ' inválido para a API ACME.');
        }
        return $valor;
    }

    private function codigoStatus(string $codigo): string
    {
        $codigo = strtoupper(trim($codigo));
        if (preg_match('/^[A-Z0-9]{1,5}$/', $codigo) !== 1) {
            throw new RuntimeException('Código de tipo/status inválido para a API ACME: ' . $codigo);
        }
        return $codigo;
    }

    /** @param array<string,mixed> $payload */
    private function authorizationDenied(array $payload): bool
    {
        $status = (int) ($payload['http_status'] ?? 0);
        if (in_array($status, [401, 403], true)) {
            return true;
        }
        $message = trim((string) ($payload['Message'] ?? $payload['message'] ?? ''));
        return strcasecmp($message, 'Authorization has been denied for this request.') === 0;
    }
}
