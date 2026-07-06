<?php
declare(strict_types=1);

namespace Ovos\Session;

use Ovos\ArrayObject;
use Ovos\Cache\Prefixer;
use Ovos\Connection\RedisCommon as Connection;
use Ovos\Exception;
use Ovos\Session\Handler\RedisJson;
use ArrayObject as BaseArrayObject;
use Redis as RedisClient;
use RedisCluster as RedisClusterClient;
use RedisException;
use RedisClusterException;

use function count;
use function explode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function stripos;
use function strlen;
use function strtoupper;
use function substr;

/**
 * Index
 *
 * A RediSearch index over the JSON session documents with a per-project
 * schema: the "index.fields" config maps field aliases to document paths
 * and index types, so every project decides what is searchable and how:
 *
 *   index:
 *     enabled: yes
 *     fields:
 *       authenticated: { path: console_auth.authenticated, type: tag }
 *       created: { path: __meta.created, type: numeric, sortable: yes }
 *       email: { path: user.email, type: text }
 *
 * RediSearch only indexes DATABASE 0 - the session connection must live
 * there, ensure() refuses any other database.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Index
{
	// Field types
	public const string TYPE_TEXT = 'TEXT';
	public const string TYPE_TAG = 'TAG';
	public const string TYPE_NUMERIC = 'NUMERIC';
	
	public const array TYPES = [
		self::TYPE_TEXT,
		self::TYPE_TAG,
		self::TYPE_NUMERIC,
	];
	
	protected Connection $connection;
	
	protected string $prefix;
	
	protected ArrayObject $config;
	
	protected string $name;
	
	/**
	 * The index existence is verified once per process
	 */
	protected bool $ensured = false;
	
	public function __construct(
		Connection $connection,
		string $prefix,
		ArrayObject $config,
	)
	{
		$this->connection = $connection;
		$this->prefix = $prefix;
		$this->config = $config;
		
		$this->name = (string)($config->offsetGet('name')
			?? $prefix . ':index');
	}
	
	public function getName(): string
	{
		return $this->name;
	}
	
	public function getClient(): RedisClient|RedisClusterClient|null
	{
		return $this->connection->getClient();
	}
	
	/**
	 * Creates the index when missing (redis indexes the existing
	 * documents in the background after creation)
	 */
	public function ensure(
		bool $force = false,
	): bool
	{
		if($this->ensured === true && $force === false)
		{
			return true;
		}
		
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		// RediSearch refuses every database but 0
		$database = (int)($this->connection->getConfig()->database ?? 0);
		if($database !== 0)
		{
			throw new Exception(
				'RediSearch only indexes database 0, the session connection uses database "'
					. $database . '".');
		}
		
		if($force === false && ($info = $this->info()) !== null)
		{
			// present, but built from the CURRENT config? an edited
			// "index.fields" must not leave searches quietly running
			// against the old shape - drop and recreate instead (redis
			// re-indexes the documents in the background)
			if($this->matchesSchema($info) === true)
			{
				$this->ensured = true;
				
				return true;
			}
			
			$this->drop();
		}
		
		// phpredis surfaces module error replies as exceptions or via
		// getLastError(), depending on the build - handle both
		$error = null;
		try
		{
			$client->clearLastError();
			$client->rawCommand('FT.CREATE', $this->name,
				'ON', 'JSON',
				'PREFIX', '1', $this->prefix . Prefixer::SEPARATOR_PREFIX,
				'SCHEMA', ...$this->schema(),
			);
			$error = $client->getLastError();
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$error = $exception->getMessage();
		}
		
		if($error !== null)
		{
			$client->clearLastError();
			
			// a parallel process won the creation race - fine
			if(stripos($error, 'already exists') === false)
			{
				throw new Exception($error);
			}
		}
		
		$this->ensured = true;
		
		return true;
	}
	
	public function exists(): bool
	{
		return $this->info() !== null;
	}
	
	/**
	 * The FT.INFO reply as a flat RESP2 map, null for a missing index
	 */
	protected function info(): ?array
	{
		if(($client = $this->getClient()) === null)
		{
			return null;
		}
		
		try
		{
			$client->clearLastError();
			$info = $client->rawCommand('FT.INFO', $this->name);
		}
		catch(RedisException|RedisClusterException)
		{
			return null;
		}
		
		if($client->getLastError())
		{
			$client->clearLastError();
			
			return null;
		}
		
		return is_array($info) === true ? $info : null;
	}
	
	/**
	 * Whether the LIVE index was built from the configured schema -
	 * compares every field's path, type and sortability by alias
	 */
	protected function matchesSchema(
		array $info,
	): bool
	{
		$attributes = $this->plucked($info, 'attributes');
		if(is_array($attributes) === false)
		{
			return false;
		}
		
		$live = [];
		foreach($attributes as $attribute)
		{
			if(is_array($attribute) === false)
			{
				continue;
			}
			
			$live[(string)$this->plucked($attribute, 'attribute')] = [
				'path' => (string)$this->plucked($attribute, 'identifier'),
				'type' => strtoupper((string)$this->plucked($attribute, 'type')),
				// the flag arrives as a bare token in the flat reply
				'sortable' => in_array('SORTABLE', $attribute, true),
			];
		}
		
		$fields = $this->fields();
		if(count($live) !== count($fields))
		{
			return false;
		}
		
		foreach($fields as $alias => $field)
		{
			if(($live[$alias] ?? null) !== $field)
			{
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * The value following a field name in a flat RESP2 map reply
	 */
	protected function plucked(
		array $reply,
		string $field,
	): mixed
	{
		foreach($reply as $index => $entry)
		{
			if(is_int($index) === true
				&& $entry === $field)
			{
				return $reply[$index + 1] ?? null;
			}
		}
		
		return null;
	}
	
	public function drop(): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		try
		{
			$client->clearLastError();
			$client->rawCommand('FT.DROPINDEX', $this->name);
			$dropped = $client->getLastError() === null;
		}
		catch(RedisException|RedisClusterException)
		{
			$dropped = false;
		}
		
		$client->clearLastError();
		$this->ensured = false;
		
		return $dropped;
	}
	
	/**
	 * Searches the session documents; returns the total and the matching
	 * documents keyed by session id
	 * A vanished index (FLUSHDB, manual drop) self-heals: it is recreated
	 * once and the search retried
	 */
	public function search(
		string $query,
		int $limit = 10,
		int $offset = 0,
		?string $sortBy = null, // requires "sortable: yes" on the field
		bool $ascending = true,
	): array
	{
		return $this->parse(
			$this->call('FT.SEARCH',
				$this->arguments($query, $limit, $offset, $sortBy, $ascending))
		);
	}
	
	/**
	 * The matching session ids only (NOCONTENT) - the lean variant for
	 * result sets whose documents are not needed; returns the total and
	 * the ids
	 */
	public function searchIds(
		string $query,
		int $limit = 10,
		int $offset = 0,
		?string $sortBy = null, // requires "sortable: yes" on the field
		bool $ascending = true,
	): array
	{
		$reply = $this->call('FT.SEARCH',
			$this->arguments($query, $limit, $offset, $sortBy, $ascending,
				idsOnly: true));
				
		$ids = [];
		$replySize = count($reply);
		for($entry = 1; $entry < $replySize; $entry++)
		{
			$ids[] = $this->sessionId((string)$reply[$entry]);
		}
		
		return [
			'total' => (int)($reply[0] ?? 0),
			'ids' => $ids,
		];
	}
	
	/**
	 * The FT.SEARCH argument tail shared by the search variants
	 */
	protected function arguments(
		string $query,
		int $limit,
		int $offset,
		?string $sortBy,
		bool $ascending,
		bool $idsOnly = false,
	): array
	{
		$arguments = [$query];
		if($sortBy !== null)
		{
			$arguments[] = 'SORTBY';
			$arguments[] = $sortBy;
			$arguments[] = $ascending === true ? 'ASC' : 'DESC';
		}
		if($idsOnly === true)
		{
			$arguments[] = 'NOCONTENT';
		}
		$arguments[] = 'LIMIT';
		$arguments[] = $offset;
		$arguments[] = $limit;
		
		return $arguments;
	}
	
	/**
	 * The session id within a document key - everything after the prefix
	 */
	protected function sessionId(
		string $key,
	): string
	{
		return substr($key,
			strlen($this->prefix) + strlen(Prefixer::SEPARATOR_PREFIX));
	}
	
	/**
	 * How many session documents match the query
	 */
	public function count(
		string $query,
	): int
	{
		$reply = $this->call('FT.SEARCH', [$query, 'LIMIT', 0, 0]);
		
		return (int)($reply[0] ?? 0);
	}
	
	protected function call(
		string $command,
		array $arguments,
	): array
	{
		$this->ensure();
		
		if(($client = $this->getClient()) === null)
		{
			return [];
		}
		
		foreach($arguments as $index => $argument)
		{
			$arguments[$index] = (string)$argument;
		}
		
		$reply = $this->raw($client, $command, $arguments, $error);
		
		if($error !== null)
		{
			// the index vanished behind our back - recreate and retry once
			if($this->isMissingIndexError($error) === false)
			{
				throw new Exception($error);
			}
			
			$this->ensure(true);
			
			$reply = $this->raw($client, $command, $arguments, $error);
			if($error !== null)
			{
				throw new Exception($error);
			}
		}
		
		return is_array($reply) === true ? $reply : [];
	}
	
	/**
	 * One rawCommand round trip with both of phpredis' module-error
	 * transports unified into $error (this build THROWS on module error
	 * replies, others only set lastError)
	 */
	protected function raw(
		RedisClient|RedisClusterClient $client,
		string $command,
		array $arguments,
		mixed &$error,
	): mixed
	{
		$error = null;
		$reply = false;
		try
		{
			$client->clearLastError();
			$reply = $client->rawCommand($command, $this->name, ...$arguments);
			$error = $client->getLastError();
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$error = $exception->getMessage();
		}
		
		if($error !== null)
		{
			$client->clearLastError();
		}
		
		return $reply;
	}
	
	/**
	 * The missing-index wording varies across RediSearch versions
	 */
	protected function isMissingIndexError(
		string $error,
	): bool
	{
		return stripos($error, 'no such index') !== false
			|| stripos($error, 'unknown index') !== false
			|| stripos($error, 'index not found') !== false;
	}
	
	/**
	 * An FT.SEARCH reply: [total, key, [field, value, ...], key, ...];
	 * with ON JSON the whole document arrives as the "$" field
	 */
	protected function parse(
		array $reply,
	): array
	{
		$total = (int)($reply[0] ?? 0);
		$sessions = [];
		
		$replySize = count($reply);
		for($entry = 1; $entry + 1 < $replySize; $entry+= 2)
		{
			$sessionId = $this->sessionId((string)$reply[$entry]);
			
			$document = null;
			$fields = $reply[$entry + 1];
			if(is_array($fields) === true)
			{
				$fieldsSize = count($fields);
				for($field = 0; $field + 1 < $fieldsSize; $field+= 2)
				{
					if($fields[$field] === '$')
					{
						$document = json_decode(
							(string)$fields[$field + 1], true);
						
						break;
					}
				}
			}
			
			$sessions[$sessionId] = $document;
		}
		
		return [
			'total' => $total,
			'sessions' => $sessions,
		];
	}
	
	/**
	 * The normalized per-project fields config: alias => path/type/
	 * sortable - the single shape behind both the FT.CREATE schema and
	 * the live-index drift comparison
	 */
	protected function fields(): array
	{
		$config = $this->config->offsetGet('fields');
		if($config === null)
		{
			throw new Exception(
				'"index.fields" config section is missing.');
		}
		
		$fields = [];
		foreach($config as $alias => $field)
		{
			// iteration yields raw values - nested config arrays are only
			// converted to ArrayObjects by offsetGet()
			if($field instanceof BaseArrayObject === false)
			{
				$field = new ArrayObject((array)$field);
			}
			
			$path = $field->offsetGet('path');
			if($path instanceof BaseArrayObject)
			{
				$path = $path->getArrayCopy();
			}
			if(is_string($path) === true)
			{
				$path = explode('.', $path);
			}
			
			$type = strtoupper((string)$field->offsetGet('type'));
			if(in_array($type, self::TYPES, true) === false)
			{
				throw new Exception(
					'Unsupported session index type "' . $type
						. '" for field "' . $alias . '".');
			}
			
			$fields[(string)$alias] = [
				'path' => RedisJson::jsonPath((array)$path),
				'type' => $type,
				'sortable' => $field->offsetGet('sortable') === true,
			];
		}
		
		if($fields === [])
		{
			throw new Exception(
				'"index.fields" config section is empty.');
		}
		
		return $fields;
	}
	
	/**
	 * Builds the FT.CREATE schema from the per-project fields config
	 */
	protected function schema(): array
	{
		$schema = [];
		foreach($this->fields() as $alias => $field)
		{
			$schema[] = $field['path'];
			$schema[] = 'AS';
			$schema[] = $alias;
			$schema[] = $field['type'];
			
			if($field['sortable'] === true)
			{
				$schema[] = 'SORTABLE';
			}
		}
		
		return $schema;
	}
}
