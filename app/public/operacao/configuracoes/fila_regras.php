<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\AgendaOperadorRepository;

require_once __DIR__ . '/../includes/bootstrap.php';
requireOperacaoPermissao('fila.configurar');

$pageTitle = 'Regras de Agenda/Fila';
$pageSubtitle = 'Configuração do comportamento da agenda individual, fila geral, reservas e transferências.';
$activePage = 'fila_regras';

$mensagemSucesso = null;
$mensagemErro = null;
$moduloDisponivel = false;
$regras = AgendaOperadorRepository::REGRAS_PADRAO;

try {
    $repo = new AgendaOperadorRepository(operacaoPdo());
    $moduloDisponivel = $repo->moduloDisponivel();

    if ($moduloDisponivel && $_SERVER['REQUEST_METHOD'] === 'POST') {
        validarCsrf();
        try {
            $antes = $repo->regras();
            $repo->salvarRegras($_POST, usuarioAtualId());
            $depois = $repo->regras();
            registrarAuditoriaSistema(
                'fila.regras_atualizadas',
                'operacao_fila_regras',
                null,
                'Regras de agenda/fila atualizadas.',
                $antes,
                $depois
            );
            $mensagemSucesso = 'Regras salvas com sucesso.';
        } catch (Throwable $e) {
            $mensagemErro = $e->getMessage();
        }
    }

    if ($moduloDisponivel) {
        $regras = $repo->regras();
    }
} catch (Throwable $e) {
    $mensagemErro = $e->getMessage();
}

require __DIR__ . '/../includes/header.php';
?>
<main class="container">
    <section class="card compact">
        <div class="actions-row">
            <a class="button secondary" href="/operacao/configuracoes/perfis.php">Perfis e permissões</a>
        </div>
    </section>

    <?php if ($mensagemSucesso !== null): ?><div class="alert success"><?= h($mensagemSucesso) ?></div><?php endif; ?>
    <?php if ($mensagemErro !== null): ?><div class="alert danger"><?= h($mensagemErro) ?></div><?php endif; ?>

    <?php if (!$moduloDisponivel): ?>
        <section class="card error">
            <strong>Módulo de agenda/fila ainda não está ativo.</strong>
            Execute a migration <code>031_agenda_fila_operador.sql</code> para liberar esta configuração.
        </section>
    <?php else: ?>
        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Regras operacionais</h2>
                    <p>Essas regras controlam quando um cliente fica exclusivo de um operador e quando volta para a fila geral.</p>
                </div>
                <?= badgeSoft('Configurável') ?>
            </div>

            <form method="post" class="form-card fila-rules-form">
                <?= authCsrfInput() ?>

                <div class="rule-card-grid">
                    <label class="rule-card">
                        <span class="rule-title">Retorno vencido vai para fila geral</span>
                        <span class="rule-text">Quando marcado, retorno agendado que venceu deixa de ser exclusivo do operador e aparece para todos com escopo.</span>
                        <select name="retorno_vencido_vai_geral">
                            <option value="1" <?= (string) ($regras['retorno_vencido_vai_geral'] ?? '1') === '1' ? 'selected' : '' ?>>Sim</option>
                            <option value="0" <?= (string) ($regras['retorno_vencido_vai_geral'] ?? '1') === '0' ? 'selected' : '' ?>>Não</option>
                        </select>
                    </label>

                    <label class="rule-card">
                        <span class="rule-title">Operador pode puxar fila geral</span>
                        <span class="rule-text">Permite que quem tem permissão de trabalhar fila reserve um cliente disponível para si.</span>
                        <select name="permitir_operador_puxar_geral">
                            <option value="1" <?= (string) ($regras['permitir_operador_puxar_geral'] ?? '1') === '1' ? 'selected' : '' ?>>Sim</option>
                            <option value="0" <?= (string) ($regras['permitir_operador_puxar_geral'] ?? '1') === '0' ? 'selected' : '' ?>>Não</option>
                        </select>
                    </label>

                    <label class="rule-card">
                        <span class="rule-title">Horas da reserva puxada da fila geral</span>
                        <span class="rule-text">Depois desse prazo, a reserva expira e o cliente pode voltar a aparecer na fila geral.</span>
                        <input type="number" name="horas_reserva_padrao" min="1" max="720" value="<?= h($regras['horas_reserva_padrao'] ?? '24') ?>">
                    </label>

                    <label class="rule-card">
                        <span class="rule-title">Horas da reserva manual/transferência</span>
                        <span class="rule-text">Prazo padrão quando líder/supervisor transfere ou reserva um cliente para operador específico.</span>
                        <input type="number" name="horas_reserva_manual" min="1" max="720" value="<?= h($regras['horas_reserva_manual'] ?? '8') ?>">
                    </label>

                    <label class="rule-card">
                        <span class="rule-title">Operador pode liberar reserva</span>
                        <span class="rule-text">Quando marcado, operador pode liberar cliente que está reservado para ele antes de vencer.</span>
                        <select name="permitir_operador_liberar_reserva">
                            <option value="1" <?= (string) ($regras['permitir_operador_liberar_reserva'] ?? '1') === '1' ? 'selected' : '' ?>>Sim</option>
                            <option value="0" <?= (string) ($regras['permitir_operador_liberar_reserva'] ?? '1') === '0' ? 'selected' : '' ?>>Não</option>
                        </select>
                    </label>

                    <label class="rule-card">
                        <span class="rule-title">Supervisão vê todos</span>
                        <span class="rule-text">Permite visão ampla na tela de supervisão para perfis com permissão de supervisionar fila.</span>
                        <select name="supervisao_ver_todos">
                            <option value="1" <?= (string) ($regras['supervisao_ver_todos'] ?? '1') === '1' ? 'selected' : '' ?>>Sim</option>
                            <option value="0" <?= (string) ($regras['supervisao_ver_todos'] ?? '1') === '0' ? 'selected' : '' ?>>Não</option>
                        </select>
                    </label>
                </div>

                <div class="section-title section-title-subtle">
                    <div>
                        <h3>Prazo de reserva por perfil</h3>
                        <p>Esses prazos são usados quando a reserva não informa prazo específico.</p>
                    </div>
                </div>
                <div class="rule-card-grid rule-card-grid-small">
                    <label class="rule-card"><span class="rule-title">Admin</span><input type="number" name="horas_reserva_admin" min="1" max="720" value="<?= h($regras['horas_reserva_admin'] ?? '72') ?>"></label>
                    <label class="rule-card"><span class="rule-title">Supervisor</span><input type="number" name="horas_reserva_supervisor" min="1" max="720" value="<?= h($regras['horas_reserva_supervisor'] ?? '48') ?>"></label>
                    <label class="rule-card"><span class="rule-title">Líder</span><input type="number" name="horas_reserva_lider" min="1" max="720" value="<?= h($regras['horas_reserva_lider'] ?? '36') ?>"></label>
                    <label class="rule-card"><span class="rule-title">Operador</span><input type="number" name="horas_reserva_operador" min="1" max="720" value="<?= h($regras['horas_reserva_operador'] ?? '24') ?>"></label>
                </div>

                <div class="actions-row form-actions">
                    <button class="button" type="submit">Salvar regras</button>
                    <a class="button secondary" href="/operacao/configuracoes/fila_regras.php">Cancelar</a>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Modelo aplicado</h2>
                    <p>A configuração não altera o escopo por credor nem permissões. O perfil define o que pode fazer; o escopo define em quais credores/carteiras atua.</p>
                </div>
            </div>
            <div class="scope-explain-grid">
                <div class="scope-explain-card"><strong>Agenda individual</strong><span>Retornos futuros do próprio operador e clientes reservados para ele.</span></div>
                <div class="scope-explain-card"><strong>Fila geral</strong><span>Retornos vencidos, reservas expiradas e itens de campanha sem dono.</span></div>
                <div class="scope-explain-card"><strong>Transferência</strong><span>Disponível para quem tem <code>fila.transferir</code>.</span></div>
                <div class="scope-explain-card"><strong>Configuração</strong><span>Disponível para quem tem <code>fila.configurar</code>.</span></div>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
