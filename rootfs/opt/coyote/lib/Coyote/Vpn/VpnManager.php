<?php

namespace Coyote\Vpn;

use Coyote\Util\Logger;

/**
 * Central VPN management for Coyote Linux.
 *
 * Orchestrates IPSec VPN services using StrongSwan.
 */
class VpnManager
{
    /** @var StrongSwanService */
    private StrongSwanService $strongswan;

    /** @var Logger */
    private Logger $logger;

    private WireGuardService $wireguard;
    private OpenVpnService $openvpn;

    /** @var array Current VPN configuration */
    private array $config = [];

    /**
     * Create a new VpnManager instance.
     */
    public function __construct()
    {
        $this->strongswan = new StrongSwanService();
        $this->wireguard = new WireGuardService();
        $this->openvpn = new OpenVpnService();
        $this->logger = new Logger('coyote-vpn');
    }

    /**
     * Apply VPN configuration.
     *
     * @param array $config VPN configuration section
     * @return bool True if successful
     */
    public function applyConfig(array $config): bool
    {
        $this->config = $config;
        $success = true;

        // Handle IPSec tunnels
        if (isset($config['ipsec'])) {
            $ipsecConfig = $config['ipsec'];

            if (!($ipsecConfig['enabled'] ?? true) || empty($ipsecConfig['tunnels'])) {
                $this->logger->info('IPSec VPN disabled or no tunnels configured');
                if (!$this->strongswan->stop()) {
                    $success = false;
                }
            } else {
                $this->logger->info('Applying IPSec VPN configuration');

                if (!$this->strongswan->applyConfig($ipsecConfig)) {
                    $this->logger->error('Failed to apply IPSec configuration');
                    $success = false;
                } else {
                    $this->logger->info('IPSec VPN configuration applied successfully');
                }
            }
        }

        if (isset($config['wireguard']) && is_array($config['wireguard'])) {
            $this->logger->info('Applying WireGuard VPN configuration');

            if (!$this->wireguard->applyConfig($config['wireguard'])) {
                $this->logger->error('Failed to apply WireGuard configuration');
                $success = false;
            } else {
                $this->logger->info('WireGuard VPN configuration applied successfully');
            }
        }

        if (isset($config['openvpn']) && is_array($config['openvpn'])) {
            $this->logger->info('Applying OpenVPN configuration');

            if (!$this->openvpn->applyConfig($config['openvpn'])) {
                $this->logger->error('Failed to apply OpenVPN configuration');
                $success = false;
            } else {
                $this->logger->info('OpenVPN configuration applied successfully');
            }
        }

        return $success;
    }

    /**
     * Get VPN status.
     *
     * @return array Status information
     */
    public function getStatus(): array
    {
        return [
            'ipsec' => $this->strongswan->getStatus(),
            'wireguard' => $this->wireguard->getInterfaceStatus(),
            'openvpn' => $this->getOpenVpnStatus(),
            'tunnels' => $this->getTunnelStatus(),
        ];
    }

    public function getOpenVpnStatus(): array
    {
        $statuses = [];

        foreach ($this->config['openvpn']['instances'] ?? [] as $name => $instanceConfig) {
            if (!is_array($instanceConfig)) {
                continue;
            }

            $statuses[(string)$name] = $this->openvpn->getStatus((string)$name);
        }

        return [
            'enabled' => (bool)($this->config['openvpn']['enabled'] ?? false),
            'instances' => $statuses,
        ];
    }

    /**
     * Get status of all configured tunnels.
     *
     * @return array Tunnel status information
     */
    public function getTunnelStatus(): array
    {
        $tunnels = [];

        foreach ($this->config['ipsec']['tunnels'] ?? [] as $name => $config) {
            $status = $this->strongswan->getTunnelStatus($name);
            $tunnels[$name] = [
                'name' => $name,
                'remote' => $config['remote_address'] ?? 'unknown',
                'status' => $status['state'] ?? 'unknown',
                'connected' => $status['established'] ?? false,
                'bytes_in' => $status['bytes_in'] ?? 0,
                'bytes_out' => $status['bytes_out'] ?? 0,
            ];
        }

        return $tunnels;
    }

    /**
     * Initiate a VPN tunnel connection.
     *
     * @param string $name Tunnel name
     * @return bool True if successful
     */
    public function connectTunnel(string $name): bool
    {
        $this->logger->info("Initiating VPN tunnel: {$name}");
        return $this->strongswan->initiateConnection($name);
    }

    /**
     * Terminate a VPN tunnel connection.
     *
     * @param string $name Tunnel name
     * @return bool True if successful
     */
    public function disconnectTunnel(string $name): bool
    {
        $this->logger->info("Terminating VPN tunnel: {$name}");
        return $this->strongswan->terminateConnection($name);
    }

    /**
     * Create a new IPSec tunnel configuration.
     *
     * @param string $name Tunnel name
     * @param array $config Tunnel configuration
     * @return IpsecTunnel Tunnel object
     */
    public function createTunnel(string $name, array $config): IpsecTunnel
    {
        return new IpsecTunnel($name, $config);
    }

    /**
     * Add a tunnel to the configuration.
     *
     * @param IpsecTunnel $tunnel Tunnel to add
     * @return bool True if successful
     */
    public function addTunnel(IpsecTunnel $tunnel): bool
    {
        if (!isset($this->config['ipsec'])) {
            $this->config['ipsec'] = ['enabled' => true, 'tunnels' => []];
        }

        $this->config['ipsec']['tunnels'][$tunnel->getName()] = $tunnel->toArray();

        return $this->applyConfig($this->config);
    }

    /**
     * Remove a tunnel from the configuration.
     *
     * @param string $name Tunnel name
     * @return bool True if successful
     */
    public function removeTunnel(string $name): bool
    {
        // Terminate connection first
        $this->disconnectTunnel($name);

        unset($this->config['ipsec']['tunnels'][$name]);

        return $this->applyConfig($this->config);
    }

    /**
     * Get the StrongSwan service instance.
     *
     * @return StrongSwanService
     */
    public function getStrongSwanService(): StrongSwanService
    {
        return $this->strongswan;
    }

    public function getWireGuardService(): WireGuardService
    {
        return $this->wireguard;
    }

    public function getOpenVpnService(): OpenVpnService
    {
        return $this->openvpn;
    }

    /**
     * Reload all VPN configurations.
     *
     * @return bool True if successful
     */
    public function reload(): bool
    {
        $this->logger->info('Reloading VPN configuration');
        return $this->strongswan->reload();
    }

    /**
     * Check if VPN service is running.
     *
     * @return bool True if running
     */
    public function isRunning(): bool
    {
        return $this->strongswan->isRunning();
    }
}
