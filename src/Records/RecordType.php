<?php

declare(strict_types=1);

namespace Kronn\Observability\Records;

enum RecordType: string
{
    case Request = 'request';
    case Command = 'command';
    case Query = 'query';
    case Exception = 'exception';
    case OutgoingRequest = 'outgoing_request';
    case CacheEvent = 'cache_event';
    case Mail = 'mail';
    case Notification = 'notification';
    case QueuedJob = 'queued_job';
    case JobAttempt = 'job_attempt';
    case ScheduledTask = 'scheduled_task';
    case Log = 'log';
    case LazyLoad = 'lazy_load';
}
