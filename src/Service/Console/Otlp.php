<?php
declare(strict_types=1);

namespace Ovos\Service\Console;

use function array_filter;
use function array_is_list;
use function array_values;
use function explode;
use function implode;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function strtotime;

/**
 * Maps the Sender's finished v1 payload batch onto an OTLP/JSON
 * ExportLogsServiceRequest — for posting to an OpenTelemetry Collector
 * (console.otlp_url), which forwards to the console's OTLP intake (the
 * records land as type otel) and to whatever else its pipelines feed.
 *
 * Pure mapping, no transport: the Sender decides where the encoded
 * request goes. Losses vs the direct ingest, inherent to OTLP: one
 * flattened stacktrace per record (chained causes become "Caused by:"
 * sections), no source-code windows (the events[].code blocks cannot
 * travel), and request variables survive only as unindexed extra.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class Otlp
{
	/**
	 * syslog priority -> OTLP severityNumber band (higher = more severe
	 * there); the console maps the bands back onto 2/3/4/6/7 — 0/1
	 * collapse into critical and 5 into info on the round trip
	 */
	protected const array SEVERITY = [
		0 => 21,
		1 => 21,
		2 => 21,
		3 => 17,
		4 => 13,
		5 => 9,
		6 => 9,
		7 => 5,
	];
	
	protected const array SEVERITY_TEXT = [
		0 => 'EMERGENCY',
		1 => 'ALERT',
		2 => 'CRITICAL',
		3 => 'ERROR',
		4 => 'WARNING',
		5 => 'NOTICE',
		6 => 'INFO',
		7 => 'DEBUG',
	];
	
	/**
	 * The Sender's flushed batch (v1 payloads WITH type/context/release)
	 * as one ExportLogsServiceRequest. Host, release and CLI args are
	 * constant within a request/run, so the first payload provides the
	 * resource.
	 *
	 * @param array[] $errors
	 */
	public static function request(
		array $errors,
	): array
	{
		$first = $errors[0] ?? [];
		$context = is_array($first['context'] ?? null) ? $first['context'] : [];
		
		$resource = [
			self::attr('telemetry.sdk.name', 'ovos-php-library'),
			self::attr('telemetry.sdk.language', 'php'),
			self::attr('service.name', (string)($context['host'] ?? '')),
		];
		
		if(($first['release'] ?? '') !== '')
		{
			$resource[] = self::attr('service.version', (string)$first['release']);
		}
		
		if(is_array($context['args'] ?? null) && $context['args'] !== [])
		{
			$resource[] = self::attr('process.command_args', $context['args']);
		}
		
		$records = [];
		foreach($errors as $payload)
		{
			if(is_array($payload))
			{
				$records[] = self::record($payload);
			}
		}
		
		return ['resourceLogs' => [[
			'resource' => ['attributes' => array_values(array_filter($resource))],
			'scopeLogs' => [[
				'scope' => ['name' => 'ovos-php-library'],
				'logRecords' => $records,
			]],
		]]];
	}
	
	/**
	 * One v1 payload -> one OTLP LogRecord, using the semconv attributes
	 * the console's OTLP intake maps back onto row fields
	 */
	protected static function record(
		array $payload,
	): array
	{
		$context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
		$events = is_array($payload['events'] ?? null) ? $payload['events'] : [];
		$first = is_array($events[0] ?? null) ? $events[0] : [];
		
		$attributes = [];
		
		if(($first['className'] ?? '') !== '')
		{
			$attributes[] = self::attr('exception.type', (string)$first['className']);
		}
		
		if(($first['message'] ?? '') !== '')
		{
			$attributes[] = self::attr('exception.message', (string)$first['message']);
		}
		
		$stacktrace = self::stacktrace($events);
		if($stacktrace !== '')
		{
			$attributes[] = self::attr('exception.stacktrace', $stacktrace);
		}
		
		if(($first['file'] ?? '') !== '')
		{
			$attributes[] = self::attr('code.file.path', (string)$first['file']);
		}
		
		if(is_numeric($first['line'] ?? null) && (int)$first['line'] > 0)
		{
			$attributes[] = self::attr('code.line.number', (int)$first['line']);
		}
		
		$uri = (string)($context['uri'] ?? '');
		if($uri !== '')
		{
			$parts = explode('?', $uri, 2);
			$attributes[] = self::attr('url.path', $parts[0]);
			if(($parts[1] ?? '') !== '')
			{
				$attributes[] = self::attr('url.query', $parts[1]);
			}
		}
		
		foreach([
			'http.request.method' => 'method',
			'client.address' => 'ip',
			'user_agent.original' => 'ua',
			'http.request.header.referer' => 'referer',
			'session.id' => 'sessionId',
			'user.id' => 'userId',
			'dir' => 'dir',
		] as $attribute => $key)
		{
			if(($context[$key] ?? '') !== '')
			{
				$attributes[] = self::attr($attribute, (string)$context[$key]);
			}
		}
		
		if(is_array($context['request'] ?? null) && $context['request'] !== [])
		{
			$attributes[] = self::attr('request', $context['request']);
		}
		
		// caller extras — one attribute each; unmapped attributes spill
		// back into the console's context.extra verbatim
		if(is_array($context['extra'] ?? null))
		{
			foreach($context['extra'] as $key => $value)
			{
				$attributes[] = self::attr((string)$key, $value);
			}
		}
		
		$priority = (int)($payload['priority'] ?? 3);
		
		$record = [
			'severityNumber' => self::SEVERITY[$priority] ?? 17,
			'severityText' => self::SEVERITY_TEXT[$priority] ?? 'ERROR',
			'body' => ['stringValue' => (string)($payload['message'] ?? '')],
			'attributes' => array_values(array_filter($attributes)),
		];
		
		$timestamp = strtotime((string)($payload['timestamp'] ?? ''));
		if($timestamp > 0)
		{
			// string math — the console reads the int64 nanosecond string
			$record['timeUnixNano'] = $timestamp . '000000000';
		}
		
		if(($context['traceId'] ?? '') !== '')
		{
			$record['traceId'] = (string)$context['traceId'];
		}
		
		return $record;
	}
	
	/**
	 * One stacktrace string per OTLP record — chained causes are appended
	 * as "Caused by:" sections, the way OTEL SDK log appenders flatten them
	 *
	 * @param array[] $events
	 */
	protected static function stacktrace(
		array $events,
	): string
	{
		$parts = [];
		
		foreach($events as $event)
		{
			if(is_array($event) === false)
			{
				continue;
			}
			
			$trace = (string)($event['backtrace'] ?? '');
			if(($event['previous'] ?? false) === true)
			{
				$trace = 'Caused by: ' . (string)($event['className'] ?? '')
					. ': ' . (string)($event['message'] ?? '')
					. ($trace !== '' ? "\n" . $trace : '');
			}
			
			if($trace !== '')
			{
				$parts[] = $trace;
			}
		}
		
		return implode("\n\n", $parts);
	}
	
	/**
	 * OTLP KeyValue, or null (filtered out) when the value has no
	 * AnyValue encoding
	 */
	protected static function attr(
		string $key,
		mixed $value,
	): ?array
	{
		$encoded = self::anyValue($value);
		
		return $encoded === null ? null : ['key' => $key, 'value' => $encoded];
	}
	
	/**
	 * PHP value -> OTLP AnyValue (int64 as string per OTLP/JSON),
	 * depth-capped; null for unsupported shapes
	 */
	protected static function anyValue(
		mixed $value,
		int $depth = 0,
	): ?array
	{
		if(is_string($value))
		{
			return ['stringValue' => $value];
		}
		
		if(is_bool($value))
		{
			return ['boolValue' => $value];
		}
		
		if(is_int($value))
		{
			return ['intValue' => (string)$value];
		}
		
		if(is_float($value))
		{
			return is_finite($value)
				? ['doubleValue' => $value]
				: ['stringValue' => (string)$value];
		}
		
		if(is_array($value) === false || $depth >= 6)
		{
			return null;
		}
		
		if(array_is_list($value))
		{
			$values = [];
			foreach($value as $item)
			{
				$encoded = self::anyValue($item, $depth + 1);
				if($encoded !== null)
				{
					$values[] = $encoded;
				}
			}
			
			return ['arrayValue' => ['values' => $values]];
		}
		
		$pairs = [];
		foreach($value as $key => $item)
		{
			$encoded = self::anyValue($item, $depth + 1);
			if($encoded !== null)
			{
				$pairs[] = ['key' => (string)$key, 'value' => $encoded];
			}
		}
		
		return ['kvlistValue' => ['values' => $pairs]];
	}
}
