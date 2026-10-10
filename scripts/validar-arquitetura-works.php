<?php

declare(strict_types=1);

// No host, o app fica em <raiz>/app. Dentro do container PHP, /var/www/html
// ja e a raiz do app. CRM_APP_ROOT permite executar este mesmo smoke nos dois.
$appRoot = getenv('CRM_APP_ROOT');
if (!is_string($appRoot) || trim($appRoot) === '') {
    $appRoot = dirname(__DIR__) . '/app';
}
$appRoot = rtrim($appRoot, '/');

if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null): string {
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}
require_once $appRoot . '/tests/bootstrap.php';

$errors = [];
$check = static function (bool $condition, string $message) use (&$errors): void {
    if ($condition) {
        echo "OK: {$message}\n";
        return;
    }
    $errors[] = $message;
    echo "ERRO: {$message}\n";
};

$repository = (new ReflectionClass(CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository::class))->newInstanceWithoutConstructor();
$cpf = $repository->normalizarBusca('cpf 529.982.247-25');
$check($cpf['tipo'] === 'documento' && $cpf['digitos'] === '52998224725', 'normalizacao CPF/CNPJ do Pactua');

$adapter = (new ReflectionClass(CRM\Modules\Operacao\Adapters\AcmeAcionamentoAdapter::class))->newInstanceWithoutConstructor();
$payload = $adapter->preparar(['payload_array' => [
    'pessoa' => ['documento' => '529.982.247-25'],
    'contrato' => ['codigo' => '9535240'],
    'resultado' => ['nome' => 'Nao atende'],
    'mapeamento' => ['codigo_externo' => '3'],
    'acionamento' => ['observacao' => 'teste', 'ocorrido_em_utc' => '2026-08-19T16:45:27.526Z'],
]]);
$check(($payload['ActionID'] ?? null) === 3 && ($payload['DocNumber'] ?? '') === '52998224725', 'payload ACME canonico');

$violations = [];
$src = $appRoot . '/src';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $source = file_get_contents($file->getPathname());
    if (!is_string($source) || !preg_match_all('/->prepare\((.*?)\);/s', $source, $matches, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($matches[1] as [$expr, $offset]) {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $expr, $placeholderMatches);
        $counts = array_count_values($placeholderMatches[1] ?? []);
        $duplicated = array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));
        if ($duplicated !== []) {
            $line = substr_count(substr($source, 0, (int) $offset), "\n") + 1;
            $violations[] = basename($file->getPathname()) . ':' . $line . ' [' . implode(',', $duplicated) . ']';
        }
    }
}
$check($violations === [], 'nenhum placeholder PDO nomeado duplicado (previne HY093)');
if ($violations !== []) {
    foreach ($violations as $violation) {
        echo "  - {$violation}\n";
    }
}

$check(is_file($appRoot . '/resources/views/works/tabs/acionamento.php'), 'view Acionamento extraida');
$check(is_file($appRoot . '/resources/views/works/tabs/localizacao.php'), 'view Localizacao extraida');
$check(is_file($appRoot . '/resources/views/works/tabs/financeiro.php'), 'view Financeiro extraida');

$missingStaticIncludes = [];
$worksViewsRoot = $appRoot . '/resources/views/works';
if (is_dir($worksViewsRoot)) {
    $viewIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($worksViewsRoot));
    foreach ($viewIterator as $viewFile) {
        if (!$viewFile->isFile() || $viewFile->getExtension() !== 'php') {
            continue;
        }
        $viewSource = file_get_contents($viewFile->getPathname());
        if (!is_string($viewSource)) {
            continue;
        }
        if (!preg_match_all(
            '/\b(?:require|require_once|include|include_once)\s+__DIR__\s*\.\s*([\'\"])([^\'\"]+)\1\s*;/',
            $viewSource,
            $includeMatches,
            PREG_OFFSET_CAPTURE
        )) {
            continue;
        }
        foreach ($includeMatches[2] as [$relativePath, $offset]) {
            $resolved = dirname($viewFile->getPathname()) . $relativePath;
            if (!is_file($resolved)) {
                $line = substr_count(substr($viewSource, 0, (int) $offset), "\n") + 1;
                $missingStaticIncludes[] = $viewFile->getFilename() . ':' . $line . ' => ' . $relativePath;
            }
        }
    }
}
$check($missingStaticIncludes === [], 'includes/requires estaticos das views Pactua apontam para arquivos existentes');
if ($missingStaticIncludes !== []) {
    foreach ($missingStaticIncludes as $missingStaticInclude) {
        echo "  - {$missingStaticInclude}\n";
    }
}
$check(is_file($appRoot . '/public/operacao/assets/works-core.js'), 'JavaScript core extraido');
$check(is_file($appRoot . '/public/operacao/assets/works-financeiro.js'), 'JavaScript Financeiro extraido');
$check(is_file($appRoot . '/public/operacao/assets/works-acionamento.js'), 'JavaScript Acionamento extraido');

exit($errors === [] ? 0 : 1);
