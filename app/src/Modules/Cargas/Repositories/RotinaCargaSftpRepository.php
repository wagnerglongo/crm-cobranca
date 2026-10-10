<?php

declare(strict_types=1);

namespace CRM\Modules\Cargas\Repositories;

use CRM\Infrastructure\Security\CredencialCryptoService;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;

final class RotinaCargaSftpRepository
{
    /** @var array<string,string> mapeamento credor.codigo => comando de processamento diario */
    private const COMANDO_PROCESSAMENTO_POR_CREDOR = [
        'ACME' => 'processar_acme_diario.php',
    ];

    private ?bool $moduloDisponivel = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly CredencialCryptoService $crypto
    ) {
    }

    public function moduloDisponivel(): bool
    {
        if ($this->moduloDisponivel !== null) {
            return $this->moduloDisponivel;
        }
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'rotinas_carga_sftp'");
            $this->moduloDisponivel = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->moduloDisponivel = false;
        }
        return $this->moduloDisponivel;
    }

    /** @return list<array<string,mixed>> */
    public function listar(): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }
        $stmt = $this->pdo->query('SELECT r.*, c.codigo AS credor_codigo, c.nome AS credor_nome
            FROM rotinas_carga_sftp r
            INNER JOIN credores c ON c.id = r.credor_id
            ORDER BY r.nome ASC, r.id ASC');
        $rows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as &$row) {
            unset($row['senha_criptografada']);
            $row['destino_caminho'] = $this->destinoPara((string) $row['credor_codigo']);
            $row['comando_disponivel'] = $this->comandoProcessamentoPara((string) $row['credor_codigo']) !== null;
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function buscarPorId(int $id): ?array
    {
        if (!$this->moduloDisponivel() || $id <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT r.*, c.codigo AS credor_codigo, c.nome AS credor_nome
            FROM rotinas_carga_sftp r
            INNER JOIN credores c ON c.id = r.credor_id
            WHERE r.id = :id LIMIT 1');
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        unset($row['senha_criptografada']);
        $row['destino_caminho'] = $this->destinoPara((string) $row['credor_codigo']);
        $row['comando_disponivel'] = $this->comandoProcessamentoPara((string) $row['credor_codigo']) !== null;
        return $row;
    }

    /** @return array{usuario:string,senha:string,origem_host:string,origem_porta:int,origem_caminho:string,padrao_arquivo:?string,credor_codigo:string} */
    public function credenciaisParaExecucao(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT r.usuario, r.senha_criptografada, r.origem_host, r.origem_porta, r.origem_caminho, r.padrao_arquivo, c.codigo AS credor_codigo
            FROM rotinas_carga_sftp r
            INNER JOIN credores c ON c.id = r.credor_id
            WHERE r.id = :id LIMIT 1');
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new InvalidArgumentException('Rotina não encontrada.');
        }
        return [
            'usuario' => (string) $row['usuario'],
            'senha' => $this->crypto->descriptografar((string) $row['senha_criptografada']),
            'origem_host' => (string) $row['origem_host'],
            'origem_porta' => (int) $row['origem_porta'],
            'origem_caminho' => (string) $row['origem_caminho'],
            'padrao_arquivo' => $row['padrao_arquivo'] !== null ? (string) $row['padrao_arquivo'] : null,
            'credor_codigo' => (string) $row['credor_codigo'],
        ];
    }

    /** @param array<string,mixed> $dados */
    public function criar(array $dados, ?int $usuarioId): int
    {
        [$dados, $senha] = $this->validar($dados, true);
        $stmt = $this->pdo->prepare('INSERT INTO rotinas_carga_sftp (
                credor_id, nome, descricao, protocolo, origem_host, origem_porta, origem_caminho,
                usuario, senha_criptografada, padrao_arquivo, periodicidade, horario, status,
                criado_por_usuario_id, atualizado_por_usuario_id
            ) VALUES (
                :credor_id, :nome, :descricao, :protocolo, :origem_host, :origem_porta, :origem_caminho,
                :usuario, :senha_criptografada, :padrao_arquivo, :periodicidade, :horario, :status,
                :usuario_id, :usuario_id_2
            )');
        $this->bindDadosSemSenha($stmt, $dados);
        $stmt->bindValue('senha_criptografada', $this->crypto->criptografar($senha));
        $stmt->bindValue('status', $dados['status']);
        $this->bindNullableInt($stmt, 'usuario_id', $usuarioId);
        $this->bindNullableInt($stmt, 'usuario_id_2', $usuarioId);
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $dados */
    public function atualizar(int $id, array $dados, ?int $usuarioId): void
    {
        $atual = $this->buscarPorId($id);
        if ($atual === null) {
            throw new InvalidArgumentException('Rotina não encontrada.');
        }
        [$dados, $senha] = $this->validar($dados, $senha_obrigatoria = false);

        if ($senha !== '') {
            $senhaCriptografada = $this->crypto->criptografar($senha);
            $stmt = $this->pdo->prepare('UPDATE rotinas_carga_sftp SET
                    credor_id = :credor_id, nome = :nome, descricao = :descricao, protocolo = :protocolo,
                    origem_host = :origem_host, origem_porta = :origem_porta, origem_caminho = :origem_caminho,
                    usuario = :usuario, senha_criptografada = :senha_criptografada, padrao_arquivo = :padrao_arquivo,
                    periodicidade = :periodicidade, horario = :horario, atualizado_por_usuario_id = :usuario_id
                WHERE id = :id LIMIT 1');
            $this->bindDadosSemSenha($stmt, $dados);
            $stmt->bindValue('senha_criptografada', $senhaCriptografada);
        } else {
            $stmt = $this->pdo->prepare('UPDATE rotinas_carga_sftp SET
                    credor_id = :credor_id, nome = :nome, descricao = :descricao, protocolo = :protocolo,
                    origem_host = :origem_host, origem_porta = :origem_porta, origem_caminho = :origem_caminho,
                    usuario = :usuario, padrao_arquivo = :padrao_arquivo,
                    periodicidade = :periodicidade, horario = :horario, atualizado_por_usuario_id = :usuario_id
                WHERE id = :id LIMIT 1');
            $this->bindDadosSemSenha($stmt, $dados);
        }
        $this->bindNullableInt($stmt, 'usuario_id', $usuarioId);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function alternarStatus(int $id, bool $ativa, ?int $usuarioId): void
    {
        $atual = $this->buscarPorId($id);
        if ($atual === null) {
            throw new InvalidArgumentException('Rotina não encontrada.');
        }
        if ($ativa && (bool) $atual['comando_disponivel'] === false) {
            throw new InvalidArgumentException(
                'Não é possível ativar: ainda não existe um comando de processamento automático para o credor "' . $atual['credor_nome'] . '".'
            );
        }
        $stmt = $this->pdo->prepare('UPDATE rotinas_carga_sftp SET status = :status, atualizado_por_usuario_id = :usuario_id WHERE id = :id LIMIT 1');
        $stmt->bindValue('status', $ativa ? 'ativa' : 'pausada');
        $this->bindNullableInt($stmt, 'usuario_id', $usuarioId);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Marca a rotina como "processando" assim que a execucao e disparada
     * em segundo plano - sem isso, o operador clica em "Executar" e nao
     * tem nenhum sinal na tabela ate o resultado final chegar (podem ser
     * so alguns segundos, mas parece que nao aconteceu nada).
     * registrarExecucao() sobrescreve isso com o resultado real quando
     * o processo em segundo plano termina.
     */
    public function marcarProcessando(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE rotinas_carga_sftp SET
                ultima_execucao_em = NOW(), ultima_execucao_status = 'processando', ultima_execucao_mensagem = NULL
            WHERE id = :id LIMIT 1");
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function registrarExecucao(int $rotinaId, string $status, int $totalArquivos, ?string $mensagem, ?int $usuarioId): void
    {
        $mensagem = $mensagem !== null ? mb_substr($mensagem, 0, 500) : null;

        $stmt = $this->pdo->prepare('INSERT INTO rotinas_carga_sftp_execucoes (
                rotina_id, status, total_arquivos_baixados, mensagem, disparado_por_usuario_id, finalizado_em
            ) VALUES (:rotina_id, :status, :total, :mensagem, :usuario_id, NOW())');
        $stmt->bindValue('rotina_id', $rotinaId, PDO::PARAM_INT);
        $stmt->bindValue('status', $status);
        $stmt->bindValue('total', $totalArquivos, PDO::PARAM_INT);
        $stmt->bindValue('mensagem', $mensagem, $mensagem === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $this->bindNullableInt($stmt, 'usuario_id', $usuarioId);
        $stmt->execute();

        $update = $this->pdo->prepare('UPDATE rotinas_carga_sftp SET
                ultima_execucao_em = NOW(), ultima_execucao_status = :status,
                ultima_execucao_mensagem = :mensagem, total_arquivos_ultima_execucao = :total
            WHERE id = :id LIMIT 1');
        $update->bindValue('status', $status);
        $update->bindValue('mensagem', $mensagem, $mensagem === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $update->bindValue('total', $totalArquivos, PDO::PARAM_INT);
        $update->bindValue('id', $rotinaId, PDO::PARAM_INT);
        $update->execute();
    }

    /** @return list<array<string,mixed>> */
    public function listarExecucoes(int $rotinaId, int $limite = 20): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT * FROM rotinas_carga_sftp_execucoes WHERE rotina_id = :rotina_id ORDER BY id DESC LIMIT :limite');
        $stmt->bindValue('rotina_id', $rotinaId, PDO::PARAM_INT);
        $stmt->bindValue('limite', max(1, min(100, $limite)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Rotinas ativas cujo horario agendado para hoje ja chegou e ainda
     * nao foram executadas hoje (evita rodar 2x o mesmo horario do dia).
     * @return list<array<string,mixed>>
     */
    public function listarAtivasDevidas(DateTimeImmutable $agora): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }
        $diaSemana = (int) $agora->format('N'); // 1=segunda .. 7=domingo
        $stmt = $this->pdo->prepare("SELECT r.*, c.codigo AS credor_codigo, c.nome AS credor_nome
            FROM rotinas_carga_sftp r
            INNER JOIN credores c ON c.id = r.credor_id
            WHERE r.status = 'ativa'
              AND r.periodicidade IN ('diario', 'seg_sex')
              AND r.horario IS NOT NULL
              AND r.horario <= :hora_atual
              AND (r.ultima_execucao_em IS NULL OR DATE(r.ultima_execucao_em) < :hoje)");
        $stmt->bindValue('hora_atual', $agora->format('H:i:s'));
        $stmt->bindValue('hoje', $agora->format('Y-m-d'));
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($diaSemana >= 6) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => (string) $row['periodicidade'] !== 'seg_sex'));
        }

        return $rows;
    }

    public function destinoPara(string $credorCodigo): string
    {
        $base = rtrim((string) \CRM\Config\Database::env('STORAGE_CARGAS_PATH', '/var/www/storage/cargas'), '/');
        return $base . '/inbox/' . mb_strtolower(trim($credorCodigo));
    }

    public function comandoProcessamentoPara(string $credorCodigo): ?string
    {
        return self::COMANDO_PROCESSAMENTO_POR_CREDOR[mb_strtoupper(trim($credorCodigo))] ?? null;
    }

    /**
     * E' comum colar a URL inteira que o time de redes passou (ex.:
     * "sftp://203.0.113.10/FilesPool/WorkTeam249/") no campo de host.
     * Se isso for passado direto pro cliente SFTP, o PHP tenta usar
     * "sftp" como protocolo de stream (que nao existe fora da extensao
     * ssh2) e falha com uma mensagem confusa ("unable to find the
     * socket transport"). Aqui a gente tira o esquema e separa o
     * caminho embutido, se houver.
     * @return array{0:string,1:?string}
     */
    private function normalizarHostOrigem(string $bruto): array
    {
        $valor = trim($bruto);
        $valor = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $valor) ?? $valor;

        $caminhoExtraido = null;
        $posicaoBarra = strpos($valor, '/');
        if ($posicaoBarra !== false) {
            $caminhoExtraido = substr($valor, $posicaoBarra);
            $valor = substr($valor, 0, $posicaoBarra);
        }

        $valor = trim($valor, " \t\n\r\0\x0B/");
        if ($valor === '' || mb_strlen($valor) > 255) {
            throw new InvalidArgumentException('Host de origem inválido. Informe só o endereço do servidor (ex.: 203.0.113.10), sem "sftp://" e sem o caminho — o caminho vai no campo "Pasta de origem".');
        }

        $caminhoExtraido = $caminhoExtraido !== null && trim($caminhoExtraido) !== '' && trim($caminhoExtraido) !== '/' ? $caminhoExtraido : null;

        return [$valor, $caminhoExtraido];
    }

    /** @param array<string,mixed> $dados @return array{0:array<string,mixed>,1:string} */
    private function validar(array $dados, bool $senhaObrigatoria): array
    {
        $credorId = (int) ($dados['credor_id'] ?? 0);
        if ($credorId <= 0) {
            throw new InvalidArgumentException('Selecione um credor.');
        }
        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE id = :id LIMIT 1');
        $stmt->bindValue('id', $credorId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('Credor não encontrado.');
        }

        $nome = mb_substr(trim((string) ($dados['nome'] ?? '')), 0, 160);
        if ($nome === '') {
            throw new InvalidArgumentException('Informe o nome da rotina.');
        }

        $descricao = trim((string) ($dados['descricao'] ?? ''));
        $descricao = $descricao !== '' ? mb_substr($descricao, 0, 500) : null;

        $protocolo = mb_strtolower(trim((string) ($dados['protocolo'] ?? 'sftp')));
        if ($protocolo !== 'sftp') {
            throw new InvalidArgumentException('Protocolo não suportado. Somente SFTP por enquanto.');
        }

        $origemPorta = (int) ($dados['origem_porta'] ?? 22);
        if ($origemPorta < 1 || $origemPorta > 65535) {
            throw new InvalidArgumentException('Porta de origem inválida.');
        }

        $origemCaminho = trim((string) ($dados['origem_caminho'] ?? '/'));
        if ($origemCaminho === '') {
            $origemCaminho = '/';
        }

        $origemHostBruto = trim((string) ($dados['origem_host'] ?? ''));
        if ($origemHostBruto === '' || mb_strlen($origemHostBruto) > 255) {
            throw new InvalidArgumentException('Informe o host de origem.');
        }
        [$origemHost, $caminhoExtraido] = $this->normalizarHostOrigem($origemHostBruto);
        if ($caminhoExtraido !== null && $origemCaminho === '/') {
            $origemCaminho = $caminhoExtraido;
        }
        $origemCaminho = mb_substr($origemCaminho, 0, 500);

        $usuario = trim((string) ($dados['usuario'] ?? ''));
        if ($usuario === '' || mb_strlen($usuario) > 150) {
            throw new InvalidArgumentException('Informe o usuário de acesso ao SFTP.');
        }

        $senha = (string) ($dados['senha'] ?? '');
        if ($senhaObrigatoria && $senha === '') {
            throw new InvalidArgumentException('Informe a senha de acesso ao SFTP.');
        }

        $padraoArquivo = trim((string) ($dados['padrao_arquivo'] ?? ''));
        if ($padraoArquivo !== '') {
            if (mb_strlen($padraoArquivo) > 255 || @preg_match('/' . $padraoArquivo . '/', '') === false) {
                throw new InvalidArgumentException('Padrão de arquivo (regex) inválido.');
            }
        } else {
            $padraoArquivo = null;
        }

        $periodicidade = trim((string) ($dados['periodicidade'] ?? 'diario'));
        if (!in_array($periodicidade, ['diario', 'seg_sex', 'manual'], true)) {
            throw new InvalidArgumentException('Periodicidade inválida.');
        }

        $horario = trim((string) ($dados['horario'] ?? ''));
        if ($periodicidade === 'manual') {
            $horario = null;
        } else {
            if ($horario === '' || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $horario)) {
                throw new InvalidArgumentException('Informe o horário de execução (HH:MM).');
            }
            $horario = mb_substr($horario, 0, 5) . ':00';
        }

        return [[
            'credor_id' => $credorId,
            'nome' => $nome,
            'descricao' => $descricao,
            'protocolo' => $protocolo,
            'origem_host' => $origemHost,
            'origem_porta' => $origemPorta,
            'origem_caminho' => $origemCaminho,
            'usuario' => $usuario,
            'padrao_arquivo' => $padraoArquivo,
            'periodicidade' => $periodicidade,
            'horario' => $horario,
            'status' => 'pausada',
        ], $senha];
    }

    private function bindDadosSemSenha(\PDOStatement $stmt, array $dados): void
    {
        $stmt->bindValue('credor_id', $dados['credor_id'], PDO::PARAM_INT);
        $stmt->bindValue('nome', $dados['nome']);
        $stmt->bindValue('descricao', $dados['descricao'], $dados['descricao'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue('protocolo', $dados['protocolo']);
        $stmt->bindValue('origem_host', $dados['origem_host']);
        $stmt->bindValue('origem_porta', $dados['origem_porta'], PDO::PARAM_INT);
        $stmt->bindValue('origem_caminho', $dados['origem_caminho']);
        $stmt->bindValue('usuario', $dados['usuario']);
        $stmt->bindValue('padrao_arquivo', $dados['padrao_arquivo'], $dados['padrao_arquivo'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue('periodicidade', $dados['periodicidade']);
        $stmt->bindValue('horario', $dados['horario'], $dados['horario'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    }

    private function bindNullableInt(\PDOStatement $stmt, string $param, ?int $valor): void
    {
        if ($valor === null) {
            $stmt->bindValue($param, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($param, $valor, PDO::PARAM_INT);
        }
    }
}
