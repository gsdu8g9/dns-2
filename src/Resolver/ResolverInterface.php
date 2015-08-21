<?php

/*
 * This file is part of the DNS package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2015 Aaron Piotrowski. All rights reserved.
 * @license Apache-2.0 See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Dns\Resolver;

interface ResolverInterface
{
    const IPv4 = 1;  // A
    const IPv6 = 28; // AAAA

    /**
     * @coroutine
     *
     * @param   string $domain Domain name to resolve.
     * @param   mixed[] $options
     *
     * @return  \Generator
     *
     * @resolve string[] List of IP address. May return an empty array if the host cannot be found.
     *
     * @throws \Icicle\Dns\Exception\FailureException If the server returns a non-zero response code.
     */
    public function resolve($domain, array $options = []);
}
