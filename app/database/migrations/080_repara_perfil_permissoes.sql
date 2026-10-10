-- Patch 080 - Reparo idempotente de perfis e permissoes
--
-- Corrige bancos adotados como baseline nos quais a estrutura de autenticacao
-- ficou incompleta. Nenhum vinculo existente e removido ou sobrescrito.
-- As permissoes padrao so sao semeadas quando perfil_permissoes nao existia.

SET @perfil_permissoes_ja_existia = (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'perfil_permissoes'
      AND table_type = 'BASE TABLE'
);

CREATE TABLE IF NOT EXISTS perfis_acesso (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(60) NOT NULL,
    nome VARCHAR(120) NOT NULL,
    nivel INT NOT NULL DEFAULT 0,
    descricao TEXT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_perfis_acesso_codigo (codigo),
    KEY idx_perfis_acesso_ativo (ativo, nivel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS permissoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(100) NOT NULL,
    nome VARCHAR(160) NOT NULL,
    grupo VARCHAR(80) NOT NULL,
    descricao TEXT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_permissoes_codigo (codigo),
    KEY idx_permissoes_grupo (grupo, ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS perfil_permissoes (
    perfil_id BIGINT UNSIGNED NOT NULL,
    permissao_id BIGINT UNSIGNED NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (perfil_id, permissao_id),
    KEY idx_perfil_permissoes_perm (permissao_id),
    CONSTRAINT fk_perfil_permissoes_perfil
        FOREIGN KEY (perfil_id) REFERENCES perfis_acesso (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_perfil_permissoes_permissao
        FOREIGN KEY (permissao_id) REFERENCES permissoes (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO perfis_acesso (codigo, nome, nivel, descricao, ativo) VALUES
('admin', 'Administrador', 100, 'Acesso total ao Pactua.', 1),
('supervisor', 'Supervisor', 80, 'Gestao operacional, acordos, preventiva e relatorios.', 1),
('lider', 'Lider', 60, 'Apoio operacional e visao de equipe.', 1),
('planejamento', 'Planejamento / Estrategia', 50, 'Relatorios e integracoes read-only para planejamento.', 1),
('preventivo', 'Preventivo', 45, 'Acompanhamento preventivo de acordos e vencimentos.', 1),
('operador', 'Operador', 20, 'Atendimento diario conforme escopo.', 1);

INSERT IGNORE INTO permissoes (codigo, nome, grupo, descricao, ativo) VALUES
('dashboard.visualizar', 'Visualizar dashboard', 'Gestao', 'Permite abrir indicadores e o resumo da operacao.', 1),
('clientes.consultar', 'Consultar clientes/contratos', 'Atendimento', 'Permite pesquisar clientes e abrir contratos.', 1),
('atendimentos.visualizar', 'Visualizar atendimentos', 'Atendimento', 'Permite consultar o historico de atendimentos.', 1),
('atendimentos.registrar', 'Registrar acionamento', 'Atendimento', 'Permite registrar eventos, tabulacoes e observacoes.', 1),
('contatos.alterar', 'Alterar localizacao', 'Atendimento', 'Permissao legada para alterar contatos e enderecos.', 1),
('contatos.editar', 'Editar localizacao 2.0', 'Atendimento', 'Permite editar contatos e enderecos no Pactua.', 1),
('contatos.excluir', 'Excluir/inativar localizacao', 'Atendimento', 'Permite excluir ou inativar dados de localizacao.', 1),
('financeiro.historico', 'Ver historico financeiro', 'Acordos', 'Permite consultar eventos financeiros do contrato.', 1),
('boletos.copiar', 'Copiar linha digitavel', 'Acordos', 'Permite copiar a linha digitavel de boleto.', 1),
('boletos.baixar', 'Baixar boleto', 'Acordos', 'Permite baixar o PDF de boleto disponivel.', 1),
('retornos.gerenciar', 'Gerenciar retornos', 'Atendimento', 'Permite visualizar e tratar retornos.', 1),
('campanhas.gerenciar', 'Criar campanhas e filas', 'Campanhas', 'Permite criar campanhas e gerar filas.', 1),
('fila.trabalhar', 'Trabalhar fila manual', 'Campanhas', 'Permite trabalhar itens da fila manual.', 1),
('acordos.visualizar', 'Visualizar acordos', 'Acordos', 'Permite consultar acordos.', 1),
('acordos.carteira.visualizar', 'Visualizar carteira de acordos', 'Acordos', 'Permite acompanhar a carteira de acordos.', 1),
('acordos.carteira.editar', 'Editar carteira de acordos', 'Acordos', 'Permite alterar dados operacionais da carteira.', 1),
('acordos.cadastro_manual', 'Cadastrar acordo manual', 'Acordos', 'Permite cadastrar acordo fechado fora da integracao.', 1),
('acordos.corrigir', 'Corrigir parcelas/pagamentos', 'Acordos', 'Permite corrigir movimentos manuais auditados.', 1),
('acordos.desfazer', 'Desfazer correcoes manuais', 'Acordos', 'Permite reverter movimentos manuais auditados.', 1),
('acordos.criar', 'Fechar promessa/acordo', 'Acordos', 'Permite criar promessa ou acordo.', 1),
('acordos.status', 'Alterar status de acordo', 'Acordos', 'Permite alterar o status operacional do acordo.', 1),
('acordos.parcelas', 'Controlar parcelas', 'Acordos', 'Permite controlar a situacao das parcelas.', 1),
('acordos.integracao', 'Integrar acordo com credor', 'Acordos', 'Permite preparar ou enviar integracao ao credor.', 1),
('acordos.atribuir', 'Atribuir acordo', 'Acordos', 'Permite alterar o responsavel pelo acordo.', 1),
('acordos.gerenciar', 'Gerenciar acordos', 'Acordos', 'Permissao ampla de gestao de acordos.', 1),
('acordos.configurar_carencia', 'Configurar carencia', 'Credores', 'Permite configurar dias de carencia.', 1),
('acordos.sincronizar', 'Sincronizar acordo', 'Acordos', 'Permite consultar o acordo no adapter do credor.', 1),
('preventiva.visualizar', 'Visualizar preventiva', 'Preventiva', 'Permite acompanhar vencimentos e pagamentos.', 1),
('credores.visualizar', 'Visualizar credores', 'Credores', 'Permite consultar credores e configuracoes.', 1),
('credores.editar', 'Editar credores', 'Credores', 'Permite alterar parametros operacionais de credores.', 1),
('relatorios.visualizar', 'Visualizar/exportar relatorios CSV', 'Relatorios', 'Permite acessar os relatorios CSV.', 1),
('relatorios.api.gerenciar', 'Gerenciar token Excel/API', 'Relatorios', 'Permite criar e revogar tokens read-only.', 1),
('tabulacoes.configurar', 'Configurar tabulacoes', 'Configuracoes', 'Permite alterar resultados de atendimento.', 1),
('usuarios.gerenciar', 'Gerenciar usuarios/perfis', 'Configuracoes', 'Permite alterar usuarios, perfis e permissoes.', 1),
('cargas.visualizar', 'Visualizar cargas', 'Configuracoes', 'Permite acessar o status das cargas.', 1),
('integracoes.visualizar', 'Visualizar integracoes', 'Configuracoes', 'Permite consultar a saude das integracoes.', 1),
('seguranca.visualizar', 'Visualizar seguranca', 'Configuracoes', 'Permite acessar paineis de seguranca.', 1),
('auditoria.visualizar', 'Visualizar auditoria', 'Configuracoes', 'Permite consultar a auditoria do sistema.', 1);

-- Em uma tabela recem-criada, recompomos os vinculos padrao. Se ela ja
-- existia, mesmo vazia, respeitamos integralmente a configuracao do cliente.
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
CROSS JOIN permissoes pe
WHERE @perfil_permissoes_ja_existia = 0
  AND pa.codigo = 'admin';

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN (
    'dashboard.visualizar','clientes.consultar','atendimentos.visualizar','atendimentos.registrar',
    'contatos.alterar','contatos.editar','contatos.excluir','financeiro.historico',
    'boletos.copiar','boletos.baixar','retornos.gerenciar','campanhas.gerenciar','fila.trabalhar',
    'acordos.visualizar','acordos.carteira.visualizar','acordos.carteira.editar',
    'acordos.cadastro_manual','acordos.corrigir','acordos.desfazer','acordos.criar',
    'acordos.status','acordos.parcelas','acordos.integracao','acordos.atribuir',
    'acordos.gerenciar','acordos.configurar_carencia','acordos.sincronizar',
    'preventiva.visualizar','credores.visualizar','relatorios.visualizar'
)
WHERE @perfil_permissoes_ja_existia = 0
  AND pa.codigo = 'supervisor';

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN (
    'dashboard.visualizar','clientes.consultar','atendimentos.visualizar','atendimentos.registrar',
    'contatos.alterar','contatos.editar','contatos.excluir','financeiro.historico',
    'boletos.copiar','boletos.baixar','retornos.gerenciar','fila.trabalhar',
    'acordos.visualizar','acordos.carteira.visualizar','acordos.carteira.editar',
    'acordos.corrigir','acordos.criar','acordos.status','acordos.parcelas',
    'acordos.sincronizar','preventiva.visualizar','relatorios.visualizar'
)
WHERE @perfil_permissoes_ja_existia = 0
  AND pa.codigo = 'lider';

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN (
    'relatorios.visualizar','relatorios.api.gerenciar','credores.visualizar'
)
WHERE @perfil_permissoes_ja_existia = 0
  AND pa.codigo = 'planejamento';

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN (
    'dashboard.visualizar','clientes.consultar','atendimentos.visualizar','atendimentos.registrar',
    'contatos.alterar','contatos.editar','financeiro.historico','boletos.copiar','boletos.baixar',
    'retornos.gerenciar','acordos.visualizar','acordos.carteira.visualizar','acordos.corrigir',
    'acordos.criar','acordos.parcelas','acordos.sincronizar','preventiva.visualizar'
)
WHERE @perfil_permissoes_ja_existia = 0
  AND pa.codigo = 'preventivo';

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN (
    'dashboard.visualizar','clientes.consultar','atendimentos.visualizar','atendimentos.registrar',
    'contatos.alterar','contatos.editar','financeiro.historico','boletos.copiar','boletos.baixar',
    'retornos.gerenciar','fila.trabalhar','acordos.visualizar','acordos.carteira.visualizar',
    'acordos.criar'
)
WHERE @perfil_permissoes_ja_existia = 0
  AND pa.codigo = 'operador';
