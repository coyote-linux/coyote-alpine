<?php

namespace Coyote\Util;

/**
 * Simple logging utility for Coyote Linux.
 *
 * Writes log messages to syslog and optionally to file.
 */
class Logger
{
    /** @var string Logger identifier */
    private string $ident;

    /** @var int Syslog facility */
    private int $facility;

    /** @var string|null Optional log file path */
    private ?string $logFile;

    /** Log level constants */
    public const EMERGENCY = LOG_EMERG;
    public const ALERT = LOG_ALERT;
    public const CRITICAL = LOG_CRIT;
    public const ERROR = LOG_ERR;
    public const WARNING = LOG_WARNING;
    public const NOTICE = LOG_NOTICE;
    public const INFO = LOG_INFO;
    public const DEBUG = LOG_DEBUG;

    /**
     * Create a new Logger instance.
     *
     * @param string $ident Logger identifier (appears in syslog)
     * @param int $facility Syslog facility (default: LOG_LOCAL0)
     * @param string|null $logFile Optional path to log file
     */
    public function __construct(
        string $ident = 'coyote',
        int $facility = LOG_LOCAL0,
        ?string $logFile = null
    ) {
        $this->ident = $ident;
        $this->facility = $facility;
        $this->logFile = $logFile;

        openlog($this->ident, LOG_PID | LOG_NDELAY, $this->facility);
    }

    /**
     * Log a message.
     *
     * @param int $level Log level
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function log(int $level, string $message, array $context = []): void
    {
        // Interpolate context into message
        $interpolated = $this->interpolate($message, $context);

        // Write to syslog
        syslog($level, $interpolated);

        // Write to file if configured
        if ($this->logFile !== null) {
            $this->writeToFile($level, $interpolated);
        }
    }

    /**
     * Log an emergency message.
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log(self::EMERGENCY, $message, $context);
    }

    /**
     * Log an alert message.
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log(self::ALERT, $message, $context);
    }

    /**
     * Log a critical message.
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    /**
     * Log an error message.
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * Log a warning message.
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /**
     * Log a notice message.
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log(self::NOTICE, $message, $context);
    }

    /**
     * Log an info message.
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /**
     * Log a debug message.
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    /**
     * Interpolate context values into message placeholders.
     *
     * @param string $message Message with {placeholder} markers
     * @param array $context Context values
     * @return string Interpolated message
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $replace['{' . $key . '}'] = $value;
            } elseif (is_bool($value)) {
                $replace['{' . $key . '}'] = $value ? 'true' : 'false';
            } elseif (is_null($value)) {
                $replace['{' . $key . '}'] = 'null';
            } elseif (is_array($value) || is_object($value)) {
                $replace['{' . $key . '}'] = json_encode($value);
            }
        }
        return strtr($message, $replace);
    }

    /**
     * Write log message to file.
     *
     * @param int $level Log level
     * @param string $message Log message
     * @return void
     */
    private function writeToFile(int $level, string $message): void
    {
        $levelNames = [
            self::EMERGENCY => 'EMERGENCY',
            self::ALERT => 'ALERT',
            self::CRITICAL => 'CRITICAL',
            self::ERROR => 'ERROR',
            self::WARNING => 'WARNING',
            self::NOTICE => 'NOTICE',
            self::INFO => 'INFO',
            self::DEBUG => 'DEBUG',
        ];

        $levelName = $levelNames[$level] ?? 'UNKNOWN';
        $timestamp = date('Y-m-d H:i:s');
        $line = "[{$timestamp}] [{$levelName}] {$message}\n";

        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Close the syslog connection.
     */
    public function __destruct()
    {
        closelog();
    }
}
