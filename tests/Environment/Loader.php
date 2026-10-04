<?php
declare(strict_types=1);

namespace Tests\Environment;

use Ovos\Cache\Store\Apcu;
use Ovos\Environment;
use Ovos\Environment\Loader as Subject;
use Ovos\Service\Memory;
use Ovos\Test;

use function clearstatcache;
use function copy;
use function filemtime;
use function sys_get_temp_dir;
use function tempnam;
use function touch;
use function unlink;

/**
 * Environment\Loader - the .env parse, and the remember/forget pair that
 * keeps an unresolvable environment out of APCu
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Loader extends Test
{
	protected const string FILE = __DIR__ . '/files/valid.env';
	protected const string CACHE_ID = 'tests-environment-loader';
	
	protected function subject(): Subject
	{
		$subject = $this->container->getClass(Subject::class);
		// every test starts from an empty slot
		$subject->forget(self::FILE, self::CACHE_ID);
		
		return $subject;
	}
	
	protected function store(): Apcu
	{
		return $this->container
			->getClass(Memory::SYMBOL)
			->getStore();
	}
	
	public function loadParsesTheFileWithoutRememberingIt(): bool
	{
		$environment = $this->subject()->load(self::FILE, self::CACHE_ID);
		
		return $environment instanceof Environment
			&& $environment->getEnv() === 'development'
			&& $environment->getConfig()['MYSQL']['HOST'] === 'db.local'
			&& $this->store()->get(self::CACHE_ID) === null;
	}
	
	public function rememberedEnvironmentAnswersTheNextLoad(): bool
	{
		$subject = $this->subject();
		$subject->remember(self::FILE, new Environment(['ENV' => 'remembered']), self::CACHE_ID);
		
		// the file still says development - the store answers first
		return $subject->load(self::FILE, self::CACHE_ID)?->getEnv() === 'remembered';
	}
	
	/**
	 * RULE: an edited .env is read on the next load — the remembered entry
	 * is keyed by the file's mtime.
	 *
	 * Prevents: a key added to .env staying invisible to the web side until
	 * an APCu clear that itself needs the key.
	 */
	public function anEditedFileIsReadAgain(): bool
	{
		$file = (string)tempnam(sys_get_temp_dir(), 'env');
		copy(self::FILE, $file);
		
		try
		{
			$subject = $this->subject();
			$subject->forget($file, self::CACHE_ID);
			$subject->remember($file, new Environment(['ENV' => 'remembered']), self::CACHE_ID);
			$before = $subject->load($file, self::CACHE_ID)?->getEnv();
			
			touch($file, (int)filemtime($file) + 10);
			clearstatcache();
			
			return $before === 'remembered'
				&& $subject->load($file, self::CACHE_ID)?->getEnv() === 'development';
		}
		finally
		{
			unlink($file);
		}
	}
	
	/**
	 * RULE: an entry remembered by the code before the mtime (a bare
	 * Environment) is no hit — the file is parsed again.
	 */
	public function anEntryWithoutAnMtimeIsParsedAgain(): bool
	{
		$subject = $this->subject();
		$this->store()->set(self::CACHE_ID, new Environment(['ENV' => 'old-shape']));
		
		return $subject->load(self::FILE, self::CACHE_ID)?->getEnv() === 'development';
	}
	
	public function forgetDropsTheRememberedEnvironment(): bool
	{
		$subject = $this->subject();
		$subject->remember(self::FILE, new Environment(['ENV' => 'remembered']), self::CACHE_ID);
		$subject->forget(self::FILE, self::CACHE_ID);
		
		return $this->store()->get(self::CACHE_ID) === null
			&& $subject->load(self::FILE, self::CACHE_ID)?->getEnv() === 'development';
	}
}
