<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use DateTimeImmutable;
use Illuminate\Log\Events\MessageLogged;
use Kronn\Observability\Core;
use Kronn\Observability\Records\RecordType;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Captures every Laravel log line and emits a kronn-v1 "log" record.
 *
 * Wire: Laravel's Log::* methods all dispatch the MessageLogged event,
 * regardless of the channel. Listening here gives us zero-config log
 * capture — the customer's app does nothing special, every Log::info(),
 * Log::error(), etc. flows through to the ingest.
 *
 * Limitation: MessageLogged does not carry the originating channel.
 * We fall back to the configured default channel name. If exact channel
 * tracking ever matters, we'd switch to a Monolog handler instead.
 *
 * Loop prevention: the listener calls Core::wants() which returns false
 * when inside Core::ignore(). The ServiceProvider wraps selfFailureReporter
 * in ignore() so our own warning logs don't recurse.
 */
class MessageLoggedListener
{
    private const LEVEL_NAMES = [
        'debug', 'info', 'notice', 'warning',
        'error', 'critical', 'alert', 'emergency',
    ];

    public function __construct(
        private readonly Core $core,
        private readonly ?Level $minLevel = null,
        private readonly string $defaultChannel = 'app',
    ) {
    }

    public function __invoke(MessageLogged $event): void
    {
        if (! $this->core->wants(RecordType::Log)) {
            return;
        }

        try {
            $level = $this->normalizeLevel((string) $event->level);
        } catch (Throwable) {
            return; // unknown level, skip silently
        }

        if ($this->minLevel !== null && $level->value < $this->minLevel->value) {
            return;
        }

        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: $this->defaultChannel,
            level: $level,
            message: (string) $event->message,
            context: is_array($event->context) ? $event->context : [],
        );

        $this->core->record(
            RecordType::Log,
            fn () => $this->core->sensors->log($record),
        );
    }

    public static function levelFromName(string $name): ?Level
    {
        $normalized = strtolower(trim($name));
        if (! in_array($normalized, self::LEVEL_NAMES, true)) {
            return null;
        }

        return match ($normalized) {
            'debug'     => Level::Debug,
            'info'      => Level::Info,
            'notice'    => Level::Notice,
            'warning'   => Level::Warning,
            'error'     => Level::Error,
            'critical'  => Level::Critical,
            'alert'     => Level::Alert,
            'emergency' => Level::Emergency,
        };
    }

    private function normalizeLevel(string $name): Level
    {
        $level = self::levelFromName($name);
        if ($level === null) {
            throw new \InvalidArgumentException("Unknown level: {$name}");
        }

        return $level;
    }
}
