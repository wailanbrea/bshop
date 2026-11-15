<?php

use App\Domain\Catalog\CatalogImportService;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/import-test-connection.php';
$file = $argv[1] ?? '';
$worker = $argv[2] ?? '';
if (realpath(dirname($file)) !== realpath(base_path('.runtime')) || ! in_array($worker, ['a', 'b'], true)) {
    throw new RuntimeException('Invalid import concurrency fixture.');
}
$data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
$owner = User::findOrFail($data['user_id']);
file_put_contents($file.'.'.$worker.'.ready', 'ready');
$deadline = microtime(true) + 20;
while (! file_exists($file.'.go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Start barrier timed out.');
    } usleep(10000);
}
try {
    $outcome = app(CatalogImportService::class)->confirm($data['imports'][$worker], $owner);
    $result = ['status' => $outcome['invalid'] ? 422 : 200, 'ids' => array_column($outcome['batch']->result['products'] ?? [], 'id')];
} catch (ValidationException) {
    $result = ['status' => 422, 'ids' => []];
} catch (HttpExceptionInterface $e) {
    $result = ['status' => $e->getStatusCode(), 'ids' => []];
}
file_put_contents($file.'.'.$worker.'.result', json_encode($result, JSON_THROW_ON_ERROR));
echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
