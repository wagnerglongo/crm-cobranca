<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Http\ApiException;
use CRM\Infrastructure\Http\ApiRequest;
use CRM\Infrastructure\Http\ApiResponse;
use CRM\Infrastructure\Http\ApiRouter;
use CRM\Infrastructure\Http\CorsMiddleware;
use CRM\Infrastructure\Http\RedisRateLimiter;
use CRM\Modules\Api\ApiApplication;
use CRM\Modules\Api\ApiRoutes;
use CRM\Modules\Api\Auth\ApiTokenRepository;
use CRM\Modules\Api\Auth\ApiTokenService;
use CRM\Modules\Api\Idempotency\ApiIdempotencyService;
use CRM\Modules\Api\Controllers\AuthController;
use CRM\Modules\Api\Controllers\ClientesController;
use CRM\Modules\Api\Controllers\ContratosController;
use CRM\Modules\Api\Controllers\HealthController;
use CRM\Modules\Api\Controllers\OperacoesController;
use CRM\Modules\Auth\Repositories\AuthRepository;
use CRM\Modules\Operacao\Repositories\AuditoriaSistemaRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Repositories\ConsultaClienteRepository;
use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoResultadoRepository;
use CRM\Modules\Operacao\Services\AcmeApiClient;

require_once dirname(__DIR__) . '/_bootstrap.php';

$requestId = bin2hex(random_bytes(16));

try {
    $request = ApiRequest::fromGlobals();
    $pdo = Database::pdo();
    $authRepository = new AuthRepository($pdo);
    $tokenRepository = new ApiTokenRepository($pdo);
    $tokenService = new ApiTokenService($tokenRepository, $authRepository);
    $rateLimiter = new RedisRateLimiter();
    $auditoria = new AuditoriaSistemaRepository($pdo);

    $authController = new AuthController($authRepository, $tokenService, $rateLimiter, $auditoria);
    $healthController = new HealthController($pdo);
    $consultaClientes = new ConsultaClienteRepository($pdo);
    $consultaContratos = new AtendimentoAdaptorRepository($pdo);
    $clientesController = new ClientesController($consultaClientes, $consultaContratos);
    $contratosController = new ContratosController($consultaContratos);
    $operacoesController = new OperacoesController(
        $pdo,
        $consultaContratos,
        $consultaClientes,
        new AtendimentoRepository($pdo),
        new AtendimentoResultadoRepository($pdo),
        new AcordoOperacionalRepository($pdo),
        $auditoria,
        new AcmeApiClient()
    );
    $router = new ApiRouter();
    ApiRoutes::register($router, $authController, $healthController, $clientesController, $contratosController, $operacoesController);

    $application = new ApiApplication(
        $router,
        $tokenService,
        $rateLimiter,
        new CorsMiddleware(),
        new ApiIdempotencyService($pdo)
    );
    $application->run($request);
} catch (ApiException $e) {
    ApiResponse::error($e->status, $e->errorCode, $e->getMessage(), $requestId, $e->details, $e->headers)->send($requestId);
} catch (Throwable $e) {
    $log = preg_replace('/wrk_api_(?:at|rt)_[A-Fa-f0-9]+/', '[TOKEN_REDACTED]', $e::class . ': ' . $e->getMessage()) ?? $e::class;
    error_log('[Pactua API] request_id=' . $requestId . ' bootstrap_exception=' . mb_substr($log, 0, 1000));
    ApiResponse::error(503, 'SERVICE_UNAVAILABLE', 'A API está temporariamente indisponível.', $requestId)->send($requestId);
}
