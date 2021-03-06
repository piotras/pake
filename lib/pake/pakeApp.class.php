<?php

/**
 * @package    pake
 * @author     Fabien Potencier <fabien.potencier@symfony-project.com>
 * @copyright  2004-2005 Fabien Potencier <fabien.potencier@symfony-project.com>
 * @license    see the LICENSE file included in the distribution
 * @version    SVN: $Id$
 */

/**
 *
 * main pake class.
 *
 * This class is a singleton.
 *
 * @package    pake
 * @author     Fabien Potencier <fabien.potencier@symfony-project.com>
 * @copyright  2004-2005 Fabien Potencier <fabien.potencier@symfony-project.com>
 * @license    see the LICENSE file included in the distribution
 * @version    SVN: $Id$
 */
class pakeApp
{
    const VERSION = '1.1.DEV';
    const QUIT_INTERACTIVE = 0xDD;

    public static $MAX_LINE_SIZE = 78;
    protected static $EXEC_NAME = 'pake';
    private static $PROPERTIES = array();
    protected static $PAKEFILES = array('pakefile', 'Pakefile', 'pakefile.php', 'Pakefile.php');
    protected static $PLUGINDIRS = array();
    protected static $OPTIONS = array(
        array('--interactive', '-i', pakeGetopt::NO_ARGUMENT,       "Start pake in interactive (shell-like) mode."),
        array('--dry-run',     '-n', pakeGetopt::NO_ARGUMENT,       "Do a dry run without executing actions."),
        array('--help',        '-H', pakeGetopt::NO_ARGUMENT,       "Display this help message."),
        array('--libdir',      '-I', pakeGetopt::REQUIRED_ARGUMENT, "Include LIBDIR in the search path for required modules."),
        array('--nosearch',    '-N', pakeGetopt::NO_ARGUMENT,       "Do not search parent directories for the pakefile."),
        array('--prereqs',     '-P', pakeGetopt::NO_ARGUMENT,       "Display the tasks and dependencies, then exit."),
        array('--quiet',       '-q', pakeGetopt::NO_ARGUMENT,       "Do not log messages to standard output."),
        array('--pakefile',    '-f', pakeGetopt::REQUIRED_ARGUMENT, "Use FILE as the pakefile."),
        array('--require',     '-r', pakeGetopt::REQUIRED_ARGUMENT, "Require MODULE before executing pakefile."),
        array('--import',      '',   pakeGetopt::REQUIRED_ARGUMENT, "Import pake-plugin before executing pakefile."),
        array('--tasks',       '-T', pakeGetopt::NO_ARGUMENT,       "Display the tasks and dependencies, then exit."),
        array('--trace',       '-t', pakeGetopt::NO_ARGUMENT,       "Turn on invoke/execute tracing, enable full backtrace."),
        array('--usage',       '-h', pakeGetopt::NO_ARGUMENT,       "Display usage."),
        array('--verbose',     '-v', pakeGetopt::NO_ARGUMENT,       "Log message to standard output (default)."),
        array('--force-tty',   '',   pakeGetopt::NO_ARGUMENT,       "Force coloured output"),
        array('--version',     '-V', pakeGetopt::NO_ARGUMENT,       "Display the program version."),
    );

    private $opt = null;
    private $nosearch = false;
    private $trace = false;
    private $verbose = true;
    private $dryrun = false;
    private $nowrite = false;
    private $show_tasks = false;
    private $show_prereqs = false;
    private $interactive = false;
    private $pakefile = '';

    protected static $instance = null;

    protected function __construct()
    {
        self::$PLUGINDIRS[] = dirname(__FILE__).'/tasks';
    }

    public static function get_plugin_dirs()
    {
        return self::$PLUGINDIRS;
    }

    public function get_properties()
    {
        return self::$PROPERTIES;
    }

    public function set_properties($properties)
    {
        self::$PROPERTIES = $properties;
    }

    public static function get_instance()
    {
        if (!self::$instance)
            self::$instance = new pakeApp();

        return self::$instance;
    }

    public function get_verbose()
    {
        return $this->verbose;
    }

    public function get_trace()
    {
        return $this->trace;
    }

    public function get_dryrun()
    {
        return $this->dryrun;
    }

    public function getPakefilePath()
    {
        return $this->pakefile;
    }

    public function run($pakefile = null, $options = null, $load_pakefile = true)
    {
        if ($pakefile) {
            self::$PAKEFILES = array($pakefile);
        }

        $this->handle_options($options);
        if ($load_pakefile) {
            $this->load_pakefile();
        }

        if ($this->show_tasks) {
            $this->display_tasks_and_comments();
            return;
        }

        if ($this->show_prereqs) {
            $this->display_prerequisites();
            return;
        }

        if ($this->interactive) {
            $this->runInteractiveSession();
            return;
        }

        // parsing out options and arguments
        $argv = $this->opt->get_arguments();
        list($task_name, $args, $options) = self::parseTaskAndParameters($argv);

        if (!$task_name) {
            return $this->runDefaultTask();
        } else {
            $task_name = pakeTask::get_full_task_name($task_name);
            return $this->initAndRunTask($task_name, $args, $options);
        }
    }

    private function runInteractiveSession()
    {
        pake_import('interactive');

        pake_echo("=================================================================================");
        pake_echo("Welcome to pake's interactive console. To get list of commands type \"?\" or \"help\"");
        pake_echo("type \"quit\" or press ^D to exit");
        pake_echo("=================================================================================");
        $this->showVersion();
        echo "\n";

        while (true) {
            $command = pakeInput::getString('pake> ', false);

            if (false === $command) {
                // Ctrl-D
                pakeInteractiveTask::run_quit_pake();
            }

            if ('' === $command) {
                continue;
            }

            $this->initAndRunTaskInSubprocess($command);
        }
    }

    protected function initAndRunTaskInSubprocess($command)
    {
        if (function_exists('pcntl_fork')) {
            // UNIX
            $argv = explode(' ', $command);
            list($task_name, $args, $options) = self::parseTaskAndParameters($argv);
            $task_name = pakeTask::get_full_task_name($task_name);

            $pid = pcntl_fork();
            if ($pid == -1) {
                die('could not fork');
            }

            if ($pid) {
                // we are the parent
                pcntl_wait($status);
                $status = pcntl_wexitstatus($status);
                if ($status == self::QUIT_INTERACTIVE) {
                    exit(0);
                }
            } else {
                try {
                    $status = $this->initAndRunTask($task_name, $args, $options);
                    if (true === $status)
                        exit(0);
                    exit($status);
                } catch (pakeException $e) {
                    pakeException::render($e);
                    exit(1);
                }
            }
        } else {
            // WINDOWS
            $php_exec = escapeshellarg((isset($_SERVER['_']) and substr($_SERVER['_'], -4) != 'pake') ? $_SERVER['_'] : 'php');

            $force_tty = '';
            if (defined('PAKE_FORCE_TTY') or (DIRECTORY_SEPARATOR != '\\' and function_exists('posix_isatty') and @posix_isatty(STDOUT))) {
                $force_tty = ' --force-tty';
            }

            $pake_php = escapeshellarg($_SERVER['SCRIPT_NAME']);
            $import_flag = ' --import=interactive';

            system($php_exec.' '.$pake_php.$force_tty.$import_flag.' '.$command, $status);
            if ($status == self::QUIT_INTERACTIVE) {
                exit(0);
            }
        }
    }

    protected function initAndRunTask($task_name, $args, $options)
    {
        // generating abbreviations
        $abbreviated_tasks = pakeTask::get_abbreviated_tasknames();

        // does requested task correspond to full or abbreviated name?
        if (!array_key_exists($task_name, $abbreviated_tasks)) {
            throw new pakeException('Task "'.$task_name.'" is not defined.');
        }

        if (count($abbreviated_tasks[$task_name]) > 1) {
            throw new pakeException('Task "'.$task_name.'" is ambiguous ('.implode(', ', $abbreviated_tasks[$task_name]).').');
        }

        // init and run task
        $task = pakeTask::get($abbreviated_tasks[$task_name][0]);
        return $task->invoke($args, $options);
    }

    protected function runDefaultTask()
    {
        return $this->initAndRunTask('default', array(), array());
    }

    protected static function parseTaskAndParameters(array $args)
    {
        $options = array();

        if (count($args) == 0) {
            $task_name = null;
        } else {
            $task_name = array_shift($args);

            for ($i = 0, $max = count($args); $i < $max; $i++) {
                if (0 === strpos($args[$i], '--')) {
                    if (false !== $pos = strpos($args[$i], '=')) {
                        $key = substr($args[$i], 2, $pos - 2);
                        $value = substr($args[$i], $pos + 1);
                    } else {
                        $key = substr($args[$i], 2);
                        $value = true;
                    }

                    if ('[]' == substr($key, -2)) {
                        if (!isset($options[$key])) {
                            $options[$key] = array();
                        }

                        $options[$key][] = $value;
                    } else {
                        $options[$key] = $value;
                    }

                    unset($args[$i]);
                }
            }

            $args = array_values($args);
        }

        return array($task_name, $args, $options);
    }

    // Read and handle the command line options.
    public function handle_options($options = null)
    {
        $this->opt = new pakeGetopt(self::$OPTIONS);
        $this->opt->parse($options);
        foreach ($this->opt->get_options() as $opt => $value) {
            $this->do_option($opt, $value);
        }
    }

    // True if one of the files in PAKEFILES is in the current directory.
    // If a match is found, it is copied into @pakefile.
    public function have_pakefile()
    {
        $here = getcwd();
        foreach (self::$PAKEFILES as $file) {
            if (file_exists($here.'/'.$file)) {
                $this->pakefile = $here.'/'.$file;
                return true;
            }
        }

        return false;
    }

    public function load_pakefile()
    {
        $start = $here = getcwd();
        while (!$this->have_pakefile()) {
            chdir('..');
            if (getcwd() == $here || $this->nosearch) {
                chdir($start);
                throw new pakeException(sprintf('No pakefile found (looking for: %s)', join(', ', self::$PAKEFILES))."\n");
            }

            $here = getcwd();
        }

        require($this->pakefile);
        chdir($start);
    }

    // Do the option defined by +opt+ and +value+.
    public function do_option($opt, $value)
    {
        switch ($opt) {
            case 'interactive':
                $this->interactive = true;
                break;
            case 'dry-run':
                $this->verbose = true;
                $this->nowrite = true;
                $this->dryrun = true;
                $this->trace = true;
                break;
            case 'help':
                $this->help();
                exit();
            case 'libdir':
                set_include_path($value.PATH_SEPARATOR.get_include_path());
                break;
            case 'nosearch':
                $this->nosearch = true;
                break;
            case 'prereqs':
                $this->show_prereqs = true;
                break;
            case 'quiet':
                $this->verbose = false;
                break;
            case 'pakefile':
                self::$PAKEFILES = array($value);
                break;
            case 'require':
                require $value;
                break;
            case 'import':
                pake_import($value);
                break;
            case 'tasks':
                $this->show_tasks = true;
                break;
            case 'trace':
                $this->trace = true;
                $this->verbose = true;
                break;
            case 'usage':
                $this->usage();
                exit();
            case 'verbose':
                $this->verbose = true;
                break;
            case 'force-tty':
                define('PAKE_FORCE_TTY', true);
                break;
            case 'version':
                $this->showVersion();
                exit();
            default:
                throw new pakeException(sprintf("Unknown option: %s", $opt));
        }
    }

    public function showVersion()
    {
        echo sprintf('pake version %s', pakeColor::colorize(self::VERSION, 'INFO'))."\n";
    }

    // Display the program usage line.
    public function usage($hint_about_help = true)
    {
        echo self::$EXEC_NAME." [-f pakefile] {options} targets...\n";

        if (true === $hint_about_help) {
            echo pakeColor::colorize("Try ".self::$EXEC_NAME." -H for more information", 'INFO')."\n";
        }
    }

    // Display the pake command line help.
    public function help()
    {
        $this->usage(false);
        echo "\n";
        echo "available options:";
        echo "\n";

        foreach (self::$OPTIONS as $option) {
            list($long, $short, $mode, $comment) = $option;
            if ($mode == pakeGetopt::REQUIRED_ARGUMENT) {
                if (preg_match('/\b([A-Z]{2,})\b/', $comment, $match))
                    $long .= '='.$match[1];
            }

            printf("  %-20s", pakeColor::colorize($long, 'INFO'));
            if (!empty($short)) {
                printf(" (%s)", pakeColor::colorize($short, 'INFO'));
            }
            printf("\n      %s\n", $comment);
        }
    }

    // Display the tasks and dependencies.
    public function display_tasks_and_comments()
    {
        $width = 0;
        $tasks = pakeTask::get_tasks();
        foreach ($tasks as $name => $task) {
            $w = strlen(pakeTask::get_mini_task_name($name));
            if ($w > $width)
                $width = $w;
        }
        $width += strlen(pakeColor::colorize(' ', 'INFO'));

        echo "available ".self::$EXEC_NAME." tasks:\n";

        // display tasks
        $has_alias = false;
        ksort($tasks);
        foreach ($tasks as $name => $task) {
            if ($task->get_alias()) {
                $has_alias = true;
            }

            if (!$task->get_alias() and $task->get_comment()) {
                $mini_name = pakeTask::get_mini_task_name($name);
                printf('  %-'.$width.'s > %s'."\n", pakeColor::colorize($mini_name, 'INFO'), $task->get_comment().($mini_name != $name ? ' ['.$name.']' : ''));
            }
        }

        if ($has_alias) {
            print("\ntask aliases:\n");

            // display aliases
            foreach ($tasks as $name => $task) {
                if ($task->get_alias()) {
                    $mini_name = pakeTask::get_mini_task_name($name);
                    printf('  %-'.$width.'s = '.self::$EXEC_NAME.' %s'."\n", pakeColor::colorize(pakeTask::get_mini_task_name($name), 'INFO'), $task->get_alias().($mini_name != $name ? ' ['.$name.']' : ''));
                }
            }
        }
    }

    // Display the tasks and prerequisites
    public function display_prerequisites()
    {
        foreach (pakeTask::get_tasks() as $name => $task) {
            echo self::$EXEC_NAME." ".pakeTask::get_mini_task_name($name)."\n";
            foreach ($task->get_prerequisites() as $prerequisite) {
                echo "    $prerequisite\n";
            }
        }
    }

    public static function screenWidth()
    {
        $cols = getenv('COLUMNS');

        return (false === $cols ? self::$MAX_LINE_SIZE : $cols);
    }
}
