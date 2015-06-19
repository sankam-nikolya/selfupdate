<?php
/**
 * @link https://github.com/yii2tech
 * @copyright Copyright (c) 2015 Yii2tech
 * @license [New BSD License](http://www.opensource.org/licenses/bsd-license.php)
 */

namespace yii2tech\selfupdate;

use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\caching\Cache;
use yii\console\Controller;
use Yii;
use yii\di\Instance;
use yii\helpers\Console;
use yii\mutex\Mutex;

/**
 * SelfUpdateController performs project update from VCS.
 * This command assumes project is store under Mercurial (.hg) version control system.
 *
 * Note: in order to work properly this command requires execution of VCS command without any prompt
 * or user input.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 1.0
 */
class SelfUpdateController extends Controller
{
    /**
     * @var string controller default action ID.
     */
    public $defaultAction = 'perform';
    /**
     * @var array list of email addresses, which should be used to send execution reports.
     */
    public $emails = [];
    /**
     * @var Mutex|array|string the mutex object or the application component ID of the mutex.
     * After the controller object is created, if you want to change this property, you should only assign it
     * with a mutex connection object.
     */
    public $mutex = 'mutex';
    /**
     * @var string path to project root directory, which means VCS root directory. Path aliases can be use here.
     */
    public $projectRootPath = '@app';
    /**
     * @var array project web path stubs configuration.
     * Each path configuration should have following keys:
     *  - 'path' - string - path to web root folder
     *  - 'link' - string - path for the symbolic link, which should point to the web root
     *  - 'stub' - string - path to folder, which contains stub for the web
     * Yii aliases can be used for all these keys.
     * For example:
     *
     * ```php
     * [
     *     [
     *         'path' => '@app/web',
     *         'link' => '@app/httpdocs',
     *         'stub' => '@app/webstub',
     *     ]
     * ]
     * ```
     */
    public $webPaths = [];
    /**
     * @var string|array
     */
    public $cache;
    /**
     * @var array list of commands, which should be executed before project update begins.
     * If command is a string it will be executed as shell command, otherwise as PHP callback.
     * For example:
     *
     * ```php
     * [
     *     'mysqldump -h localhost -u root myproject > /path/to/backup/myproject.sql'
     * ],
     * ```
     */
    public $beforeUpdateCommands = [];
    /**
     * @var array list of shell commands, which should be executed after project update.
     * If command is a string it will be executed as shell command, otherwise as PHP callback.
     * For example:
     *
     * ```php
     * [
     *     'php /path/to/project/yii migrate/up --interactive=0'
     * ],
     * ```
     */
    public $afterUpdateCommands = [];
    /**
     * @var array list of log entries.
     * @see log()
     */
    private $logLines = [];
    /**
     * @var array list of keywords, which presence in the shell command output is considered as
     * its execution error.
     */
    public $shellResponseErrorKeywords = [
        'error',
        'exception',
        'ошибка',
    ];


    /**
     * Performs project update from VCS.
     * @param string|null $configFile the path or alias of the configuration file.
     * You may use the "yii message/config" command to generate
     * this file and then customize it for your needs.
     * @throws Exception on failure
     * @return integer CLI exit code
     */
    public function actionPerform($configFile = null)
    {
        if (!empty($configFile)) {
            $configFile = Yii::getAlias($configFile);
            if (!is_file($configFile)) {
                throw new Exception("The configuration file does not exist: $configFile");
            }
            Yii::configure($this, require $configFile);
        }

        if (!$this->acquireMutex()) {
            $this->stderr("Execution terminated: command is already running.\n", Console::FG_RED);
            return self::EXIT_CODE_ERROR;
        }

        try {
            $this->normalizeWebPaths();

            $this->linkWebStubs();

            $projectRootPath = Yii::getAlias($this->projectRootPath);

            $output = $this->execShellCommand('(cd ' . escapeshellarg($projectRootPath) . '; hg pull)');

            if (strpos($output, 'hg update') !== false) {
                $this->executeCommands($this->beforeUpdateCommands);

                $this->execShellCommand('(cd ' . escapeshellarg($projectRootPath) . '; hg update --clean)');

                $this->executeCommands($this->afterUpdateCommands);
                $this->flushCache();
                $this->reportSuccess();
            }

            $this->linkWebPaths();
        } catch (\Exception $exception) {
            $this->log($exception->getMessage());
            $this->reportFail();

            $this->releaseMutex();
            return self::EXIT_CODE_ERROR;
        }

        $this->releaseMutex();
        return self::EXIT_CODE_NORMAL;
    }

    /**
     * Creates a configuration file for the "perform" command.
     *
     * The generated configuration file contains detailed instructions on
     * how to customize it to fit for your needs. After customization,
     * you may use this configuration file with the "perform" command.
     *
     * @param string $filePath output file name or alias.
     * @return integer CLI exit code
     */
    public function actionConfig($filePath)
    {
        $filePath = Yii::getAlias($filePath);
        if (file_exists($filePath)) {
            if (!$this->confirm("File '{$filePath}' already exists. Do you wish to overwrite it?")) {
                return self::EXIT_CODE_NORMAL;
            }
        }
        copy(Yii::getAlias('@yii2tech/selfupdate/views/selfUpdateConfig.php'), $filePath);
        $this->stdout("Configuration file template created at '{$filePath}' . \n\n", Console::FG_GREEN);
        return self::EXIT_CODE_NORMAL;
    }

    /**
     * Acquires current action lock.
     * @return boolean lock acquiring result.
     */
    protected function acquireMutex()
    {
        $this->mutex = Instance::ensure($this->mutex, Mutex::className());
        return $this->mutex->acquire($this->composeMutexName());
    }

    /**
     * Release current action lock.
     * @return boolean lock release result.
     */
    protected function releaseMutex()
    {
        return $this->mutex->release($this->composeMutexName());
    }

    /**
     * Composes the mutex name.
     * @return string mutex name.
     */
    protected function composeMutexName()
    {
        return $this->className() . '::' . $this->action->getUniqueId();
    }

    /**
     * Links web roots to the stub directories.
     * @see webPaths
     */
    protected function linkWebStubs()
    {
        foreach ($this->webPaths as $webPath) {
            if (is_link($webPath['link'])) {
                unlink($webPath['link']);
            }
            symlink($webPath['stub'], $webPath['link']);
        }
    }

    /**
     * Links web roots to the actual web directories.
     * @see webPaths
     */
    protected function linkWebPaths()
    {
        foreach ($this->webPaths as $webPath) {
            if (is_link($webPath['link'])) {
                unlink($webPath['link']);
            }
            symlink($webPath['path'], $webPath['link']);
        }
    }

    /**
     * Normalizes [[webPaths]] value.
     * @throws InvalidConfigException on invalid configuration.
     */
    protected function normalizeWebPaths()
    {
        $rawWebPaths = $this->webPaths;
        $webPaths = [];
        foreach ($rawWebPaths as $rawWebPath) {
            if (!isset($rawWebPath['path'], $rawWebPath['link'], $rawWebPath['stub'])) {
                throw new InvalidConfigException("Web path configuration should contain keys: 'path', 'link', 'stub'");
            }
            $webPath = [
                'path' => Yii::getAlias($rawWebPath['path']),
                'link' => Yii::getAlias($rawWebPath['link']),
                'stub' => Yii::getAlias($rawWebPath['stub']),
            ];
            if (!is_dir($webPath['path'])) {
                throw new InvalidConfigException("'{$webPath['path']}' ('{$rawWebPath['path']}') is not a directory.");
            }
            if (!is_dir($webPath['stub'])) {
                throw new InvalidConfigException("'{$webPath['stub']}' ('{$rawWebPath['stub']}') is not a directory.");
            }
            if (!is_link($webPath['link'])) {
                throw new InvalidConfigException("'{$webPath['link']}' ('{$rawWebPath['link']}') is not a symbolic link.");
            }
            if (!in_array(readlink($webPath['link']), [$webPath['path'], $webPath['stub']])) {
                throw new InvalidConfigException("'{$webPath['link']}' ('{$rawWebPath['link']}') does not pointing to actual web or stub directory.");
            }
            $webPaths[] = $webPath;
        }
        $this->webPaths = $webPaths;
    }

    /**
     * Flushes cache for all components specified at [[cache]].
     */
    protected function flushCache()
    {
        if (!empty($this->cache)) {
            foreach ((array)$this->cache as $cache) {
                $cache = Instance::ensure($cache, Cache::className());
                $cache->flush();
            }
            $this->log('Cache flushed.');
        }
    }

    /**
     * @return string server hostname.
     */
    public function getHostName()
    {
        $hostName = @exec('hostname');
        if (empty($hostName)) {
            $hostName = preg_replace('/[^a-z0-1_]/s', '_', strtolower(Yii::$app->name)) . '.com';
        }
        return $hostName;
    }

    /**
     * @return string current date string.
     */
    public function getCurrentDate()
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Logs the message
     * @param string $message log message.
     */
    protected function log($message)
    {
        $this->logLines[] = $message;
        $this->stdout($message . "\n\n");
    }

    /**
     * Flushes log lines, returning them.
     * @return array log lines.
     */
    protected function flushLog()
    {
        $logLines = $this->logLines;
        $this->logLines = [];
        return $logLines;
    }

    /**
     * Executes list of given commands.
     * @param array $commands commands to be executed.
     * @throws InvalidConfigException on invalid commands specification.
     */
    protected function executeCommands(array $commands)
    {
        foreach ($commands as $command) {
            if (is_string($command)) {
                $this->execShellCommand($command);
            } elseif (is_callable($command)) {
                $this->log(call_user_func($command));
            } else {
                throw new InvalidConfigException('Command should be a string or a valid PHP callback');
            }
        }
    }

    /**
     * Executes shell command.
     * @param string $command command text.
     * @return string command output.
     * @throws Exception on failure.
     */
    protected function execShellCommand($command)
    {
        $outputLines = [];
        $this->log($command);
        exec($command . ' 2>&1', $outputLines, $responseCode);
        $output = implode("\n", $outputLines);
        if ($responseCode != 0) {
            throw new Exception("Execution of '{$command}' failed: response code = '{$responseCode}': \nOutput: \n{$output}");
        }
        foreach ($this->shellResponseErrorKeywords as $errorKeyword) {
            if (stripos($output, $errorKeyword) !== false) {
                throw new Exception("Execution of '{$command}' failed! \nOutput: \n{$output}");
            }
        }
        $this->log($output);
        return $output;
    }

    /**
     * Sends report about success.
     */
    protected function reportSuccess()
    {
        $this->reportResult('Update success');
    }

    /**
     * Sends report about failure.
     */
    protected function reportFail()
    {
        $this->reportResult('UPDATE FAILED');
    }

    /**
     * Sends execution report.
     * Report message content will be composed from log messages.
     * @param string $subjectPrefix report message subject.
     */
    protected function reportResult($subjectPrefix)
    {
        $emails = $this->emails;
        if (!empty($emails)) {
            @$userName = exec('whoami');
            if (empty($userName)) {
                $userName = $this->getUniqueId();
            }
            $hostName = $this->getHostName();
            $from = $userName . '@' . $hostName;
            $subject = $subjectPrefix . ': ' . $this->getHostName() . ' at ' . $this->getCurrentDate();
            $message = implode("\n", $this->flushLog());
            foreach ($emails as $email) {
                $this->sendEmail($from, $email, $subject, $message);
            }
        }
    }

    /**
     * Sends an email.
     * @param string $from sender email address
     * @param string $email single email address
     * @param string $subject email subject
     * @param string $message email content
     */
    protected function sendEmail($from, $email, $subject, $message)
    {
        $headers = [
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=UTF-8",
        ];
        $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $matches = [];
        preg_match_all('/([^<]*)<([^>]*)>/iu', $from, $matches);
        if (isset($matches[1][0],$matches[2][0])) {
            $name = $this->utf8 ? '=?UTF-8?B?' . base64_encode(trim($matches[1][0])) . '?=' : trim($matches[1][0]);
            $from = trim($matches[2][0]);
            $headers[] = "From: {$name} <{$from}>";
        } else {
            $headers[] = "From: {$from}";
        }
        $headers[] = "Reply-To: {$from}";

        mail($email, $subject, $message, implode("\n", $headers));
    }
}