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
-- the "invalidated" mark, the data and the mark expiring with the window
-- (never later than the item would have); its tags go, so it leaves every
-- tag match too
local function cache_search_remove(key, token, window_ms)
	if token and window_ms and window_ms > 0 then
		local soft_ms = tonumber(redis.call('HGET', key, 'soft') or '')
		if soft_ms and soft_ms > 0 and redis.call('HEXISTS', key, 'data') == 1 then
			local ttl_ms = redis.call('PTTL', key)
			local left_ms = soft_ms
			if ttl_ms > 0 and ttl_ms < left_ms then
				left_ms = ttl_ms
			end
			
			redis.call('HDEL', key, 'tags')
			redis.call('HSET', key, 'epoch', token, 'invalidated', '1')
			redis.call('HPEXPIRE', key, left_ms, 'LT', 'FIELDS', 3, 'data', 'invalidated', 'soft')
			
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
	local batch_size = 10000
	local offset = 0
	
	while true do
		-- Perform the FT.SEARCH with batching
		local search_command = {'FT.SEARCH', index, tags, 'LIMIT', offset, batch_size}
		local result = redis.call(unpack(search_command))
		
		-- quit if there are no more matches
		local total_results = tonumber(result[1])
		if total_results == 0 then
			break
		end
		
		local rems = {}
		
		-- loop every second item, skipping the first which is total_results
		for i = 2, #result, 2 do
			-- local id = result[i] -- The document ID/key
			-- local fields = result[i+1] -- The document's fields and values (array)
			
			table.insert(rems, result[i]) -- save for removal after the loop
		end
		
		-- remove the matched items (one by one: each leaves its tombstone)
		for _, key in ipairs(rems) do
			cache_search_remove(key, token, window_ms)
		end
	end
end
redis.register_function('[prefix]cache_search_unlink_by_tags', cache_search_unlink_by_tags)	
