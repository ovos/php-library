--[[
	Redis Versioned Cache Functions
	
	Logical (rule based) tag invalidation: an invalidation appends one
	rule to a stream instead of deleting the matched items - O(1)
	regardless of the match count; items physically expire by their TTL.
	
	Ordering is causal, not clock based: every item stores the rules
	stream's last entry id at write time (its "watermark"), and a read
	only evaluates the rules the item has not seen (id > watermark).
	Stream ids are generated monotonically by the node owning the rules
	stream, so no clock comparison happens anywhere - cluster nodes do
	not need synchronized clocks for correctness.
	
	Cluster compatibility, by function flags:
	- cluster-safe (no special flag): every key is declared and hashes to
	  one slot (cache_versioned_set_stamped, cache_versioned_invalidate).
	- standalone-only ('no-cluster'): cache_versioned_set declares two keys
	  of different slots (item + rules); the cluster store reads the
	  watermark separately and uses cache_versioned_set_stamped instead.
	
	@author Marcin Gil <mg@ovos.at>
]]

-- the rules stream's last entry id, '0-0' when there are no rules yet
-- (every real id is newer, and an exclusive XRANGE starts past it)
local function cache_versioned_watermark(rules_key)
	local last = redis.call('XREVRANGE', rules_key, '+', '-', 'COUNT', 1)
	if #last == 0 then
		return '0-0'
	end
	
	return last[1][1]
end

-- shared write: stamp the item with the rules watermark it has seen; a
-- write-through (new_epoch given) also sets the fresh epoch it brings, so a
-- recomputation that missed before it is refused (see Cache.lua's
-- invalidation guard)
local function cache_versioned_write(item_key, mark, data, tags, ttl_ms, retention_ms, new_epoch, window_ms)
	local rem_ms = redis.call('PTTL', item_key)
	redis.call('HSET', item_key,
		'data', data,
		'tags', tags,
		'mark', mark
	)
	if new_epoch and new_epoch ~= '' then
		redis.call('HSET', item_key, 'epoch', new_epoch)
	end
	
	-- an item must never outlive the invalidation rules, otherwise it
	-- would resurrect once the rules that made it stale are trimmed:
	-- cap the TTL at the rules retention (0 = no TTL requested)
	if ttl_ms <= 0 or ttl_ms > retention_ms then
		ttl_ms = retention_ms
	end
	
	-- a key carrying an epoch keeps it for the window: the data fields expire
	-- at the TTL, the key at the window's end (a full window when it had no
	-- expiry of its own) - a short-lived item cannot take the mark with it
	local keep_ms = 0
	if window_ms and window_ms > 0 and redis.call('HEXISTS', item_key, 'epoch') == 1 then
		keep_ms = window_ms
		if rem_ms > 0 and rem_ms < window_ms then
			keep_ms = rem_ms
		end
	end
	if keep_ms > ttl_ms then
		redis.call('PEXPIRE', item_key, keep_ms)
		redis.call('HPEXPIRE', item_key, ttl_ms, 'FIELDS', 3, 'data', 'tags', 'mark')
	else
		redis.call('PEXPIRE', item_key, ttl_ms)
	end
	
	return 1
end

-- Store an item, reading the current watermark from the rules stream
local function cache_versioned_set(keys, args)
	return cache_versioned_write(
		keys[1],
		cache_versioned_watermark(keys[2]),
		args[1], -- data
		args[2], -- tags
		tonumber(args[3]), -- ttl ms
		tonumber(args[4]), -- retention ms
		args[5], -- the write-through's epoch (optional)
		tonumber(args[6] or 0) -- window ms (optional)
	)
end
-- standalone-only: the item and the rules keys hash to different slots,
-- the cluster store reads the watermark separately (see _stamped)
redis.register_function
{
	function_name = '[prefix]cache_versioned_set',
	callback = cache_versioned_set,
	flags = {'no-cluster'}
}

-- Store an item with a watermark the caller has already read
-- cluster-safe: one declared key
local function cache_versioned_set_stamped(keys, args)
	return cache_versioned_write(
		keys[1],
		args[5], -- watermark
		args[1], -- data
		args[2], -- tags
		tonumber(args[3]), -- ttl ms
		tonumber(args[4]), -- retention ms
		args[6], -- the write-through's epoch (optional)
		tonumber(args[7] or 0) -- window ms (optional)
	)
end
redis.register_function('[prefix]cache_versioned_set_stamped', cache_versioned_set_stamped)

-- Store an item a recomputation produced - the write that follows a miss:
-- refused (0) when the key was invalidated since that miss (delete() left
-- its tombstone, whose "epoch" the item keeps - see Cache.lua), and stamped
-- with the watermark the MISS saw, so a rule appended between the miss and
-- this write makes the item stale on its next read
-- cluster-safe: one declared key
local function cache_versioned_set_guarded(keys, args)
	local epoch = redis.call('HGET', keys[1], 'epoch') or ''
	if epoch ~= args[6] then
		return 0
	end
	
	return cache_versioned_write(
		keys[1],
		args[5], -- the watermark the miss saw
		args[1], -- data
		args[2], -- tags
		tonumber(args[3]), -- ttl ms
		tonumber(args[4]), -- retention ms
		nil, -- a guarded write keeps the epoch it compared
		tonumber(args[7] or 0) -- window ms
	)
end
redis.register_function('[prefix]cache_versioned_set_guarded', cache_versioned_set_guarded)

-- Removes a stale item a read found - only while it is still that item: the
-- read and this removal are two round trips, and a delete() in between left
-- a tombstone whose epoch must survive (a plain UNLINK erased it, and a
-- recomputation that missed before the delete then passed the guard). The
-- epoch an item keeps stays too, for the window: only the data fields go
-- keys: [1] item; args: [1] the stale mark the read saw, [2] the window in ms
-- (absent: an older caller, the epoch keeps the item's TTL)
-- cluster-safe: one declared key
local function cache_versioned_drop_stale(keys, args)
	if redis.call('HGET', keys[1], 'mark') ~= args[1] then
		return 0
	end
	if redis.call('HEXISTS', keys[1], 'epoch') == 1 then
		redis.call('HDEL', keys[1], 'data', 'tags', 'mark')
		-- the epoch guards a miss for the window only: a miss older than that
		-- goes unguarded, so beyond it the key is memory and nothing else (an
		-- item's TTL runs up to the retention)
		local window_ms = tonumber(args[2])
		if window_ms ~= nil then
			if window_ms <= 0 then
				redis.call('DEL', keys[1])
			else
				local ttl_ms = redis.call('PTTL', keys[1])
				if ttl_ms < 0 or ttl_ms > window_ms then
					redis.call('PEXPIRE', keys[1], window_ms)
				end
			end
		end
	else
		redis.call('DEL', keys[1])
	end
	
	return 1
end
redis.register_function('[prefix]cache_versioned_drop_stale', cache_versioned_drop_stale)

-- Append one invalidation rule, O(1) regardless of how many items match
-- cluster-safe: one declared key (the rules stream)
--
-- Every rule records whether it opened the stream ('first'): NOMKSTREAM
-- refuses to create one, so a nil reply says there was none, and the rule
-- is appended again as the first entry of a new stream. A reader holding
-- an item stamped older than an opening rule knows the stream has lost
-- what the item once saw (see RedisVersioned::isStale()).
--
-- The stream is the only record that an invalidation happened, so it
-- carries no TTL: under a volatile-* policy that keeps it out of the
-- eviction pool, where a TTL made it a candidate like any item. PERSIST
-- also strips the TTL from a stream written by an earlier version. Items
-- cannot outlive the rules anyway - their TTL is capped to the retention,
-- and the trim below drops only what nothing alive can match.
local function cache_versioned_invalidate(keys, args)
	local rules_key = keys[1]
	local mode = args[1]
	local tags = args[2]
	local retention_ms = tonumber(args[3])
	
	-- the entry id is generated by the stream: server milliseconds plus
	-- a sequence number, guaranteed monotonic even across clock hiccups
	local id = redis.call('XADD', rules_key, 'NOMKSTREAM', '*', 'mode', mode, 'tags', tags, 'first', '0')
	
	if not id then
		-- no stream to append to: this rule opens one
		id = redis.call('XADD', rules_key, '*', 'mode', mode, 'tags', tags, 'first', '1')
	end
	
	if mode == 'all' and tags == '' then
		-- a clear (an 'all' rule with no tags) matches every item, so it
		-- subsumes every older rule - keep only the clear itself
		redis.call('XTRIM', rules_key, 'MINID', id)
	else
		-- drop the rules older than the longest possible item TTL,
		-- they can no longer match any living item
		local ms = tonumber(string.match(id, '^(%d+)'))
		redis.call('XTRIM', rules_key, 'MINID', ms - retention_ms)
	end
	-- the stream must outlive every item: no TTL, and none left from before
	redis.call('PERSIST', rules_key)
	
	return 1
end
redis.register_function('[prefix]cache_versioned_invalidate', cache_versioned_invalidate)
