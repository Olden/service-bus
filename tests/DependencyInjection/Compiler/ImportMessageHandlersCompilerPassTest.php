<?php

/**
 * PHP Service Bus (publish-subscribe pattern implementation)
 * Supports Saga pattern and Event Sourcing
 *
 * @author  Maksim Masiukevich <desperado@minsk-info.ru>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace Desperado\ServiceBus\Tests\DependencyInjection\Compiler;

use Desperado\ServiceBus\DependencyInjection\Compiler\ImportMessageHandlersCompilerPass;
use Desperado\ServiceBus\DependencyInjection\Compiler\TaggedMessageHandlersCompilerPass;
use Desperado\ServiceBus\Scheduler\SchedulerListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 *
 */
final class ImportMessageHandlersCompilerPassTest extends TestCase
{
    /**
     * @test
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function process(): void
    {
        $containerBuilder = new ContainerBuilder();

        (new ImportMessageHandlersCompilerPass([__DIR__ . '/../../../src'], []))->process($containerBuilder);
        (new TaggedMessageHandlersCompilerPass())->process($containerBuilder);

        static::assertTrue($containerBuilder->has('service_bus.services_locator'));
        static::assertTrue($containerBuilder->hasParameter('service_bus.services_map'));

        static::assertCount(1, $containerBuilder->getParameter('service_bus.services_map'));
        static::assertEquals([SchedulerListener::class], $containerBuilder->getParameter('service_bus.services_map'));
    }
}
