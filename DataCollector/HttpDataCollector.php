<?php

namespace Bolt\Extension\Blimp\Client\DataCollector;

use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Message\MessageInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\Stopwatch\Stopwatch;

class HttpDataCollector extends DataCollector implements SubscriberInterface {
    /**
     * Apache Common Log Format.
     * @link http://httpd.apache.org/docs/1.3/logs.html#common
     * @var string
     */
    const CLF = "{hostname} {req_header_User-Agent} - [{ts}] \"{method} {resource} {protocol}/{version}\" {code} {res_header_Content-Length}";

    private $watchs = [];
    private $calls = [];

    protected $data;

    public function getName() {
        return 'blimp-http';
    }

    public function collect(Request $request, Response $response, \Exception $exception = null) {
        $this->data = ['calls' => $this->calls];

        $this->watchs = [];
    }

    public function getCallsCount() {
        return count($this->data['calls']);
    }

    public function getCalls() {
        return $this->data['calls'];
    }

    public function getTime() {
        $time = 0;
        foreach ($this->data['calls'] as $call) {
            $time += $call['executionMS'];
        }

        return $time;
    }

    public function getEvents() {
        return [
            // Fire after responses are verified (which trigger error events).
            'before' => ['onBefore'],
            'complete' => ['onComplete', RequestEvents::VERIFY_RESPONSE - 10],
            'error' => ['onError', RequestEvents::EARLY],
        ];
    }

    public function onBefore(BeforeEvent $event) {
        $id = spl_object_hash($event->getRequest());

        $this->watchs[$id] = new Stopwatch();
        $this->watchs[$id]->start('guzzleRequest');
    }

    public function onComplete(CompleteEvent $event) {
        $this->logIt($event->getRequest(), $event->getResponse());
    }

    public function onError(ErrorEvent $event) {
        $this->logIt($event->getRequest(), $event->getResponse(), $event->getException());
    }

    public function logIt($request, $response, $ex = null) {
        $id = spl_object_hash($request);

        $watch = $this->watchs[$id]->stop('guzzleRequest');

        $this->calls[] = [
            'request_method' => $request ? $request->getMethod() : null,
            'request_path' => $request ? $request->getResource() : null,
            'request_headers' => $request ? $this->headers($request) : null,
            'response_status' => $response ? $response->getStatusCode() : null,
            'response_headers' => $response ?
            sprintf(
                'HTTP/%s %d %s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                $response->getReasonPhrase()
            ) . "\r\n" . $this->headers($response)
            : null,
            'log' => $this->format(HttpDataCollector::CLF,
                $request,
                $response,
                $ex
            ),
            'executionMS' => $watch->getDuration(),
            'memory' => $watch->getMemory(),
        ];

        unset($this->watchs[$id]);
    }

    // from: https://github.com/guzzle/log-subscriber/blob/0b37cd9ab4cb4346ce25cc98b0fea876d61f368e/src/Formatter.php

    /**
     * Returns a formatted message
     *
     * @param RequestInterface  $request    Request that was sent
     * @param ResponseInterface $response   Response that was received
     * @param \Exception        $error      Exception that was received
     * @param array             $customData Associative array of custom template data
     *
     * @return string
     */
    public function format($template,
        RequestInterface $request,
        ResponseInterface $response = null,
        \Exception $error = null,
        array $customData = []
    ) {
        $cache = $customData;
        return preg_replace_callback(
            '/{\s*([A-Za-z_\-\.0-9]+)\s*}/',
            function (array $matches) use ($request, $response, $error, &$cache) {
                if (isset($cache[$matches[1]])) {
                    return $cache[$matches[1]];
                }
                $result = '';
                switch ($matches[1]) {
                    case 'request':
                        $result = $request;
                        break;
                    case 'response':
                        $result = $response;
                        break;
                    case 'req_headers':
                        $result = trim($request->getMethod() . ' '
                            . $request->getResource()) . ' HTTP/'
                        . $request->getProtocolVersion() . "\r\n"
                        . $this->headers($request);
                        break;
                    case 'res_headers':
                        $result = $response ?
                        sprintf(
                            'HTTP/%s %d %s',
                            $response->getProtocolVersion(),
                            $response->getStatusCode(),
                            $response->getReasonPhrase()
                        ) . "\r\n" . $this->headers($response)
                        : 'NULL';
                        break;
                    case 'req_body':
                        $result = $request->getBody();
                        break;
                    case 'res_body':
                        $result = $response ? $response->getBody() : 'NULL';
                        break;
                    case 'ts':
                        $result = gmdate('c');
                        break;
                    case 'method':
                        $result = $request->getMethod();
                        break;
                    case 'url':
                        $result = $request->getUrl();
                        break;
                    case 'resource':
                        $result = $request->getResource();
                        break;
                    case 'req_version':
                        $result = $request->getProtocolVersion();
                        break;
                    case 'res_version':
                        $result = $response
                        ? $response->getProtocolVersion()
                        : 'NULL';
                        break;
                    case 'host':
                        $result = $request->getHost();
                        break;
                    case 'hostname':
                        $result = gethostname();
                        break;
                    case 'code':
                        $result = $response
                        ? $response->getStatusCode()
                        : 'NULL';
                        break;
                    case 'phrase':
                        $result = $response
                        ? $response->getReasonPhrase()
                        : 'NULL';
                        break;
                    case 'error':
                        $result = $error ? $error->getMessage() : 'NULL';
                        break;
                    default:
                        // handle prefixed dynamic headers
                        if (strpos($matches[1], 'req_header_') === 0) {
                            $result = $request->getHeader(substr($matches[1], 11));
                        } elseif (strpos($matches[1], 'res_header_') === 0) {
                            $result = $response
                            ? $response->getHeader(substr($matches[1], 11))
                            : 'NULL';
                        }
                }
                $cache[$matches[1]] = $result;
                return $result;
            },
            $template
        );
    }

    private function headers(MessageInterface $message) {
        $result = '';
        foreach ($message->getHeaders() as $name => $values) {
            $result .= $name . ': ' . implode(', ', $values) . "\r\n";
        }
        return trim($result);
    }
}
