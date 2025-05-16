--[[
	Cache Search Functions
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
redis.register_function('cache_search_batches', cache_search_batches)

-- Unlink all items from given tags
-- Removes every ID that refereces any or all of the given tags,
-- depending on the syntax passed to "tags": 
-- * any: @tags:{New York|Los Angeles|Barcelona}
-- * all: @tags:{New York} @tags:{Los Angeles} @tags:{Barcelona}"
-- See https://redis.io/docs/latest/develop/interact/search-and-query/advanced-concepts/tags/
local function cache_search_unlink_by_tags(keys, args)
	local index = args[1]
	local tags = args[2]
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
		
		-- remove hash keys which no longer exist
		if #rems > 0 then
			for from, to in cache_search_batches(#rems) do
				redis.call('UNLINK', unpack(rems, from, to))
			end
		end
	end
end
redis.register_function('cache_search_unlink_by_tags', cache_search_unlink_by_tags)	
