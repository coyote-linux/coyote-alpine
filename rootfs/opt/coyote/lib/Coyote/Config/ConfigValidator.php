<?php

namespace Coyote\Config;

/**
 * Validates system configuration data.
 */
class ConfigValidator
{
    /**
     * Validate configuration data.
     *
     * @param array $config Configuration data to validate
     * @return array Array of error messages, empty if valid
     */
    public function validate(array $config): array
    {
        $errors = [];

        // Validate system section
        if (isset($config['system'])) {
            $errors = array_merge($errors, $this->validateSystem($config['system']));
        }

        // Validate network section
        if (isset($config['network'])) {
            $errors = array_merge($errors, $this->validateNetwork($config['network']));
        }

        // Validate services section
        if (isset($config['services'])) {
            $errors = array_merge($errors, $this->validateServices($config['services']));
        }

        return $errors;
    }

    /**
     * Validate the system configuration section.
     *
     * @param array $system System configuration
     * @return array Validation errors
     */
    private function validateSystem(array $system): array
    {
        $errors = [];

        // Validate hostname
        if (isset($system['hostname'])) {
            if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $system['hostname'])) {
                $errors[] = 'Invalid hostname format';
            }
        }

        // Validate timezone
        if (isset($system['timezone'])) {
            $timezones = \DateTimeZone::listIdentifiers();
            if (!in_array($system['timezone'], $timezones, true)) {
                $errors[] = 'Invalid timezone: ' . $system['timezone'];
            }
        }

        return $errors;
    }

    /**
     * Validate the network configuration section.
     *
     * @param array $network Network configuration
     * @return array Validation errors
     */
    private function validateNetwork(array $network): array
    {
        $errors = [];

        // Validate interfaces
        if (isset($network['interfaces']) && is_array($network['interfaces'])) {
            foreach ($network['interfaces'] as $name => $iface) {
                if (isset($iface['ipv4']['address'])) {
                    if (!filter_var($iface['ipv4']['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $errors[] = "Invalid IPv4 address for interface {$name}";
                    }
                }
                if (isset($iface['ipv6']['address'])) {
                    if (!filter_var($iface['ipv6']['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $errors[] = "Invalid IPv6 address for interface {$name}";
                    }
                }
            }
        }

        // Validate routes
        if (isset($network['routes']) && is_array($network['routes'])) {
            foreach ($network['routes'] as $i => $route) {
                if (isset($route['gateway'])) {
                    if (!filter_var($route['gateway'], FILTER_VALIDATE_IP)) {
                        $errors[] = "Invalid gateway IP in route {$i}";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Validate the services configuration section.
     *
     * @param array $services Services configuration
     * @return array Validation errors
     */
    private function validateServices(array $services): array
    {
        $errors = [];

        // Validate DHCP server configuration
        if (isset($services['dhcpd'])) {
            $dhcp = $services['dhcpd'];
            if (isset($dhcp['range_start']) && isset($dhcp['range_end'])) {
                if (!filter_var($dhcp['range_start'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $errors[] = 'Invalid DHCP range start address';
                }
                if (!filter_var($dhcp['range_end'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $errors[] = 'Invalid DHCP range end address';
                }
            }
        }

        // Validate DNS configuration
        if (isset($services['dns'])) {
            $dns = $services['dns'];
            if (isset($dns['forwarders']) && is_array($dns['forwarders'])) {
                foreach ($dns['forwarders'] as $i => $server) {
                    if (!filter_var($server, FILTER_VALIDATE_IP)) {
                        $errors[] = "Invalid DNS forwarder address at index {$i}";
                    }
                }
            }
        }

        return $errors;
    }
}
