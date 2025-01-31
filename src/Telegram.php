<?php

/**
 * This file is part of the TelegramBot package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Longman\TelegramBot;

defined('TB_BASE_PATH') || define('TB_BASE_PATH', __DIR__);
defined('TB_BASE_COMMANDS_PATH') || define('TB_BASE_COMMANDS_PATH', TB_BASE_PATH . '/Commands');

use Exception;
use InvalidArgumentException;
use Longman\TelegramBot\Commands\AdminCommand;
use Longman\TelegramBot\Commands\Command;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Exception\TelegramException;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

class Telegram
{
    /**
     * Auth name for user commands
     */
    const AUTH_USER   = 'User';
    /**
     * Auth name tof system commands
     */
    const AUTH_SYSTEM = 'System';
    /**
     * Auth name for admin commands
     */
    const AUTH_ADMIN = 'Admin';

    /**
     * Version
     *
     * @var string
     */
    protected $version = '0.71.0';

    /**
     * Telegram API key
     *
     * @var string
     */
    protected $api_key = '';

    /**
     * Telegram Bot username
     *
     * @var string
     */
    protected $bot_username = '';

    /**
     * Telegram Bot id
     *
     * @var int
     */
    protected $bot_id = 0;

    /**
     * Raw request data (json) for webhook methods
     *
     * @var string
     */
    protected $input = '';

    /**
     * Custom commands paths
     *
     * @var array
     */
    protected $commands_paths = [];

    /**
     * Custom commands class names
     * ```
     * [
     *  'User' => [
     *      //commandName => className
     *      'start' => 'name\space\to\StartCommand',
     *  ],
     *  'Admin' => [], //etc
     * ]
     * ```
     * @var array
     */
    protected $commandsClasses = [
        self::AUTH_USER => [],
        self::AUTH_ADMIN => [],
        self::AUTH_SYSTEM => [],
    ];

    /**
     * Custom commands objects
     *
     * @var array
     */
    protected $commands_objects = [];

    /**
     * Current Update object
     *
     * @var Update
     */
    protected $update;

    /**
     * Upload path
     *
     * @var string
     */
    protected $upload_path = '';

    /**
     * Download path
     *
     * @var string
     */
    protected $download_path = '';

    /**
     * MySQL integration
     *
     * @var bool
     */
    protected $mysql_enabled = false;

    /**
     * PDO object
     *
     * @var PDO
     */
    protected $pdo;

    /**
     * Commands config
     *
     * @var array
     */
    protected $commands_config = [];

    /**
     * Admins list
     *
     * @var array
     */
    protected $admins_list = [];

    /**
     * ServerResponse of the last Command execution
     *
     * @var ServerResponse
     */
    protected $last_command_response;

    /**
     * Check if runCommands() is running in this session
     *
     * @var bool
     */
    protected $run_commands = false;

    /**
     * Is running getUpdates without DB enabled
     *
     * @var bool
     */
    protected $getupdates_without_database = false;

    /**
     * Last update ID
     * Only used when running getUpdates without a database
     *
     * @var int
     */
    protected $last_update_id;

    /**
     * The command to be executed when there's a new message update and nothing more suitable is found
     */
    public const GENERIC_MESSAGE_COMMAND = 'genericmessage';

    /**
     * The command to be executed by default (when no other relevant commands are applicable)
     */
    public const GENERIC_COMMAND = 'generic';

    /**
     * Update filter
     * Filter updates
     *
     * @var callback
     */
    protected $update_filter;

    /**
     * Telegram constructor.
     *
     * @param string $api_key
     * @param string $bot_username
     *
     * @throws TelegramException
     */
    public function __construct(string $api_key, string $bot_username = '')
    {
        if (empty($api_key)) {
            throw new TelegramException('API KEY not defined!');
        }
        preg_match('/(\d+):[\w\-]+/', $api_key, $matches);
        if (!isset($matches[1])) {
            throw new TelegramException('Invalid API KEY defined!');
        }
        $this->bot_id  = (int) $matches[1];
        $this->api_key = $api_key;

        $this->bot_username = $bot_username;

        //Add default system commands path
        $this->addCommandsPath(TB_BASE_COMMANDS_PATH . '/SystemCommands');

        Request::initialize($this);
    }

    /**
     * Initialize Database connection
     *
     * @param array  $credentials
     * @param string $table_prefix
     * @param string $encoding
     *
     * @return Telegram
     * @throws TelegramException
     */
    public function enableMySql(array $credentials, string $table_prefix = '', string $encoding = 'utf8mb4'): Telegram
    {
        $this->pdo = DB::initialize($credentials, $this, $table_prefix, $encoding);
        ConversationDB::initializeConversation();
        $this->mysql_enabled = true;

        return $this;
    }

    /**
     * Initialize Database external connection
     *
     * @param PDO    $external_pdo_connection PDO database object
     * @param string $table_prefix
     *
     * @return Telegram
     * @throws TelegramException
     */
    public function enableExternalMySql(PDO $external_pdo_connection, string $table_prefix = ''): Telegram
    {
        $this->pdo = DB::externalInitialize($external_pdo_connection, $this, $table_prefix);
        ConversationDB::initializeConversation();
        $this->mysql_enabled = true;

        return $this;
    }

    /**
     * Get commands list
     *
     * @return array $commands
     * @throws TelegramException
     */
    public function getCommandsList(): array
    {
        $commands = [];

        foreach ($this->commands_paths as $path) {
            try {
                //Get all "*Command.php" files
                $files = new RegexIterator(
                    new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($path)
                    ),
                    '/^.+Command.php$/'
                );

                foreach ($files as $file) {
                    //Remove "Command.php" from filename
                    $command      = $this->sanitizeCommand(substr($file->getFilename(), 0, -11));
                    $command_name = mb_strtolower($command);

                    if (array_key_exists($command_name, $commands)) {
                        continue;
                    }

                    require_once $file->getPathname();

                    $command_obj = $this->getCommandObject($command, $file->getPathname());
                    if ($command_obj instanceof Command) {
                        $commands[$command_name] = $command_obj;
                    }
                }
            } catch (Exception $e) {
                throw new TelegramException('Error getting commands from path: ' . $path, $e);
            }
        }

        return $commands;
    }

    /**
     * Get classname of predefined commands
     * @see commandsClasses
     * @param string $auth Auth of command
     * @param string $command Command name
     *
     * @return string|null
     */
    public function getCommandClassName(string $auth, string $command): ?string
    {
        $command = mb_strtolower($command);
        $auth = $this->ucFirstUnicode($auth);

        if (!empty($this->commandsClasses[$auth][$command])) {
            $className = $this->commandsClasses[$auth][$command];
            if (class_exists($className)){
                return $className;
            }
        }

        return null;
    }

    /**
     * Get an object instance of the passed command
     *
     * @param string $command
     * @param string $filepath
     *
     * @return Command|null
     */
    public function getCommandObject(string $command, string $filepath = ''): ?Command
    {
        if (isset($this->commands_objects[$command])) {
            return $this->commands_objects[$command];
        }

        $which = [self::AUTH_SYSTEM];
        $this->isAdmin() && $which[] = self::AUTH_ADMIN;
        $which[] = self::AUTH_USER;

        foreach ($which as $auth)
        {
            if (!($command_class = $this->getCommandClassName($auth, $command)))
            {
                if ($filepath) {
                    $command_namespace = $this->getFileNamespace($filepath);
                } else {
                    $command_namespace = __NAMESPACE__ . '\\Commands\\' . $auth . 'Commands';
                }
                $command_class = $command_namespace . '\\' . $this->ucFirstUnicode($command) . 'Command';
            }

            if (class_exists($command_class)) {
                $command_obj = new $command_class($this, $this->update);

                switch ($auth) {
                    case self::AUTH_SYSTEM:
                        if ($command_obj instanceof SystemCommand) {
                            return $command_obj;
                        }
                        break;

                    case self::AUTH_ADMIN:
                        if ($command_obj instanceof AdminCommand) {
                            return $command_obj;
                        }
                        break;

                    case self::AUTH_USER:
                        if ($command_obj instanceof UserCommand) {
                            return $command_obj;
                        }
                        break;
                }
            }
        }

        return null;
    }

    /**
     * Get namespace from php file by src path
     *
     * @param string $src (absolute path to file)
     *
     * @return string|null ("Longman\TelegramBot\Commands\SystemCommands" for example)
     */
    protected function getFileNamespace(string $src): ?string
    {
        $content = file_get_contents($src);
        if (preg_match('#^\s+namespace\s+(.+?);#m', $content, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Set custom input string for debug purposes
     *
     * @param string $input (json format)
     *
     * @return Telegram
     */
    public function setCustomInput(string $input): Telegram
    {
        $this->input = $input;

        return $this;
    }

    /**
     * Get custom input string for debug purposes
     *
     * @return string
     */
    public function getCustomInput(): string
    {
        return $this->input;
    }

    /**
     * Get the ServerResponse of the last Command execution
     *
     * @return ServerResponse
     */
    public function getLastCommandResponse(): ServerResponse
    {
        return $this->last_command_response;
    }

    /**
     * Handle getUpdates method
     *
     * @param int|null $limit
     * @param int|null $timeout
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function handleGetUpdates(?int $limit = null, ?int $timeout = null): ServerResponse
    {
        if (empty($this->bot_username)) {
            throw new TelegramException('Bot Username is not defined!');
        }

        if (!DB::isDbConnected() && !$this->getupdates_without_database) {
            return new ServerResponse(
                [
                    'ok'          => false,
                    'description' => 'getUpdates needs MySQL connection! (This can be overridden - see documentation)',
                ],
                $this->bot_username
            );
        }

        $offset = 0;

        //Take custom input into account.
        if ($custom_input = $this->getCustomInput()) {
            try {
                $input = json_decode($this->input, true, 512, JSON_THROW_ON_ERROR);
                if (empty($input)) {
                    throw new TelegramException('Custom input is empty');
                }
                $response = new ServerResponse($input, $this->bot_username);
            } catch (\Throwable $e) {
                throw new TelegramException('Invalid custom input JSON: ' . $e->getMessage());
            }
        } else {
            if (DB::isDbConnected() && $last_update = DB::selectTelegramUpdate(1)) {
                // Get last Update id from the database.
                $last_update          = reset($last_update);
                $this->last_update_id = $last_update['id'] ?? null;
            }

            if ($this->last_update_id !== null) {
                $offset = $this->last_update_id + 1; // As explained in the telegram bot API documentation.
            }

            $response = Request::getUpdates([
                'offset'  => $offset,
                'limit'   => $limit,
                'timeout' => $timeout,
            ]);
        }

        if ($response->isOk()) {
            // Log update.
            TelegramLog::update($response->toJson());

            // Process all updates
            /** @var Update $update */
            foreach ($response->getResult() as $update) {
                $this->processUpdate($update);
            }

            if (!DB::isDbConnected() && !$custom_input && $this->last_update_id !== null && $offset === 0) {
                //Mark update(s) as read after handling
                Request::getUpdates([
                    'offset'  => $this->last_update_id + 1,
                    'limit'   => 1,
                    'timeout' => $timeout,
                ]);
            }
        }

        return $response;
    }

    /**
     * Handle bot request from webhook
     *
     * @return bool
     *
     * @throws TelegramException
     */
    public function handle(): bool
    {
        if ($this->bot_username === '') {
            throw new TelegramException('Bot Username is not defined!');
        }

        $input = Request::getInput();
        if (empty($input)) {
            throw new TelegramException('Input is empty! The webhook must not be called manually, only by Telegram.');
        }

        // Log update.
        TelegramLog::update($input);

        $post = json_decode($input, true);
        if (empty($post)) {
            throw new TelegramException('Invalid input JSON! The webhook must not be called manually, only by Telegram.');
        }

        if ($response = $this->processUpdate(new Update($post, $this->bot_username))) {
            return $response->isOk();
        }

        return false;
    }

    /**
     * Get the command name from the command type
     *
     * @param string $type
     *
     * @return string
     */
    protected function getCommandFromType(string $type): string
    {
        return $this->ucFirstUnicode(str_replace('_', '', $type));
    }

    /**
     * Process bot Update request
     *
     * @param Update $update
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function processUpdate(Update $update): ServerResponse
    {
        $this->update         = $update;
        $this->last_update_id = $update->getUpdateId();

        if (is_callable($this->update_filter)) {
            $reason = 'Update denied by update_filter';
            try {
                $allowed = (bool) call_user_func_array($this->update_filter, [$update, $this, &$reason]);
            } catch (\Exception $e) {
                $allowed = false;
            }

            if (!$allowed) {
                TelegramLog::debug($reason);
                return new ServerResponse(['ok' => false, 'description' => 'denied']);
            }
        }

        //Load admin commands
        if ($this->isAdmin()) {
            $this->addCommandsPath(TB_BASE_COMMANDS_PATH . '/AdminCommands', false);
        }

        //Make sure we have an up-to-date command list
        //This is necessary to "require" all the necessary command files!
        $this->commands_objects = $this->getCommandsList();

        //If all else fails, it's a generic message.
        $command = self::GENERIC_MESSAGE_COMMAND;

        $update_type = $this->update->getUpdateType();
        if ($update_type === 'message') {
            $message = $this->update->getMessage();
            $type    = $message->getType();

            // Let's check if the message object has the type field we're looking for...
            $command_tmp = $type === 'command' ? $message->getCommand() : $this->getCommandFromType($type);
            // ...and if a fitting command class is available.
            $command_obj = $command_tmp ? $this->getCommandObject($command_tmp) : null;

            // Empty usage string denotes a non-executable command.
            // @see https://github.com/php-telegram-bot/core/issues/772#issuecomment-388616072
            if (
                ($command_obj === null && $type === 'command')
                || ($command_obj !== null && $command_obj->getUsage() !== '')
            ) {
                $command = $command_tmp;
            }
        } elseif ($update_type !== null) {
            $command = $this->getCommandFromType($update_type);
        }

        //Make sure we don't try to process update that was already processed
        $last_id = DB::selectTelegramUpdate(1, $this->update->getUpdateId());
        if ($last_id && count($last_id) === 1) {
            TelegramLog::debug('Duplicate update received, processing aborted!');
            return Request::emptyResponse();
        }

        DB::insertRequest($this->update);

        return $this->executeCommand($command);
    }

    /**
     * Execute /command
     *
     * @param string $command
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function executeCommand(string $command): ServerResponse
    {
        $command = mb_strtolower($command);

        $command_obj = $this->commands_objects[$command] ?? $this->getCommandObject($command);

        if (!$command_obj || !$command_obj->isEnabled()) {
            //Failsafe in case the Generic command can't be found
            if ($command === self::GENERIC_COMMAND) {
                throw new TelegramException('Generic command missing!');
            }

            //Handle a generic command or non existing one
            $this->last_command_response = $this->executeCommand(self::GENERIC_COMMAND);
        } else {
            //execute() method is executed after preExecute()
            //This is to prevent executing a DB query without a valid connection
            $this->last_command_response = $command_obj->preExecute();
        }

        return $this->last_command_response;
    }

    /**
     * Sanitize Command
     *
     * @param string $command
     *
     * @return string
     */
    protected function sanitizeCommand(string $command): string
    {
        return str_replace(' ', '', $this->ucWordsUnicode(str_replace('_', ' ', $command)));
    }

    /**
     * Enable a single Admin account
     *
     * @param int $admin_id Single admin id
     *
     * @return Telegram
     */
    public function enableAdmin(int $admin_id): Telegram
    {
        if ($admin_id <= 0) {
            TelegramLog::error('Invalid value "' . $admin_id . '" for admin.');
        } elseif (!in_array($admin_id, $this->admins_list, true)) {
            $this->admins_list[] = $admin_id;
        }

        return $this;
    }

    /**
     * Enable a list of Admin Accounts
     *
     * @param array $admin_ids List of admin ids
     *
     * @return Telegram
     */
    public function enableAdmins(array $admin_ids): Telegram
    {
        foreach ($admin_ids as $admin_id) {
            $this->enableAdmin($admin_id);
        }

        return $this;
    }

    /**
     * Get list of admins
     *
     * @return array
     */
    public function getAdminList(): array
    {
        return $this->admins_list;
    }

    /**
     * Check if the passed user is an admin
     *
     * If no user id is passed, the current update is checked for a valid message sender.
     *
     * @param int|null $user_id
     *
     * @return bool
     */
    public function isAdmin($user_id = null): bool
    {
        if ($user_id === null && $this->update !== null) {
            //Try to figure out if the user is an admin
            $update_methods = [
                'getMessage',
                'getEditedMessage',
                'getChannelPost',
                'getEditedChannelPost',
                'getInlineQuery',
                'getChosenInlineResult',
                'getCallbackQuery',
            ];
            foreach ($update_methods as $update_method) {
                $object = call_user_func([$this->update, $update_method]);
                if ($object !== null && $from = $object->getFrom()) {
                    $user_id = $from->getId();
                    break;
                }
            }
        }

        return ($user_id === null) ? false : in_array($user_id, $this->admins_list, true);
    }

    /**
     * Check if user required the db connection
     *
     * @return bool
     */
    public function isDbEnabled(): bool
    {
        return $this->mysql_enabled;
    }

    /**
     * Add a single custom commands class
     *
     * @param string $className Set full classname
     * @return Telegram
     */
    public function addCommandsClass(string $className): Telegram
    {
        if (!$className || !class_exists($className))
        {
            $error = 'Command class name: "' . $className . '" does not exist.';
            TelegramLog::error($error);
            throw new InvalidArgumentException($error);
        }

        if (!is_array($this->commandsClasses))
        {
            $this->commandsClasses = [];
        }

        if (!is_a($className, Command::class, true)) {
            $error = 'Command class is not a base command class';
            TelegramLog::error($error);
            throw new InvalidArgumentException($error);
        }

        $commandObject = new $className($this);

        $command = $commandObject->getName();
        $auth = null;
        switch (true) {
            case $commandObject->isSystemCommand():
                $auth = self::AUTH_SYSTEM;
                break;
            case $commandObject->isAdminCommand():
                $auth = self::AUTH_ADMIN;
                break;
            case $commandObject->isUserCommand():
                $auth = self::AUTH_USER;
                break;
        }

        if ($auth) {
            $this->commandsClasses[$auth][$command] = $className;
        }

        return $this;
    }

    /**
     * Add a single custom commands path
     *
     * @param string $path   Custom commands path to add
     * @param bool   $before If the path should be prepended or appended to the list
     *
     * @return Telegram
     */
    public function addCommandsPath(string $path, bool $before = true): Telegram
    {
        if (!is_dir($path)) {
            TelegramLog::error('Commands path "' . $path . '" does not exist.');
        } elseif (!in_array($path, $this->commands_paths, true)) {
            if ($before) {
                array_unshift($this->commands_paths, $path);
            } else {
                $this->commands_paths[] = $path;
            }
        }

        return $this;
    }

    /**
     * Add multiple custom commands paths
     *
     * @param array $paths  Custom commands paths to add
     * @param bool  $before If the paths should be prepended or appended to the list
     *
     * @return Telegram
     */
    public function addCommandsPaths(array $paths, $before = true): Telegram
    {
        foreach ($paths as $path) {
            $this->addCommandsPath($path, $before);
        }

        return $this;
    }

    /**
     * Return the list of commands paths
     *
     * @return array
     */
    public function getCommandsPaths(): array
    {
        return $this->commands_paths;
    }

    /**
     * Return the list of commands classes
     *
     * @return array
     */
    public function getCommandsClasses(): array
    {
        return $this->commandsClasses;
    }

    /**
     * Set custom upload path
     *
     * @param string $path Custom upload path
     *
     * @return Telegram
     */
    public function setUploadPath(string $path): Telegram
    {
        $this->upload_path = $path;

        return $this;
    }

    /**
     * Get custom upload path
     *
     * @return string
     */
    public function getUploadPath(): string
    {
        return $this->upload_path;
    }

    /**
     * Set custom download path
     *
     * @param string $path Custom download path
     *
     * @return Telegram
     */
    public function setDownloadPath(string $path): Telegram
    {
        $this->download_path = $path;

        return $this;
    }

    /**
     * Get custom download path
     *
     * @return string
     */
    public function getDownloadPath(): string
    {
        return $this->download_path;
    }

    /**
     * Set command config
     *
     * Provide further variables to a particular commands.
     * For example you can add the channel name at the command /sendtochannel
     * Or you can add the api key for external service.
     *
     * @param string $command
     * @param array  $config
     *
     * @return Telegram
     */
    public function setCommandConfig(string $command, array $config): Telegram
    {
        $this->commands_config[$command] = $config;

        return $this;
    }

    /**
     * Get command config
     *
     * @param string $command
     *
     * @return array
     */
    public function getCommandConfig(string $command): array
    {
        return $this->commands_config[$command] ?? [];
    }

    /**
     * Get API key
     *
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->api_key;
    }

    /**
     * Get Bot name
     *
     * @return string
     */
    public function getBotUsername(): string
    {
        return $this->bot_username;
    }

    /**
     * Get Bot Id
     *
     * @return int
     */
    public function getBotId(): int
    {
        return $this->bot_id;
    }

    /**
     * Get Version
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Set Webhook for bot
     *
     * @param string $url
     * @param array  $data Optional parameters.
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function setWebhook(string $url, array $data = []): ServerResponse
    {
        if ($url === '') {
            throw new TelegramException('Hook url is empty!');
        }

        $data        = array_intersect_key($data, array_flip([
            'certificate',
            'max_connections',
            'allowed_updates',
        ]));
        $data['url'] = $url;

        // If the certificate is passed as a path, encode and add the file to the data array.
        if (!empty($data['certificate']) && is_string($data['certificate'])) {
            $data['certificate'] = Request::encodeFile($data['certificate']);
        }

        $result = Request::setWebhook($data);

        if (!$result->isOk()) {
            throw new TelegramException(
                'Webhook was not set! Error: ' . $result->getErrorCode() . ' ' . $result->getDescription()
            );
        }

        return $result;
    }

    /**
     * Delete any assigned webhook
     *
     * @return mixed
     * @throws TelegramException
     */
    public function deleteWebhook()
    {
        $result = Request::deleteWebhook();

        if (!$result->isOk()) {
            throw new TelegramException(
                'Webhook was not deleted! Error: ' . $result->getErrorCode() . ' ' . $result->getDescription()
            );
        }

        return $result;
    }

    /**
     * Replace function `ucwords` for UTF-8 characters in the class definition and commands
     *
     * @param string $str
     * @param string $encoding (default = 'UTF-8')
     *
     * @return string
     */
    protected function ucWordsUnicode(string $str, string $encoding = 'UTF-8'): string
    {
        return mb_convert_case($str, MB_CASE_TITLE, $encoding);
    }

    /**
     * Replace function `ucfirst` for UTF-8 characters in the class definition and commands
     *
     * @param string $str
     * @param string $encoding (default = 'UTF-8')
     *
     * @return string
     */
    protected function ucFirstUnicode(string $str, string $encoding = 'UTF-8'): string
    {
        return mb_strtoupper(mb_substr($str, 0, 1, $encoding), $encoding)
            . mb_strtolower(mb_substr($str, 1, mb_strlen($str), $encoding), $encoding);
    }

    /**
     * Enable requests limiter
     *
     * @param array $options
     *
     * @return Telegram
     * @throws TelegramException
     */
    public function enableLimiter(array $options = []): Telegram
    {
        Request::setLimiter(true, $options);

        return $this;
    }

    /**
     * Run provided commands
     *
     * @param array $commands
     *
     * @throws TelegramException
     */
    public function runCommands(array $commands): void
    {
        if (empty($commands)) {
            throw new TelegramException('No command(s) provided!');
        }

        $this->run_commands = true;

        $result = Request::getMe();

        if ($result->isOk()) {
            $result = $result->getResult();

            $bot_id       = $result->getId();
            $bot_name     = $result->getFirstName();
            $bot_username = $result->getUsername();
        } else {
            $bot_id       = $this->getBotId();
            $bot_name     = $this->getBotUsername();
            $bot_username = $this->getBotUsername();
        }

        // Give bot access to admin commands
        $this->enableAdmin($bot_id);

        $newUpdate = static function ($text = '') use ($bot_id, $bot_name, $bot_username) {
            return new Update([
                'update_id' => 0,
                'message'   => [
                    'message_id' => 0,
                    'from'       => [
                        'id'         => $bot_id,
                        'first_name' => $bot_name,
                        'username'   => $bot_username,
                    ],
                    'date'       => time(),
                    'chat'       => [
                        'id'   => $bot_id,
                        'type' => 'private',
                    ],
                    'text'       => $text,
                ],
            ]);
        };

        foreach ($commands as $command) {
            $this->update = $newUpdate($command);

            // Load up-to-date commands list
            if (empty($this->commands_objects)) {
                $this->commands_objects = $this->getCommandsList();
            }

            $this->executeCommand($this->update->getMessage()->getCommand());
        }
    }

    /**
     * Is this session initiated by runCommands()
     *
     * @return bool
     */
    public function isRunCommands(): bool
    {
        return $this->run_commands;
    }

    /**
     * Switch to enable running getUpdates without a database
     *
     * @param bool $enable
     *
     * @return Telegram
     */
    public function useGetUpdatesWithoutDatabase(bool $enable = true): Telegram
    {
        $this->getupdates_without_database = $enable;

        return $this;
    }

    /**
     * Return last update id
     *
     * @return int
     */
    public function getLastUpdateId(): int
    {
        return $this->last_update_id;
    }

    /**
     * Set an update filter callback
     *
     * @param callable $callback
     *
     * @return Telegram
     */
    public function setUpdateFilter(callable $callback): Telegram
    {
        $this->update_filter = $callback;

        return $this;
    }

    /**
     * Return update filter callback
     *
     * @return callable|null
     */
    public function getUpdateFilter(): ?callable
    {
        return $this->update_filter;
    }
}
