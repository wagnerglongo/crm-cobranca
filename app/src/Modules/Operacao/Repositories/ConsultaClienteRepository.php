<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use CRM\Modules\Operacao\Services\ContatoIntegracaoService;
use CRM\Modules\Operacao\Support\EscopoCredorSql;
use InvalidArgumentException;
use PDO;

final class ConsultaClienteRepository
{

    public const CONTATO_TIPOS = [
        'telefone' => 'Telefone',
        'whatsapp' => 'WhatsApp',
        'email' => 'E-mail',
        'outro' => 'Outro',
    ];

    public const CONTATO_STATUS = [
        'novo' => 'Novo',
        'hot' => 'Hot',
        'correto' => 'Correto',
        'incorreto' => 'Incorreto',
        'desconhecido' => 'Desconhecido',
        'nao_confirmado' => 'Não confirmado',
        'bloqueado' => 'Bloqueado',
    ];

    public static function contatoTipoLabel(?string $tipo): string
    {
        return self::CONTATO_TIPOS[(string) $tipo] ?? 'Outro';
    }

    public static function contatoStatusLabel(?string $status): string
    {
        return self::CONTATO_STATUS[(string) $status] ?? 'Novo';
    }

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function buscarPessoas(string $termo, int $limite = 20, ?array $escopo = null): array
    {
        $termo = trim($termo);
        if ($termo === '' || strlen($termo) < 2) {
            return [];
        }

        $limite = max(1, min($limite, 50));
        $digitos = preg_replace('/\D+/', '', $termo) ?? '';

        $whereBusca = [];
        $params = [];

        if ($digitos !== '' && strlen($digitos) >= 3) {
            $whereBusca[] = 'p.documento LIKE :documento';
            $params['documento'] = '%' . $digitos . '%';

            $whereBusca[] = 'EXISTS (
                SELECT 1
                FROM pessoa_contatos pc_busca
                WHERE pc_busca.pessoa_id = p.id
                  AND pc_busca.valor_normalizado LIKE :telefone
            )';
            $params['telefone'] = '%' . $digitos . '%';
        }

        if (strlen($termo) >= 3) {
            $whereBusca[] = 'p.nome LIKE :nome';
            $whereBusca[] = 'c.codigo_contrato LIKE :contrato';
            $params['nome'] = '%' . $termo . '%';
            $params['contrato'] = '%' . $termo . '%';
        }

        if ($whereBusca === []) {
            return [];
        }

        // Importante para performance com usuário restrito:
        // antes a busca fazia EXISTS por pessoa + outro JOIN para contar contratos.
        // Agora a consulta parte diretamente de contratos já filtrados pelo escopo,
        // reduzindo bastante o volume quando o usuário vê apenas alguns credores/carteiras.
        $scope = EscopoCredorSql::contrato($escopo, 'c', 'busca_escopo');
        $params = array_merge($params, $scope['params']);

        $sql = 'SELECT
                p.id,
                p.documento,
                p.tipo_documento,
                p.nome,
                p.data_nascimento,
                p.sexo,
                p.estado_civil,
                p.criado_em,
                p.atualizado_em,
                COUNT(DISTINCT c.id) AS total_contratos,
                (SELECT COUNT(*) FROM pessoa_contatos pc_count WHERE pc_count.pessoa_id = p.id) AS total_contatos,
                COALESCE(SUM(c.saldo_atual), 0) AS saldo_total_aproximado,
                MAX(c.atualizado_em) AS ultimo_contrato_atualizado_em
            FROM contratos c
            INNER JOIN pessoas p ON p.id = c.pessoa_id
            WHERE ' . $scope['sql'] . '
              AND (' . implode(' OR ', $whereBusca) . ')
            GROUP BY
                p.id,
                p.documento,
                p.tipo_documento,
                p.nome,
                p.data_nascimento,
                p.sexo,
                p.estado_civil,
                p.criado_em,
                p.atualizado_em
            ORDER BY p.nome ASC
            LIMIT :limite';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function buscarPessoaPorId(int $pessoaId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT
                p.*,
                ca_origem.nome_original AS origem_carga_nome,
                ca_origem.criado_em AS origem_carga_criado_em,
                ca_ultima.nome_original AS ultima_carga_nome,
                ca_ultima.finalizado_em AS ultima_carga_finalizado_em
            FROM pessoas p
            LEFT JOIN cargas_arquivos ca_origem ON ca_origem.id = p.origem_carga_id
            LEFT JOIN cargas_arquivos ca_ultima ON ca_ultima.id = p.ultima_carga_id
            WHERE p.id = :id
            LIMIT 1');
        $stmt->execute(['id' => $pessoaId]);

        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed> */
    public function buscarResumoPessoa(int $pessoaId, ?array $escopo = null): array
    {
        $scope = EscopoCredorSql::contrato($escopo, 'c', 'resumo_escopo');
        $stmt = $this->pdo->prepare('SELECT
                COUNT(c.id) AS total_contratos,
                COALESCE(SUM(c.saldo_atual), 0) AS saldo_total,
                COALESCE(MAX(c.atualizado_em), p.atualizado_em) AS ultima_atualizacao_contrato,
                (SELECT COUNT(*) FROM pessoa_contatos pc WHERE pc.pessoa_id = p.id) AS total_contatos,
                (SELECT COUNT(*) FROM pessoa_enderecos pe WHERE pe.pessoa_id = p.id) AS total_enderecos
            FROM pessoas p
            LEFT JOIN contratos c ON c.pessoa_id = p.id AND ' . $scope['sql'] . '
            WHERE p.id = :pessoa_id
            GROUP BY p.id, p.atualizado_em');
        foreach ($scope['params'] as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch() ?: [];

        return [
            'total_contratos' => (int) ($row['total_contratos'] ?? 0),
            'saldo_total' => (float) ($row['saldo_total'] ?? 0),
            'total_contatos' => (int) ($row['total_contatos'] ?? 0),
            'total_enderecos' => (int) ($row['total_enderecos'] ?? 0),
            'ultima_atualizacao_contrato' => $row['ultima_atualizacao_contrato'] ?? null,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listarContatos(int $pessoaId): array
    {
        $stmt = $this->pdo->prepare('SELECT
                pc.*,
                ca_ultima.nome_original AS ultima_carga_nome
            FROM pessoa_contatos pc
            LEFT JOIN cargas_arquivos ca_ultima ON ca_ultima.id = pc.ultima_carga_id
            WHERE pc.pessoa_id = :pessoa_id
            ORDER BY pc.principal DESC, pc.tipo ASC, pc.status ASC, pc.id ASC');
        $stmt->execute(['pessoa_id' => $pessoaId]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listarEnderecos(int $pessoaId): array
    {
        $stmt = $this->pdo->prepare('SELECT
                pe.*,
                ca_ultima.nome_original AS ultima_carga_nome
            FROM pessoa_enderecos pe
            LEFT JOIN cargas_arquivos ca_ultima ON ca_ultima.id = pe.ultima_carga_id
            WHERE pe.pessoa_id = :pessoa_id
            ORDER BY pe.principal DESC, pe.id ASC');
        $stmt->execute(['pessoa_id' => $pessoaId]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listarContratos(int $pessoaId, ?array $escopo = null): array
    {
        $scope = EscopoCredorSql::contrato($escopo, 'c', 'contratos_escopo');
        $stmt = $this->pdo->prepare('SELECT
                c.*,
                cr.codigo AS credor_codigo,
                cr.nome AS credor_nome,
                car.codigo AS carteira_codigo,
                car.nome AS carteira_nome,
                ca_origem.nome_original AS origem_carga_nome,
                ca_ultima.nome_original AS ultima_carga_nome,
                ca_ultima.finalizado_em AS ultima_carga_finalizado_em
            FROM contratos c
            INNER JOIN credores cr ON cr.id = c.credor_id
            LEFT JOIN carteiras car ON car.id = c.carteira_id
            LEFT JOIN cargas_arquivos ca_origem ON ca_origem.id = c.origem_carga_id
            LEFT JOIN cargas_arquivos ca_ultima ON ca_ultima.id = c.ultima_carga_id
            WHERE c.pessoa_id = :pessoa_id
              AND ' . $scope['sql'] . '
            ORDER BY c.atualizado_em DESC, c.id DESC');
        foreach ($scope['params'] as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }


    /** @return array<string,mixed>|null */
    public function buscarContatoPorId(int $pessoaId, int $contatoId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pessoa_contatos WHERE id = :id AND pessoa_id = :pessoa_id LIMIT 1');
        $stmt->bindValue('id', $contatoId, PDO::PARAM_INT);
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $dados */
    public function adicionarContatoManual(int $pessoaId, array $dados): int
    {
        $tipo = $this->validarContatoTipo((string) ($dados['tipo'] ?? ''));
        $status = $this->validarContatoStatus((string) ($dados['status'] ?? 'novo'));
        $valor = $this->normalizarTextoContato($dados['valor'] ?? null, 255);
        $subtipo = $this->normalizarTextoContato($dados['subtipo'] ?? null, 60);
        $observacao = $this->normalizarTextoContato($dados['observacao'] ?? null, 1000);
        $principal = (string) ($dados['principal'] ?? '') === '1' ? 1 : 0;

        if ($valor === null) {
            throw new InvalidArgumentException('Informe o telefone, WhatsApp, e-mail ou contato.');
        }

        [$valorNormalizado, $ddd, $telefone, $email] = $this->normalizarValorContato($tipo, $valor);
        $payloadExtra = [
            'origem_manual' => true,
            'observacao' => $observacao,
            'criado_por_tela' => 'operacao/cliente_detalhe.php',
        ];

        if ($principal === 1) {
            $this->limparPrincipalContatos($pessoaId, $tipo);
        }

        // Estado anterior (se o numero ja existia) pra integracao com o credor saber o que mudou.
        $antes = $this->buscarContatoPorChave($pessoaId, $tipo, $valorNormalizado);

        $stmt = $this->pdo->prepare('INSERT INTO pessoa_contatos (
                pessoa_id,
                tipo,
                subtipo,
                valor,
                valor_normalizado,
                ddd,
                telefone,
                email,
                status,
                principal,
                origem,
                payload_extra
            ) VALUES (
                :pessoa_id,
                :tipo,
                :subtipo,
                :valor,
                :valor_normalizado,
                :ddd,
                :telefone,
                :email,
                :status,
                :principal,
                \'manual\',
                :payload_extra
            )
            ON DUPLICATE KEY UPDATE
                subtipo = COALESCE(VALUES(subtipo), subtipo),
                valor = VALUES(valor),
                ddd = COALESCE(VALUES(ddd), ddd),
                telefone = COALESCE(VALUES(telefone), telefone),
                email = COALESCE(VALUES(email), email),
                status = VALUES(status),
                principal = VALUES(principal),
                origem = COALESCE(origem, \'manual\'),
                payload_extra = VALUES(payload_extra),
                atualizado_em = CURRENT_TIMESTAMP');
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->bindValue('tipo', $tipo);
        $this->bindNullableStringLocal($stmt, 'subtipo', $subtipo);
        $stmt->bindValue('valor', $valor);
        $stmt->bindValue('valor_normalizado', $valorNormalizado);
        $this->bindNullableStringLocal($stmt, 'ddd', $ddd);
        $this->bindNullableStringLocal($stmt, 'telefone', $telefone);
        $this->bindNullableStringLocal($stmt, 'email', $email);
        $stmt->bindValue('status', $status);
        $stmt->bindValue('principal', $principal, PDO::PARAM_INT);
        $stmt->bindValue('payload_extra', json_encode($payloadExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->execute();

        $contatoId = $antes !== null ? (int) $antes['id'] : (int) $this->pdo->lastInsertId();
        if ($contatoId <= 0) {
            $contatoId = (int) (($this->buscarContatoPorChave($pessoaId, $tipo, $valorNormalizado) ?? [])['id'] ?? 0);
        }

        if ($contatoId > 0) {
            (new ContatoIntegracaoService($this->pdo))->contatoAlterado($contatoId, $antes);
        }

        return $contatoId;
    }

    /** @return array<string,mixed>|null */
    private function buscarContatoPorChave(int $pessoaId, string $tipo, string $valorNormalizado): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pessoa_contatos WHERE pessoa_id = :pessoa_id AND tipo = :tipo AND valor_normalizado = :valor_normalizado LIMIT 1');
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->bindValue('tipo', $tipo);
        $stmt->bindValue('valor_normalizado', $valorNormalizado);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $dados */
    public function atualizarContatoManual(int $pessoaId, int $contatoId, array $dados): void
    {
        $contato = $this->buscarContatoPorId($pessoaId, $contatoId);
        if ($contato === null) {
            throw new InvalidArgumentException('Contato não encontrado para este cliente.');
        }

        $status = $this->validarContatoStatus((string) ($dados['status'] ?? $contato['status'] ?? 'novo'));
        $subtipo = $this->normalizarTextoContato($dados['subtipo'] ?? ($contato['subtipo'] ?? null), 60);
        $observacao = $this->normalizarTextoContato($dados['observacao'] ?? null, 1000);
        $principal = (string) ($dados['principal'] ?? '') === '1' ? 1 : 0;

        if ($principal === 1) {
            $this->limparPrincipalContatos($pessoaId, (string) $contato['tipo']);
        }

        $payloadExtra = $this->decodePayloadExtra($contato['payload_extra'] ?? null);
        $payloadExtra['observacao_manual'] = $observacao;
        $payloadExtra['atualizado_por_tela'] = 'operacao/cliente_detalhe.php';

        $stmt = $this->pdo->prepare('UPDATE pessoa_contatos
            SET status = :status,
                subtipo = :subtipo,
                principal = :principal,
                payload_extra = :payload_extra
            WHERE id = :id AND pessoa_id = :pessoa_id
            LIMIT 1');
        $stmt->bindValue('status', $status);
        $this->bindNullableStringLocal($stmt, 'subtipo', $subtipo);
        $stmt->bindValue('principal', $principal, PDO::PARAM_INT);
        $stmt->bindValue('payload_extra', json_encode($payloadExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->bindValue('id', $contatoId, PDO::PARAM_INT);
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();

        (new ContatoIntegracaoService($this->pdo))->contatoAlterado($contatoId, $contato);
    }

    private function limparPrincipalContatos(int $pessoaId, string $tipo): void
    {
        $stmt = $this->pdo->prepare('UPDATE pessoa_contatos SET principal = 0 WHERE pessoa_id = :pessoa_id AND tipo = :tipo');
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->bindValue('tipo', $tipo);
        $stmt->execute();
    }

    private function validarContatoTipo(string $tipo): string
    {
        $tipo = trim($tipo);
        if ($tipo === '' || !array_key_exists($tipo, self::CONTATO_TIPOS)) {
            throw new InvalidArgumentException('Tipo de contato inválido.');
        }

        return $tipo;
    }

    private function validarContatoStatus(string $status): string
    {
        $status = trim($status);
        if ($status === '' || !array_key_exists($status, self::CONTATO_STATUS)) {
            throw new InvalidArgumentException('Status de contato inválido.');
        }

        return $status;
    }

    private function normalizarTextoContato(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max);
        }

        return $text;
    }

    /** @return array{0:string,1:?string,2:?string,3:?string} */
    private function normalizarValorContato(string $tipo, string $valor): array
    {
        if ($tipo === 'email') {
            $email = mb_strtolower(trim($valor));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('E-mail inválido.');
            }

            return [$email, null, null, $email];
        }

        if ($tipo === 'telefone' || $tipo === 'whatsapp') {
            $digitos = preg_replace('/\D+/', '', $valor) ?? '';
            if (strlen($digitos) < 8 || strlen($digitos) > 15) {
                throw new InvalidArgumentException('Telefone/WhatsApp inválido. Informe de 8 a 15 dígitos.');
            }
            $ddd = strlen($digitos) >= 10 ? substr($digitos, 0, 2) : null;

            return [$digitos, $ddd, $digitos, null];
        }

        $normalizado = mb_strtolower(trim($valor));
        if ($normalizado === '') {
            throw new InvalidArgumentException('Contato inválido.');
        }

        return [$normalizado, null, null, null];
    }

    /** @return array<string,mixed> */
    private function decodePayloadExtra(mixed $payload): array
    {
        if (!is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function bindNullableStringLocal(\PDOStatement $stmt, string $key, ?string $value): void
    {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($key, $value);
    }

    /**
     * @return array<int,list<array<string,mixed>>>
     */
    public function listarVeiculosPorContratos(array $contratoIds): array
    {
        $contratoIds = array_values(array_filter(array_map('intval', $contratoIds), static fn (int $id): bool => $id > 0));
        if ($contratoIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($contratoIds as $index => $id) {
            $key = 'id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $stmt = $this->pdo->prepare('SELECT *
            FROM contrato_veiculos
            WHERE contrato_id IN (' . implode(',', $placeholders) . ')
            ORDER BY id ASC');

        foreach ($params as $key => $id) {
            $stmt->bindValue($key, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        $agrupado = [];
        foreach ($stmt->fetchAll() as $row) {
            $contratoId = (int) $row['contrato_id'];
            $agrupado[$contratoId] ??= [];
            $agrupado[$contratoId][] = $row;
        }

        return $agrupado;
    }

    public function pessoaTemContratoNoEscopo(int $pessoaId, ?array $escopo = null): bool
    {
        $scope = EscopoCredorSql::contrato($escopo, 'c', 'pessoa_check');
        $stmt = $this->pdo->prepare('SELECT 1 FROM contratos c WHERE c.pessoa_id = :pessoa_id AND ' . $scope['sql'] . ' LIMIT 1');
        foreach ($scope['params'] as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listarCargasOrigemPessoa(int $pessoaId, int $limite = 20): array
    {
        $limite = max(1, min($limite, 50));

        $sql = 'SELECT DISTINCT
                ca.id,
                ca.nome_original,
                ca.status,
                ca.total_linhas,
                ca.linhas_validas,
                ca.linhas_com_erro,
                ca.criado_em,
                ca.finalizado_em
            FROM cargas_arquivos ca
            INNER JOIN (
                SELECT origem_carga_id AS carga_id FROM pessoas WHERE id = ? AND origem_carga_id IS NOT NULL
                UNION
                SELECT ultima_carga_id AS carga_id FROM pessoas WHERE id = ? AND ultima_carga_id IS NOT NULL
                UNION
                SELECT origem_carga_id AS carga_id FROM contratos WHERE pessoa_id = ? AND origem_carga_id IS NOT NULL
                UNION
                SELECT ultima_carga_id AS carga_id FROM contratos WHERE pessoa_id = ? AND ultima_carga_id IS NOT NULL
                UNION
                SELECT origem_carga_id AS carga_id FROM pessoa_contatos WHERE pessoa_id = ? AND origem_carga_id IS NOT NULL
                UNION
                SELECT ultima_carga_id AS carga_id FROM pessoa_contatos WHERE pessoa_id = ? AND ultima_carga_id IS NOT NULL
                UNION
                SELECT origem_carga_id AS carga_id FROM pessoa_enderecos WHERE pessoa_id = ? AND origem_carga_id IS NOT NULL
                UNION
                SELECT ultima_carga_id AS carga_id FROM pessoa_enderecos WHERE pessoa_id = ? AND ultima_carga_id IS NOT NULL
            ) origem ON origem.carga_id = ca.id
            ORDER BY ca.finalizado_em DESC, ca.id DESC
            LIMIT ' . $limite;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_fill(0, 8, $pessoaId));

        return $stmt->fetchAll();
    }
}
