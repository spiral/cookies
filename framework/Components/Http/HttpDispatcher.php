<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamableInterface;
use Psr\Http\Message\UriInterface;
use Spiral\Components\Debug\Snapshot;
use Spiral\Components\Http\Router\RouterTrait;
use Spiral\Components\Http\Router\Router;
use Spiral\Components\View\ViewManager;
use Spiral\Core\Component;
use Spiral\Core\Container;
use Spiral\Core\CoreInterface;
use Spiral\Core\Dispatcher\ClientException;
use Spiral\Core\DispatcherInterface;

class HttpDispatcher extends Component implements DispatcherInterface
{
    /**
     * Required traits.
     */
    use Component\SingletonTrait,
        Component\LoggerTrait,
        Component\EventsTrait,
        Component\ConfigurableTrait,
        RouterTrait;

    /**
     * Declares to IoC that component instance should be treated as singleton.
     */
    const SINGLETON = 'http';

    /**
     * Max block size to use while sending streams to client. Default is 16Kb.
     */
    const STREAM_BLOCK_SIZE = 16384;

    /**
     * Core instance.
     *
     * @invisible
     * @var CoreInterface
     */
    protected $core = null;

    /**
     * Original server request generated by spiral while starting HttpDispatcher.
     *
     * @var Request
     */
    protected $request = null;

    /**
     * Set of middleware layers built to handle incoming Request and return Response. Middleware
     * can be represented as class, string (DI), closure or array (callable method). HttpDispatcher
     * layer middlewares will be called in start() method. This set of middleware(s) used to filter
     * http request and response on application layer.
     *
     * @var array|MiddlewareInterface[]|callable[]
     */
    protected $middlewares = array();

    /**
     * Endpoints is a set of middleware or callback used to handle some application parts separately
     * from application controllers and routes. Such Middlewares can perform their own routing,
     * mapping, render and etc and only have to return ResponseInterface object.
     *
     * You can use add() method to create new endpoint. Every endpoint should be specified as path
     * with / and in lower case.
     *
     * Example (in bootstrap):
     * $this->http->add('/forum', 'Vendor\Forum\Forum');
     *
     * P.S. Router middleware automatically assigned to base path of application.
     *
     * @var array|MiddlewareInterface[]
     */
    protected $endpoints = array();

    /**
     * Set of middleware aliases defined to be used in routes for filtering request and altering
     * response.
     *
     * @var array
     */
    protected $routeMiddlewares = array();

    /**
     * New HttpDispatcher instance.
     *
     * @param CoreInterface $core
     */
    public function __construct(CoreInterface $core)
    {
        $this->core = $core;
        $this->config = $core->loadConfig('http');
        $this->middlewares = $this->config['middlewares'];
        $this->endpoints = $this->config['endpoints'];

        $this->routeMiddlewares = $this->config['routeMiddlewares'];
    }

    /**
     * Application base path.
     *
     * @return string
     */
    public function getBasePath()
    {
        return $this->config['basePath'];
    }

    /**
     * Register new endpoint or middleware inside HttpDispatcher. HttpDispatcher will execute such
     * enterpoint only with URI path matched to specified value. The rest of http flow will be
     * given to this enterpoint.
     *
     * Example (in bootstrap):
     * $this->http->add('/forum', 'Vendor\Forum\Forum');
     * $this->http->add('/blog', new Vendor\Module\Blog());
     *
     * @param string                              $path Http Uri path with / and in lower case.
     * @param string|callable|MiddlewareInterface $endpoint
     * @return static
     */
    public function add($path, $endpoint)
    {
        $this->endpoints[$path] = $endpoint;

        return $this;
    }

    /**
     * Letting dispatcher to control application flow and functionality.
     *
     * @param CoreInterface $core
     */
    public function start(CoreInterface $core)
    {
        if (empty($this->endpoints[$this->config['basePath']]))
        {
            //Base path wasn't handled, let's attach our router
            $this->endpoints[$this->config['basePath']] = $this->createRouter();
        }

        $pipeline = new MiddlewarePipe($this->middlewares);
        $response = $pipeline->target(array($this, 'perform'))->run(
            $this->getRequest(),
            $this
        );

        //Use $event->object->getRequest() to access original request
        $this->dispatch($this->event('dispatch', $response));
    }

    /**
     * Getting instance of default http router to be attached ot http base path (usually /).
     *
     * @return Router
     */
    protected function createRouter()
    {
        return Container::get(
            $this->config['router']['class'],
            array(
                'core'             => $this->core,
                'routes'           => $this->routes,
                'default'          => $this->config['router']['defaultRoute'],
                'routeMiddlewares' => $this->routeMiddlewares,
                'activePath'       => $this->config['basePath']
            )
        );
    }

    /**
     * Get initial request generated by HttpDispatcher. This is untouched request object, all
     * cookies will be encrypted and other values will not be pre-processed.
     *
     * @return Request|null
     */
    public function getRequest()
    {
        if (empty($this->request))
        {
            $this->request = Request::castRequest(array(
                'basePath'     => $this->config['basePath'],
                'exposeErrors' => $this->config['exposeErrors']
            ));
        }

        return $this->request;
    }

    /**
     * Execute given request and return response. Request Uri will be passed thought Http routes
     * to find appropriate endpoint. By default this method will be called at the end of middleware
     * pipeline inside HttpDispatcher->start() method, however method can be called manually with
     * custom or altered request instance.
     *
     * Every request passed to perform method will be registered in Container scope under "request"
     * and class name binding.
     *
     * @param Request $request
     * @return array|ResponseInterface
     * @throws ClientException
     */
    public function perform(Request $request)
    {
        if (!$endpoint = $this->findEndpoint($request->getUri(), $activePath))
        {
            //This should never happen as request should be handled at least by Router middleware
            throw new ClientException(Response::SERVER_ERROR, 'Unable to select endpoint');
        }

        $parentRequest = Container::getBinding('request');

        /**
         * So all inner middleware and code will known their context URL.
         */
        $request = $request->withAttribute('activePath', $activePath);

        //Creating scope
        Container::bind('request', $request);
        Container::bind(get_class($request), $request);

        $name = is_object($endpoint) ? get_class($endpoint) : $endpoint;

        benchmark('http::endpoint', $name);
        $response = $this->execute($request, $endpoint);
        benchmark('http::endpoint', $name);

        Container::removeBinding(get_class($request));
        Container::removeBinding('request');

        if (!empty($parentRequest))
        {
            //Restoring scope
            Container::bind('request', $parentRequest);
            Container::bind(get_class($parentRequest), $parentRequest);
        }

        return $response;
    }

    /**
     * Locate appropriate middleware endpoint based on Uri part.
     *
     * @param UriInterface $uri     Request Uri.
     * @param string       $uriPath Selected path.
     * @return null|MiddlewareInterface
     */
    protected function findEndpoint(UriInterface $uri, &$uriPath = null)
    {
        $uriPath = strtolower($uri->getPath());
        if (isset($this->endpoints[$uriPath]))
        {
            return $this->endpoints[$uriPath];
        }
        else
        {
            foreach ($this->endpoints as $path => $middleware)
            {
                if (strpos($uriPath, $path) === 0)
                {
                    $uriPath = $path;

                    return $middleware;
                }
            }
        }

        return null;
    }

    /**
     * Execute endpoint middleware. Right now this method supports only spiral middlewares, but can
     * also be easily changed to support another syntax like handle(request, response).
     *
     * @param Request                             $request
     * @param string|callable|MiddlewareInterface $endpoint
     * @return mixed
     */
    protected function execute(Request $request, $endpoint)
    {
        /**
         * @var callable $endpoint
         */
        $endpoint = is_string($endpoint) ? Container::get($endpoint) : $endpoint;

        ob_start();
        $response = $endpoint($request, null, $this);
        $plainOutput = ob_get_clean();

        return $this->wrapResponse($response, $plainOutput);
    }

    /**
     * Helper method used to wrap raw response from middlewares and controllers to correct Response
     * class. Method support string and JsonSerializable (including arrays) inputs. Default status
     * will be set as 200. If you want to specify default set of headers for raw responses check
     * http->config->headers section.
     *
     * You can force status for JSON responses by providing response as array with "status" key equals
     * to desired HTTP code.
     *
     * @param mixed  $response
     * @param string $plainOutput
     * @return Response
     */
    protected function wrapResponse($response, $plainOutput = '')
    {
        if ($response instanceof ResponseInterface)
        {
            if (!empty($plainOutput))
            {
                $response->getBody()->write($plainOutput);
            }

            return $response;
        }

        if (is_array($response) || $response instanceof \JsonSerializable)
        {
            if (is_array($response) && !empty($plainOutput))
            {
                $response['plainOutput'] = $plainOutput;
            }

            $code = 200;
            if (is_array($response) && isset($response['status']))
            {
                $code = $response['status'];
            }

            return new Response(json_encode($response), $code, array(
                'Content-Type' => 'application/json'
            ));
        }

        return new Response($response . $plainOutput);
    }

    /**
     * Dispatch provided request to client. Application will stop after this method call.
     *
     * @param ResponseInterface $response
     */
    public function dispatch(ResponseInterface $response)
    {
        while (ob_get_level())
        {
            ob_get_clean();
        }

        $statusHeader = "HTTP/{$response->getProtocolVersion()} {$response->getStatusCode()}";
        header(rtrim("{$statusHeader} {$response->getReasonPhrase()}"));

        $defaultHeaders = $this->config['headers'];
        foreach ($response->getHeaders() as $header => $values)
        {
            unset($defaultHeaders[$header]);

            $replace = true;
            foreach ($values as $value)
            {
                header("{$header}: {$value}", $replace);
                $replace = false;
            }
        }

        if (!empty($defaultHeaders))
        {
            //We can force some header values if no replacement specified
            foreach ($defaultHeaders as $header => $value)
            {
                header("{$header}: {$value}");
            }
        }

        if ($response->getStatusCode() == 204)
        {
            return;
        }

        $this->sendStream($response->getBody());
    }

    /**
     * Sending stream content to client.
     *
     * @param StreamableInterface $stream
     */
    protected function sendStream(StreamableInterface $stream)
    {
        if (!$stream->isSeekable())
        {
            echo (string)$stream;
        }
        else
        {
            ob_implicit_flush(true);
            $stream->rewind();

            while (!$stream->eof())
            {
                echo $stream->read(static::STREAM_BLOCK_SIZE);
            }
        }
    }

    /**
     * Every dispatcher should know how to handle exception snapshot provided by Debugger.
     *
     * @param Snapshot $snapshot
     * @return void
     */
    public function handleException(Snapshot $snapshot)
    {
        $exception = $snapshot->getException();
        if ($exception instanceof ClientException)
        {
            $uri = $this->request->getUri();
            self::logger()->warning(
                "{scheme}://{host}{path} caused the error {code} ({message}) by client {remote}.",
                array(
                    'scheme'  => $uri->getScheme(),
                    'host'    => $uri->getHost(),
                    'path'    => $uri->getPath(),
                    'code'    => $exception->getCode(),
                    'message' => $exception->getMessage() ?: '-not specified-',
                    'remote'  => $this->request->remoteAddr()
                )
            );

            $this->dispatch($this->errorResponse($exception->getCode()));

            return;
        }

        if (!$this->config['exposeErrors'])
        {
            $this->dispatch($this->errorResponse(500));

            return;
        }

        //We can expose snapshot to client
        $this->dispatch(new Response($snapshot->renderSnapshot(), 500));
    }

    /**
     * Get response dedicated to represent server or client error.
     *
     * @param int $code
     * @return Response
     */
    protected function errorResponse($code)
    {
        $content = '';

        if (strpos($this->request->getHeader('Accept', false), 'application/json') !== false)
        {
            $content = array('status' => $code);

            return new Response(json_encode($content), $code, array(
                'Content-Type' => 'application/json'
            ));
        }

        if (isset($this->config['httpErrors'][$code]))
        {
            //We can render some content
            $content = ViewManager::getInstance()->render(
                $this->config['httpErrors'][$code],
                array('request' => $this->request)
            );
        }

        return new Response($content, $code);
    }
}
