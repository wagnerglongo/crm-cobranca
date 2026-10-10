-- Compatível com bancos que já receberam criado_por_usuario_id pela migration 019.
-- Cada objeto é criado somente quando ainda não existe.

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'acordos_operacionais' AND column_name = 'criado_por_usuario_id'),
    'SELECT 1', 'ALTER TABLE acordos_operacionais ADD COLUMN criado_por_usuario_id BIGINT UNSIGNED NULL AFTER operador_nome');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'acordos_operacionais' AND column_name = 'api_token_id'),
    'SELECT 1', 'ALTER TABLE acordos_operacionais ADD COLUMN api_token_id BIGINT UNSIGNED NULL AFTER criado_por_usuario_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'acordos_operacionais' AND column_name = 'criado_por_usuario_id'),
    'SELECT 1', 'ALTER TABLE acordos_operacionais ADD KEY idx_acordos_criado_por (criado_por_usuario_id, criado_em)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'acordos_operacionais' AND column_name = 'api_token_id'),
    'SELECT 1', 'ALTER TABLE acordos_operacionais ADD KEY idx_acordos_api_token (api_token_id, criado_em)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = 'acordos_operacionais' AND column_name = 'criado_por_usuario_id' AND referenced_table_name = 'usuarios'),
    'SELECT 1', 'ALTER TABLE acordos_operacionais ADD CONSTRAINT fk_acordos_criado_por_usuario FOREIGN KEY (criado_por_usuario_id) REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE SET NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = 'acordos_operacionais' AND column_name = 'api_token_id' AND referenced_table_name = 'api_tokens'),
    'SELECT 1', 'ALTER TABLE acordos_operacionais ADD CONSTRAINT fk_acordos_api_token FOREIGN KEY (api_token_id) REFERENCES api_tokens (id) ON UPDATE CASCADE ON DELETE SET NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
