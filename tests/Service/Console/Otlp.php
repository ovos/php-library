<?php
declare(strict_types=1);

namespace Tests\Service\Console;

use Ovos\Service\Console\Otlp as ConsoleOtlp;
use Ovos\Test;

use function array_column;
use function array_combine;
use function count;
use function date;
use function str_contains;

/**
 * Otlp — the Sender's v1 payload batch as an OTLP/JSON
 * ExportLogsServiceRequest: resource from the first payload, semconv
 * attributes the console's OTLP intake maps back onto row fields, the
 * chained-exception flattening and the severity band mapping.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Otlp extends Test
{
	public function mapsPayloadOntoLogRecord(): bool
	{
		$request = ConsoleOtlp::request([$this->payload()]);
		
		$log = $request['resourceLogs'][0];
		$resource = $this->attributes($log['resource']['attributes']);
		$record = $log['scopeLogs'][0]['logRecords'][0];
		$attributes = $this->attributes($record['attributes']);
		
		return $resource['telemetry.sdk.language'] === ['stringValue' => 'php']
			&& $resource['service.name'] === ['stringValue' => 'shop.example.at']
			&& $resource['service.version'] === ['stringValue' => 'r-2026']
			&& $resource['deployment.environment.name'] === ['stringValue' => 'staging']
			&& $record['severityNumber'] === 17
			&& $record['severityText'] === 'ERROR'
			&& $record['body'] === ['stringValue' => 'boom']
			&& $record['traceId'] === 'abababababababababababababababab'
			&& $attributes['exception.type'] === ['stringValue' => 'RuntimeException']
			&& $attributes['code.file.path'] === ['stringValue' => '/app/src/Db.php']
			&& $attributes['code.line.number'] === ['intValue' => '42']
			&& $attributes['url.path'] === ['stringValue' => '/checkout']
			&& $attributes['url.query'] === ['stringValue' => 'step=2']
			&& $attributes['http.request.method'] === ['stringValue' => 'POST']
			&& $attributes['client.address'] === ['stringValue' => '203.0.113.7'];
	}
	
	public function flattensExceptionChainIntoStacktrace(): bool
	{
		$payload = $this->payload();
		$payload['events'][] = [
			'message' => 'root cause',
			'className' => 'LogicException',
			'file' => '/app/src/Inner.php',
			'line' => 7,
			'backtrace' => '#0 inner',
			'previous' => true,
		];
		
		$record = ConsoleOtlp::request([$payload])['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
		$stacktrace = $this->attributes($record['attributes'])['exception.stacktrace']['stringValue'];
		
		return str_contains($stacktrace, '#0 /app/src/Db.php(42)')
			&& str_contains($stacktrace, 'Caused by: LogicException: root cause')
			&& str_contains($stacktrace, '#0 inner');
	}
	
	public function mapsSeverityBands(): bool
	{
		$expected = [0 => 21, 2 => 21, 3 => 17, 4 => 13, 5 => 9, 6 => 9, 7 => 5];
		
		foreach($expected as $priority => $severity)
		{
			$payload = $this->payload();
			$payload['priority'] = $priority;
			
			$record = ConsoleOtlp::request([$payload])['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
			if($record['severityNumber'] !== $severity)
			{
				return false;
			}
		}
		
		return true;
	}
	
	public function spillsRequestAndExtrasAsAttributes(): bool
	{
		$record = ConsoleOtlp::request([$this->payload()])['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
		$attributes = $this->attributes($record['attributes']);
		
		return $attributes['request']['kvlistValue']['values'][0]['key'] === 'post'
			&& $attributes['orderId'] === ['intValue' => '4711']
			&& $attributes['dir'] === ['stringValue' => '/var/www/app'];
	}
	
	public function cliArgsBecomeResourceCommandArgs(): bool
	{
		$payload = $this->payload();
		$payload['type'] = 'cli';
		$payload['context']['args'] = ['cli.php', 'import', 'run'];
		
		$resource = $this->attributes(
			ConsoleOtlp::request([$payload])['resourceLogs'][0]['resource']['attributes']);
		
		return $resource['process.command_args']['arrayValue']['values'][1]
			=== ['stringValue' => 'import'];
	}
	
	public function timestampBecomesNanosecondString(): bool
	{
		$payload = $this->payload();
		$payload['timestamp'] = date('c', 1720000000);
		
		$record = ConsoleOtlp::request([$payload])['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
		
		$missing = $this->payload();
		unset($missing['timestamp']);
		$bare = ConsoleOtlp::request([$missing])['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
		
		return $record['timeUnixNano'] === '1720000000000000000'
			&& isset($bare['timeUnixNano']) === false;
	}
	
	public function survivesGarbage(): bool
	{
		$empty = ConsoleOtlp::request([]);
		$junk = ConsoleOtlp::request([['message' => 'bare'], 'not a payload']);
		
		$records = $junk['resourceLogs'][0]['scopeLogs'][0]['logRecords'];
		
		return $empty['resourceLogs'][0]['scopeLogs'][0]['logRecords'] === []
			&& count($records) === 1
			&& $records[0]['body'] === ['stringValue' => 'bare']
			&& $records[0]['severityNumber'] === 17;
	}
	
	/**
	 * A complete flushed payload, as the Sender hands it to the mapper
	 */
	protected function payload(): array
	{
		return [
			'v' => 1,
			'type' => 'http',
			'priority' => 3,
			'timestamp' => date('c'),
			'release' => 'r-2026',
			'environment' => 'staging',
			'message' => 'boom',
			'events' => [[
				'message' => 'boom',
				'className' => 'RuntimeException',
				'file' => '/app/src/Db.php',
				'line' => 42,
				'backtrace' => '#0 /app/src/Db.php(42): PDO->query()',
				'previous' => false,
			]],
			'context' => [
				'dir' => '/var/www/app',
				'traceId' => 'abababababababababababababababab',
				'host' => 'shop.example.at',
				'uri' => '/checkout?step=2',
				'method' => 'POST',
				'ip' => '203.0.113.7',
				'ua' => 'Mozilla/5.0',
				'request' => ['post' => ['step' => '2']],
				'extra' => ['orderId' => 4711],
			],
		];
	}
	
	/**
	 * KeyValue list -> [key => AnyValue] for readable assertions
	 */
	protected function attributes(
		array $list,
	): array
	{
		return array_combine(
			array_column($list, 'key'),
			array_column($list, 'value'),
		);
	}
}
