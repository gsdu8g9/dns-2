<?php

/*
 * This file is part of the DNS package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2015 Aaron Piotrowski. All rights reserved.
 * @license Apache-2.0 See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Dns\Exception;

use LibDNS\Messages\Message;

class ResponseIdException extends ResponseException
{
    public function __construct(Message $response)
    {
        parent::__construct("Response ID did not match request ID", $response);
    }
}
