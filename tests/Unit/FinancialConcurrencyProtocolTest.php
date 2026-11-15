<?php

namespace Tests\Unit;

use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FinancialConcurrencyProtocolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2).'/scripts/financial-concurrency-support.php';
    }

    public function test_windows_and_linux_result_lines_preserve_status_and_replay(): void
    {
        foreach (["\r\n", "\n"] as $newline) {
            $result = \FinancialConcurrencyOperations::parseWorkerResult('Protected QA connection'.$newline.'RESULT:{"status":200,"replay":true,"entity_id":7}'.$newline);
            $this->assertSame(['status' => 200, 'replay' => true, 'entity_id' => 7], $result);
        }
    }

    public static function invalidResults(): array
    {
        return [
            ['no result'],
            ["RESULT:{\"status\":200,\"replay\":false}\nRESULT:{\"status\":409,\"replay\":false}\n"],
            ['RESULT:{"status":"200","replay":false}'],
            ['RESULT:{"status":200}'],
        ];
    }

    #[DataProvider('invalidResults')]
    public function test_missing_ambiguous_or_untyped_results_fail_closed(string $output): void
    {
        $this->expectException(RuntimeException::class);
        \FinancialConcurrencyOperations::parseWorkerResult($output);
    }

    public function test_corrupt_result_json_is_not_treated_as_a_success(): void
    {
        $this->expectException(JsonException::class);
        \FinancialConcurrencyOperations::parseWorkerResult('RESULT:{invalid}');
    }

    public function test_invalid_run_tag_is_rejected_before_resolving_any_directory(): void
    {
        $this->expectException(RuntimeException::class);
        \FinancialConcurrencyOperations::directory('../outside');
    }
}
