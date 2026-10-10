<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

final class AtendimentoResultadoRepository
{
    private ?bool $moduloDisponivel = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function moduloDisponivel(): bool
    {
        if ($this->moduloDisponivel !== null) {
            return $this->moduloDisponivel;
        }

        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'atendimento_resultados'");
            $this->moduloDisponivel = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->moduloDisponivel = false;
        }

        return $this->moduloDisponivel;
    }

    /** @return list<array<string,mixed>> */
    public function listarTodos(): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $stmt = $this->pdo->query('SELECT
                id,
                codigo,
                nome,
                categoria,
                credor_id,
                resultado_legacy,
                descricao,
                gera_reagendamento,
                padrao_fechamento_acordo,
                considera_sucesso,
                considera_contato_efetivo,
                considera_alo,
                considera_telefone_ruim,
                ativo,
                ordem,
                criado_em,
                atualizado_em
            FROM atendimento_resultados
            ORDER BY ativo DESC, ordem ASC, nome ASC');

        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $id): ?array
    {
        if (!$this->moduloDisponivel()) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT
                id,
                codigo,
                nome,
                categoria,
                credor_id,
                resultado_legacy,
                descricao,
                gera_reagendamento,
                padrao_fechamento_acordo,
                considera_sucesso,
                considera_contato_efetivo,
                considera_alo,
                considera_telefone_ruim,
                ativo,
                ordem,
                criado_em,
                atualizado_em
            FROM atendimento_resultados
            WHERE id = :id
            LIMIT 1');
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $dados */
    public function salvar(array $dados): int
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('A tabela atendimento_resultados ainda não existe. Execute a migration 010 antes de configurar tabulações.');
        }

        $id = $this->normalizarId($dados['id'] ?? null);
        $nome = $this->normalizarTextoObrigatorio($dados['nome'] ?? null, 120, 'nome');
        $codigo = $this->normalizarCodigo($dados['codigo'] ?? null, $nome);
        $categoria = $this->normalizarTextoOpcional($dados['categoria'] ?? null, 80) ?? 'Outros';
        $resultadoLegacy = $this->normalizarResultadoLegacy($dados['resultado_legacy'] ?? null);
        $descricao = $this->normalizarTextoOpcional($dados['descricao'] ?? null, 255);
        $ordem = $this->normalizarOrdem($dados['ordem'] ?? null);
        $geraReagendamento = $this->normalizarFlag($dados['gera_reagendamento'] ?? null);
        $consideraSucesso = $this->normalizarFlag($dados['considera_sucesso'] ?? null);
        $consideraContatoEfetivo = $this->normalizarFlag($dados['considera_contato_efetivo'] ?? null);
        $consideraAlo = $this->normalizarFlag($dados['considera_alo'] ?? null);
        $consideraTelefoneRuim = $this->normalizarFlag($dados['considera_telefone_ruim'] ?? null);
        $ativo = $this->normalizarFlag($dados['ativo'] ?? null);
        $credorId = $this->normalizarId($dados['credor_id'] ?? null);
        $padraoFechamentoAcordo = $this->normalizarFlag($dados['padrao_fechamento_acordo'] ?? null);

        if ($id !== null && $this->buscar($id) === null) {
            throw new InvalidArgumentException('Tabulação de atendimento não encontrada para edição.');
        }

        try {
            if ($id === null) {
                $stmt = $this->pdo->prepare('INSERT INTO atendimento_resultados (
                        codigo,
                        nome,
                        categoria,
                        credor_id,
                        resultado_legacy,
                        descricao,
                        gera_reagendamento,
                        padrao_fechamento_acordo,
                        considera_sucesso,
                        considera_contato_efetivo,
                        considera_alo,
                        considera_telefone_ruim,
                        ativo,
                        ordem
                    ) VALUES (
                        :codigo,
                        :nome,
                        :categoria,
                        :credor_id,
                        :resultado_legacy,
                        :descricao,
                        :gera_reagendamento,
                        :padrao_fechamento_acordo,
                        :considera_sucesso,
                        :considera_contato_efetivo,
                        :considera_alo,
                        :considera_telefone_ruim,
                        :ativo,
                        :ordem
                    )');
            } else {
                $stmt = $this->pdo->prepare('UPDATE atendimento_resultados
                    SET codigo = :codigo,
                        nome = :nome,
                        categoria = :categoria,
                        credor_id = :credor_id,
                        resultado_legacy = :resultado_legacy,
                        descricao = :descricao,
                        gera_reagendamento = :gera_reagendamento,
                        padrao_fechamento_acordo = :padrao_fechamento_acordo,
                        considera_sucesso = :considera_sucesso,
                        considera_contato_efetivo = :considera_contato_efetivo,
                        considera_alo = :considera_alo,
                        considera_telefone_ruim = :considera_telefone_ruim,
                        ativo = :ativo,
                        ordem = :ordem
                    WHERE id = :id');
                $stmt->bindValue('id', $id, PDO::PARAM_INT);
            }

            $stmt->bindValue('codigo', $codigo);
            $stmt->bindValue('nome', $nome);
            $stmt->bindValue('categoria', $categoria);
            $stmt->bindValue('resultado_legacy', $resultadoLegacy);
            $this->bindNullableString($stmt, 'descricao', $descricao);
            $this->bindNullableInt($stmt, 'credor_id', $credorId);
            $stmt->bindValue('gera_reagendamento', $geraReagendamento, PDO::PARAM_INT);
            $stmt->bindValue('padrao_fechamento_acordo', $padraoFechamentoAcordo, PDO::PARAM_INT);
            $stmt->bindValue('considera_sucesso', $consideraSucesso, PDO::PARAM_INT);
            $stmt->bindValue('considera_contato_efetivo', $consideraContatoEfetivo, PDO::PARAM_INT);
            $stmt->bindValue('considera_alo', $consideraAlo, PDO::PARAM_INT);
            $stmt->bindValue('considera_telefone_ruim', $consideraTelefoneRuim, PDO::PARAM_INT);
            $stmt->bindValue('ativo', $ativo, PDO::PARAM_INT);
            $stmt->bindValue('ordem', $ordem, PDO::PARAM_INT);
            $stmt->execute();

            $idFinal = $id ?? (int) $this->pdo->lastInsertId();

            // Achado (2026-10-01): "padrão pra acordo fechado" só
            // faz sentido como ÚNICO por credor (senão volta à mesma
            // ambiguidade que causou o bug de enviar "ACORDO - EXCEÇÃO" pra
            // ACME). Ao marcar uma tabulação como padrão, desmarca qualquer
            // outra do mesmo escopo de credor (credor_id igual, tratando
            // NULL - "vale pra qualquer credor" - como o próprio escopo).
            if ($padraoFechamentoAcordo === 1) {
                $escopoCredor = $credorId === null ? 'credor_id IS NULL' : 'credor_id = :credor_id';
                $limpar = $this->pdo->prepare("UPDATE atendimento_resultados SET padrao_fechamento_acordo = 0 WHERE {$escopoCredor} AND id <> :id");
                if ($credorId !== null) {
                    $limpar->bindValue('credor_id', $credorId, PDO::PARAM_INT);
                }
                $limpar->bindValue('id', $idFinal, PDO::PARAM_INT);
                $limpar->execute();
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new InvalidArgumentException('Já existe uma tabulação com esse código. Use um código único.');
            }

            throw $e;
        }

        return $idFinal;
    }

    public function alternarAtivo(int $id, bool $ativo): void
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('A tabela atendimento_resultados ainda não existe.');
        }

        if ($this->buscar($id) === null) {
            throw new InvalidArgumentException('Tabulação de atendimento não encontrada.');
        }

        $stmt = $this->pdo->prepare('UPDATE atendimento_resultados SET ativo = :ativo WHERE id = :id');
        $stmt->bindValue('ativo', $ativo ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** @return array<string,string> */
    public function resultadosLegacy(): array
    {
        return AtendimentoRepository::RESULTADOS;
    }

    private function normalizarId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!ctype_digit((string) $value)) {
            throw new InvalidArgumentException('ID inválido.');
        }

        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function normalizarCodigo(mixed $value, string $fallbackNome): string
    {
        $codigo = trim((string) ($value ?? ''));
        if ($codigo === '') {
            $codigo = $fallbackNome;
        }

        $transliterado = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $codigo);
        if (is_string($transliterado) && $transliterado !== '') {
            $codigo = $transliterado;
        }

        $codigo = strtoupper($codigo);
        $codigo = preg_replace('/[^A-Z0-9]+/', '_', $codigo) ?? '';
        $codigo = trim($codigo, '_');
        $codigo = substr($codigo, 0, 60);

        if ($codigo === '') {
            throw new InvalidArgumentException('Informe um código válido para a tabulação.');
        }

        return $codigo;
    }

    private function normalizarTextoObrigatorio(mixed $value, int $limite, string $campo): string
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            throw new InvalidArgumentException('Informe o campo ' . $campo . '.');
        }

        if (mb_strlen($texto) > $limite) {
            $texto = mb_substr($texto, 0, $limite);
        }

        return $texto;
    }

    private function normalizarTextoOpcional(mixed $value, int $limite): ?string
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            return null;
        }

        if (mb_strlen($texto) > $limite) {
            $texto = mb_substr($texto, 0, $limite);
        }

        return $texto;
    }

    private function normalizarResultadoLegacy(mixed $value): string
    {
        $valor = trim((string) ($value ?? ''));
        if ($valor === '' || !array_key_exists($valor, AtendimentoRepository::RESULTADOS)) {
            throw new InvalidArgumentException('Selecione um resultado legado válido para compatibilidade.');
        }

        return $valor;
    }

    private function normalizarOrdem(mixed $value): int
    {
        $ordem = trim((string) ($value ?? ''));
        if ($ordem === '') {
            return 100;
        }

        if (!ctype_digit($ordem)) {
            throw new InvalidArgumentException('A ordem deve ser um número inteiro positivo.');
        }

        return max(0, min((int) $ordem, 65535));
    }

    private function normalizarFlag(mixed $value): int
    {
        $valor = (string) ($value ?? '0');
        return in_array($valor, ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
    }

    private function bindNullableString(\PDOStatement $stmt, string $key, ?string $value): void
    {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($key, $value);
    }

    private function bindNullableInt(\PDOStatement $stmt, string $key, ?int $value): void
    {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }
}
