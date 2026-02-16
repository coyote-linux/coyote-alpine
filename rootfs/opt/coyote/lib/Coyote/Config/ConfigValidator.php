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

        if (isset($config['firewall'])) {
            $errors = array_merge($errors, $this->validateFirewall($config['firewall']));
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

    private function validateFirewall(array $firewall): array
    {
        $errors = [];

        if (isset($firewall['default_policy']) && $firewall['default_policy'] !== 'drop') {
            $errors[] = 'Firewall default policy must be drop';
        }

        $acls = $firewall['acls'] ?? [];
        if (!is_array($acls)) {
            return $errors;
        }

        foreach ($acls as $aclIndex => $acl) {
            $aclName = is_array($acl) ? ($acl['name'] ?? (string)$aclIndex) : (string)$aclIndex;
            $rules = is_array($acl) ? ($acl['rules'] ?? []) : [];

            if (!is_array($rules)) {
                $errors[] = "Firewall ACL '{$aclName}' has invalid rules format";
                continue;
            }

            foreach ($rules as $ruleIndex => $rule) {
                if (!is_array($rule)) {
                    $errors[] = "Firewall ACL '{$aclName}' rule {$ruleIndex} is invalid";
                    continue;
                }

                $action = strtolower((string)($rule['action'] ?? 'accept'));
                if (!in_array($action, ['permit', 'deny', 'accept', 'allow', 'drop', 'reject'], true)) {
                    $errors[] = "Firewall ACL '{$aclName}' rule {$ruleIndex} has invalid action";
                }

                $protocol = strtolower((string)($rule['protocol'] ?? 'any'));
                if (!in_array($protocol, ['any', 'all', 'tcp', 'udp', 'icmp', 'icmpv6', 'gre', 'esp', 'ah', 'sctp'], true)) {
                    $errors[] = "Firewall ACL '{$aclName}' rule {$ruleIndex} has invalid protocol";
                }

                $ports = $rule['ports'] ?? ($rule['destination_port'] ?? ($rule['port'] ?? ($rule['dport'] ?? null)));
                if ($ports !== null && $ports !== '') {
                    if (!in_array($protocol, ['tcp', 'udp'], true)) {
                        $errors[] = "Firewall ACL '{$aclName}' rule {$ruleIndex} uses ports with non-TCP/UDP protocol";
                    } elseif (!$this->isValidPortSpec((string)$ports)) {
                        $errors[] = "Firewall ACL '{$aclName}' rule {$ruleIndex} has invalid port specification";
                    }
                }
            }
        }

        return $errors;
    }

    private function isValidPortSpec(string $ports): bool
    {
        $ports = preg_replace('/\s+/', '', trim($ports));
        if ($ports === '') {
            return false;
        }

        foreach (explode(',', $ports) as $part) {
            if ($part === '') {
                return false;
            }

            if (strpos($part, '-') !== false) {
                [$start, $end] = explode('-', $part, 2);
                if (!$this->isValidPort($start) || !$this->isValidPort($end) || (int)$start >= (int)$end) {
                    return false;
                }
                continue;
            }

            if (!$this->isValidPort($part)) {
                return false;
            }
        }

        return true;
    }

    private function isValidPort(string $port): bool
    {
        if (!preg_match('/^[0-9]+$/', $port)) {
            return false;
        }

        $portInt = (int)$port;
        return $portInt >= 1 && $portInt <= 65535;
    }
}
