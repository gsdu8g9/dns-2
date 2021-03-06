<?php

/*
 * This file is part of the DNS package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Dns\Executor;

use Icicle\Awaitable\Exception\TimeoutException;
use Icicle\Dns\Exception\{
    FailureException,
    InvalidTypeError,
    NoResponseException,
    ResponseCodeException,
    ResponseIdException
};
use Icicle\Socket;
use Icicle\Socket\Connector\Connector as SocketConnector;
use LibDNS\Messages\{Message, MessageFactory, MessageTypes};
use LibDNS\{Encoder\EncoderFactory, Decoder\DecoderFactory};
use LibDNS\Records\{Question, QuestionFactory};

class BasicExecutor implements Executor
{
    const PROTOCOL = 'udp';
    const DEFAULT_PORT = 53;
    const MAX_PACKET_SIZE = 512;

    private static $recordTypes = [
        'A'          => 1,
        'AAAA'       => 28,
        'ALL'        => 255,
        'AFSDB'      => 18,
        'ANY'        => 255,
        'APL'        => 42,
        'AXFR'       => 252,
        'CAA'        => 257,
        'CDNSKEY'    => 60,
        'CDS'        => 59,
        'CERT'       => 37,
        'CNAME'      => 5,
        'DHCID'      => 49,
        'DLV'        => 32769,
        'DNAME'      => 39,
        'DNSKEY'     => 48,
        'DS'         => 43,
        'HIP'        => 55,
        'IPSECKEY'   => 45,
        'IXFR'       => 251,
        'KEY'        => 25,
        'KX'         => 36,
        'LOC'        => 29,
        'MAILB'      => 253,
        'MAILA'      => 254,
        'MX'         => 15,
        'NAPTR'      => 35,
        'NS'         => 2,
        'NSEC'       => 47,
        'NSEC3'      => 50,
        'NSEC3PARAM' => 51,
        'OPT'        => 41,
        'PTR'        => 12,
        'RRSIG'      => 46,
        'SIG'        => 24,
        'SOA'        => 6,
        'SRV'        => 33,
        'SSHFP'      => 44,
        'TA'         => 32768,
        'TKEY'       => 249,
        'TLSA'       => 52,
        'TSIG'       => 250,
        'TXT'        => 16,
        '*'          => 255,
    ];

    /**
     * @var string IP address of DNS server.
     */
    private $address;

    /**
     * @var int
     */
    private $port;

    /**
     * @var \LibDNS\Messages\MessageFactory
     */
    private $messageFactory;

    /**
     * @var \LibDNS\Records\QuestionFactory
     */
    private $questionFactory;
    
    /**
     * @var \LibDNS\Encoder\Encoder
     */
    private $encoder;
    
    /**
     * @var \LibDNS\Decoder\Decoder
     */
    private $decoder;

    /**
     * @var \Icicle\Socket\Connector\Connector
     */
    private $connector;
    
    /**
     * @param string $address Name server IP address to resolve queries.
     * @param int $port
     * @param \Icicle\Socket\Connector\Connector|null $connector
     */
    public function __construct($address, $port = self::DEFAULT_PORT, SocketConnector $connector = null)
    {
        $this->address = $address;
        $this->port = $port;
        
        $this->messageFactory = new MessageFactory();
        $this->questionFactory = new QuestionFactory();

        $this->encoder = (new EncoderFactory())->create();
        $this->decoder = (new DecoderFactory())->create();

        $this->connector = $connector ?: Socket\connector();
    }
    
    /**
     * {@inheritdoc}
     */
    public function execute(string $name, $type, array $options = []): \Generator
    {
        $timeout = isset($options['timeout']) ? (float) $options['timeout'] : self::DEFAULT_TIMEOUT;
        $retries = isset($options['retries']) ? (int) $options['retries'] : self::DEFAULT_RETRIES;

        if (0 > $retries) {
            $retries = 0;
        }

        $question = $this->createQuestion($name, $type);

        $request = $this->createRequest($question);

        $data = $this->encoder->encode($request);

        /** @var \Icicle\Socket\Socket $socket */
        $socket = yield from $this->connector->connect($this->address, $this->port, ['protocol' => self::PROTOCOL]);

        try {
            $attempt = 0;

            do {
                try {
                    yield from $socket->write($data);

                    $response = yield from $socket->read(self::MAX_PACKET_SIZE, null, $timeout);

                    try {
                        $response = $this->decoder->decode($response);
                    } catch (\Exception $exception) {
                        throw new FailureException($exception); // Wrap in more specific exception.
                    }

                    if (0 !== $response->getResponseCode()) {
                        throw new ResponseCodeException($response);
                    }

                    if ($response->getId() !== $request->getId()) {
                        throw new ResponseIdException($response);
                    }

                    return $response;
                } catch (TimeoutException $exception) {
                    // Ignore timeout and try the request again.
                }
            } while (++$attempt <= $retries);

            throw new NoResponseException('No response from server.');
        } finally {
            $socket->close();
        }
    }

    /**
     * IP address of the name server used by this executor.
     *
     * @return  string
     */
    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * @return  int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @param   string $name
     * @param   string|int $type
     *
     * @return \LibDNS\Records\Question
     *
     * @throws \Icicle\Dns\Exception\InvalidTypeError If the record type given is invalid.
     */
    protected function createQuestion(string $name, $type): Question
    {
        if (!is_int($type)) {
            $type = strtoupper($type);
            $types = static::getRecordTypes();
            if (!array_key_exists($type, $types)) {
                throw new InvalidTypeError($type);
            }
            $type = $types[$type];
        } elseif (0 > $type || 0xffff < $type) {
            throw new InvalidTypeError($type);
        }

        $question = $this->questionFactory->create($type);
        $question->setName($name);

        return $question;
    }

    /**
     * @param \LibDNS\Records\Question
     *
     * @return \LibDNS\Messages\Message
     */
    protected function createRequest(Question $question): Message
    {
        $request = $this->messageFactory->create(MessageTypes::QUERY);
        $request->getQuestionRecords()->add($question);
        $request->isRecursionDesired(true);

        $request->setID($this->createId());

        return $request;
    }

    /**
     * Creates message ID.
     *
     * @return int
     */
    protected function createId(): int
    {
        return random_int(0, 0xffff);
    }

    /**
     * @return int[]
     */
    protected static function getRecordTypes(): array
    {
        return self::$recordTypes;
    }
}
