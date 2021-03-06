<?php
namespace Phly\Http;

use OutOfBoundsException;
use Psr\Http\Message\RequestInterface as RequestInterface;

/**
 * "Serve" incoming HTTP requests
 *
 * Given a callback, takes an incoming request, dispatches it to the
 * callback, and then sends a response.
 */
class Server
{
    /**
     * Level of output buffering at start of listen cycle; never flush more
     * than this.
     *
     * @var int
     */
    private $bufferLevel;

    /**
     * @var callable
     */
    private $callback;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var ResponseInterface
     */
    private $response;

    /**
     * Constructor
     *
     * Given a callback, a request, and a response, we can create a server.
     *
     * @param callable $callback
     * @param RequestInterface $request
     * @param ResponseInterface $response
     */
    public function __construct(
        callable $callback,
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $this->callback = $callback;
        $this->request  = $request;
        $this->response = $response;
    }

    /**
     * Allow retrieving the request, response and callback as properties
     *
     * @param string $name
     * @return mixed
     * @throws OutOfBoundsException for invalid properties
     */
    public function __get($name)
    {
        if (! property_exists($this, $name)) {
            throw new OutOfBoundsException('Cannot retrieve arbitrary properties from server');
        }
        return $this->{$name};
    }

    /**
     * Create a Server instance
     *
     * Creates a server instance from the callback and server array
     * passed; typically this will be the $_SERVER superglobal.
     *
     * @param callable $callback
     * @param array $server
     * @return self
     */
    public static function createServer(
        callable $callback,
        array $server
    ) {
        $request  = RequestFactory::fromServer($server);
        $response = new Response();
        return new self($callback, $request, $response);
    }

    /**
     * Create a Server instance from an existing request object
     *
     * Provided a callback, an existing request object, and optionally an
     * existing response object, create and return the Server instance.
     *
     * If no Response object is provided, one will be created.
     *
     * @param callable $callback
     * @param RequestInterface $request
     * @param null|ResponseInterface $response
     * @return self
     */
    public static function createServerFromRequest(
        callable $callback,
        RequestInterface $request,
        ResponseInterface $response = null
    ) {
        if (! $response) {
            $response = new Response();
        }
        return new self($callback, $request, $response);
    }

    /**
     * "Listen" to an incoming request
     *
     * If provided a $finalHandler, that callable will be used for
     * incomplete requests.
     *
     * Output buffering is enabled prior to invoking the attached
     * callback; any output buffered will be sent prior to any
     * response body content.
     *
     * @param null|callable $finalHandler
     */
    public function listen(callable $finalHandler = null)
    {
        $callback = $this->callback;
        ob_start();
        $this->bufferLevel = ob_get_level();
        $callback($this->request, $this->response, $finalHandler);
        $this->send($this->response);
    }

    /**
     * Send the response
     *
     * If headers have not yet been sent, they will be.
     *
     * If any output buffering remains active, it will be flushed.
     *
     * Finally, the response body will be emitted.
     *
     * @param ResponseInterface $response
     */
    private function send(ResponseInterface $response)
    {
        if (! headers_sent()) {
            $this->sendHeaders($response);
        }

        while (ob_get_level() >= $this->bufferLevel) {
            ob_end_flush();
        }

        $this->bufferLevel = null;

        // Using printf so that we can override the function when testing
        printf($response->getBody());
    }

    /**
     * Send response headers
     *
     * Sends the response status/reason, followed by all headers;
     * header names are filtered to be word-cased.
     *
     * @param ResponseInterface $response
     */
    private function sendHeaders(ResponseInterface $response)
    {
        if ($response->getReasonPhrase()) {
            header(sprintf(
                'HTTP/%s %d %s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                $response->getReasonPhrase()
            ));
        } else {
            header(sprintf(
                'HTTP/%s %d',
                $response->getProtocolVersion(),
                $response->getStatusCode()
            ));
        }

        foreach ($response->getHeaders() as $header => $value) {
            header(sprintf(
                '%s: %s',
                $this->filterHeader($header),
                implode(',', $value)
            ));
        }
    }

    /**
     * Filter a header name to wordcase
     *
     * @param string $header
     * @return string
     */
    private function filterHeader($header)
    {
        $filtered = str_replace('-', ' ', $header);
        $filtered = ucwords($filtered);
        return str_replace(' ', '-', $filtered);
    }
}
