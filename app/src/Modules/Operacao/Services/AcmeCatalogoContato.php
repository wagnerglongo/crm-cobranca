<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Services;

use Closure;
use Throwable;

/**
 * Tabelas de códigos de contato da ACME (status e tipos de telefone, endereço
 * e e-mail), com consulta SOB DEMANDA - decisão de projeto (2026-10-06): em vez
 * de checar todo dia, só quando chega da ACME um código que o CRM não
 * conhece, consulta as tabelas dela (ContactStatusInfo / ContactTypeInfo) e
 * atualiza a cópia local.
 *
 * Parte das tabelas validadas na API em 2026-10-06 (PADRAO); o que vier da
 * ACME depois fica em storage/cache/acme_catalogo_contato.json. No máximo uma
 * consulta por hora: se a ACME mandar um código que nem ela lista, não fica
 * martelando a API. Falha na consulta nunca quebra quem chamou.
 */
final class AcmeCatalogoContato
{
    public const PADRAO = [
        'status' => ['H' => 'Hot', 'O' => 'Correto', 'I' => 'Incorreto', 'P' => 'Não Confirmado', 'N' => 'Desconhecido'],
        'telefone' => [
            '1' => 'Residencial', '2' => 'Comercial', '3' => 'Fax', '4' => 'Celular', '6' => 'Vizinho', '9' => 'Parentes',
            '10' => 'Avalista', '11' => 'Advogado', '12' => 'Avalista Removido', '13' => 'Terceiro',
            '14' => 'Responsável/Interessado', '15' => 'Celular / Whats', '16' => 'Não Definido', '17' => 'Enriquecimento Externo',
        ],
        'endereco' => ['C' => 'Comercial', 'R' => 'Residencial', 'A' => 'Avalista', 'N' => 'Não Definido', 'E' => 'Endereço de Cobrança', 'F' => 'Refêrencias'],
        'email' => ['1' => 'Financiado', '2' => 'Terceiro', '3' => 'Comercial', '4' => 'Enriquecimento Externo', '11' => 'Advogado'],
    ];

    private const INTERVALO_MINIMO_SEGUNDOS = 3600;

    /** @var array{tabelas:array<string,array<string,string>>,consultado_em:int}|null */
    private ?array $estado = null;

    /**
     * @param (Closure(): array<string,array<string,string>>)|null $buscarNaAcme devolve as tabelas atuais da ACME
     *        (mesmas chaves de PADRAO); null = só a cópia local, sem consultar
     */
    public function __construct(private readonly string $arquivoCache, private readonly ?Closure $buscarNaAcme = null)
    {
    }

    /** Só a cópia local (tabelas padrão + o que já foi consultado), sem chamar a ACME. */
    public static function local(): self
    {
        return new self(dirname(__DIR__, 5) . '/storage/cache/acme_catalogo_contato.json');
    }

    /** Catálogo que consulta a ACME de verdade quando precisa. */
    public static function comApi(AcmeApiClient $client, ?string $arquivoCache = null): self
    {
        return new self(
            $arquivoCache ?? dirname(__DIR__, 5) . '/storage/cache/acme_catalogo_contato.json',
            static function () use ($client): array {
                $tabela = static function (array $itens, string $codigo, string $alias): array {
                    $mapa = [];
                    foreach ($itens as $item) {
                        $chave = strtoupper(trim((string) ($item[$codigo] ?? '')));
                        if ($chave !== '') {
                            $mapa[$chave] = trim((string) ($item[$alias] ?? ''));
                        }
                    }
                    return $mapa;
                };
                return [
                    'status' => $tabela($client->listarStatusContato(), 'Status', 'StatusAlias'),
                    'telefone' => $tabela($client->listarTiposContato(1), 'Type', 'TypeAlias'),
                    'endereco' => $tabela($client->listarTiposContato(2), 'Type', 'TypeAlias'),
                    'email' => $tabela($client->listarTiposContato(3), 'Type', 'TypeAlias'),
                ];
            }
        );
    }

    /** Descrição do código na cópia local (sem consultar a ACME). */
    public function descricao(string $tabela, mixed $codigo): ?string
    {
        $codigo = strtoupper(trim((string) $codigo));
        if ($codigo === '') {
            return null;
        }
        $descricao = $this->tabelas()[$tabela][$codigo] ?? null;
        return $descricao !== null && $descricao !== '' ? $descricao : null;
    }

    public function conhece(string $tabela, mixed $codigo): bool
    {
        return $this->descricao($tabela, $codigo) !== null;
    }

    /**
     * Se algum código não está na cópia local, consulta as tabelas da ACME
     * (uma vez, respeitando o intervalo mínimo) e atualiza a cópia.
     *
     * @param array<string,list<mixed>> $codigosPorTabela ex.: ['status' => ['I','X'], 'telefone' => [4, 18]]
     * @return bool true se consultou a ACME agora
     */
    public function garantirCodigos(array $codigosPorTabela): bool
    {
        $desconhecidos = [];
        foreach ($codigosPorTabela as $tabela => $codigos) {
            foreach ($codigos as $codigo) {
                if (trim((string) $codigo) !== '' && !$this->conhece($tabela, $codigo)) {
                    $desconhecidos[] = $tabela . ':' . strtoupper(trim((string) $codigo));
                }
            }
        }
        if ($desconhecidos === [] || $this->buscarNaAcme === null) {
            return false;
        }

        $estado = $this->estado();
        if (time() - $estado['consultado_em'] < self::INTERVALO_MINIMO_SEGUNDOS) {
            return false;
        }

        $estado['consultado_em'] = time();
        try {
            $novas = ($this->buscarNaAcme)();
            foreach (array_keys(self::PADRAO) as $tabela) {
                if (!empty($novas[$tabela]) && is_array($novas[$tabela])) {
                    $estado['tabelas'][$tabela] = $novas[$tabela] + $estado['tabelas'][$tabela];
                }
            }
            $aindaDesconhecidos = array_values(array_filter($desconhecidos, function (string $chave) use ($estado): bool {
                [$tabela, $codigo] = explode(':', $chave, 2);
                return trim((string) ($estado['tabelas'][$tabela][$codigo] ?? '')) === '';
            }));
            error_log('[acme-catalogo] tabelas da ACME consultadas por código desconhecido (' . implode(', ', $desconhecidos) . ')'
                . ($aindaDesconhecidos !== [] ? '; a ACME também não lista: ' . implode(', ', $aindaDesconhecidos) : ''));
        } catch (Throwable $e) {
            error_log('[acme-catalogo] falha ao consultar tabelas da ACME: ' . $e->getMessage());
        }

        $this->estado = $estado;
        $this->salvar($estado);
        return true;
    }

    /** @return array<string,array<string,string>> */
    private function tabelas(): array
    {
        return $this->estado()['tabelas'];
    }

    /** @return array{tabelas:array<string,array<string,string>>,consultado_em:int} */
    private function estado(): array
    {
        if ($this->estado !== null) {
            return $this->estado;
        }

        $estado = ['tabelas' => self::PADRAO, 'consultado_em' => 0];
        $conteudo = is_file($this->arquivoCache) ? @file_get_contents($this->arquivoCache) : false;
        $salvo = $conteudo !== false ? json_decode($conteudo, true) : null;
        if (is_array($salvo)) {
            foreach (array_keys(self::PADRAO) as $tabela) {
                if (isset($salvo['tabelas'][$tabela]) && is_array($salvo['tabelas'][$tabela])) {
                    $estado['tabelas'][$tabela] = array_map('strval', $salvo['tabelas'][$tabela]) + self::PADRAO[$tabela];
                }
            }
            $estado['consultado_em'] = (int) ($salvo['consultado_em'] ?? 0);
        }

        return $this->estado = $estado;
    }

    /** @param array{tabelas:array<string,array<string,string>>,consultado_em:int} $estado */
    private function salvar(array $estado): void
    {
        $dir = dirname($this->arquivoCache);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $this->arquivoCache . '.tmp.' . getmypid();
        $json = json_encode($estado + ['atualizado_em' => date('c')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json !== false && @file_put_contents($tmp, $json, LOCK_EX) !== false && !@rename($tmp, $this->arquivoCache)) {
            @unlink($tmp);
        }
    }
}
