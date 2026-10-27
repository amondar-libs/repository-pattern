<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\DB;
use Tests\_fixtures\RepositoryCacheTestCase;

pest()->extend(RepositoryCacheTestCase::class);

dataset('repository call modes', ['direct call' => ['direct'], 'cache miss' => ['miss'], 'cache hit' => ['hit']]);

// Compare identical COUNT reads. Warmup and forced misses are outside timing;
// assert query counts and results, never hardware-dependent speed thresholds.
it('measures repository read paths', function (string $mode): void {
    // -------- Arrange
    for ($index = 0; $index < 20; $index++) {
        $this->createUser();
    }
    $warmupResults = [];
    for ($index = 0; $index < 10; $index++) {
        $warmupResults[] = $this->repository->userCount('Oleg Sereda');
    }
    $this->repository->cache->userCount('Oleg Sereda');
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    $iterations = 100;
    $batches = [];
    $results = [];

    // -------- Act
    for ($batch = 0; $batch < 5; $batch++) {
        $elapsed = 0;
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            if ($mode === 'miss') {
                $this->repository->cache->forget();
            }
            $start = hrtime(true);
            $result = $mode === 'direct'
                ? $this->repository->userCount('Oleg Sereda')
                : $this->repository->cache->userCount('Oleg Sereda');
            $elapsed += hrtime(true) - $start;
            $results[] = $result;
        }
        $batches[] = $elapsed / $iterations / 1000;
    }
    sort($batches);
    fwrite(STDERR, sprintf(
        "\nCache measurement [%s]: median %.2f us/call; batch range %.2f-%.2f us/call; %d calls; %d SQL queries; serialized ArrayStore + SQLite\n",
        $mode,
        $batches[2],
        min($batches),
        max($batches),
        $iterations * 5,
        $queries
    ));

    // -------- Assert
    expect($warmupResults)->each->toBe(20);
    expect($results)->each->toBe(20);
    expect($queries)->toBe($mode === 'hit' ? 0 : $iterations * 5)
        ->and($batches[2])->toBeGreaterThan(0);
})->with('repository call modes')->group('measurement');
