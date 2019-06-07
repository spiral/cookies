<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
declare(strict_types=1);

namespace Spiral\Cookies\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Spiral\Cookies\Config\CookiesConfig;
use Spiral\Cookies\Cookie;
use Spiral\Cookies\CookieQueue;
use Spiral\Core\ScopeInterface;
use Spiral\Encrypter\EncryptionInterface;
use Spiral\Encrypter\Exception\DecryptException;
use Spiral\Encrypter\Exception\EncryptException;

/**
 * Middleware used to encrypt and decrypt cookies. Creates container scope for a cookie bucket.
 *
 * Attention, EncrypterInterface is requested from container on demand.
 */
final class CookiesMiddleware implements MiddlewareInterface
{
    /** @var CookiesConfig */
    private $config = null;

    /** @var ScopeInterface */
    private $scope = null;

    /** @var EncryptionInterface */
    private $encryption = null;

    /**
     * @param CookiesConfig       $config
     * @param ScopeInterface      $scope
     * @param EncryptionInterface $encryption
     */
    public function __construct(
        CookiesConfig $config,
        ScopeInterface $scope,
        EncryptionInterface $encryption
    ) {
        $this->config = $config;
        $this->scope = $scope;
        $this->encryption = $encryption;
    }

    /**
     * {@inheritdoc}
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        //Aggregates all user cookies
        $queue = new CookieQueue(
            $this->config->resolveDomain($request->getUri()),
            $request->getUri()->getScheme() == "https"
        );

        $response = $this->scope->runScope(
            [CookieQueue::class => $queue],
            function () use ($request, $handler, $queue) {
                return $handler->handle(
                    $this->unpackCookies($request)->withAttribute(CookieQueue::ATTRIBUTE, $queue)
                );
            }
        );

        return $this->packCookies($response, $queue);
    }

    /**
     * Unpack incoming cookies and decrypt their content.
     *
     * @param Request $request
     * @return Request
     */
    protected function unpackCookies(Request $request): Request
    {
        $cookies = $request->getCookieParams();

        foreach ($cookies as $name => $cookie) {
            if (!$this->isProtected($name)) {
                //Nothing to protect
                continue;
            }

            $cookies[$name] = $this->decodeCookie($cookie);
        }

        return $request->withCookieParams($cookies);
    }

    /**
     * Check if cookie has to be protected.
     *
     * @param string $cookie
     * @return bool
     */
    protected function isProtected(string $cookie): bool
    {
        if (in_array($cookie, $this->config->getExcludedCookies())) {
            //Excluded
            return false;
        }

        return $this->config->getProtectionMethod() != CookiesConfig::COOKIE_UNPROTECTED;
    }

    /**
     * @param string|array $cookie
     * @return array|mixed|null
     */
    private function decodeCookie($cookie)
    {
        if ($this->config->getProtectionMethod() == CookiesConfig::COOKIE_ENCRYPT) {
            $encrypter = $this->encryption->getEncrypter();
            try {
                if (is_array($cookie)) {
                    return array_map([$this, 'decodeCookie'], $cookie);
                }

                return $encrypter->decrypt($cookie);
            } catch (DecryptException $exception) {
                return null;
            }
        }

        //HMAC
        $hmac = substr($cookie, -1 * CookiesConfig::MAC_LENGTH);
        $value = substr($cookie, 0, strlen($cookie) - strlen($hmac));

        if ($this->hmacSign($value) != $hmac) {
            return null;
        }

        return $value;
    }

    /**
     * Sign string.
     *
     * @param string|null $value
     * @return string
     */
    private function hmacSign($value): string
    {
        return hash_hmac(
            CookiesConfig::HMAC_ALGORITHM,
            $value,
            $this->encryption->getKey()
        );
    }

    /**
     * Pack outcoming cookies with encrypted value.
     *
     * @param Response    $response
     * @param CookieQueue $queue
     * @return Response
     *
     * @throws EncryptException
     */
    protected function packCookies(Response $response, CookieQueue $queue): Response
    {
        if (empty($queue->getScheduled())) {
            return $response;
        }

        $cookies = $response->getHeader('Set-Cookie');

        foreach ($queue->getScheduled() as $cookie) {
            if (!$this->isProtected($cookie->getName()) || empty($cookie->getValue())) {
                $cookies[] = $cookie->createHeader();
                continue;
            }

            $cookies[] = $this->encodeCookie($cookie)->createHeader();
        }

        return $response->withHeader('Set-Cookie', $cookies);
    }

    /**
     * @param Cookie $cookie
     * @return Cookie
     */
    private function encodeCookie(Cookie $cookie): Cookie
    {
        if ($this->config->getProtectionMethod() == CookiesConfig::COOKIE_ENCRYPT) {
            $encrypter = $this->encryption->getEncrypter();

            return $cookie->withValue($encrypter->encrypt($cookie->getValue()));
        }

        //VALUE.HMAC
        return $cookie->withValue($cookie->getValue() . $this->hmacSign($cookie->getValue()));
    }
}