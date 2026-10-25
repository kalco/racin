<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Container\Container;
use Racin\Queue\FailedJobRepository;
use Racin\Queue\QueueManager;

final class QueueRetryCommand extends QueueFailedCommand
{
    public function __construct(FailedJobRepository $failed, QueueManager $queues, Container $container)
    {
        parent::__construct($failed, $queues, $container, 'retry');
    }
}
