<?php

declare(strict_types=1);

namespace Kronn\Observability\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * Detects framework features by version. Each flag points to the release
 * that introduced it. The class exists so we don't sprinkle
 * `version_compare()` calls throughout the codebase.
 *
 * Naming: `has*` for the presence of an event or method, `supports*`
 * for parametrizable behavior (e.g. sub-minute scheduler).
 */
class LaravelFeatures
{
    public bool $hasContextFacade = false;          // L11.0
    public bool $hasTerminatingEvent = false;       // L11.18
    public bool $hasScheduledTaskEvents = false;    // varies L10/L11/L12
    public bool $hasSubMinuteScheduler = false;     // L10.15
    public bool $hasQueuedJobDuration = false;      // L10.42
    public bool $hasCacheStoreInEvents = false;     // L11.0
    public bool $hasCacheDuration = false;          // L11.11
    public bool $hasCacheFailureEvents = false;     // L11.11
    public bool $hasMailableClassNameMacro = false; // L11.27
    public bool $hasQueryConnectionType = false;    // L12.45

    public string $laravelVersion = '0.0.0';
    public string $phpVersion = PHP_VERSION;

    public static function detect(Application $app): self
    {
        $instance = new self();
        $instance->laravelVersion = (string) $app->version();

        $v = $instance->laravelVersion;

        $instance->hasContextFacade        = version_compare($v, '11.0.0', '>=');
        $instance->hasCacheStoreInEvents   = $instance->hasContextFacade;
        $instance->hasCacheDuration        = version_compare($v, '11.11.0', '>=');
        $instance->hasCacheFailureEvents   = $instance->hasCacheDuration;
        $instance->hasTerminatingEvent     = version_compare($v, '11.18.0', '>=');
        $instance->hasMailableClassNameMacro = version_compare($v, '11.27.0', '>=');
        $instance->hasScheduledTaskEvents  = version_compare($v, '12.18.0', '>=')
            || version_compare($v, '12.11.0', '=');
        $instance->hasSubMinuteScheduler   = version_compare($v, '10.15.0', '>=');
        $instance->hasQueuedJobDuration    = version_compare($v, '10.42.0', '>=');
        $instance->hasQueryConnectionType  = version_compare($v, '12.45.0', '>=');

        return $instance;
    }
}
