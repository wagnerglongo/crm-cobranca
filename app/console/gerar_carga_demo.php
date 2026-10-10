<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Cargas\Parsers\AcmePipeParser;

require_once __DIR__ . '/_bootstrap.php';

/**
 * Gera um arquivo de carga de demonstração no layout pipe do credor ACME
 * (55 colunas, o mesmo que AcmePipeParser valida) com devedores fictícios:
 * CPFs com dígito verificador válido, nomes, telefones, e-mails e endereços
 * inventados. Serve para popular um ambiente local sem nenhum dado real.
 *
 * Uso:
 *   php console/gerar_carga_demo.php [--quantidade=300] [--semente=42] [--saida=/caminho/arquivo.txt]
 *
 * Sem --saida, grava em STORAGE_CARGAS_PATH/inbox/acme, onde o
 * console/processar_acme_diario.php encontra o arquivo, importa e aplica.
 */

$opcoes = ['quantidade' => 300, 'semente' => 42, 'saida' => null];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(quantidade|semente|saida)=(.+)$/', $arg, $m) === 1) {
        $opcoes[$m[1]] = $m[1] === 'saida' ? $m[2] : max(1, (int) $m[2]);
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "Uso: php console/gerar_carga_demo.php [--quantidade=300] [--semente=42] [--saida=arquivo.txt]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Opção inválida: {$arg}\n");
        exit(2);
    }
}

mt_srand((int) $opcoes['semente']);

$hoje = new DateTimeImmutable('today');
$saida = $opcoes['saida'] ?? rtrim((string) Database::env('STORAGE_CARGAS_PATH', '/var/www/storage/cargas'), '/')
    . '/inbox/acme/ACMECOB_DEMO_' . $hoje->format('Ymd') . '.txt';

$nomes = ['ANA', 'BRUNO', 'CARLA', 'DIEGO', 'ELISA', 'FABIO', 'GABRIELA', 'HEITOR', 'ISABELA', 'JOAO', 'LARISSA', 'MARCOS', 'NATALIA', 'OTAVIO', 'PAULA', 'RAFAEL', 'SOFIA', 'THIAGO', 'VANESSA', 'YURI'];
$sobrenomes = ['ALMEIDA', 'BARBOSA', 'CARDOSO', 'DUARTE', 'ESTEVES', 'FERRAZ', 'GOMES', 'HENRIQUES', 'LIMA', 'MORAES', 'NOGUEIRA', 'OLIVEIRA', 'PEREIRA', 'QUEIROZ', 'ROCHA', 'SANTANA', 'TEIXEIRA', 'VIEIRA'];
$cidades = [['SAO PAULO', 'SP', '11'], ['CAMPINAS', 'SP', '19'], ['BELO HORIZONTE', 'MG', '31'], ['CURITIBA', 'PR', '41'], ['PORTO ALEGRE', 'RS', '51'], ['RECIFE', 'PE', '81'], ['GOIANIA', 'GO', '62'], ['SALVADOR', 'BA', '71']];
$produtos = ['CARTAO DE CREDITO', 'CREDITO PESSOAL', 'FINANCIAMENTO DE VEICULO', 'CONSIGNADO'];
$veiculos = [['FIAT', 'ARGO DRIVE 1.0'], ['VOLKSWAGEN', 'POLO TRACK'], ['CHEVROLET', 'ONIX LT'], ['HYUNDAI', 'HB20 COMFORT'], ['RENAULT', 'KWID ZEN']];
$filas = ['FILA PREVENTIVA', 'FILA 31-90', 'FILA 91-180', 'FILA 181+'];

$escolher = static fn (array $lista): mixed => $lista[mt_rand(0, count($lista) - 1)];
$data = static fn (DateTimeImmutable $d): string => $d->format('d/m/Y');
$moeda = static fn (float $v): string => number_format($v, 2, ',', '.');

/** CPF com dígitos verificadores válidos (gerado, não pertence a ninguém de propósito). */
$cpf = static function (): string {
    do {
        $base = array_map(static fn (): int => mt_rand(0, 9), range(1, 9));
    } while (count(array_unique($base)) === 1);
    foreach ([10, 11] as $peso) {
        $soma = 0;
        foreach ($base as $i => $digito) {
            $soma += $digito * ($peso - $i);
        }
        $base[] = ($soma * 10) % 11 % 10;
    }
    return implode('', $base);
};

$linhas = [implode('|', AcmePipeParser::EXPECTED_HEADER)];
$documentos = [];
for ($i = 1; $i <= $opcoes['quantidade']; $i++) {
    do {
        $documento = $cpf();
    } while (isset($documentos[$documento]));
    $documentos[$documento] = true;

    $sobrenome1 = $escolher($sobrenomes);
    do {
        $sobrenome2 = $escolher($sobrenomes);
    } while ($sobrenome2 === $sobrenome1);
    $nome = $escolher($nomes) . ' ' . $sobrenome1 . ' ' . $sobrenome2;
    [$cidade, $uf, $ddd] = $escolher($cidades);
    $produto = $escolher($produtos);
    $atraso = mt_rand(5, 900);
    $saldo = mt_rand(30000, 2500000) / 100;
    $pago = mt_rand(0, 3) === 0 ? round($saldo * mt_rand(5, 40) / 100, 2) : 0.0;
    $vencimento = $hoje->sub(new DateInterval('P' . $atraso . 'D'));
    [$marca, $modelo] = $produto === 'FINANCIAMENTO DE VEICULO' ? $escolher($veiculos) : ['', ''];
    $ano = $produto === 'FINANCIAMENTO DE VEICULO' ? (string) mt_rand(2015, 2024) : '';
    $email = strtolower(str_replace(' ', '.', $nome)) . '@exemplo.com.br';

    $campos = array_fill_keys(AcmePipeParser::EXPECTED_HEADER, '');
    $campos = array_replace($campos, [
        'DebtorProviderDescription' => 'ACME FINANCEIRA',
        'ProviderName' => 'LOTE DEMO ' . str_pad((string) (1 + intdiv($i - 1, 100)), 2, '0', STR_PAD_LEFT),
        'ProductDescription' => $produto,
        'Queuename' => $atraso <= 30 ? $filas[0] : ($atraso <= 90 ? $filas[1] : ($atraso <= 180 ? $filas[2] : $filas[3])),
        'Number' => $documento,
        'Name' => $nome,
        'DebtorStatus' => 'ATIVO',
        'Accountid' => (string) (500000 + $i),
        'DebtorId' => (string) (900000 + $i),
        'AccountNumber' => 'ACM' . str_pad((string) (10000000 + $i * 7), 8, '0', STR_PAD_LEFT),
        'AccountStatus' => 'EM COBRANCA',
        'CutOfdate' => $data($hoje),
        'Age' => (string) $atraso,
        'CurrentBalance' => $moeda($saldo),
        'TotalCollected' => $moeda($pago),
        'RegiOn' => $uf,
        'City' => $cidade,
        'DescricaoVeiculos' => $marca !== '' ? $marca . ' ' . $modelo : '',
        'Marca' => $marca,
        'Modelo' => $modelo,
        'AnoFabricacao' => $ano,
        'AnoModelo' => $ano,
        'CategoriaVeiculo' => $marca !== '' ? 'AUTOMOVEL' : '',
        'Vencimento' => $data($vencimento),
        'DataFila' => $data($hoje->sub(new DateInterval('P' . mt_rand(0, 20) . 'D'))),
        'DiasFila' => (string) mt_rand(0, 20),
        'CelularHot' => $ddd . '9' . mt_rand(10000000, 99999999),
        'FixoHot' => mt_rand(0, 1) === 1 ? $ddd . '3' . mt_rand(1000000, 9999999) : '',
        'IdadeDevedor' => (string) mt_rand(21, 75),
        'QuantidadeParcelasPagas' => (string) mt_rand(0, 12),
        'EmailHot' => $email,
        'PersonType' => 'F',
        'AccountDebtorName' => $nome,
    ]);
    $linhas[] = implode('|', $campos);
}

$diretorio = dirname($saida);
if (!is_dir($diretorio) && !mkdir($diretorio, 0775, true) && !is_dir($diretorio)) {
    fwrite(STDERR, "Não foi possível criar {$diretorio}\n");
    exit(1);
}
file_put_contents($saida, implode("\n", $linhas) . "\n");

echo 'Carga de demonstração gerada: ' . $saida . ' (' . $opcoes['quantidade'] . " contratos fictícios)\n";
echo "Próximo passo: php console/processar_acme_diario.php\n";
