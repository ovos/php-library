--[[
	Cache Search Functions (RediSearch)
	@author Marcin Gil <mg@ovos.at>
]]

-- Splits a set of items into batches, returning a function iterator
-- Each iteration returns (from, to) indexes for a slice of the collection
-- Has to be used for unpack() calls due to a limit of 8000 arguments
local function cache_search_batches(n, batch_size)
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
redis.register_function('[prefix]cache_search_batches', cache_search_batches)

-- An invalidated item: its tombstone (the "epoch" field alone, for the
-- window - see Cache.lua's invalidation guard), so a write computed before
-- this invalidation cannot land after it; a plain UNLINK for a caller from
-- before the guard. A tombstone carries no tags, so it leaves every tag
-- match at once - the loop below ends as it did with UNLINK.
-- A soft value's item (its "soft" field holds its stale time, ms) is marked
-- instead - soft invalidation, as Cache.lua's cache_soft_mark: a new epoch,
-- the "invalidated" mark, the data, the mark and its tags expiring with the
-- window (never later than the item would have). It keeps its tags until
-- then, so a hard invalidation (invalidateTags(hard: true)) after the soft
-- one still matches it - and tombstones it like any other; a write after the
-- mark sets its tags again (an HSET drops a field's expiry)
local function cache_search_remove(key, token, window_ms, hard)
	if token and window_ms and window_ms > 0 then
		local soft_ms = hard ~= true and tonumber(redis.call('HGET', key, 'soft') or '') or nil
		if soft_ms and soft_ms > 0 and redis.call('HEXISTS', key, 'data') == 1 then
			local ttl_ms = redis.call('PTTL', key)
			local left_ms = soft_ms
			if ttl_ms > 0 and ttl_ms < left_ms then
				left_ms = ttl_ms
			end
			
			redis.call('HSET', key, 'epoch', token, 'invalidated', '1')
			redis.call('HPEXPIRE', key, left_ms, 'LT', 'FIELDS', 4, 'data', 'invalidated', 'soft', 'tags')
			
			local keep_ms = math.max(left_ms, window_ms)
			if ttl_ms < 0 or ttl_ms < keep_ms then
				redis.call('PEXPIRE', key, keep_ms)
			end
			
			return
		end
		
		redis.call('DEL', key)
		redis.call('HSET', key, 'epoch', token)
		redis.call('PEXPIRE', key, window_ms)
		
		return
	end
	
	redis.call('UNLINK', key)
end

-- Unlink all items from given tags
-- Removes every ID that references any or all of the given tags,
-- depending on the syntax passed to "tags": 
-- * any: @tags:{New York|Los Angeles|Barcelona}
-- * all: @tags:{New York} @tags:{Los Angeles} @tags:{Barcelona}"
-- See https://redis.io/docs/latest/develop/interact/search-and-query/advanced-concepts/tags/
local function cache_search_unlink_by_tags(keys, args)
	local index = args[1]
	local tags = args[2]
	local token = args[3]
	local window_ms = tonumber(args[4])
	local hard = args[5] == '1' -- invalidateTags(hard: true): soft values are tombstoned too
	local batch_size = 10000
	local offset = 0
	
	-- the matches first, page by page (NOCONTENT: the keys alone, not their
	-- data), then their removal: a soft value's item stays matched (its tags
	-- stay - see cache_search_remove()), so a search from the start after
	-- each batch would find it again and again
	local matched = {}
	while true do
		local result = redis.call('FT.SEARCH', index, tags, 'NOCONTENT', 'LIMIT', offset, batch_size)
		for i = 2, #result do
			table.insert(matched, result[i])
		end
		
		offset = offset + batch_size
		if #result - 1 < batch_size or offset >= tonumber(result[1]) then
			break
		end
	end
	
	-- remove the matched items (one by one: each leaves its tombstone, or its
	-- soft mark)
	for _, key in ipairs(matched) do
		cache_search_remove(key, token, window_ms, hard)
	end
end
redis.register_function('[prefix]cache_search_unlink_by_tags', cache_search_unlink_by_tags)	
