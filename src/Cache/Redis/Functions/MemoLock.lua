--[[
	Redis MemoLock Functions
	@author Marcin Gil <mg@ovos.at>
]]

-- Renew a lock if we still own it
local function memolock_renew_lock(keys, args)
	local lock_key = keys[1]
	local lock_value = args[1]
	local new_ttl_ms = args[2]
	
	if redis.call('GET', lock_key) == lock_value then
		-- we still own the lock, so extend its life
		return redis.call('PEXPIRE', lock_key, new_ttl_ms)
		-- 0 if the timeout was not set. For example, if the key doesn't exist, or the operation skipped because of the provided arguments.
		-- 1 if the timeout was set.
	end
	
	-- we lost the lock, signal failure
	return 0
end
redis.register_function('[prefix]memolock_renew_lock', memolock_renew_lock)

-- Release a lock and publish a notification
local function memolock_release_lock_and_publish(keys, args)
	local lock_key = keys[1]
	local channel = keys[2]
	local lock_value = args[1]
	
	-- only delete the lock if we still own it (value matches)
	if redis.call('GET', lock_key) == lock_value then
		-- notify waiters on the channel
		redis.call('PUBLISH', channel, '1')
		-- delete the lock
		return redis.call('DEL', lock_key)
	end
	
	return 0
end
redis.register_function('[prefix]memolock_release_lock_and_publish', memolock_release_lock_and_publish)
