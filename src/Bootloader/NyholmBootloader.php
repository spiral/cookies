<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
declare(strict_types=1);

namespace Spiral\Nyholm\Bootloader;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Spiral\Boot\Bootloader\Bootloader;

final class NyholmBootloader extends Bootloader
{
    const SINGLETONS = [
        ServerRequestFactoryInterface::class => [self::class, 'psr17Factory'],
        ResponseFactoryInterface::class      => [self::class, 'psr17Factory'],
        StreamFactoryInterface::class        => [self::class, 'psr17Factory'],
        UploadedFileFactoryInterface::class  => [self::class, 'psr17Factory'],
        UriFactoryInterface::class           => [self::class, 'psr17Factory']
    ];

    /**
     * @return Psr17Factory
     */
    protected function psr17Factory(): Psr17Factory
    {
        return new Psr17Factory();
    }
}