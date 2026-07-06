--[[
	Redis JSON Session Functions

	A session is a single RedisJSON document; values are read and written
	at nested paths, so parallel requests only touch what they use.
	All writes slide the session expiration (ttl_ms) and stamp the document
	creation time into a reserved "__meta" object on first write.

	Write functions accept the value's foreign lock keys after the document
	key and refuse to write while one is held, returning a "__locked__:<n>"
	marker instead (n = the 0-based index into the lock keys) - the caller
	waits for that lock and retries. On a Redis Cluster every key passed to
	a function must share the document's hash slot (hashtag prefixes).

	@author Marcin Gil <mg@ovos.at>
]]

-- Builds a RedisJSON path from raw segments using bracket notation,
-- so any character (dots, spaces, quotes) is allowed in a key
local function session_path(segments, first, last)
	local path = '$'
	for i = first, last do
		local segment = segments[i]:gsub('\\', '\\\\'):gsub('"', '\\"')
		path = path .. '["' .. segment .. '"]'
	end

	return path
end

-- Creates the session document when missing, stamping its creation time
local function session_ensure_root(key, now)
	if redis.call('EXISTS', key) == 0 then
		redis.call('JSON.SET', key, '$',
			'{"__meta":{"created":' .. now .. '}}')
	end
end

-- Ensures every intermediate segment is an object; anything else in the
-- way is overwritten (PHP-like auto-vivification)
local function session_ensure_parents(key, segments, last)
	for i = 1, last do
		local path = session_path(segments, 1, i)
		local types = redis.call('JSON.TYPE', key, path)
		if types == false or #types == 0 or types[1] ~= 'object' then
			redis.call('JSON.SET', key, path, '{}')
		end
	end
end

-- Collects the trailing arguments (path segments) into a table
local function session_segments(args, first)
	local segments = {}
	for i = first, #args do
		segments[#segments + 1] = args[i]
	end

	return segments
end

-- The "__locked__:<n>" marker when one of the lock keys (KEYS[first..])
-- is currently held - a write must not sail past a value lock taken by
-- another request (the caller filters out its own locks); nil when free
local function session_locked(keys, first)
	for i = first, #keys do
		if redis.call('EXISTS', keys[i]) == 1 then
			return '__locked__:' .. (i - first)
		end
	end

	return nil
end

-- Sets a JSON-encoded value at a nested path, creating missing parents
-- keys: [document, lock...] args: [ttl_ms, now, value, segment...]
local function session_set(keys, args)
	local locked = session_locked(keys, 2)
	if locked then
		return locked
	end

	local key = keys[1]
	local ttl_ms = tonumber(args[1])
	local now = tonumber(args[2])
	local value = args[3]
	local segments = session_segments(args, 4)

	if #segments == 0 then
		-- a root write replaces the whole document
		redis.call('JSON.SET', key, '$', value)
	else
		session_ensure_root(key, now)
		session_ensure_parents(key, segments, #segments - 1)
		redis.call('JSON.SET', key,
			session_path(segments, 1, #segments), value)
	end

	redis.call('PEXPIRE', key, ttl_ms)

	return 1
end
redis.register_function('[prefix]session_set', session_set)

-- Atomically increments a numeric value at a nested path, creating it
-- (and missing parents) when necessary; returns the new value as JSON
-- keys: [document, lock...] args: [ttl_ms, now, by, segment...]
local function session_increment(keys, args)
	local locked = session_locked(keys, 2)
	if locked then
		return locked
	end

	local key = keys[1]
	local ttl_ms = tonumber(args[1])
	local now = tonumber(args[2])
	local by = args[3]
	local segments = session_segments(args, 4)

	session_ensure_root(key, now)
	session_ensure_parents(key, segments, #segments - 1)

	local path = session_path(segments, 1, #segments)
	local types = redis.call('JSON.TYPE', key, path)
	if types == false or #types == 0
		or (types[1] ~= 'integer' and types[1] ~= 'number')
	then
		redis.call('JSON.SET', key, path, '0')
	end

	local result = redis.call('JSON.NUMINCRBY', key, path, by)
	redis.call('PEXPIRE', key, ttl_ms)

	return result
end
redis.register_function('[prefix]session_increment', session_increment)

-- Appends a JSON-encoded entry to an array at a nested path (used for the
-- "__journey" timeline), trimming it to the last "limit" entries (0 = no
-- limit); returns the array length
-- keys: [document, lock...] args: [ttl_ms, now, limit, entry, segment...]
local function session_append(keys, args)
	local locked = session_locked(keys, 2)
	if locked then
		return locked
	end

	local key = keys[1]
	local ttl_ms = tonumber(args[1])
	local now = tonumber(args[2])
	local limit = tonumber(args[3])
	local entry = args[4]
	local segments = session_segments(args, 5)

	session_ensure_root(key, now)
	session_ensure_parents(key, segments, #segments - 1)

	local path = session_path(segments, 1, #segments)
	local types = redis.call('JSON.TYPE', key, path)
	if types == false or #types == 0 or types[1] ~= 'array' then
		redis.call('JSON.SET', key, path, '[]')
	end

	local lengths = redis.call('JSON.ARRAPPEND', key, path, entry)
	local length = lengths[1]
	if limit > 0 and length > limit then
		redis.call('JSON.ARRTRIM', key, path, length - limit, length - 1)
		length = limit
	end

	redis.call('PEXPIRE', key, ttl_ms)

	return length
end
redis.register_function('[prefix]session_append', session_append)

-- Moves a session document to a new id (session id regeneration)
-- Multi-key: on a Redis Cluster both keys must share a hash slot
-- keys: [old document, new document] args: [ttl_ms]
local function session_rename(keys, args)
	local old_key = keys[1]
	local new_key = keys[2]
	local ttl_ms = tonumber(args[1])

	if redis.call('EXISTS', old_key) == 0 then
		return 0
	end

	redis.call('RENAME', old_key, new_key)
	redis.call('PEXPIRE', new_key, ttl_ms)

	return 1
end
redis.register_function('[prefix]session_rename', session_rename)
