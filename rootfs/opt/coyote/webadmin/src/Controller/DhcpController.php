<?php

namespace Coyote\WebAdmin\Controller;

use Coyote\WebAdmin\Service\ConfigService;

class DhcpController extends BaseController
{
    private ConfigService $configService;

    public function __construct()
    {
        parent::__construct();
        $this->configService = new ConfigService();
    }

    public function index(array $params = []): void
    {
        $config = $this->configService->getWorkingConfig()->toArray();
        $dhcpConfig = $config['services']['dhcpd'] ?? [];
        $interfaces = $config['network']['interfaces'] ?? [];

        $availableInterfaces = [];
        foreach ($interfaces as $iface) {
            $name = $iface['name'] ?? '';
            $enabled = $iface['enabled'] ?? false;
            $bridge = $iface['bridge'] ?? false;

            if (!empty($name) && $enabled && !$bridge) {
                $availableInterfaces[] = $name;
            }
        }

        $reservationCount = count($dhcpConfig['reservations'] ?? []);

        $this->render('pages/dhcp', [
            'page' => 'dhcp',
            'title' => 'DHCP Server',
            'dhcpConfig' => $dhcpConfig,
            'availableInterfaces' => $availableInterfaces,
            'reservationCount' => $reservationCount,
        ]);
    }

    public function save(array $params = []): void
    {
        $enabled = (bool)$this->post('enabled', false);
        $interface = trim($this->post('interface', ''));
        $domain = trim($this->post('domain', ''));
        $rangeStart = trim($this->post('range_start', ''));
        $rangeEnd = trim($this->post('range_end', ''));
        $subnetMask = trim($this->post('subnet_mask', ''));
        $gateway = trim($this->post('gateway', ''));
        $dns1 = trim($this->post('dns1', ''));
        $dns2 = trim($this->post('dns2', ''));
        $leaseTime = (int)$this->post('lease_time', 86400);

        $errors = [];

        if ($enabled) {
            if (empty($interface)) {
                $errors[] = 'Interface is required when DHCP is enabled';
            }
            if (empty($rangeStart)) {
                $errors[] = 'Range start is required';
            }
            if (empty($rangeEnd)) {
                $errors[] = 'Range end is required';
            }
            if (!empty($rangeStart) && !filter_var($rangeStart, FILTER_VALIDATE_IP)) {
                $errors[] = 'Invalid range start IP address';
            }
            if (!empty($rangeEnd) && !filter_var($rangeEnd, FILTER_VALIDATE_IP)) {
                $errors[] = 'Invalid range end IP address';
            }
            if (!empty($subnetMask) && !filter_var($subnetMask, FILTER_VALIDATE_IP)) {
                $errors[] = 'Invalid subnet mask';
            }
            if (!empty($gateway) && !filter_var($gateway, FILTER_VALIDATE_IP)) {
                $errors[] = 'Invalid gateway IP address';
            }
            if (!empty($dns1) && !filter_var($dns1, FILTER_VALIDATE_IP)) {
                $errors[] = 'Invalid primary DNS server IP address';
            }
            if (!empty($dns2) && !filter_var($dns2, FILTER_VALIDATE_IP)) {
                $errors[] = 'Invalid secondary DNS server IP address';
            }
            if ($leaseTime < 120) {
                $errors[] = 'Lease time must be at least 120 seconds';
            }
        }

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect('/dhcp');
            return;
        }

        $dnsServers = [];
        if (!empty($dns1)) {
            $dnsServers[] = $dns1;
        }
        if (!empty($dns2)) {
            $dnsServers[] = $dns2;
        }

        $config = $this->configService->getWorkingConfig();
        $config->set('services.dhcpd.enabled', $enabled);
        $config->set('services.dhcpd.interface', $interface);
        $config->set('services.dhcpd.domain', $domain);
        $config->set('services.dhcpd.range_start', $rangeStart);
        $config->set('services.dhcpd.range_end', $rangeEnd);
        $config->set('services.dhcpd.subnet_mask', $subnetMask);
        $config->set('services.dhcpd.gateway', $gateway);
        $config->set('services.dhcpd.dns_servers', $dnsServers);
        $config->set('services.dhcpd.lease_time', $leaseTime);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'DHCP settings saved. Click "Apply Configuration" to activate changes.');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/dhcp');
    }

    public function reservations(array $params = []): void
    {
        $config = $this->configService->getWorkingConfig()->toArray();
        $reservations = $config['services']['dhcpd']['reservations'] ?? [];

        $this->render('pages/dhcp/reservations', [
            'page' => 'dhcp',
            'title' => 'DHCP Reservations',
            'reservations' => $reservations,
        ]);
    }

    public function saveReservations(array $params = []): void
    {
        $macs = $this->post('mac', []);
        $ips = $this->post('ip', []);
        $hostnames = $this->post('hostname', []);

        $errors = [];
        $reservations = [];

        if (!is_array($macs)) {
            $macs = [];
        }
        if (!is_array($ips)) {
            $ips = [];
        }
        if (!is_array($hostnames)) {
            $hostnames = [];
        }

        $count = max(count($macs), count($ips), count($hostnames));

        for ($i = 0; $i < $count; $i++) {
            $mac = trim($macs[$i] ?? '');
            $ip = trim($ips[$i] ?? '');
            $hostname = trim($hostnames[$i] ?? '');

            if (empty($mac) && empty($ip)) {
                continue;
            }

            if (empty($mac)) {
                $errors[] = "Row " . ($i + 1) . ": MAC address is required";
                continue;
            }

            if (empty($ip)) {
                $errors[] = "Row " . ($i + 1) . ": IP address is required";
                continue;
            }

            if (!preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac)) {
                $errors[] = "Row " . ($i + 1) . ": Invalid MAC address format";
                continue;
            }

            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $errors[] = "Row " . ($i + 1) . ": Invalid IP address";
                continue;
            }

            $reservations[] = [
                'mac' => $mac,
                'ip' => $ip,
                'hostname' => $hostname,
            ];
        }

        if (!empty($errors)) {
            $this->flash('error', implode('. ', $errors));
            $this->redirect('/dhcp/reservations');
            return;
        }

        $config = $this->configService->getWorkingConfig();
        $config->set('services.dhcpd.reservations', $reservations);

        if ($this->configService->saveWorkingConfig($config)) {
            $this->flash('success', 'DHCP reservations saved. Click "Apply Configuration" to activate changes.');
        } else {
            $this->flash('error', 'Failed to save configuration');
        }

        $this->redirect('/dhcp/reservations');
    }
}
