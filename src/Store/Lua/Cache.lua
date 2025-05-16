--[[
	Cache Functions
	@author Marcin Gil <mg@ovos.at>
]]

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
redis.register_function('cache_batches', cache_batches)

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
redis.register_function('cache_split_string', cache_split_string)

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
redis.register_function('cache_scan_keys', cache_scan_keys)

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
redis.register_function('cache_hscan_keys', cache_hscan_keys)

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
	function_name = 'cache_get_tags',
	callback = cache_get_tags,
	flags = {'no-writes'}
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
	function_name = 'cache_get_ids_by_tag',
	callback = cache_get_ids_by_tag,
	flags = {'no-writes'}
}

-- Unlink (remove) items by their ids and remove references from all tags
local function cache_unlink_clean_tags(keys, args)
	local ids = keys
	local prefix = args[1]
	local prefix_ids = prefix .. args[2]
	local prefix_tag_ids = prefix .. args[3]
	local field_tags = args[4]
	
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
			
			-- remove the id itself
			redis.call('UNLINK', prefix_ids .. id)
		end
	
	end
	
	return 1
end
redis.register_function('cache_unlink_clean_tags', cache_unlink_clean_tags)

-- Unlink items by a given tag only if they match provided ids
local function cache_unlink_ids_by_tag(keys, args)
	local ids = keys
	local prefix = args[1]
	local tag = args[2]
	local prefix_ids = prefix .. args[3]
	local prefix_tag_ids = prefix .. args[4]
	
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
			-- remove the id itself
			redis.call('UNLINK', prefix_ids .. id)
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
redis.register_function('cache_unlink_ids_by_tag', cache_unlink_ids_by_tag)

-- Unlink all items from a given tag (removes every ID that tag references)
local function cache_unlink_by_tag(keys, args)
	local prefix = args[1]
	local tag = args[2]
	local prefix_ids = prefix .. args[3]
	local prefix_tag_ids = prefix .. args[4]
	
	if redis.call('EXISTS', prefix_tag_ids .. tag) == 0 then
		return 1
	end
	
	-- unlink every id matching input ids for the given tag
	local rems = {}
	cache_hscan_keys(prefix_tag_ids .. tag, 5000, function(id)
		-- save for removal after the loop
		table.insert(rems, id)
		-- remove the id itself
		redis.call('UNLINK', prefix_ids .. id)
	end)
	
	-- remove the ids from the tag hash in batches
	if #rems > 0 then
		for from, to in cache_batches(#rems) do
			redis.call('HDEL', prefix_tag_ids .. tag, unpack(rems, from, to))
		end
	end
	
	return 1
end
redis.register_function('cache_unlink_by_tag', cache_unlink_by_tag)

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
redis.register_function('cache_unlink_all', cache_unlink_all)

-- Removes references from a tag that no longer has valid items
local function cache_clean_tag(keys, args)
	local prefix = args[1]
	local tag = args[2]
	local prefix_ids = prefix .. args[3]
	local prefix_tag_ids = prefix .. args[4]
	
	if redis.call('EXISTS', prefix_tag_ids .. tag) == 0 then
		return 0
	end
	
	local rems = {}
	
	-- loop the tag
	cache_hscan_keys(prefix_tag_ids .. tag, 1000, function(id)
		-- Check if the ID still exists in the main items hash
		if redis.call('EXISTS', prefix_ids .. id) == 0 then
			table.insert(rems, id)
		end
	end)
		
	-- remove hash keys which no longer exist
	if #rems > 0 then
		for from, to in cache_batches(#rems) do
			redis.call('HDEL', prefix_tag_ids .. tag, unpack(rems, from, to))
		end
	end
	
	return #rems -- return count of deleted ids
end
redis.register_function('cache_clean_tag', cache_clean_tag)

-- Removes all items matching a prefix
-- (used to "flush" the database, but only the prefixed items)
local function cache_clear(keys, args)
	local prefix = args[1]
	
	-- unlink every ID
	local count = 0
	
	local ids = {}
	cache_scan_keys(prefix, 5000, function(key)
		table.insert(ids, key)
	end)
	
	if #ids > 0 then
		count = count + #ids
		for from, to in cache_batches(#ids) do -- security measure, not really needed with 5000 batch size
			redis.call('UNLINK', unpack(ids, from, to))
		end
	end
	
	return count -- return count of deleted ids
end
redis.register_function('cache_clear', cache_clear)