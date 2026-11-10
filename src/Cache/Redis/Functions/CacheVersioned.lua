--[[
	Redis Versioned Cache Functions
	
	Logical (rule based) tag invalidation: an invalidation appends one
	rule to a stream instead of deleting the matched items - O(1)
	regardless of the match count; items physically expire by their TTL.
	
	Ordering is causal, not clock based: every item stores a rule id (its
	"mark"), the newest one in its writer's copy of the rules, and a read
	only checks the rules newer than that id. Stream ids are generated
	monotonically by the node owning the rules stream, so no clock
	comparison happens anywhere - cluster nodes do not need synchronized
	clocks for correctness.
	
	Cluster compatibility: every function declares its keys, and they hash
	to one slot - a write is stamped with the rule id its writer holds
	(its copy's newest id, see RedisVersioned::missStamp()), never one
	read from the rules stream in the same call.
	
	@author Marcin Gil <mg@ovos.at>
]]

-- shared write: stamp the item with the rule id its writer has seen; a
-- write-through (new_epoch given) also sets the fresh epoch it brings, so a
-- recomputation that missed before it is refused (see Cache.lua's
-- invalidation guard)
local function cache_versioned_write(item_key, mark, data, tags, ttl_ms, retention_ms, new_epoch, window_ms, soft)
	local rem_ms = redis.call('PTTL', item_key)
	redis.call('HSET', item_key,
		'data', data,
		'tags', tags,
		'mark', mark
	)
	-- whether the value is soft ('1', '0'): a read judges an invalidated hard
	-- value without decoding it; an older caller says nothing (the read then
	-- decodes it to tell)
	local fields = {'data', 'tags', 'mark'}
	if soft == '1' or soft == '0' then
		redis.call('HSET', item_key, 'soft', soft)
		table.insert(fields, 'soft')
	else
		redis.call('HDEL', item_key, 'soft')
	end
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
	-- at the TTL, the key at the window's end - a short-lived item cannot take
	-- the mark with it. A write that brings a new epoch keeps it a full window,
	-- so every guarded write within the window after it sees it, also on a key
	-- that had only moments left; a guarded write keeps the epoch it compared,
	-- and so what was left of the key's life
	local keep_ms = 0
	if window_ms and window_ms > 0 and redis.call('HEXISTS', item_key, 'epoch') == 1 then
		keep_ms = window_ms
		if rem_ms > 0 and rem_ms < window_ms and not (new_epoch and new_epoch ~= '') then
			keep_ms = rem_ms
		end
	end
	if keep_ms > ttl_ms then
		redis.call('PEXPIRE', item_key, keep_ms)
		redis.call('HPEXPIRE', item_key, ttl_ms, 'FIELDS', #fields, unpack(fields))
	else
		redis.call('PEXPIRE', item_key, ttl_ms)
	end
	
	return 1
end

-- Store an item stamped with the rule id its writer holds - its copy's
-- newest id for a write-through, the one a miss took for a deliberate set()
-- made after a read older than the guard's window: every versioned write
-- that compares no epoch
-- cluster-safe: one declared key
local function cache_versioned_set_stamped(keys, args)
	return cache_versioned_write(
		keys[1],
		args[5], -- the rule id
		args[1], -- data
		args[2], -- tags
		tonumber(args[3]), -- ttl ms
		tonumber(args[4]), -- retention ms
		args[6], -- the write-through's epoch (optional)
		tonumber(args[7] or 0), -- window ms (optional)
		args[8] -- whether the value is soft (optional)
	)
end
redis.register_function('[prefix]cache_versioned_set_stamped', cache_versioned_set_stamped)

-- Store an item a recomputation produced - the write that follows a miss:
-- refused (0) when the key holds an epoch other than the one the miss saw
-- (a delete() since the miss left its tombstone with a new one - see
-- Cache.lua - and a plain write brought its own). The same epoch, or none,
-- takes the write: every delete and plain write leaves its epoch for a full
-- window, so a key without one had neither within the window. Stamped with
-- the rule id the MISS saw, so a rule appended between the miss and this
-- write makes the item stale on its next read
-- cluster-safe: one declared key
local function cache_versioned_set_guarded(keys, args)
	local epoch = redis.call('HGET', keys[1], 'epoch')
	if epoch and epoch ~= args[6] then
		return 0
	end
	
	return cache_versioned_write(
		keys[1],
		args[5], -- the rule id the miss saw
		args[1], -- data
		args[2], -- tags
		tonumber(args[3]), -- ttl ms
		tonumber(args[4]), -- retention ms
		nil, -- a guarded write keeps the epoch it compared
		tonumber(args[7] or 0), -- window ms
		args[8] -- whether the value is soft (optional)
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
		redis.call('HDEL', keys[1], 'data', 'tags', 'mark', 'soft')
		-- the epoch is kept for the window only: a delete or a write is seen by
		-- the writes made within the window after it, and a write whose miss saw
		-- this epoch is taken whether it is still there or gone, so beyond the
		-- window the key is memory and nothing else (an item's TTL runs up to
		-- the retention)
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
-- what the item once saw (see Rules::isStale()).
--
-- The stream is the only record that an invalidation happened, so it
-- carries no TTL: under a volatile-* policy that keeps it out of the
-- eviction pool, where a TTL made it a candidate like any item. PERSIST
-- also strips the TTL from a stream written by an earlier version. Items
-- cannot outlive the rules anyway - their TTL is capped to the retention,
-- and the trim below drops only what nothing alive can match.
--
-- It answers the new id, the id of the rule just before it ('0-0' when the
-- stream was empty) and whether it opened the stream. A caller whose copy of
-- the rules ends with that previous id adds the new rule to its copy itself
-- (see RedisVersioned::addRule()). A copy that ends anywhere else would skip
-- whatever someone else added in between.
local function cache_versioned_invalidate(keys, args)
	local rules_key = keys[1]
	local mode = args[1]
	local tags = args[2]
	local retention_ms = tonumber(args[3])
	-- invalidateTags(hard: true): the rule says so, and a soft value it
	-- reaches is a miss at once (absent from an older caller: soft-capable)
	local fields = {'mode', mode, 'tags', tags}
	if args[4] == '1' then
		table.insert(fields, 'hard')
		table.insert(fields, '1')
	end
	
	-- the newest rule before this one, which the caller compares with its copy
	local head = redis.call('XREVRANGE', rules_key, '+', '-', 'COUNT', 1)
	local previous = head[1] and head[1][1] or '0-0'
	local opened = '0'
	
	-- the entry id is generated by the stream: server milliseconds plus
	-- a sequence number, guaranteed monotonic even across clock hiccups
	local id = redis.call('XADD', rules_key, 'NOMKSTREAM', '*', 'first', '0', unpack(fields))
	
	if not id then
		-- no stream to append to: this rule opens one
		opened = '1'
		id = redis.call('XADD', rules_key, '*', 'first', '1', unpack(fields))
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
	
	return {id, previous, opened}
end
redis.register_function('[prefix]cache_versioned_invalidate', cache_versioned_invalidate)
