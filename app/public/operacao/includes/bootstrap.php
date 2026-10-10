<?php

declare(strict_types=1);

use CRM\Config\Database;

require_once __DIR__ . '/../../../src/Config/Database.php';

$vendorAutoload = __DIR__ . '/../../../vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'CRM\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = __DIR__ . '/../../../src/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}



use CRM\Modules\Auth\Repositories\AuthRepository;
use CRM\Modules\Operacao\Repositories\AuditoriaSistemaRepository;
use CRM\Modules\Operacao\Repositories\SegurancaOperacionalRepository;
use CRM\Infrastructure\Redis\RedisConnections;

if (session_status() !== PHP_SESSION_ACTIVE) {
    try {
        RedisConnections::configureSession();
    } catch (Throwable) {
        // Fallback seguro: mantem o handler local se o Redis estiver indisponivel.
    }
    // 2026-10-06: acesso por https://crm.exemplo.com.br (HTTPS no
    // proxy da empresa) -> cookie de sessao so trafega criptografado.
    // Acesso direto por http://IP:8080 continua funcionando (sem Secure).
    if (PHP_SAPI !== 'cli' && \CRM\Infrastructure\Http\ProxyConfiavel::viaHttps($_SERVER)) {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

function operacaoAssetUrl(string $asset): string
{
    $asset = ltrim(str_replace('\\', '/', $asset), '/');
    if ($asset === '' || str_contains($asset, '..')) {
        throw new InvalidArgumentException('Asset operacional inválido.');
    }

    $arquivo = dirname(__DIR__) . '/assets/' . $asset;
    $versao = is_file($arquivo) ? (int) filemtime($arquivo) : 1;
    return '/operacao/assets/' . $asset . '?v=' . $versao;
}

function operacaoCssUrl(): string
{
    static $url = null;
    if ($url === null) {
        $url = operacaoAssetUrl('operacao.css');
    }

    return $url;
}

function authRepository(): AuthRepository
{
    static $repo = null;
    if ($repo === null) {
        $repo = new AuthRepository(operacaoPdo());
    }

    return $repo;
}


function auditoriaSistemaRepository(): AuditoriaSistemaRepository
{
    static $repo = null;
    if ($repo === null) {
        $repo = new AuditoriaSistemaRepository(operacaoPdo());
    }

    return $repo;
}

/** @param array<string,mixed>|null $payloadAntes @param array<string,mixed>|null $payloadDepois */
function registrarAuditoriaSistema(
    string $evento,
    ?string $entidadeTipo = null,
    int|string|null $entidadeId = null,
    ?string $descricao = null,
    ?array $payloadAntes = null,
    ?array $payloadDepois = null
): void {
    registrarAuditoriaSistemaUsuario(usuarioAtualId(), $evento, $entidadeTipo, $entidadeId, $descricao, $payloadAntes, $payloadDepois);
}

/** @param array<string,mixed>|null $payloadAntes @param array<string,mixed>|null $payloadDepois */
function registrarAuditoriaSistemaUsuario(
    ?int $usuarioId,
    string $evento,
    ?string $entidadeTipo = null,
    int|string|null $entidadeId = null,
    ?string $descricao = null,
    ?array $payloadAntes = null,
    ?array $payloadDepois = null
): void {
    try {
        auditoriaSistemaRepository()->registrar($usuarioId, $evento, $entidadeTipo, $entidadeId, $descricao, $payloadAntes, $payloadDepois);
    } catch (Throwable) {
        return;
    }
}

function authModuloDisponivel(): bool
{
    try {
        return authRepository()->moduloDisponivel();
    } catch (Throwable) {
        return false;
    }
}

/** @return array<string,mixed>|null */
function usuarioAtual(): ?array
{
    if (!authModuloDisponivel()) {
        return null;
    }

    $usuarioId = isset($_SESSION['operacao_usuario_id']) ? (int) $_SESSION['operacao_usuario_id'] : 0;
    if ($usuarioId <= 0) {
        return null;
    }

    static $cache = [];
    if (!array_key_exists($usuarioId, $cache)) {
        $cache[$usuarioId] = authRepository()->buscarUsuarioSessao($usuarioId);
    }

    return $cache[$usuarioId];
}

function usuarioAtualId(): ?int
{
    $usuario = usuarioAtual();
    return $usuario === null ? null : (int) $usuario['id'];
}

function usuarioAtualNome(): ?string
{
    $usuario = usuarioAtual();
    return $usuario === null ? null : (string) $usuario['nome'];
}

function usuarioPode(string $permissao): bool
{
    if (!authModuloDisponivel()) {
        return true;
    }

    $usuarioId = usuarioAtualId();
    if ($usuarioId === null) {
        return false;
    }

    return authRepository()->usuarioTemPermissao($usuarioId, $permissao);
}

/** @return array{todos:bool,credor_ids:list<int>,carteira_ids:list<int>} */
function usuarioEscopoCredores(): array
{
    $usuarioId = usuarioAtualId();
    if ($usuarioId === null || !authModuloDisponivel()) {
        return ['todos' => true, 'credor_ids' => [], 'carteira_ids' => []];
    }

    return authRepository()->escopoUsuario($usuarioId);
}

/** @return array{todos:bool,credor_ids:list<int>,carteira_ids:list<int>} */
function usuarioEscopoCredoresAcao(string $acao = 'consultar'): array
{
    $usuarioId = usuarioAtualId();
    if ($usuarioId === null || !authModuloDisponivel()) {
        return ['todos' => true, 'credor_ids' => [], 'carteira_ids' => []];
    }

    return authRepository()->escopoUsuarioPorAcao($usuarioId, $acao);
}

/**
 * Resolve o escopo em IDs de credores. Quando o perfil possui apenas carteiras,
 * os respectivos credores também são incluídos sem ampliar o acesso.
 *
 * @return list<int>|null Null significa acesso a todos os credores.
 */
function usuarioEscopoCredorIdsAcao(string $acao = 'consultar'): ?array
{
    static $cache = [];

    $usuarioId = usuarioAtualId() ?? 0;
    $cacheKey = $usuarioId . ':' . $acao;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $escopo = usuarioEscopoCredoresAcao($acao);
    if ($escopo['todos']) {
        $cache[$cacheKey] = null;
        return null;
    }

    $ids = [];
    foreach ($escopo['credor_ids'] as $credorId) {
        $credorId = (int) $credorId;
        if ($credorId > 0) {
            $ids[$credorId] = $credorId;
        }
    }

    $carteiraIds = [];
    foreach ($escopo['carteira_ids'] as $carteiraId) {
        $carteiraId = (int) $carteiraId;
        if ($carteiraId > 0) {
            $carteiraIds[$carteiraId] = $carteiraId;
        }
    }

    if ($carteiraIds !== []) {
        try {
            $values = array_values($carteiraIds);
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $stmt = operacaoPdo()->prepare('SELECT DISTINCT credor_id FROM carteiras WHERE id IN (' . $placeholders . ')');
            $stmt->execute($values);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $credorId) {
                $credorId = (int) $credorId;
                if ($credorId > 0) {
                    $ids[$credorId] = $credorId;
                }
            }
        } catch (Throwable) {
            // Falha fechada: conserva apenas os credores explicitamente permitidos.
        }
    }

    ksort($ids, SORT_NUMERIC);
    $cache[$cacheKey] = array_values($ids);
    return $cache[$cacheKey];
}

function usuarioPodeCredorId(int $credorId, string $acao = 'consultar'): bool
{
    if ($credorId <= 0) {
        return false;
    }

    $credorIds = usuarioEscopoCredorIdsAcao($acao);
    return $credorIds === null || in_array($credorId, $credorIds, true);
}

function usuarioPodeContratoId(int $contratoId, string $acao = 'consultar'): bool
{
    $usuarioId = usuarioAtualId();
    if ($usuarioId === null || !authModuloDisponivel()) {
        return true;
    }

    return authRepository()->usuarioPodeContrato($usuarioId, $contratoId, $acao);
}

function usuarioPodePessoaId(int $pessoaId, string $acao = 'consultar'): bool
{
    $usuarioId = usuarioAtualId();
    if ($usuarioId === null || !authModuloDisponivel()) {
        return true;
    }

    return authRepository()->usuarioPodePessoa($usuarioId, $pessoaId, $acao);
}

function usuarioPodeAcordoId(int $acordoId, string $acao = 'consultar'): bool
{
    $usuarioId = usuarioAtualId();
    if ($usuarioId === null || !authModuloDisponivel()) {
        return true;
    }

    return authRepository()->usuarioPodeAcordo($usuarioId, $acordoId, $acao);
}

function requirePessoaNoEscopo(int $pessoaId, string $acao = 'consultar'): void
{
    if (usuarioPodePessoaId($pessoaId, $acao)) {
        return;
    }

    http_response_code(403);
    throw new RuntimeException('Seu usuário não possui escopo para acessar este cliente/credor.');
}

function requireContratoNoEscopo(int $contratoId, string $acao = 'consultar'): void
{
    if ($contratoId <= 0 || usuarioPodeContratoId($contratoId, $acao)) {
        return;
    }

    http_response_code(403);
    throw new RuntimeException('Seu usuário não possui escopo para operar este contrato/credor.');
}

function requireAcordoNoEscopo(int $acordoId, string $acao = 'consultar'): void
{
    if (usuarioPodeAcordoId($acordoId, $acao)) {
        return;
    }

    http_response_code(403);
    throw new RuntimeException('Seu usuário não possui escopo para acessar este acordo/credor.');
}

function requireOperacaoLogin(): void
{
    if (!authModuloDisponivel()) {
        return;
    }

    $usuario = usuarioAtual();
    if ($usuario !== null && (string) $usuario['status'] === 'ativo') {
        operacaoExigirTrocaSenhaPendente($usuario);
        return;
    }

    $destino = $_SERVER['REQUEST_URI'] ?? '/operacao/index.php';
    header('Location: /operacao/login.php?redirect=' . rawurlencode($destino));
    exit;
}

/**
 * Senha provisória (usuário criado ou com senha redefinida pelo admin com
 * "trocar no próximo acesso", requisito de 2026-10-06): nenhuma tela
 * abre antes de o usuário definir a senha dele.
 *
 * @param array<string,mixed> $usuario
 */
function operacaoExigirTrocaSenhaPendente(array $usuario): void
{
    if ((int) ($usuario['forcar_troca_senha'] ?? 0) !== 1
        || basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'trocar_senha.php') {
        return;
    }

    if (operacaoRequisicaoEsperaJson()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'erro' => 'Defina a sua nova senha antes de continuar.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: /operacao/trocar_senha.php?redirect=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/operacao/works.php')));
    exit;
}


function worksAtendimentoUrl(?int $contratoId = null, ?int $pessoaId = null, string $tab = 'acionamento', array $extras = []): string
{
    $params = [];
    if ($contratoId !== null && $contratoId > 0) {
        $params['contrato_id'] = $contratoId;
        $params['tab'] = $tab !== '' ? $tab : 'acionamento';
    } elseif ($pessoaId !== null && $pessoaId > 0) {
        $params['pessoa_id'] = $pessoaId;
    }

    foreach ($extras as $key => $value) {
        if ($value !== null && $value !== '') {
            $params[(string) $key] = $value;
        }
    }

    return '/operacao/works.php' . queryString($params);
}

function requireOperacaoAlgumaPermissao(array $permissoes): void
{
    requireOperacaoLogin();
    foreach ($permissoes as $permissao) {
        if (usuarioPode((string) $permissao)) {
            return;
        }
    }

    http_response_code(403);
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Acesso negado</title><link rel="stylesheet" href="' . h(operacaoCssUrl()) . '"></head><body><main class="container"><section class="card error"><strong>Acesso negado.</strong><p>Seu usuário não possui permissão para acessar este módulo do Pactua.</p><p><a href="/operacao/works.php">Ir para o atendimento Pactua</a></p></section></main></body></html>';
    exit;
}

function requireOperacaoPermissao(string $permissao): void
{
    requireOperacaoLogin();
    if (usuarioPode($permissao)) {
        return;
    }

    http_response_code(403);
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Acesso negado</title><link rel="stylesheet" href="' . h(operacaoCssUrl()) . '"></head><body><main class="container"><section class="card error"><strong>Acesso negado.</strong><p>Seu usuário não possui a permissão necessária: ' . h($permissao) . '.</p><p><a href="/operacao/index.php">Voltar ao início</a></p></section></main></body></html>';
    exit;
}

function usuarioEhAdminOperacao(): bool
{
    if (!authModuloDisponivel()) {
        return true;
    }

    $usuario = usuarioAtual();
    if ($usuario === null) {
        return false;
    }

    return (int) ($usuario['admin_total'] ?? 0) === 1 || (string) ($usuario['perfil_codigo'] ?? '') === 'admin' || usuarioPode('usuarios.gerenciar');
}

function requireOperacaoAdminTecnico(): void
{
    requireOperacaoLogin();
    if (usuarioEhAdminOperacao()) {
        return;
    }

    http_response_code(403);
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Acesso restrito</title><link rel="stylesheet" href="' . h(operacaoCssUrl()) . '"></head><body><main class="container"><section class="card error"><strong>Acesso restrito.</strong><p>A consulta técnica antiga agora é exclusiva do administrador. Operadores devem usar somente o Pactua Atendimento.</p><p><a href="/operacao/works.php">Ir para Pactua Atendimento</a></p></section></main></body></html>';
    exit;
}

function authCsrfToken(): string
{
    if (!isset($_SESSION['operacao_csrf']) || !is_string($_SESSION['operacao_csrf'])) {
        $_SESSION['operacao_csrf'] = bin2hex(random_bytes(24));
    }

    return $_SESSION['operacao_csrf'];
}

function authCsrfInput(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(authCsrfToken()) . '">';
}

function validarCsrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['operacao_csrf'] ?? '');
    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        throw new RuntimeException('Sessão expirada ou token inválido. Atualize a página e tente novamente.');
    }
}

function operacaoPdo(): \PDO
{
    return Database::pdo();
}

function operacaoSessaoTimezone(): DateTimeZone
{
    static $timezone = null;
    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    try {
        $timezone = new DateTimeZone((string) Database::env('APP_TIMEZONE', 'America/Sao_Paulo'));
    } catch (Throwable) {
        $timezone = new DateTimeZone('America/Sao_Paulo');
    }

    return $timezone;
}

function operacaoSessaoHorasConfiguradas(): int
{
    try {
        $repo = new SegurancaOperacionalRepository(operacaoPdo());
        $config = $repo->configuracoes();
        return max(1, min(24, (int) ($config['sessao_expira_horas'] ?? 10)));
    } catch (Throwable) {
        return 10;
    }
}

function iniciarSessaoOperacionalAutenticada(int $usuarioId, string $usuarioNome): void
{
    $horas = operacaoSessaoHorasConfiguradas();
    $agora = time();
    $dataLogin = (new DateTimeImmutable('@' . $agora))
        ->setTimezone(operacaoSessaoTimezone())
        ->format('Y-m-d');

    session_regenerate_id(true);
    $_SESSION['operacao_usuario_id'] = $usuarioId;
    $_SESSION['operacao_usuario_nome'] = $usuarioNome;
    $_SESSION['operacao_sessao_iniciada_em'] = $agora;
    $_SESSION['operacao_sessao_expira_em'] = $agora + ($horas * 3600);
    $_SESSION['operacao_sessao_horas'] = $horas;
    $_SESSION['operacao_sessao_data_login'] = $dataLogin;
}

function operacaoSessaoExpiracaoMotivo(): ?string
{
    $usuarioId = isset($_SESSION['operacao_usuario_id']) ? (int) $_SESSION['operacao_usuario_id'] : 0;
    if ($usuarioId <= 0) {
        return null;
    }

    $iniciadaEm = isset($_SESSION['operacao_sessao_iniciada_em']) ? (int) $_SESSION['operacao_sessao_iniciada_em'] : 0;
    $expiraEm = isset($_SESSION['operacao_sessao_expira_em']) ? (int) $_SESSION['operacao_sessao_expira_em'] : 0;
    $dataLogin = trim((string) ($_SESSION['operacao_sessao_data_login'] ?? ''));

    // Sessoes antigas sem metadados de prazo exigem um novo login uma unica vez
    // para iniciar a politica de expiracao corretamente.
    if ($iniciadaEm <= 0 || $expiraEm <= 0 || $dataLogin === '') {
        return 'atualizacao';
    }

    $agora = time();
    $dataAtual = (new DateTimeImmutable('@' . $agora))
        ->setTimezone(operacaoSessaoTimezone())
        ->format('Y-m-d');

    if ($dataAtual !== $dataLogin) {
        return 'novo_dia';
    }

    if ($agora >= $expiraEm) {
        return 'tempo';
    }

    return null;
}

function limparAutenticacaoSessaoOperacional(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function operacaoRequisicaoEsperaJson(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
}

function aplicarPoliticaSessaoOperacional(): ?string
{
    $motivo = operacaoSessaoExpiracaoMotivo();
    if ($motivo === null) {
        return null;
    }

    $usuarioId = isset($_SESSION['operacao_usuario_id']) ? (int) $_SESSION['operacao_usuario_id'] : null;
    $horas = isset($_SESSION['operacao_sessao_horas']) ? (int) $_SESSION['operacao_sessao_horas'] : null;

    if ($usuarioId !== null && $usuarioId > 0) {
        registrarAuditoriaSistemaUsuario(
            $usuarioId,
            'auth.sessao_expirada',
            'usuario',
            $usuarioId,
            'Sessao operacional encerrada pela politica de seguranca.',
            null,
            ['motivo' => $motivo, 'limite_horas' => $horas]
        );
    }

    limparAutenticacaoSessaoOperacional();
    return $motivo;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function valorOuTraco(mixed $value): string
{
    $value = is_string($value) ? trim($value) : $value;
    if ($value === null || $value === '') {
        return '<span class="muted">-</span>';
    }

    return h($value);
}

function dinheiro(mixed $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    return 'R$ ' . number_format((float) $value, 2, ',', '.');
}

function dataBr(mixed $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable((string) $value))->format('d/m/Y');
    } catch (Throwable) {
        return h($value);
    }
}

function dataHoraBr(mixed $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable((string) $value))->format('d/m/Y H:i');
    } catch (Throwable) {
        return h($value);
    }
}

function documentoFormatado(?string $documento): string
{
    $digitos = preg_replace('/\D+/', '', (string) $documento) ?? '';

    if (strlen($digitos) === 11) {
        return substr($digitos, 0, 3) . '.' . substr($digitos, 3, 3) . '.' . substr($digitos, 6, 3) . '-' . substr($digitos, 9, 2);
    }

    if (strlen($digitos) === 14) {
        return substr($digitos, 0, 2) . '.' . substr($digitos, 2, 3) . '.' . substr($digitos, 5, 3) . '/' . substr($digitos, 8, 4) . '-' . substr($digitos, 12, 2);
    }

    return h($documento);
}

function badge(?string $value, string $fallback = 'sem status'): string
{
    $label = trim((string) ($value ?? ''));
    if ($label === '') {
        $label = $fallback;
    }

    return '<span class="badge">' . h($label) . '</span>';
}

function badgeSoft(?string $value, string $fallback = 'em desenvolvimento'): string
{
    $label = trim((string) ($value ?? ''));
    if ($label === '') {
        $label = $fallback;
    }

    return '<span class="badge badge-soft">' . h($label) . '</span>';
}

function menuAtivo(string $paginaAtual, string $pagina): string
{
    return $paginaAtual === $pagina ? ' active' : '';
}

function queryString(array $params): string
{
    $limpos = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $limpos[$key] = $value;
    }

    $query = http_build_query($limpos);
    return $query === '' ? '' : '?' . $query;
}


$operacaoSessaoExpiradaMotivo = aplicarPoliticaSessaoOperacional();
if ($operacaoSessaoExpiradaMotivo !== null) {
    $GLOBALS['operacao_sessao_expirada_motivo'] = $operacaoSessaoExpiradaMotivo;

    if (!defined('OPERACAO_AUTH_SKIP')) {
        $esperaJson = operacaoRequisicaoEsperaJson();
        $destino = (string) ($_SERVER['REQUEST_URI'] ?? '/operacao/works.php');

        // Em chamadas AJAX, voltar ao endpoint JSON depois do login seria ruim.
        // Preferimos a pagina operacional que originou a requisicao.
        if ($esperaJson) {
            $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
            $refererPath = $referer !== '' ? (string) (parse_url($referer, PHP_URL_PATH) ?? '') : '';
            $refererQuery = $referer !== '' ? (string) (parse_url($referer, PHP_URL_QUERY) ?? '') : '';
            if (str_starts_with($refererPath, '/operacao/')) {
                $destino = $refererPath . ($refererQuery !== '' ? '?' . $refererQuery : '');
            } else {
                $destino = '/operacao/works.php';
            }
        }

        $loginUrl = '/operacao/login.php?' . http_build_query([
            'redirect' => $destino,
            'sessao' => $operacaoSessaoExpiradaMotivo,
        ]);

        if ($esperaJson) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'erro' => 'Sessao expirada. Faca login novamente para continuar.',
                'codigo' => 'SESSION_EXPIRED',
                'login_url' => $loginUrl,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        header('Location: ' . $loginUrl);
        exit;
    }
}

if (!defined('OPERACAO_AUTH_SKIP')) {
    requireOperacaoLogin();
}
