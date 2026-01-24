<?php

namespace Coyote\Firewall;

/**
 * Quality of Service (QoS) management service.
 *
 * Uses Linux traffic control (tc) to implement bandwidth limiting,
 * traffic shaping, and prioritization.
 */
class QosService
{
    /** @var string Path to tc binary */
    private string $tc = '/sbin/tc';

    /** @var string Path to iptables binary */
    private string $iptables = '/sbin/iptables';

    /**
     * Apply QoS configuration.
     *
     * @param array $config QoS configuration
     * @return bool True if successful
     */
    public function applyConfig(array $config): bool
    {
        if (!($config['enabled'] ?? false)) {
            return true;
        }

        $success = true;

        foreach ($config['interfaces'] ?? [] as $interface => $settings) {
            // Clear existing qdisc
            $this->clearQdisc($interface);

            // Set up traffic shaping
            if (isset($settings['bandwidth'])) {
                $success = $success && $this->setupBandwidthLimit(
                    $interface,
                    $settings['bandwidth'],
                    $settings['burst'] ?? null
                );
            }

            // Set up traffic classes
            if (isset($settings['classes'])) {
                $success = $success && $this->setupTrafficClasses($interface, $settings['classes']);
            }
        }

        return $success;
    }

    /**
     * Clear qdisc on an interface.
     *
     * @param string $interface Interface name
     * @return void
     */
    public function clearQdisc(string $interface): void
    {
        exec("{$this->tc} qdisc del dev " . escapeshellarg($interface) . " root 2>/dev/null");
    }

    /**
     * Set up bandwidth limiting on an interface.
     *
     * @param string $interface Interface name
     * @param string $bandwidth Bandwidth limit (e.g., "10mbit", "100kbit")
     * @param string|null $burst Burst size (e.g., "10k")
     * @return bool True if successful
     */
    public function setupBandwidthLimit(string $interface, string $bandwidth, ?string $burst = null): bool
    {
        // Use HTB (Hierarchical Token Bucket) qdisc
        $cmd = "{$this->tc} qdisc add dev " . escapeshellarg($interface);
        $cmd .= " root handle 1: htb default 10";

        exec($cmd . ' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            return false;
        }

        // Add root class with bandwidth limit
        $classCmd = "{$this->tc} class add dev " . escapeshellarg($interface);
        $classCmd .= " parent 1: classid 1:1 htb rate {$bandwidth}";

        if ($burst !== null) {
            $classCmd .= " burst {$burst}";
        }

        exec($classCmd . ' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            return false;
        }

        // Add default class
        $defaultCmd = "{$this->tc} class add dev " . escapeshellarg($interface);
        $defaultCmd .= " parent 1:1 classid 1:10 htb rate {$bandwidth}";
        exec($defaultCmd . ' 2>&1');

        return true;
    }

    /**
     * Set up traffic classes for prioritization.
     *
     * @param string $interface Interface name
     * @param array $classes Traffic class definitions
     * @return bool True if successful
     */
    public function setupTrafficClasses(string $interface, array $classes): bool
    {
        $classId = 20;

        foreach ($classes as $class) {
            $priority = $class['priority'] ?? 5;
            $rate = $class['rate'] ?? '1mbit';
            $ceil = $class['ceil'] ?? $rate;

            // Add class
            $cmd = "{$this->tc} class add dev " . escapeshellarg($interface);
            $cmd .= " parent 1:1 classid 1:{$classId} htb rate {$rate} ceil {$ceil} prio {$priority}";
            exec($cmd . ' 2>&1');

            // Add filter for matching traffic
            if (isset($class['match'])) {
                $this->addTrafficFilter($interface, $classId, $class['match']);
            }

            $classId++;
        }

        return true;
    }

    /**
     * Add a traffic filter.
     *
     * @param string $interface Interface name
     * @param int $classId Target class ID
     * @param array $match Match criteria
     * @return bool True if successful
     */
    private function addTrafficFilter(string $interface, int $classId, array $match): bool
    {
        $cmd = "{$this->tc} filter add dev " . escapeshellarg($interface);
        $cmd .= " parent 1: protocol ip prio 1";

        // Use u32 filter for matching
        $cmd .= " u32";

        if (isset($match['port'])) {
            // Match destination port
            $port = (int)$match['port'];
            $cmd .= sprintf(" match ip dport %d 0xffff", $port);
        }

        if (isset($match['source'])) {
            // Match source IP
            $cmd .= " match ip src " . escapeshellarg($match['source']);
        }

        if (isset($match['destination'])) {
            // Match destination IP
            $cmd .= " match ip dst " . escapeshellarg($match['destination']);
        }

        if (isset($match['protocol'])) {
            // Match protocol
            $proto = $match['protocol'];
            $protoNum = $proto === 'tcp' ? 6 : ($proto === 'udp' ? 17 : 0);
            if ($protoNum > 0) {
                $cmd .= sprintf(" match ip protocol %d 0xff", $protoNum);
            }
        }

        $cmd .= " flowid 1:{$classId}";

        exec($cmd . ' 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Get QoS statistics for an interface.
     *
     * @param string $interface Interface name
     * @return array Statistics
     */
    public function getStats(string $interface): array
    {
        $output = [];
        exec("{$this->tc} -s class show dev " . escapeshellarg($interface) . ' 2>&1', $output);

        return [
            'interface' => $interface,
            'classes' => $output,
        ];
    }

    /**
     * Get qdisc information for all interfaces.
     *
     * @return array Qdisc info
     */
    public function listQdiscs(): array
    {
        $output = [];
        exec("{$this->tc} qdisc show 2>&1", $output);
        return $output;
    }

    /**
     * Apply simple rate limit using iptables MARK and tc.
     *
     * @param string $interface Interface name
     * @param string $source Source network
     * @param string $rate Rate limit
     * @return bool True if successful
     */
    public function limitSourceRate(string $interface, string $source, string $rate): bool
    {
        // Mark packets from source
        $mark = crc32($source) & 0xFF;

        $cmd = "{$this->iptables} -t mangle -A PREROUTING";
        $cmd .= " -s " . escapeshellarg($source);
        $cmd .= " -j MARK --set-mark {$mark}";
        exec($cmd . ' 2>&1');

        // Add tc filter for marked packets
        $filterCmd = "{$this->tc} filter add dev " . escapeshellarg($interface);
        $filterCmd .= " parent 1: protocol ip prio 1 handle {$mark} fw";
        $filterCmd .= " flowid 1:10";

        exec($filterCmd . ' 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }
}
