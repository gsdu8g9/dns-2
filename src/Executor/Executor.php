<?php
namespace Icicle\Dns\Executor;

use Icicle\Coroutine\Coroutine;
use Icicle\Dns\Exception\FailureException;
use Icicle\Dns\Exception\InvalidTypeException;
use Icicle\Dns\Exception\NoResponseException;
use Icicle\Dns\Exception\NotFoundException;
use Icicle\Dns\Exception\ResponseException;
use Icicle\Socket\Client\Connector;
use Icicle\Socket\Client\ConnectorInterface;
use Icicle\Socket\Exception\TimeoutException;
use LibDNS\Messages\MessageFactory;
use LibDNS\Messages\MessageTypes;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Records\Question;
use LibDNS\Records\QuestionFactory;

class Executor implements ExecutorInterface
{
    const PROTOCOL = 'udp';
    const DEFAULT_PORT = 53;
    const MAX_PACKET_SIZE = 512;
    
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
     * @var \Icicle\Socket\Client\ConnectorInterface
     */
    private $connector;
    
    /**
     * @param   string $address Name server IP address to resolve queries.
     * @param   int $port
     * @param   \Icicle\Socket\Client\ConnectorInterface|null $connector
     */
    public function __construct($address, $port = self::DEFAULT_PORT, ConnectorInterface $connector = null)
    {
        $this->address = $address;
        $this->port = $port;
        
        $this->messageFactory = new MessageFactory();
        $this->questionFactory = new QuestionFactory();

        $this->encoder = (new EncoderFactory())->create();
        $this->decoder = (new DecoderFactory())->create();

        $this->connector = $connector ?: new Connector();
    }
    
    /**
     * @inheritdoc
     */
    public function execute($name, $type, $timeout = self::DEFAULT_TIMEOUT, $retries = self::DEFAULT_RETRIES)
    {
        $retries = (int) $retries;
        if (0 > $retries) {
            $retries = 0;
        }

        return new Coroutine($this->run($name, $type, $timeout, $retries));
    }

    /**
     * IP address of the name server used by this executor.
     *
     * @return  string
     */
    public function getAddress()
    {
        return $this->address;
    }

    /**
     * @return  int
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * @coroutine
     *
     * @param   string $name Domain name.
     * @param   string|int $type Record type (e.g., 'A', 'MX', 'AAAA', 'NS' or integer value of type)
     * @param   float|int $timeout Seconds until a request times out.
     * @param   int $retries Number of times to attempt the request.
     *
     * @return  \Generator
     *
     * @resolve \LibDNS\Messages\Message
     *
     * @reject  \Icicle\Dns\Exception\FailureException If the server responds with a non-zero response code or does
     *          not respond at all.
     * @reject  \Icicle\Dns\Exception\NotFoundException If a record for the given query is not found.
     * @reject  \Icicle\Dns\Exception\ResponseException If a non-zero response code is received.
     * @reject  \Icicle\Dns\Exception\NoResponseException If no response is received.
     */
    protected function run($name, $type, $timeout, $retries)
    {
        $question = $this->createQuestion($name, $type);

        $request = $this->createRequest($question);

        $data = $this->encoder->encode($request);

        /** @var \Icicle\Socket\Client\ClientInterface $client */
        $client = (yield $this->connect());

        $attempt = 0;

        do {
            try {
                $client->write($data);

                $response = $this->decoder->decode(
                    yield $client->read(self::MAX_PACKET_SIZE, null, $timeout)
                );

                if (0 !== $response->getResponseCode()) {
                    throw new ResponseException($response);
                }

                $answers = $response->getAnswerRecords();

                if (0 === count($answers)) {
                    $name = $question->getName();
                    $type = $question->getType();
                    throw new NotFoundException($name, $type);
                }

                yield $response;
                return;
            } catch (TimeoutException $exception) {
                // Ignore TimeoutException and try the request again.
            } catch (\UnexpectedValueException $exception) {
                throw new FailureException($exception);
            } catch (\InvalidArgumentException $exception) {
                throw new FailureException($exception);
            }
        } while (++$attempt <= $retries);

        throw new NoResponseException('No response from server.');
    }

    /**
     * @param   string $name
     * @param   string|int $type
     *
     * @return  \LibDNS\Records\Question
     */
    protected function createQuestion($name, $type)
    {
        if (!is_int($type)) {
            $type = strtoupper($type);
            // Error reporting suppressed since constant() emits an E_WARNING if constant not found.
            // Check for null === $value handles error.
            $value = @constant('\LibDNS\Records\ResourceQTypes::' . $type);
            if (null === $value) {
                throw new InvalidTypeException($type);
            }
            $type = $value;
        }

        $question = $this->questionFactory->create($type);
        $question->setName($name);

        return $question;
    }

    /**
     * @param   \LibDNS\Records\Question
     *
     * @return  \LibDNS\Messages\Message
     */
    protected function createRequest(Question $question)
    {
        $request = $this->messageFactory->create(MessageTypes::QUERY);
        $request->getQuestionRecords()->add($question);
        $request->isRecursionDesired(true);

        return $request;
    }

    /**
     * @return  \Icicle\Promise\PromiseInterface
     *
     * @see     \Icicle\Socket\Client\ConnectorInterface::connect()
     */
    protected function connect()
    {
        return $this->connector->connect($this->address, $this->port, ['protocol' => self::PROTOCOL]);
    }
}