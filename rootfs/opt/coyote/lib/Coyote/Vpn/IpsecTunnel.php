<?php

namespace Coyote\Vpn;

/**
 * Represents an IPSec VPN tunnel configuration.
 *
 * Provides a fluent interface for building tunnel configurations.
 */
class IpsecTunnel
{
    /** @var string Tunnel name */
    private string $name;

    /** @var array Tunnel configuration */
    private array $config = [];

    /**
     * Create a new IpsecTunnel instance.
     *
     * @param string $name Tunnel name
     * @param array $config Optional initial configuration
     */
    public function __construct(string $name, array $config = [])
    {
        $this->name = $name;
        $this->config = array_merge($this->getDefaults(), $config);
    }

    /**
     * Get default tunnel configuration.
     *
     * @return array Default values
     */
    private function getDefaults(): array
    {
        return [
            'version' => 2,
            'local_address' => '%any',
            'remote_address' => '%any',
            'local_auth' => 'psk',
            'remote_auth' => 'psk',
            'start_action' => 'trap',
            'dpd_action' => 'restart',
            'close_action' => 'restart',
            'proposals' => ['aes256-sha256-modp2048'],
            'esp_proposals' => ['aes256-sha256'],
        ];
    }

    /**
     * Get the tunnel name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the local IP address.
     *
     * @param string $address IP address or %any
     * @return static
     */
    public function localAddress(string $address): static
    {
        $this->config['local_address'] = $address;
        return $this;
    }

    /**
     * Set the remote IP address.
     *
     * @param string $address IP address or hostname
     * @return static
     */
    public function remoteAddress(string $address): static
    {
        $this->config['remote_address'] = $address;
        return $this;
    }

    /**
     * Set the local ID.
     *
     * @param string $id Local identifier
     * @return static
     */
    public function localId(string $id): static
    {
        $this->config['local_id'] = $id;
        return $this;
    }

    /**
     * Set the remote ID.
     *
     * @param string $id Remote identifier
     * @return static
     */
    public function remoteId(string $id): static
    {
        $this->config['remote_id'] = $id;
        return $this;
    }

    /**
     * Set the pre-shared key.
     *
     * @param string $psk Pre-shared key
     * @return static
     */
    public function psk(string $psk): static
    {
        $this->config['psk'] = $psk;
        $this->config['local_auth'] = 'psk';
        $this->config['remote_auth'] = 'psk';
        return $this;
    }

    /**
     * Set local traffic selector (network behind local gateway).
     *
     * @param string $network Network in CIDR notation
     * @return static
     */
    public function localNetwork(string $network): static
    {
        $this->config['local_ts'] = $network;
        return $this;
    }

    /**
     * Set remote traffic selector (network behind remote gateway).
     *
     * @param string $network Network in CIDR notation
     * @return static
     */
    public function remoteNetwork(string $network): static
    {
        $this->config['remote_ts'] = $network;
        return $this;
    }

    /**
     * Set the IKE version.
     *
     * @param int $version IKE version (1 or 2)
     * @return static
     */
    public function ikeVersion(int $version): static
    {
        $this->config['version'] = $version;
        return $this;
    }

    /**
     * Set IKE proposals.
     *
     * @param array $proposals List of proposal strings
     * @return static
     */
    public function ikeProposals(array $proposals): static
    {
        $this->config['proposals'] = $proposals;
        return $this;
    }

    /**
     * Set ESP proposals.
     *
     * @param array $proposals List of proposal strings
     * @return static
     */
    public function espProposals(array $proposals): static
    {
        $this->config['esp_proposals'] = $proposals;
        return $this;
    }

    /**
     * Set the start action.
     *
     * @param string $action Action (none, trap, start)
     * @return static
     */
    public function startAction(string $action): static
    {
        $this->config['start_action'] = $action;
        return $this;
    }

    /**
     * Set the DPD (Dead Peer Detection) action.
     *
     * @param string $action Action (none, clear, restart)
     * @return static
     */
    public function dpdAction(string $action): static
    {
        $this->config['dpd_action'] = $action;
        return $this;
    }

    /**
     * Set the close action.
     *
     * @param string $action Action (none, clear, restart)
     * @return static
     */
    public function closeAction(string $action): static
    {
        $this->config['close_action'] = $action;
        return $this;
    }

    /**
     * Enable/disable the tunnel.
     *
     * @param bool $enabled Whether tunnel is enabled
     * @return static
     */
    public function enabled(bool $enabled = true): static
    {
        $this->config['enabled'] = $enabled;
        return $this;
    }

    /**
     * Set the rekey time for IKE SA.
     *
     * @param int $seconds Time in seconds
     * @return static
     */
    public function rekeyTime(int $seconds): static
    {
        $this->config['rekey_time'] = $seconds;
        return $this;
    }

    /**
     * Set the lifetime for child SA.
     *
     * @param int $seconds Time in seconds
     * @return static
     */
    public function lifetime(int $seconds): static
    {
        $this->config['life_time'] = $seconds;
        return $this;
    }

    /**
     * Get the tunnel configuration as an array.
     *
     * @return array Configuration array
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * Create a tunnel from an array configuration.
     *
     * @param string $name Tunnel name
     * @param array $config Configuration array
     * @return static
     */
    public static function fromArray(string $name, array $config): static
    {
        return new static($name, $config);
    }

    /**
     * Validate the tunnel configuration.
     *
     * @return array Validation errors (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];

        if (empty($this->config['remote_address']) || $this->config['remote_address'] === '%any') {
            // Remote address is usually required
        }

        if (empty($this->config['psk']) && $this->config['local_auth'] === 'psk') {
            $errors[] = 'Pre-shared key is required for PSK authentication';
        }

        if (empty($this->config['local_ts'])) {
            $errors[] = 'Local traffic selector (network) is required';
        }

        if (empty($this->config['remote_ts'])) {
            $errors[] = 'Remote traffic selector (network) is required';
        }

        return $errors;
    }
}
