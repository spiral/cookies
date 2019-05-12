<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

namespace Spiral\Framework;

use Spiral\App\TestApp;
use Spiral\Boot\DirectoriesInterface;
use Spiral\Files\Files;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

abstract class ConsoleTest extends BaseTest
{
    /** @var TestApp */
    protected $app;

    public function setUp()
    {
        $this->app = $this->makeApp();
    }

    public function tearDown()
    {
        parent::tearDown();

        $fs = new Files();

        if ($fs->isDirectory(__DIR__ . '/../app/migrations')) {
            $fs->deleteDirectory(__DIR__ . '/../app/migrations');
        }

        $runtime = $this->app->get(DirectoriesInterface::class)->get('runtime');
        if ($fs->isDirectory($runtime)) {
            $fs->deleteDirectory($runtime);
        }
    }

    public function runCommand(string $command, array $args = []): string
    {
        $input = new ArrayInput($args);
        $output = new BufferedOutput();

        $this->app->console()->run($command, $input, $output);

        return $output->fetch();
    }

    public function runCommandDebug(string $command, array $args = []): string
    {
        $input = new ArrayInput($args);
        $output = new BufferedOutput();
        $output->setVerbosity(BufferedOutput::VERBOSITY_VERBOSE);

        $this->app->console()->run($command, $input, $output);

        return $output->fetch();
    }
}