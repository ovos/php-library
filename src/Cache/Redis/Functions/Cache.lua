--[[
	Redis Cache Functions
	
	Cluster compatibility, by function flags:
	- node-local ('allow-cross-slot-keys'): operates on the keys of
	  whichever node it runs on; on a cluster run it once per master node
	  (cache_clear).
	- standalone-only ('no-cluster'): the legacy tag-hash model reaches
	  undeclared keys across slots and nodes by design and can never run
	  on a cluster - the flag makes it fail fast with a clear error.
	
	The versioned (rule based) functions live in CacheVersioned.lua.
	
	@author Marcin Gil <mg@ovos.at>
]]

--[[ ------------------------------------------------------------------
	Internal helpers (not registered - they are not FCALL entry points
	and do not follow the (keys, args) signature)
--]] ------------------------------------------------------------------

-- Splits a set of items into batches, returning a function iterator
-- Each iteration returns (from, to) indexes for a slice of the collection
-- Has to be used for unpack() calls due to a limit of 8000 arguments
local function cache_batches(n, batch_size)
	batch_size = batch_size or 7500
	local i = 0
	
	return function()
		local from = i * batch_size + 1
		i = i + 1
		if (from <= n) then
			local to = math.min(from + batch_size - 1, n)
			return from, to
		end
	end
end

-- Splits a string by a given delimiter
local function cache_split_string(str, delimiter)
	local result = {}
	local start = 1
	local delim_length = #delimiter
	
	while true do
		local pos = string.find(str, delimiter, start, true)
		if not pos then
			table.insert(result, string.sub(str, start))
			break
		end
		table.insert(result, string.sub(str, start, pos - 1))
		start = pos + delim_length
	end
	
	return result
end

-- Scans redis for keys matching a specified prefix and processes them with a callback
local function cache_scan_keys(prefix, count, callback)
	local cursor = '0'
	repeat
		local results = redis.call('SCAN', cursor, 'MATCH', prefix .. '*', 'COUNT', count)
		cursor = results[1]
		for _, key in ipairs(results[2]) do
			callback(key)
		end
	until cursor == '0'
end

-- Scans a redis hash for fields using HSCAN and processes them with a callback
local function cache_hscan_keys(hash_key, count, callback)
	local cursor = '0'
	repeat
		local results = redis.call('HSCAN', hash_key, cursor, 'COUNT', count, 'NOVALUES')
		cursor = results[1]
		for _, field in ipairs(results[2]) do
			callback(field)
		end
	until cursor == '0'
end

-- Scans a redis hash for fields using HSCAN, processes them with a callback, and returns the next cursor.
local function cache_hscan_keys_batch(hash_key, cursor, count, callback)
	local results = redis.call('HSCAN', hash_key, cursor, 'COUNT', count, 'NOVALUES')
	local new_cursor = results[1]
	local fields = results[2]
	
	for _, field in ipairs(fields) do
		callback(field)
	end
	
	return new_cursor
end

--[[ ------------------------------------------------------------------
	Invalidation guard - a write computed before an invalidation never
	lands after it (KeyValue: the miss remembers what it saw, the write
	that follows it compares)
--]] ------------------------------------------------------------------

-- the field a tombstone holds alone, and an item keeps once its key was
-- invalidated or written through: a later writer whose miss saw another
-- token is refused
local CACHE_EPOCH = 'epoch'

-- soft invalidation: a soft value's item carries its stale time (ms) in
-- CACHE_SOFT; a tag invalidation then marks it (CACHE_INVALIDATED) instead
-- of tombstoning it, and a read inside the window serves it aged
local CACHE_SOFT = 'soft'
local CACHE_INVALIDATED = 'invalidated'

-- Replaces a key with its tombstone: DEL, the token, the window - one step,
-- or a tombstone landing on a fresh write would leave its data behind. Only a
-- cache item (a hash) or an absent key gets one: a key holding anything else
-- is a raw value a caller keeps under the store's prefix (a counter, a set)
-- and is removed as before - a tombstone would turn it into a hash, and the
-- caller's next INCR into a WRONGTYPE error. A window of 0 (the guard off)
-- removes as before too. Answers whether an ITEM was there - a tombstone an
-- earlier delete left is none. kind: the key's TYPE when the caller read it
local function cache_tombstone_key(key, token, window_ms, kind)
	kind = kind or redis.call('TYPE', key)['ok']
	if kind ~= 'none' and kind ~= 'hash' then
		return redis.call('DEL', key)
	end
	
	local existed = 0
	if kind == 'hash' then
		existed = redis.call('HEXISTS', key, 'data')
		redis.call('DEL', key)
	end
	if window_ms > 0 then
		redis.call('HSET', key, CACHE_EPOCH, token)
		redis.call('PEXPIRE', key, window_ms)
	end
	
	return existed
end

-- Soft invalidation: a soft value's item a tag invalidation reached is
-- marked, not tombstoned - a new epoch (a recomputation that missed before
-- it is refused, as a tombstone would refuse it), the CACHE_INVALIDATED
-- mark; the mark and the data expire at the end of its stale time, never
-- later than the item would have (LT), the key - and its epoch - not before
-- the guard's window ends
local function cache_soft_mark(key, token, window_ms, soft_ms)
	local ttl_ms = redis.call('PTTL', key)
	local left_ms = soft_ms
	if ttl_ms > 0 and ttl_ms < left_ms then
		left_ms = ttl_ms
	end
	
	redis.call('HSET', key, CACHE_EPOCH, token, CACHE_INVALIDATED, '1')
	local fields = {'data', CACHE_INVALIDATED, CACHE_SOFT}
	if redis.call('HEXISTS', key, 'tags') == 1 then
		table.insert(fields, 'tags')
	end
	redis.call('HPEXPIRE', key, left_ms, 'LT', 'FIELDS', #fields, unpack(fields))
	
	local keep_ms = math.max(left_ms, window_ms)
	if ttl_ms < 0 or ttl_ms < keep_ms then
		redis.call('PEXPIRE', key, keep_ms)
	end
	
	return 1
end

-- Removes an item a tag invalidation reached: its tombstone when the caller
-- passed one - a key already gone gets none (the tag's stamp covers the
-- values being computed for it), a soft value's item its soft mark (the
-- guard on, the invalidation not hard) - a plain UNLINK for a caller from
-- before the guard
local function cache_remove_item(key, token, window_ms, hard)
	if token and window_ms then
		local kind = redis.call('TYPE', key)['ok']
		if kind == 'none' then
			return 0
		end
		
		if window_ms > 0 and kind == 'hash' and hard ~= true then
			local soft_ms = tonumber(redis.call('HGET', key, CACHE_SOFT) or '')
			if soft_ms and soft_ms > 0 and redis.call('HEXISTS', key, 'data') == 1 then
				return cache_soft_mark(key, token, window_ms, soft_ms)
			end
		end
		
		return cache_tombstone_key(key, token, window_ms, kind)
	end
	
	return redis.call('UNLINK', key)
end

-- The server clock in microseconds, as an exact integer string (Lua 5.1
-- numbers are doubles - 1.8e15 fits, but tostring() would print it in
-- scientific notation)
local function cache_now_us()
	local now = redis.call('TIME')
	
	return string.format('%.0f', tonumber(now[1]) * 1000000 + tonumber(now[2]))
end

-- Whether a write may land: the key's epoch is still the one its miss saw
-- ('' = none), and - when the miss stamped its time - no stamp key given was
-- set after it (a tag invalidated, or the store cleared, meanwhile)
local function cache_write_allowed(item_key, epoch, since_us, stamp_keys)
	local current = redis.call('HGET', item_key, CACHE_EPOCH) or ''
	if current ~= epoch then
		return false
	end
	if since_us ~= '' then
		local since = tonumber(since_us)
		for _, stamp_key in ipairs(stamp_keys) do
			local stamp = redis.call('GET', stamp_key)
			if stamp and tonumber(stamp) > since then
				return false
			end
		end
	end
	
	return true
end

-- Whether a write finds a value of the item's own: a softly invalidated item's
-- data is the old value kept for the stale window, under the mark's expiry
local function cache_had_data(item_key)
	if redis.call('HEXISTS', item_key, 'data') == 0 then
		return 0
	end
	if redis.call('HEXISTS', item_key, CACHE_INVALIDATED) == 1 then
		return 0
	end
	return 1
end

-- An item's expiry after a write. Its TTL when one is asked - unless the key
-- carries an epoch whose window outlives it: then the data fields expire at
-- the TTL, the key (and its epoch) at the window's end - a full window when
-- the key had no expiry of its own - so a short-lived item cannot take the
-- invalidation mark with it. No TTL: none for a key that held no data (fresh,
-- a tombstone whose window must not become the item's, or a soft mark's);
-- an overwrite without a TTL keeps the one it had
local function cache_write_expiry(item_key, ttl_s, had_data, rem_ms, window_ms, fields)
	if ttl_s > 0 then
		local ttl_ms = ttl_s * 1000
		local keep_ms = 0
		if window_ms > 0 and redis.call('HEXISTS', item_key, CACHE_EPOCH) == 1 then
			keep_ms = window_ms
			if rem_ms > 0 and rem_ms < window_ms then
				keep_ms = rem_ms
			end
		end
		if keep_ms > ttl_ms then
			redis.call('PEXPIRE', item_key, keep_ms)
			redis.call('HPEXPIRE', item_key, ttl_ms, 'FIELDS', #fields, unpack(fields))
		else
			redis.call('PEXPIRE', item_key, ttl_ms)
		end
	elseif had_data == 0 then
		redis.call('PERSIST', item_key)
	end
end

-- Tombstone one key (delete())
-- cluster-safe: one declared key
local function cache_tombstone(keys, args)
	return cache_tombstone_key(keys[1], args[1], tonumber(args[2]))
end
redis.register_function('[prefix]cache_tombstone', cache_tombstone)

-- Stamp the given keys with the server time (an invalidated tag, a cleared
-- store), for the window: a guarded write whose miss came earlier refuses
-- standalone-only: the stamp keys are of any slot
local function cache_stamp(keys, args)
	local now_us = cache_now_us()
	for _, key in ipairs(keys) do
		redis.call('SET', key, now_us, 'PX', tonumber(args[1]))
	end
	
	return now_us
end
redis.register_function
{
	function_name = '[prefix]cache_stamp',
	callback = cache_stamp,
	flags = {'no-cluster'}
}

-- A hash item write - the Redisearch store's: guarded when args[1] is not
-- the unguarded marker '*'; an unguarded write (a write-through) sets the
-- fresh epoch it brings, so a recomputation that missed before it is refused
-- keys: [1] item, [2..] stamp keys; args: [1] epoch the miss saw | '*',
-- [2] miss time (us) | '', [3] ttl (s), [4] the write-through's epoch | '',
-- [5] window (ms), [6..] field, value pairs
-- standalone-only: the stamp keys are of any slot
local function cache_guarded_hset(keys, args)
	local item_key = keys[1]
	if args[1] ~= '*' then
		local stamp_keys = {}
		for i = 2, #keys do
			table.insert(stamp_keys, keys[i])
		end
		if cache_write_allowed(item_key, args[1], args[2], stamp_keys) == false then
			return 0
		end
	end
	
	local had_data = cache_had_data(item_key)
	local rem_ms = redis.call('PTTL', item_key)
	local pairs_list = {}
	local names = {}
	local soft = false
	for i = 6, #args, 2 do
		table.insert(pairs_list, args[i])
		table.insert(pairs_list, args[i + 1])
		table.insert(names, args[i])
		if args[i] == CACHE_SOFT then
			soft = true
		end
	end
	redis.call('HSET', item_key, unpack(pairs_list))
	if args[1] == '*' and args[4] ~= '' then
		redis.call('HSET', item_key, CACHE_EPOCH, args[4])
	end
	-- a write ends a soft invalidation, and a value written without a stale
	-- time leaves none behind
	redis.call('HDEL', item_key, CACHE_INVALIDATED)
	if soft == false then
		redis.call('HDEL', item_key, CACHE_SOFT)
	end
	cache_write_expiry(item_key, tonumber(args[3]), had_data, rem_ms, tonumber(args[5]), names)
	
	return 1
end
redis.register_function
{
	function_name = '[prefix]cache_guarded_hset',
	callback = cache_guarded_hset,
	flags = {'no-cluster'}
}

-- The tag-hash store's (Store\Redis) item write: the item and its tag
-- index in one step - guarded when args[1] is not the unguarded marker '*';
-- an unguarded write (a write-through) sets the fresh epoch it brings
-- keys: [1] item, [2] the store's "cleared" stamp; args: [1] epoch the miss
-- saw | '*', [2] miss time (us) | '', [3] ttl (s), [4] the item's key (its
-- field in the tag hashes), [5] tag hash key prefix, [6] tag stamp key
-- prefix, [7] the write-through's epoch | '', [8] window (ms), [9] data,
-- [10..] tags
-- standalone-only: the tag hashes and stamps are of any slot
local function cache_set_item(keys, args, soft_ms)
	local item_key = keys[1]
	local key = args[4]
	local tag_prefix = args[5]
	local tags = {}
	for i = 10, #args do
		table.insert(tags, args[i])
	end
	
	if args[1] ~= '*' then
		local stamp_keys = {keys[2]}
		for _, tag in ipairs(tags) do
			table.insert(stamp_keys, args[6] .. tag)
		end
		if cache_write_allowed(item_key, args[1], args[2], stamp_keys) == false then
			return 0
		end
	end
	
	local ttl = tonumber(args[3])
	local had_data = cache_had_data(item_key)
	local rem_ms = redis.call('PTTL', item_key)
	local current_csv = redis.call('HGET', item_key, 'tags')
	local current = {}
	if current_csv and current_csv ~= '' then
		current = cache_split_string(current_csv, ',')
	end
	
	local fields = {'data'}
	if #tags > 0 then
		redis.call('HSET', item_key, 'data', args[9], 'tags', table.concat(tags, ','))
		table.insert(fields, 'tags')
	else
		redis.call('HSET', item_key, 'data', args[9])
		-- an item that had tags and is written without them drops the field
		if current_csv then
			redis.call('HDEL', item_key, 'tags')
		end
	end
	if args[1] == '*' and args[7] ~= '' then
		redis.call('HSET', item_key, CACHE_EPOCH, args[7])
	end
	-- a write ends a soft invalidation; a soft value's item carries its stale
	-- time (it expires with the data), any other leaves none behind
	redis.call('HDEL', item_key, CACHE_INVALIDATED)
	if soft_ms then
		redis.call('HSET', item_key, CACHE_SOFT, soft_ms)
		table.insert(fields, CACHE_SOFT)
	else
		redis.call('HDEL', item_key, CACHE_SOFT)
	end
	cache_write_expiry(item_key, ttl, had_data, rem_ms, tonumber(args[8]), fields)
	
	-- the tag index: the key joins its new tags; on a write with a TTL every
	-- tag field's HEXPIRE follows it (re-saving with the same tags would
	-- otherwise leave the field to expire before the item, which made the
	-- item invisible to tag invalidation); it leaves the tags it dropped
	local kept = {}
	for _, tag in ipairs(tags) do
		kept[tag] = true
		if redis.call('HEXISTS', tag_prefix .. tag, key) == 0 then
			redis.call('HSET', tag_prefix .. tag, key, '')
		end
		if ttl > 0 then
			redis.call('HEXPIRE', tag_prefix .. tag, ttl, 'FIELDS', 1, key)
		end
	end
	for _, tag in ipairs(current) do
		if kept[tag] == nil then
			redis.call('HDEL', tag_prefix .. tag, key)
		end
	end
	
	return 1
end
local function cache_set(keys, args)
	return cache_set_item(keys, args, nil)
end
redis.register_function
{
	function_name = '[prefix]cache_set',
	callback = cache_set,
	flags = {'no-cluster'}
}

-- cache_set for a soft value (get(stale:, soft: true)): args [1] its stale
-- time (ms), then cache_set's - a function of its own, so cache_set keeps
-- the argument shape every caller sends
local function cache_set_soft(keys, args)
	local soft_ms = args[1]
	table.remove(args, 1)
	
	return cache_set_item(keys, args, soft_ms)
end
redis.register_function
{
	function_name = '[prefix]cache_set_soft',
	callback = cache_set_soft,
	flags = {'no-cluster'}
}

-- The tag-hash store's delete: the item leaves its tags' indexes and becomes
-- a tombstone in one step - a write landing between the two would otherwise
-- lose its tag entry to the HDEL, and stay out of reach of tag invalidation
-- keys: [1] item; args: [1] token, [2] window (ms), [3] tag hash key prefix,
-- [4] the item's key (its field in the tag hashes)
-- standalone-only: the tag hashes are of any slot
local function cache_delete_item(keys, args)
	local item_key = keys[1]
	if redis.call('TYPE', item_key)['ok'] == 'hash' then
		local current_csv = redis.call('HGET', item_key, 'tags')
		if current_csv and current_csv ~= '' then
			for _, tag in ipairs(cache_split_string(current_csv, ',')) do
				redis.call('HDEL', args[3] .. tag, args[4])
			end
		end
	end
	
	return cache_tombstone_key(item_key, args[1], tonumber(args[2]))
end
redis.register_function
{
	function_name = '[prefix]cache_delete_item',
	callback = cache_delete_item,
	flags = {'no-cluster'}
}

--[[ ------------------------------------------------------------------
	Maintenance
--]] ------------------------------------------------------------------

-- Removes all items matching a prefix
-- (used to "flush" the database, but only the prefixed items)
local function cache_clear(keys, args)
	local prefix = args[1]
	local stamp_key = args[2] -- the store's "cleared" stamp (KeyValue\Redis), optional
	local window_ms = tonumber(args[3] or 0)
	
	-- unlink every key one by one: a multi-key UNLINK would be illegal
	-- on a cluster even under 'allow-cross-slot-keys' - the flag lets
	-- the script touch keys of many slots, but every single command must
	-- still stay within one slot (server side calls are cheap, the
	-- batching was only ever an unpack() argument-count measure)
	local count = 0
	cache_scan_keys(prefix, 5000, function(key)
		redis.call('UNLINK', key)
		count = count + 1
	end)
	
	-- the "cleared" stamp in this same step: a write landing between a wipe
	-- and a separate stamp would find neither its tombstone nor the stamp
	if stamp_key and window_ms > 0 then
		redis.call('SET', stamp_key, cache_now_us(), 'PX', window_ms)
	end
	
	return count -- return count of deleted ids
end
-- node-local: SCAN only sees the keys of the node it runs on; on a
-- cluster run it once per master node ('allow-cross-slot-keys' permits
-- touching that node's keys regardless of their slots)
redis.register_function
{
	function_name = '[prefix]cache_clear',
	callback = cache_clear,
	flags = {'allow-cross-slot-keys'}
}

--[[ ------------------------------------------------------------------
	Legacy tag-hash model (Store\Redis) - standalone-only: these reach
	undeclared item and tag-index keys across slots by design
--]] ------------------------------------------------------------------

-- Returns a list of all tags (suffix extracted from matching keys)
local function cache_get_tags(keys, args)
	local prefix = args[1]
	local prefix_tag_ids = prefix .. args[2]
	local prefix_tag_ids_length = string.len(prefix_tag_ids)
	local tags = {}
	
	cache_scan_keys(prefix_tag_ids, 5000, function(full_tag_key)
		-- Strip the prefix to get the actual tag name
		local tag = string.sub(full_tag_key, prefix_tag_ids_length + 1)
		table.insert(tags, tag)
	end)
	
	return tags
end
-- https://redis.io/docs/latest/develop/interact/programmability/functions-intro/
redis.register_function
{
	function_name = '[prefix]cache_get_tags',
	callback = cache_get_tags,
	flags = {'no-writes', 'no-cluster'}
}

-- Returns a list of all ids in a given tag
local function cache_get_ids_by_tag(keys, args)
	local prefix = args[1]
	local tag = args[2]
	local prefix_tag_ids = prefix .. args[3]
	local ids = {}
	
	if redis.call('EXISTS', prefix_tag_ids .. tag) == 0 then
		return ids
	end
	
	cache_hscan_keys(prefix_tag_ids .. tag, 5000, function(field)
		table.insert(ids, field)
	end)
	
	return ids
end
-- https://redis.io/docs/latest/develop/interact/programmability/functions-intro/
redis.register_function
{
	function_name = '[prefix]cache_get_ids_by_tag',
	callback = cache_get_ids_by_tag,
	flags = {'no-writes', 'no-cluster'}
}

-- Unlink (remove) items by their ids and remove references from all tags
local function cache_unlink_clean_tags(keys, args)
	local ids = keys
	local prefix = args[1]
	local prefix_ids = prefix .. args[2]
	local prefix_tag_ids = prefix .. args[3]
	local field_tags = args[4]
	local token = args[5] -- the tombstone (KeyValue\Redis), absent from an older caller
	local window_ms = tonumber(args[6])
	local hard = args[7] == '1' -- invalidateTags(hard: true): soft values are tombstoned too
	
	for _, id in ipairs(ids) do
		
		-- first remove the id all tags where this id is present
		local item_tags = redis.call('HGET', prefix_ids .. id, field_tags)
		
		if item_tags then -- not false = item & hash field exist
			if item_tags ~= '' then -- not an empty string
				local tags = cache_split_string(item_tags, ',')
				for i, tag in ipairs(tags) do
					redis.call('HDEL', prefix_tag_ids .. tag, id)
				end
			end
			
			-- the item itself: its tombstone, so a write computed before this
			-- invalidation cannot land after it
			cache_remove_item(prefix_ids .. id, token, window_ms, hard)
		end
	
	end
	
	return 1
end
redis.register_function
{
	function_name = '[prefix]cache_unlink_clean_tags',
	callback = cache_unlink_clean_tags,
	flags = {'no-cluster'}
}

-- Unlink items by a given tag only if they match provided ids
local function cache_unlink_ids_by_tag(keys, args)
	local ids = keys
	local prefix = args[1]
	local tag = args[2]
	local prefix_ids = prefix .. args[3]
	local prefix_tag_ids = prefix .. args[4]
	local token = args[5]
	local window_ms = tonumber(args[6])
	local hard = args[7] == '1' -- invalidateTags(hard: true): soft values are tombstoned too
	
	if #ids == 0 then
		return 1
	end
	
	if redis.call('EXISTS', prefix_tag_ids .. tag) == 0 then
		return 1
	end
	
	-- unlink every id matching input ids for the given tag
	
	-- collect all ids from the tag
	local all_tag_ids = {}
	cache_hscan_keys(prefix_tag_ids .. tag, 5000, function(id)
		table.insert(all_tag_ids, id)
	end)
	
	-- create a map of ids for quick lookup
	local lookup = {}
	for _, single_id in ipairs(ids) do
		lookup[single_id] = true
	end
	
	-- prepare a list of ids to be removed (comparison against lookup)
	local rems = {}
	for _, id in ipairs(all_tag_ids) do
		if lookup[id] then
			-- save for removal after the loop
			table.insert(rems, id)
			-- the item itself: its tombstone
			cache_remove_item(prefix_ids .. id, token, window_ms, hard)
		end
	end
	
	-- remove the ids from the tag hash in batches
	if #rems > 0 then
		for from, to in cache_batches(#rems) do
			redis.call('HDEL', prefix_tag_ids .. tag, unpack(rems, from, to))
		end
	end
	
	return 1
end
redis.register_function
{
	function_name = '[prefix]cache_unlink_ids_by_tag',
	callback = cache_unlink_ids_by_tag,
	flags = {'no-cluster'}
}

-- Unlink all items from a given tag (removes every ID that tag references)
local function cache_unlink_by_tag(keys, args)
	local prefix = args[1]
	local tag = args[2]
	local prefix_ids = prefix .. args[3]
	local prefix_tag_ids = prefix .. args[4]
	local cursor = args[5] or '0' -- cursor for HSCAN, '0' for initial call
	local token = args[6]
	local window_ms = tonumber(args[7])
	local hard = args[8] == '1' -- invalidateTags(hard: true): soft values are tombstoned too
	
	if redis.call('EXISTS', prefix_tag_ids .. tag) == 0 then
		return {0, '0'} -- tag does not exist, nothing to unlink, scan complete
	end
	
	-- unlink every id matching input ids for the given tag
	local rems = {}
	-- loop the tag in batches to prevent locking redis for too long
	local cursor_new = cache_hscan_keys_batch(
		prefix_tag_ids .. tag,
		cursor,
		7500,
		function(id)
			-- save for removal after the loop (within this batch)
			table.insert(rems, id)
			-- the item itself: its tombstone
			cache_remove_item(prefix_ids .. id, token, window_ms, hard)
		end
	)
	
	-- remove the ids from the tag hash in batches
	if #rems > 0 then
		for from, to in cache_batches(#rems) do -- security measure for unpack
			redis.call('HDEL', prefix_tag_ids .. tag, unpack(rems, from, to))
		end
	end
	
	return {#rems, cursor_new} -- return count of unlinked IDs and the new cursor
end
redis.register_function
{
	function_name = '[prefix]cache_unlink_by_tag',
	callback = cache_unlink_by_tag,
	flags = {'no-cluster'}
}

-- Unlink all items and all tags (full cleanup)
local function cache_unlink_all(keys, args)
	local prefix = args[1]
	local prefix_ids = prefix .. args[2]
	local prefix_tag_ids = prefix .. args[3]
	
	-- unlink every ID
	local ids = {}
	cache_scan_keys(prefix_ids, 5000, function(key)
		table.insert(ids, key)
	end)
	
	if #ids > 0 then
		for from, to in cache_batches(#ids) do
			redis.call('UNLINK', unpack(ids, from, to))
		end
	end
	
	-- unlink every tag
	local tags = {}
	cache_scan_keys(prefix_tag_ids, 5000, function(tag_key)
		table.insert(tags, tag_key)
	end)
	
	if #tags > 0 then
		for from, to in cache_batches(#tags) do
			redis.call('UNLINK', unpack(tags, from, to))
		end
	end
	
	return #ids, #tags
end
redis.register_function
{
	function_name = '[prefix]cache_unlink_all',
	callback = cache_unlink_all,
	flags = {'no-cluster'}
}

-- Removes references from a tag that no longer has valid items
local function cache_clean_tag(keys, args)
	local prefix = args[1]
	local tag = args[2]
	local prefix_ids = prefix .. args[3]
	local prefix_tag_ids = prefix .. args[4]
	local cursor = args[5] or '0' -- cursor for HSCAN, '0' for initial call
	
	if redis.call('EXISTS', prefix_tag_ids .. tag) == 0 then
		return {0, '0'} -- tag does not exist, nothing to clean, no more to process
	end
	
	local rems = {}
	-- loop the tag in batches to prevent locking redis for too long
	local cursor_new = cache_hscan_keys_batch(
		prefix_tag_ids .. tag,
		cursor,
		7500,
		function(id)
			-- check if the ID still exists in the main items hash
			if redis.call('EXISTS', prefix_ids .. id) == 0 then
				table.insert(rems, id)
			end
		end
	)
	
	-- remove hash keys which no longer exist
	if #rems > 0 then
		for from, to in cache_batches(#rems) do -- security measure for unpack
			redis.call('HDEL', prefix_tag_ids .. tag, unpack(rems, from, to))
		end
	end
	
	return {#rems, cursor_new} -- return count of deleted IDs and the new cursor for the next batch scan
end
redis.register_function
{
	function_name = '[prefix]cache_clean_tag',
	callback = cache_clean_tag,
	flags = {'no-cluster'}
}
