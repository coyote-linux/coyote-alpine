<?php

namespace Coyote\System;

/**
 * Hardware detection and information.
 */
class Hardware
{
    /**
     * Detect all network interfaces in the system.
     *
     * @return array List of interface information
     */
    public function detectNetworkInterfaces(): array
    {
        $interfaces = [];
        $sysNetDir = '/sys/class/net';

        if (!is_dir($sysNetDir)) {
            return $interfaces;
        }

        foreach (scandir($sysNetDir) as $iface) {
            if ($iface === '.' || $iface === '..' || $iface === 'lo') {
                continue;
            }

            $interfaces[$iface] = [
                'name' => $iface,
                'mac' => $this->getMacAddress($iface),
                'driver' => $this->getDriver($iface),
                'speed' => $this->getLinkSpeed($iface),
                'state' => $this->getLinkState($iface),
            ];
        }

        return $interfaces;
    }

    /**
     * Get the MAC address of an interface.
     *
     * @param string $interface Interface name
     * @return string|null MAC address or null
     */
    public function getMacAddress(string $interface): ?string
    {
        $path = "/sys/class/net/{$interface}/address";
        if (file_exists($path)) {
            return trim(file_get_contents($path));
        }
        return null;
    }

    /**
     * Get the driver for a network interface.
     *
     * @param string $interface Interface name
     * @return string|null Driver name or null
     */
    public function getDriver(string $interface): ?string
    {
        $path = "/sys/class/net/{$interface}/device/driver";
        if (is_link($path)) {
            return basename(readlink($path));
        }
        return null;
    }

    /**
     * Get the link speed of an interface.
     *
     * @param string $interface Interface name
     * @return int|null Speed in Mbps or null
     */
    public function getLinkSpeed(string $interface): ?int
    {
        $path = "/sys/class/net/{$interface}/speed";
        if (file_exists($path)) {
            $speed = @file_get_contents($path);
            if ($speed !== false && is_numeric(trim($speed))) {
                return (int)trim($speed);
            }
        }
        return null;
    }

    /**
     * Get the link state of an interface.
     *
     * @param string $interface Interface name
     * @return string Link state (up/down/unknown)
     */
    public function getLinkState(string $interface): string
    {
        $path = "/sys/class/net/{$interface}/operstate";
        if (file_exists($path)) {
            return trim(file_get_contents($path));
        }
        return 'unknown';
    }

    /**
     * Detect storage devices.
     *
     * @return array List of storage device information
     */
    public function detectStorageDevices(): array
    {
        $devices = [];
        $blockDir = '/sys/block';

        if (!is_dir($blockDir)) {
            return $devices;
        }

        foreach (scandir($blockDir) as $dev) {
            if ($dev === '.' || $dev === '..' || str_starts_with($dev, 'loop')) {
                continue;
            }

            // Skip partitions
            if (preg_match('/^(sd[a-z]|nvme\d+n\d+|vd[a-z])$/', $dev)) {
                $devices[$dev] = [
                    'name' => $dev,
                    'path' => "/dev/{$dev}",
                    'size' => $this->getDeviceSize($dev),
                    'model' => $this->getDeviceModel($dev),
                    'removable' => $this->isRemovable($dev),
                ];
            }
        }

        return $devices;
    }

    /**
     * Get device size in bytes.
     *
     * @param string $device Device name
     * @return int|null Size in bytes or null
     */
    private function getDeviceSize(string $device): ?int
    {
        $path = "/sys/block/{$device}/size";
        if (file_exists($path)) {
            $sectors = (int)trim(file_get_contents($path));
            return $sectors * 512; // Sector size is typically 512 bytes
        }
        return null;
    }

    /**
     * Get device model name.
     *
     * @param string $device Device name
     * @return string|null Model name or null
     */
    private function getDeviceModel(string $device): ?string
    {
        $path = "/sys/block/{$device}/device/model";
        if (file_exists($path)) {
            return trim(file_get_contents($path));
        }
        return null;
    }

    /**
     * Check if device is removable.
     *
     * @param string $device Device name
     * @return bool True if removable
     */
    private function isRemovable(string $device): bool
    {
        $path = "/sys/block/{$device}/removable";
        if (file_exists($path)) {
            return trim(file_get_contents($path)) === '1';
        }
        return false;
    }

    /**
     * Get system memory information.
     *
     * @return array Memory information
     */
    public function getMemoryInfo(): array
    {
        $info = [
            'total' => 0,
            'free' => 0,
            'available' => 0,
        ];

        if (file_exists('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $info['total'] = (int)$m[1] * 1024;
            }
            if (preg_match('/MemFree:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $info['free'] = (int)$m[1] * 1024;
            }
            if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $info['available'] = (int)$m[1] * 1024;
            }
        }

        return $info;
    }

    /**
     * Get CPU information.
     *
     * @return array CPU information
     */
    public function getCpuInfo(): array
    {
        $info = [
            'model' => 'Unknown',
            'cores' => 1,
        ];

        if (file_exists('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            if (preg_match('/model name\s+:\s+(.+)/', $cpuinfo, $m)) {
                $info['model'] = trim($m[1]);
            }
            $info['cores'] = substr_count($cpuinfo, 'processor');
        }

        return $info;
    }

    /**
     * Get system load average.
     *
     * @return array Load average (1, 5, 15 minute averages)
     */
    public function getLoadAverage(): array
    {
        $load = [
            '1min' => 0.0,
            '5min' => 0.0,
            '15min' => 0.0,
        ];

        if (file_exists('/proc/loadavg')) {
            $loadavg = file_get_contents('/proc/loadavg');
            $parts = explode(' ', $loadavg);
            if (count($parts) >= 3) {
                $load['1min'] = (float)$parts[0];
                $load['5min'] = (float)$parts[1];
                $load['15min'] = (float)$parts[2];
            }
        }

        return $load;
    }
}
