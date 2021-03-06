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

namespace Desperado\ServiceBus\Transport\AmqpExt;

use function Amp\asyncCall;
use Amp\Loop;
use function Desperado\ServiceBus\Common\uuid;
use Desperado\ServiceBus\Transport\Consumer;
use Desperado\ServiceBus\Transport\IncomingEnvelope;
use Desperado\ServiceBus\Transport\Marshal\Decoder\TransportMessageDecoder;
use Desperado\ServiceBus\Transport\Marshal\Exceptions\DecodeMessageFailed;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Amqp extension-based consumer
 */
final class AmqpConsumer implements Consumer
{
    public const  STOP_MESSAGE_CONTENT                 = 'quit';
    private const ITERATIONS_BEFORE_GARBAGE_COLLECTION = 10000;

    /**
     * Listen queue
     *
     * @var \AMQPQueue
     */
    private $listenQueue;

    /**
     * Restore the message object from string
     *
     * @var TransportMessageDecoder
     */
    private $messageDecoder;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Total number of iterations during the session
     *
     * @var int
     */
    private $iterationsCount = 0;

    /**
     * A string describing this consumer. Used for canceling subscriptions with cancel()
     *
     * @var string
     */
    private $consumerTag;

    /**
     * Reason for end of subscription. If specified, the next cycle will stop the cycle
     *
     * @var string|null
     */
    private $cancelSubscriptionReason;

    /**
     * @param \AMQPQueue              $listenQueue
     * @param TransportMessageDecoder $messageDecoder
     * @param LoggerInterface|null    $logger
     */
    public function __construct(
        \AMQPQueue $listenQueue,
        TransportMessageDecoder $messageDecoder,
        LoggerInterface $logger = null
    )
    {
        $this->listenQueue    = $listenQueue;
        $this->messageDecoder = $messageDecoder;
        $this->logger         = $logger ?? new NullLogger();
    }

    /**
     * @inheritdoc
     */
    public function listen(callable $messageProcessor): void
    {
        Loop::run();

        $processor         = $this->messageHandler($messageProcessor);
        $this->consumerTag = \sha1(uuid());

        try
        {
            $this->listenQueue->consume(
                function(\AMQPEnvelope $envelope) use ($processor): void
                {
                    $processor->send($envelope);
                },
                \AMQP_NOPARAM,
                $this->consumerTag
            );
        }
        catch(\AMQPConnectionException | \AMQPChannelException $connectionException)
        {
            $this->handleConnectionFail($connectionException);
        }
        catch(\Throwable $throwable)
        {
            $this->logger->critical($throwable->getMessage(), ['e' => $throwable]);
        }
    }


    /**
     * Create message handler
     *
     * @param callable $messageProcessor
     *
     * @return \Generator
     */
    private function messageHandler(callable $messageProcessor): \Generator
    {
        while(true)
        {
            Loop::run();

            $this->checkCycleActivity();
            $this->increaseIterations();
            $this->garbageCleaning();

            /** @var \AMQPEnvelope $envelope */
            $envelope = yield;

            if(self::STOP_MESSAGE_CONTENT !== $envelope->getBody())
            {
                $this->process($envelope, $messageProcessor);
            }
            else
            {
                $this->acknowledge($envelope);
                $this->cancelSubscription('Received stop message command');
            }
        }
    }

    /**
     * Handle message
     *
     * @param \AMQPEnvelope $envelope
     * @param callable      $messageProcessor static function (IncomingEnvelope $incomingEnvelope): void {}
     *
     * @return void
     */
    private function process(\AMQPEnvelope $envelope, callable $messageProcessor): void
    {
        $operationId = uuid();

        try
        {
            if('' !== (string) $envelope->getBody())
            {
                /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
                asyncCall(
                    $messageProcessor,
                    static::transformEnvelope($operationId, $envelope, $this->messageDecoder)
                );
            }

            $this->acknowledge($envelope);
        }
        catch(DecodeMessageFailed $exception)
        {
            $this->handleDecodeFailed($operationId, $envelope, $exception);
        }
        catch(\Throwable $throwable)
        {
            $this->handleThrowable($operationId, $envelope, $throwable);
        }
    }

    /**
     * Create incoming message envelope
     *
     * @param string                  $operationId
     * @param \AMQPEnvelope           $envelope
     * @param TransportMessageDecoder $decoder
     *
     * @return IncomingEnvelope
     */
    private static function transformEnvelope(
        string $operationId,
        \AMQPEnvelope $envelope,
        TransportMessageDecoder $decoder
    ): IncomingEnvelope
    {
        $body         = $envelope->getBody();
        $unserialized = $decoder->unserialize($body);

        return new IncomingEnvelope(
            $operationId,
            $envelope->getBody(),
            $unserialized['message'],
            $decoder->denormalize(
                $unserialized['namespace'],
                $unserialized['message']
            ),
            self::extractHeaders($envelope)
        );
    }

    /**
     * Extract message headers
     *
     * @todo: only custom headers
     *
     * @param \AMQPEnvelope $envelope
     *
     * @return array<string, string>
     */
    private static function extractHeaders(\AMQPEnvelope $envelope): array
    {
        return $envelope->getHeaders();
    }

    /**
     * Message accepted for processing
     *
     * @param \AMQPEnvelope $envelope
     *
     * @return void
     */
    private function acknowledge(\AMQPEnvelope $envelope): void
    {
        try
        {
            $this->listenQueue->ack($envelope->getDeliveryTag());
        }
        catch(\Throwable $throwable)
        {
            $this->logger->error(
                'Acknowledge error: "{throwableMessage}"', [
                    'throwableMessage' => $throwable->getMessage(),
                    'throwablePoint'   => \sprintf('%s:%d', $throwable->getFile(), $throwable->getLine())
                ]
            );
        }
    }

    /**
     * The message was not accepted to processing. When specifying a $retry flag, it will be re-sent to the queue
     *
     * @param \AMQPEnvelope $envelope
     * @param bool          $retry
     *
     * @return void
     */
    private function reject(\AMQPEnvelope $envelope, $retry = false): void
    {
        try
        {
            $this->listenQueue->reject(
                $envelope->getDeliveryTag(),
                true === $retry ? \AMQP_REQUEUE : \AMQP_NOPARAM
            );
        }
        catch(\Throwable $throwable)
        {
            $this->logger->error(
                'Error while rejecting message: "{throwableMessage}"', [
                    'throwableMessage' => $throwable->getMessage(),
                    'throwablePoint'   => \sprintf('%s:%d', $throwable->getFile(), $throwable->getLine())
                ]
            );
        }
    }

    /**
     * Mark subscription as canceled
     *
     * @param string $reason
     *
     * @return void
     */
    private function cancelSubscription(string $reason): void
    {
        $this->cancelSubscriptionReason = $reason;
    }

    /**
     * Increase loop iterations count
     *
     * @return void
     */
    private function increaseIterations(): void
    {
        $this->iterationsCount++;
    }

    /**
     * If a command was issued to stop the loop, perform this
     *
     * @return void
     */
    private function checkCycleActivity(): void
    {
        if(null !== $this->cancelSubscriptionReason)
        {
            $this->logger->info(
                'Cancel subscription with reason: "{cancelSubscriptionReason}"', [
                    'cancelSubscriptionReason' => $this->cancelSubscriptionReason,
                    'consumerTag'              => $this->consumerTag
                ]
            );

            try
            {
                $this->listenQueue->cancel($this->consumerTag);
            }
            catch(\Throwable $throwable)
            {

            }
        }
    }

    /**
     * @return void
     */
    private function garbageCleaning(): void
    {
        if(0 === $this->iterationsCount % self::ITERATIONS_BEFORE_GARBAGE_COLLECTION)
        {
            $this->logger->info(
                'Processed "{iterationsCount}" iterations. Number of collected cycles: "{collectedCycles}"', [
                    'iterationsCount' => $this->iterationsCount,
                    'collectedCycles' => \gc_collect_cycles()
                ]
            );
        }
    }

    /**
     * @param string              $operationId
     * @param \AMQPEnvelope       $envelope
     * @param DecodeMessageFailed $exception
     *
     * @return void
     */
    private function handleDecodeFailed(string $operationId, \AMQPEnvelope $envelope, DecodeMessageFailed $exception): void
    {
        $this->logger->error(
            'An incorrectly serialized message was received. Error details: "{throwableMessage}"', [
                'throwableMessage'  => $exception->getMessage(),
                'throwablePoint'    => \sprintf('%s:%d', $exception->getFile(), $exception->getLine()),
                'operationId'       => $operationId,
                'rawMessagePayload' => $envelope->getBody()
            ]
        );

        $this->acknowledge($envelope);
    }

    /**
     * @param \Exception $connectionException
     *
     * @return void
     */
    private function handleConnectionFail(\Exception $connectionException): void
    {
        $this->logger->emergency(
            'Connection to broker failed: "{throwableMessage}". Cancel subscription', [
                'throwableMessage' => $connectionException->getMessage(),
                'throwablePoint'   => \sprintf('%s:%d', $connectionException->getFile(), $connectionException->getLine())
            ]
        );

        $this->cancelSubscription('Connection to broker failed');
    }

    /**
     * @param string        $operationId
     * @param \AMQPEnvelope $envelope
     * @param \Throwable    $throwable
     *
     * @return void
     */
    private function handleThrowable(string $operationId, \AMQPEnvelope $envelope, \Throwable $throwable): void
    {
        $this->logger->error(
            'Error processing message: "{throwableMessage}"', [
                'throwableMessage'  => $throwable->getMessage(),
                'throwablePoint'    => \sprintf('%s:%d', $throwable->getFile(), $throwable->getLine()),
                'operationId'       => $operationId,
                'rawMessagePayload' => $envelope->getBody(),
            ]
        );

        $this->reject($envelope, true);
    }
}
