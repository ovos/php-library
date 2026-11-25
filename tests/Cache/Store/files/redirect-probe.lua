--[[
	A function for the cluster redirect tests (RedisClusterVersioned): it
	appends to a list, so the list's length says how often it ran, and answers
	more than the 16 KB a client's reply buffer holds, so a client output
	buffer limit closes the connection right after the call ran.
]]

-- keys: [1] the list; args: [1] the entry
local function redirect_probe_append(keys, args)
	redis.call('RPUSH', keys[1], args[1])
	
	return string.rep('x', 20000)
end
redis.register_function('[prefix]redirect_probe_append', redirect_probe_append)
