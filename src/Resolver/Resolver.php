<?php

/*
 * This file is part of the DNS package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2015 Aaron Piotrowski. All rights reserved.
 * @license Apache-2.0 See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Dns\Resolver;

use Icicle\Dns\Exception\InvalidArgumentError;
use Icicle\Dns\Executor\{Executor, ExecutorInterface, MultiExecutor};

class Resolver implements ResolverInterface
{
    /**
     * @var \Icicle\Dns\Executor\ExecutorInterface
     */
    private $executor;
    
    /**
     * @param \Icicle\Dns\Executor\ExecutorInterface|null $executor
     */
    public function __construct(ExecutorInterface $executor = null)
    {
        // @codeCoverageIgnoreStart
        if (null === $executor) {
            $this->executor = new MultiExecutor();
            $this->executor->add(new Executor('8.8.8.8'));
            $this->executor->add(new Executor('8.8.4.4'));
        } else { // @codeCoverageIgnoreEnd
            $this->executor = $executor;
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function resolve(string $domain, array $options = []): \Generator
    {
        $mode = isset($options['mode']) ? $options['mode'] : self::IPv4;

        if (!($mode === self::IPv4 || $mode === self::IPv6)) {
            throw new InvalidArgumentError('Invalid resolver mode.');
        }

        if (strtolower($domain) === 'localhost') {
            return $mode === self::IPv4 ? ['127.0.0.1'] : ['::1'];
        }

        /** @var \LibDNS\Messages\Message $response */
        $response = yield from $this->executor->execute($domain, $mode, $options);

        $answers = $response->getAnswerRecords();

        $result = [];

        /** @var \LibDNS\Records\Resource $record */
        foreach ($answers as $record) {
            // Skip any CNAME or other records returned in result.
            if ($record->getType() === $mode) {
                $result[] = $record->getData()->getField(0)->getValue();
            }
        }

        return $result;
    }
}
