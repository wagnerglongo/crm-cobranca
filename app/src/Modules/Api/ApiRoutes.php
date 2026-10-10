<?php

declare(strict_types=1);

namespace CRM\Modules\Api;

use CRM\Infrastructure\Http\ApiRouter;
use CRM\Modules\Api\Controllers\AuthController;
use CRM\Modules\Api\Controllers\ClientesController;
use CRM\Modules\Api\Controllers\ContratosController;
use CRM\Modules\Api\Controllers\HealthController;
use CRM\Modules\Api\Controllers\OperacoesController;

final class ApiRoutes
{
    public static function register(
        ApiRouter $router,
        AuthController $auth,
        HealthController $health,
        ClientesController $clientes,
        ContratosController $contratos,
        OperacoesController $operacoes
    ): void
    {
        $router->add('GET', '/api/v1/health', [$health, 'health'], ['auth' => false]);
        $router->add('GET', '/api/v1/health/components', [$health, 'components'], [
            'auth' => true,
            'scope' => 'api',
            'permission' => 'integracoes.visualizar',
        ]);
        $router->add('POST', '/api/v1/auth/token', [$auth, 'token'], ['auth' => false]);
        $router->add('GET', '/api/v1/auth/me', [$auth, 'me'], ['auth' => true, 'scope' => 'api']);

        $consulta = ['auth' => true, 'scope' => 'api', 'permission' => 'clientes.consultar'];
        $router->add('GET', '/api/v1/clientes/documento/{documento}', [$clientes, 'porDocumento'], $consulta);
        $router->add('GET', '/api/v1/contratos/codigo/{contrato}', [$contratos, 'porCodigo'], $consulta);

        $router->add('GET', '/api/v1/tabulacoes', [$operacoes, 'tabulacoes'], [
            'auth' => true, 'scope' => 'api', 'permission' => 'atendimentos.visualizar',
        ]);
        $router->add('POST', '/api/v1/acionamentos/contrato/{contrato}', [$operacoes, 'criarAcionamentoPorContrato'], [
            'auth' => true, 'scope' => 'api', 'permission' => 'atendimentos.registrar', 'idempotent' => true,
        ]);
        $router->add('POST', '/api/v1/acionamentos/documento/{documento}', [$operacoes, 'criarAcionamentoPorDocumento'], [
            'auth' => true, 'scope' => 'api', 'permission' => 'atendimentos.registrar', 'idempotent' => true,
        ]);
        $router->add('POST', '/api/v1/acionamentos/lote', [$operacoes, 'criarAcionamentosLote'], [
            'auth' => true, 'scope' => 'api', 'permission' => 'atendimentos.registrar', 'idempotent' => true,
        ]);
        $router->add('GET', '/api/v1/acordos', [$operacoes, 'listarAcordos'], [
            'auth' => true, 'scope' => 'api', 'permission' => 'acordos.visualizar',
        ]);
        $router->add('GET', '/api/v1/acordos/contrato/{contrato}', [$operacoes, 'acordosPorContrato'], [
            'auth' => true, 'scope' => 'api', 'permission' => 'acordos.visualizar',
        ]);
        $router->add('GET', '/api/v1/acordos/documento/{documento}', [$operacoes, 'acordosPorDocumento'], [
            'auth' => true, 'scope' => 'api', 'permission' => 'acordos.visualizar',
        ]);
        $router->add('GET', '/api/v1/acme/documento/{documento}/contrato/{contrato}/simulacoes', [$operacoes, 'simulacoesAcme'], [
            'auth' => true, 'scope' => 'api', 'permission' => 'acordos.criar',
        ]);
        $router->add('POST', '/api/v1/acme/documento/{documento}/contrato/{contrato}/acordos', [$operacoes, 'fecharAcordoAcme'], [
            'auth' => true, 'scope' => 'api', 'permission' => 'acordos.criar', 'idempotent' => true,
        ]);
    }
}
