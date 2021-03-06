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

namespace Desperado\ServiceBus\EventSourcingSnapshots;

use function Amp\call;
use Amp\Promise;
use Amp\Success;
use Desperado\ServiceBus\EventSourcing\Aggregate;
use Desperado\ServiceBus\EventSourcing\AggregateId;
use Desperado\ServiceBus\EventSourcing\AggregateSnapshot;
use Desperado\ServiceBus\EventSourcingSnapshots\SnapshotStore\SnapshotStore;
use Desperado\ServiceBus\EventSourcingSnapshots\SnapshotStore\StoredAggregateSnapshot;
use Desperado\ServiceBus\EventSourcingSnapshots\Trigger\SnapshotTrigger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Snapshotter
 */
final class Snapshotter
{
    /**
     * Snapshot storage
     *
     * @var SnapshotStore
     */
    private $storage;

    /**
     * Snapshot generation trigger
     *
     * @var SnapshotTrigger
     */
    private $trigger;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param SnapshotStore        $storage
     * @param SnapshotTrigger      $trigger
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        SnapshotStore $storage,
        SnapshotTrigger $trigger,
        LoggerInterface $logger = null
    )
    {
        $this->storage = $storage;
        $this->trigger = $trigger;
        $this->logger  = $logger ?? new NullLogger();
    }

    /**
     * Load snapshot for aggregate
     *
     * @param AggregateId $id
     *
     * @psalm-suppress MoreSpecificReturnType Incorrect resolving the value of the promise
     * @psalm-suppress LessSpecificReturnStatement Incorrect resolving the value of the promise
     *
     * @return Promise<\Desperado\EventSourcing\Snapshot\AggregateSnapshot|null>
     */
    public function load(AggregateId $id): Promise
    {
        $storage = $this->storage;
        $logger  = $this->logger;

        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function(AggregateId $id) use ($storage, $logger): \Generator
            {
                $snapshot = null;

                try
                {
                    /** @var StoredAggregateSnapshot|null $storedSnapshot */
                    $storedSnapshot = yield $storage->load($id);

                    if(null !== $storedSnapshot)
                    {
                        $snapshot = new AggregateSnapshot(
                            \unserialize($storedSnapshot->payload(), ['allowed_classes' => true]),
                            $storedSnapshot->version()
                        );
                    }

                    return yield new Success($snapshot);
                }
                catch(\Throwable $throwable)
                {
                    $logger->error($throwable->getMessage(), ['e' => $throwable]);
                }

                return yield new Success($snapshot);
            },
            $id
        );
    }

    /**
     * Store new snapshot
     *
     * @param AggregateSnapshot $snapshot
     *
     * @psalm-suppress MoreSpecificReturnType Incorrect resolving the value of the promise
     * @psalm-suppress LessSpecificReturnStatement Incorrect resolving the value of the promise
     *
     * @return Promise<null>
     */
    public function store(AggregateSnapshot $snapshot): Promise
    {
        $storage = $this->storage;
        $logger  = $this->logger;

        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function(AggregateSnapshot $snapshot) use ($storage, $logger): \Generator
            {
                try
                {
                    yield $storage->remove($snapshot->aggregate()->id());
                    yield $storage->save(
                        new StoredAggregateSnapshot(
                            (string) $snapshot->aggregate()->id(),
                            \get_class($snapshot->aggregate()->id()),
                            \get_class($snapshot->aggregate()),
                            $snapshot->version(),
                            \serialize($snapshot->aggregate()),
                            \date('Y-m-d H:i:s')
                        )
                    );
                }
                catch(\Throwable $throwable)
                {
                    $logger->error($throwable->getMessage(), ['e' => $throwable]);
                }

                return yield new Success();
            },
            $snapshot
        );
    }

    /**
     * A snapshot must be created
     *
     * @param Aggregate         $aggregate
     * @param AggregateSnapshot $previousSnapshot
     *
     * @return bool
     */
    public function snapshotMustBeCreated(Aggregate $aggregate, AggregateSnapshot $previousSnapshot = null): bool
    {
        return $this->trigger->snapshotMustBeCreated($aggregate, $previousSnapshot);
    }
}
