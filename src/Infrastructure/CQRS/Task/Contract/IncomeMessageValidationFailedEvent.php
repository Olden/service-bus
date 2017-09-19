<?php

/**
 * Command Query Responsibility Segregation, Event Sourcing implementation
 *
 * @author  Maksim Masiukevich <desperado@minsk-info.ru>
 * @url     https://github.com/mmasiukevich
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace Desperado\Framework\Infrastructure\CQRS\Task\Contract;

use Desperado\Framework\Domain\Messages\EventInterface;

/**
 * Validation failed
 */
class IncomeMessageValidationFailedEvent implements EventInterface
{
    /**
     * Message class
     *
     * @var string
     */
    public $message;

    /**
     * Violations
     *
     * [
     *    'propertyKey' => [
     *        0 => [
     *            'reasonMessage'
     *        ],
     *        ....
     *    ],
     *    ...
     * ]
     *
     * @var array
     */
    public $violations = [];
}