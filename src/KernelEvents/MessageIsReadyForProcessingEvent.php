<?php

/**
 * PHP Service Bus (CQS implementation)
 *
 * @author  Maksim Masiukevich <desperado@minsk-info.ru>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace Desperado\ServiceBus\KernelEvents;


/**
 * The message is ready to be processed
 */
class MessageIsReadyForProcessingEvent extends AbstractMessageFlowEvent
{
    public const EVENT_NAME = 'service_bus.kernel_events.before_execution';
}
