# Spiral Framework: Nyholm PSR-7/PSR-17 bridge
[![Latest Stable Version](https://poser.pugx.org/spiral/nyholm-bridge/version)](https://packagist.org/packages/spiral/nyholm-bridge)
[![Build Status](https://travis-ci.org/spiral/nyholm-bridge.svg?branch=master)](https://travis-ci.org/spiral/nyholm-bridge)
[![Codecov](https://codecov.io/gh/spiral/nyholm-bridge/branch/master/graph/badge.svg)](https://codecov.io/gh/spiral/nyholm-bridge/)

## Installation
```
$ composer require spiral/nyholm-bridge
```

To enable extension modify your application by adding `Spiral\Nyholm\Bootloader\NyholmBootloader`:

```php
class App extends Kernel
{
    /*
     * List of components and extensions to be automatically registered
     * within system container on application start.
     */
    protected const LOAD = [
        // ...
        
        Spiral\Nyholm\Bootloader\NyholmBootloader::class,
    ];
}
```

> Make sure to remove default `Spiral\Bootloader\Http\DiactorosBootloader`.