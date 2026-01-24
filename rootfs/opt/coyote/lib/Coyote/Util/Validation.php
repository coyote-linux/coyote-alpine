<?php

namespace Coyote\Util;

/**
 * Input validation utilities.
 *
 * Provides validators for common network and system values.
 */
class Validation
{
    /**
     * Validate an IPv4 address.
     *
     * @param string $ip IP address to validate
     * @return bool True if valid
     */
    public static function isValidIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Validate an IPv6 address.
     *
     * @param string $ip IP address to validate
     * @return bool True if valid
     */
    public static function isValidIpv6(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /**
     * Validate an IP address (IPv4 or IPv6).
     *
     * @param string $ip IP address to validate
     * @return bool True if valid
     */
    public static function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Validate a CIDR notation (e.g., 192.168.1.0/24).
     *
     * @param string $cidr CIDR to validate
     * @return bool True if valid
     */
    public static function isValidCidr(string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return false;
        }

        [$ip, $mask] = explode('/', $cidr, 2);

        if (!is_numeric($mask)) {
            return false;
        }

        $mask = (int)$mask;

        if (self::isValidIpv4($ip)) {
            return $mask >= 0 && $mask <= 32;
        }

        if (self::isValidIpv6($ip)) {
            return $mask >= 0 && $mask <= 128;
        }

        return false;
    }

    /**
     * Validate a MAC address.
     *
     * @param string $mac MAC address to validate
     * @return bool True if valid
     */
    public static function isValidMac(string $mac): bool
    {
        return preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac) === 1;
    }

    /**
     * Validate a port number.
     *
     * @param int|string $port Port number to validate
     * @return bool True if valid (1-65535)
     */
    public static function isValidPort($port): bool
    {
        if (!is_numeric($port)) {
            return false;
        }

        $port = (int)$port;
        return $port >= 1 && $port <= 65535;
    }

    /**
     * Validate a port range (e.g., 80-443).
     *
     * @param string $range Port range to validate
     * @return bool True if valid
     */
    public static function isValidPortRange(string $range): bool
    {
        if (strpos($range, '-') === false) {
            return self::isValidPort($range);
        }

        [$start, $end] = explode('-', $range, 2);

        if (!self::isValidPort($start) || !self::isValidPort($end)) {
            return false;
        }

        return (int)$start <= (int)$end;
    }

    /**
     * Validate a hostname.
     *
     * @param string $hostname Hostname to validate
     * @return bool True if valid
     */
    public static function isValidHostname(string $hostname): bool
    {
        // Max length 253, labels 1-63 chars, alphanumeric with hyphens
        if (strlen($hostname) > 253) {
            return false;
        }

        $pattern = '/^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/';
        return preg_match($pattern, $hostname) === 1;
    }

    /**
     * Validate an interface name.
     *
     * @param string $name Interface name to validate
     * @return bool True if valid
     */
    public static function isValidInterfaceName(string $name): bool
    {
        // Linux interface names: max 15 chars, alphanumeric, underscores, hyphens
        return preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,14}$/', $name) === 1;
    }

    /**
     * Validate a protocol name.
     *
     * @param string $protocol Protocol to validate
     * @return bool True if valid
     */
    public static function isValidProtocol(string $protocol): bool
    {
        $valid = ['tcp', 'udp', 'icmp', 'all', 'gre', 'esp', 'ah', 'sctp'];
        return in_array(strtolower($protocol), $valid, true);
    }

    /**
     * Validate a timezone string.
     *
     * @param string $timezone Timezone to validate
     * @return bool True if valid
     */
    public static function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, timezone_identifiers_list(), true);
    }

    /**
     * Sanitize a string for use in shell commands.
     *
     * @param string $value Value to sanitize
     * @return string Sanitized value
     */
    public static function sanitizeShellArg(string $value): string
    {
        return escapeshellarg($value);
    }

    /**
     * Sanitize a string for use as a filename.
     *
     * @param string $filename Filename to sanitize
     * @return string Sanitized filename
     */
    public static function sanitizeFilename(string $filename): string
    {
        // Remove any path components
        $filename = basename($filename);

        // Replace problematic characters
        $filename = preg_replace('/[^\w\-\.]/', '_', $filename);

        // Remove leading dots
        $filename = ltrim($filename, '.');

        return $filename ?: 'unnamed';
    }

    /**
     * Validate a URL.
     *
     * @param string $url URL to validate
     * @param array $schemes Allowed schemes (default: http, https)
     * @return bool True if valid
     */
    public static function isValidUrl(string $url, array $schemes = ['http', 'https']): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parsed = parse_url($url);
        if (!isset($parsed['scheme'])) {
            return false;
        }

        return in_array(strtolower($parsed['scheme']), $schemes, true);
    }

    /**
     * Validate a domain name.
     *
     * @param string $domain Domain to validate
     * @return bool True if valid
     */
    public static function isValidDomain(string $domain): bool
    {
        return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
