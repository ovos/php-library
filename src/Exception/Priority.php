<?php
declare(strict_types=1);

namespace Ovos\Exception;

/**
 * Syslog severities (RFC 5424 / BSD syslog), lowest number = most severe —
 * the framework-wide severity scale: Exception::withPriority()/HasPriority,
 * the Logger/Events message default and the error console v1 payload all
 * speak it (see Ovos\Service\Console\Payload::priorityFor).
 *
 * These are our own constants on purpose: PHP's built-in LOG_* constants are
 * platform-dependent (Windows maps them to different numbers, e.g. LOG_ERR is
 * 4 there, not 3), so they cannot express this fixed 0-7 contract portably.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class Priority
{
	public const int EMERGENCY = 0;
	public const int ALERT = 1;
	public const int CRITICAL = 2;
	public const int ERROR = 3;
	public const int WARNING = 4;
	public const int NOTICE = 5;
	public const int INFO = 6;
	public const int DEBUG = 7;
}
