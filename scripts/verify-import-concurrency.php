<?php

use App\Domain\Catalog\CatalogImportService;
use App\Models\CatalogImport;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\UploadedFile;

require __DIR__.'/import-test-connection.php';
$owner = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->firstOrFail();
$service = app(CatalogImportService::class);
$beforeProducts = Product::count();
$tag = strtoupper(bin2hex(random_bytes(5)));
$parallel = function (string $scenario, array $imports) use ($owner, $tag): array {
    $file = base_path(".runtime/import-concurrency-$scenario-$tag.json");
    file_put_contents($file, json_encode(['user_id' => $owner->id, 'imports' => $imports], JSON_THROW_ON_ERROR));
    $processes = [];
    foreach (['a', 'b'] as $worker) {
        $pipes = [];
        $processes[$worker] = proc_open([PHP_BINARY, __DIR__.'/import-concurrency-worker.php', $file, $worker], [0 => ['pipe', 'r'], 1 => ['file', $file.'.'.$worker.'.log', 'w'], 2 => ['file', $file.'.'.$worker.'.errors', 'w']], $pipes, base_path());
        if (! is_resource($processes[$worker])) {
            throw new RuntimeException('Could not start an independent worker.');
        }
        fclose($pipes[0]);
    }
    $deadline = microtime(true) + 20;
    while (! file_exists($file.'.a.ready') || ! file_exists($file.'.b.ready')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Workers did not reach barrier; inspect .runtime logs.');
        } usleep(10000);
    }
    file_put_contents($file.'.go', 'go');
    $results = [];
    foreach ($processes as $worker => $process) {
        if (proc_close($process) !== 0 || ! file_exists($file.'.'.$worker.'.result')) {
            throw new RuntimeException('Worker failed; inspect .runtime logs.');
        }
        $results[] = json_decode(file_get_contents($file.'.'.$worker.'.result'), true, flags: JSON_THROW_ON_ERROR);
    }
    echo "$scenario: ".json_encode($results, JSON_THROW_ON_ERROR)."\n";

    return $results;
};
$csv = fn ($prefix) => "name,sku,sale_price\nRace first,$prefix-A,1.00\nRace second,$prefix-B,2.00\n";
$same = $service->preview(UploadedFile::fake()->createWithContent('race-same.csv', $csv('SAME-'.$tag)), $owner);
$sameResults = $parallel('same-import', ['a' => $same->id, 'b' => $same->id]);
if ($sameResults[0]['status'] !== 200 || $sameResults[1]['status'] !== 200 || $sameResults[0]['ids'] !== $sameResults[1]['ids'] || ProductVariant::whereIn('sku', ['SAME-'.$tag.'-A', 'SAME-'.$tag.'-B'])->count() !== 2 || Product::count() !== $beforeProducts + 2) {
    throw new RuntimeException('Same import was duplicated or failed.');
}
echo "PASS: concurrent confirmation of one import returns identical product IDs.\n";
$first = $service->preview(UploadedFile::fake()->createWithContent('race-overlap-a.csv', $csv('OVERLAP-'.$tag)), $owner);
$second = $service->preview(UploadedFile::fake()->createWithContent('race-overlap-b.csv', $csv('OVERLAP-'.$tag)), $owner);
$overlap = $parallel('overlapping-imports', ['a' => $first->id, 'b' => $second->id]);
$statuses = array_column($overlap, 'status');
sort($statuses);
if ($statuses !== [200, 422] || ProductVariant::whereIn('sku', ['OVERLAP-'.$tag.'-A', 'OVERLAP-'.$tag.'-B'])->count() !== 2 || Product::count() !== $beforeProducts + 4) {
    throw new RuntimeException('Overlapping imports created duplicate or partial data.');
}
$states = CatalogImport::whereIn('id', [$first->id, $second->id])->pluck('status')->all();
sort($states);
if ($states !== ['committed', 'preview']) {
    throw new RuntimeException('Incorrect loser/winner import states.');
}
echo "PASS: overlapping imports yield one committed batch and one rejected preview, with no duplicates.\n";
echo "Synthetic concurrency fixtures retained only in bshop_testing_single; no reset or truncation.\n";
