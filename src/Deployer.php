<?php
/* (c) Anton Medvedev <anton@medv.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Deployer;

use Deployer\Collection\Collection;
use Deployer\Console\Application;
use Deployer\Console\CommandEvent;
use Deployer\Console\InitCommand;
use Deployer\Console\Output\Informer;
use Deployer\Console\Output\OutputWatcher;
use Deployer\Console\SshCommand;
use Deployer\Console\TaskCommand;
use Deployer\Console\WorkerCommand;
use Deployer\Executor\SeriesExecutor;
use Deployer\Task;
use Deployer\Utility\ProcessRunner;
use Deployer\Utility\Reporter;
use Deployer\Utility\Rsync;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Pimple\Container;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console;
use function Deployer\Support\array_merge_alternate;

/**
 * Deployer class represents DI container for configuring
 *
 * @property Task\TaskCollection|Task\Task[] tasks
 * @property Host\HostCollection|Collection|Host\Host[] hosts
 * @property Collection config
 * @property Rsync rsync
 * @property Ssh\Client sshClient
 * @property ProcessRunner processRunner
 * @property Task\ScriptManager scriptManager
 * @property Host\HostSelector hostSelector
 * @property SeriesExecutor seriesExecutor
 */
class Deployer extends Container
{
    /**
     * Global instance of deployer. It's can be accessed only after constructor call.
     * @var Deployer
     */
    private static $instance;

    /**
     * @param Application $console
     */
    public function __construct(Application $console)
    {
        parent::__construct();

        /******************************
         *           Console          *
         ******************************/

        $this['console'] = function () use ($console) {
            $console->catchIO(function ($input, $output) {
                $this['input'] = $input;
                $this['output'] =  new OutputWatcher($output);
                return [$this['input'], $this['output']];
            });
            return $console;
        };

        /******************************
         *           Config           *
         ******************************/

        $this['config'] = function () {
            return new Collection();
        };
        $this->config['ssh_multiplexing'] = true;
        $this->config['default_stage'] = null;

        /******************************
         *            Core            *
         ******************************/

        $this['sshClient'] = function ($c) {
            return new Ssh\Client($c['output'], $c['config']['ssh_multiplexing']);
        };
        $this['rsync'] = function ($c) {
            return new Rsync($c['output']);
        };
        $this['processRunner'] = function () {
            return new ProcessRunner();
        };
        $this['tasks'] = function () {
            return new Task\TaskCollection();
        };
        $this['hosts'] = function () {
            return new Host\HostCollection();
        };
        $this['scriptManager'] = function ($c) {
            return new Task\ScriptManager($c['tasks']);
        };
        $this['hostSelector'] = function ($c) {
            return new Host\HostSelector($c['hosts'], $c['config']['default_stage']);
        };
        $this['onFailure'] = function () {
            return new Collection();
        };
        $this['informer'] = function ($c) {
            return new Informer($c['output']);
        };
        $this['seriesExecutor'] = function ($c) {
            return new SeriesExecutor($c['input'], $c['output'], $c['informer']);
        };

        /******************************
         *           Logger           *
         ******************************/

        $this['log_level'] = Logger::DEBUG;
        $this['log_handler'] = function () {
            return isset($this->config['log_file'])
                ? new StreamHandler($this->config['log_file'], $this['log_level'])
                : new NullHandler($this['log_level']);
        };
        $this['log'] = function () {
            $name = isset($this->config['log_name']) ? $this->config['log_name'] : 'Deployer';
            return new Logger($name, [
                $this['log_handler']
            ]);
        };

        /******************************
         *        Init command        *
         ******************************/

        $this['init_command'] = function () {
            return new InitCommand();
        };

        self::$instance = $this;
    }

    /**
     * @return Deployer
     */
    public static function get()
    {
        return self::$instance;
    }

    /**
     * @param string $name
     * @param mixed $value
     */
    public static function setDefault($name, $value)
    {
        Deployer::get()->config[$name] = $value;
    }

    /**
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public static function getDefault($name, $default = null)
    {
        return self::hasDefault($name) ? Deployer::get()->config[$name] : $default;
    }

    /**
     * @param string $name
     * @return boolean
     */
    public static function hasDefault($name)
    {
        return isset(Deployer::get()->config[$name]);
    }

    /**
     * @param string $name
     * @param array $array
     */
    public static function addDefault($name, $array)
    {
        if (self::hasDefault($name)) {
            $config = self::getDefault($name);
            if (!is_array($config)) {
                throw new \RuntimeException("Configuration parameter `$name` isn't array.");
            }
            self::setDefault($name, array_merge_alternate($config, $array));
        } else {
            self::setDefault($name, $array);
        }
    }

    /**
     * Init console application
     */
    public function init()
    {
        $this->addConsoleCommands();
        $this->getConsole()->add(new WorkerCommand($this));
        $this->getConsole()->add($this['init_command']);
        $this->getConsole()->add(new SshCommand($this));
        $this->getConsole()->afterRun([$this, 'collectAnonymousStats']);
    }

    /**
     * Transform tasks to console commands.
     */
    public function addConsoleCommands()
    {
        $this->getConsole()->addUserArgumentsAndOptions();

        foreach ($this->tasks as $name => $task) {
            if ($task->isPrivate()) {
                continue;
            }

            $this->getConsole()->add(new TaskCommand($name, $task->getDescription(), $this));
        }
    }

    /**
     * @param string $name
     * @return mixed
     * @throws \InvalidArgumentException
     */
    public function __get($name)
    {
        if (isset($this[$name])) {
            return $this[$name];
        } else {
            throw new \InvalidArgumentException("Property \"$name\" does not exist.");
        }
    }

    /**
     * @return Application
     */
    public function getConsole()
    {
        return $this['console'];
    }

    /**
     * @return Console\Input\InputInterface
     */
    public function getInput()
    {
        return $this['input'];
    }

    /**
     * @return Console\Output\OutputInterface
     */
    public function getOutput()
    {
        return $this['output'];
    }

    /**
     * @param string $name
     * @return Console\Helper\HelperInterface
     */
    public function getHelper($name)
    {
        return $this->getConsole()->getHelperSet()->get($name);
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this['log'];
    }

    /**
     * Collect anonymous stats about Deployer usage for improving developer experience.
     * If you are not comfortable with this, you will always be able to disable this
     * by setting `allow_anonymous_stats` to false in your deploy.php file.
     *
     * @param CommandEvent $commandEvent
     */
    public function collectAnonymousStats(CommandEvent $commandEvent)
    {
        if ($this->config->has('allow_anonymous_stats') && $this->config['allow_anonymous_stats'] === false) {
            return;
        }

        $stats = [
            'status' => 'success',
            'command_name' => $commandEvent->getCommand()->getName(),
            'project_hash' => empty($this->config['repository']) ? null : sha1($this->config['repository']),
            'servers_count' => $this->hosts->count(),
            'deployer_version' => $this->getConsole()->getVersion(),
            'deployer_phar' => $this->getConsole()->isPharArchive(),
            'php_version' => phpversion(),
            'extension_pcntl' => extension_loaded('pcntl'),
            'extension_curl' => extension_loaded('curl'),
            'os' => defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY : (stristr(PHP_OS, 'DAR') ? 'OSX' : (stristr(PHP_OS, 'WIN') ? 'WIN' : (stristr(PHP_OS, 'LINUX') ? 'LINUX' : PHP_OS))),
            'exception' => null,
        ];

        if ($commandEvent->getException() !== null) {
            $stats['status'] = 'error';
            $stats['exception'] = get_class($commandEvent->getException());
        }

        Reporter::report($stats);
    }
}
