#!/usr/bin/env php
<?php
/**
 * Coyote Linux Installer TUI Menu
 *
 * Text-based user interface for the installation process.
 */

require_once __DIR__ . '/lib/tui-widgets.php';

/**
 * Main installer TUI class
 */
class InstallerMenu
{
    private TuiWidgets $tui;

    public function __construct()
    {
        $this->tui = new TuiWidgets();
    }

    /**
     * Run the installer TUI
     */
    public function run(): void
    {
        $this->tui->clear();
        $this->showWelcome();

        // Main installation flow
        $disk = $this->selectDisk();
        if (!$disk) {
            $this->tui->showMessage('Installation cancelled.');
            return;
        }

        $network = $this->configureNetwork();

        if ($this->confirmInstall($disk)) {
            $this->performInstall($disk, $network);
        }
    }

    /**
     * Show welcome screen
     */
    private function showWelcome(): void
    {
        $this->tui->showBox('Coyote Linux 4 Installer', [
            '',
            'Welcome to the Coyote Linux installer.',
            '',
            'This will guide you through the installation process.',
            '',
            'Press any key to continue...',
        ]);

        $this->tui->waitForKey();
    }

    /**
     * Select target disk
     */
    private function selectDisk(): ?string
    {
        $disks = $this->detectDisks();

        if (empty($disks)) {
            $this->tui->showMessage('No suitable disks found for installation.');
            return null;
        }

        $options = [];
        foreach ($disks as $disk => $info) {
            $options[$disk] = "{$disk} - {$info['size']}GB {$info['model']}";
        }

        return $this->tui->showMenu('Select Installation Disk', $options);
    }

    /**
     * Detect available disks
     */
    private function detectDisks(): array
    {
        $disks = [];

        foreach (glob('/sys/block/sd*') + glob('/sys/block/nvme*') + glob('/sys/block/vd*') as $path) {
            $name = basename($path);

            // Skip if it's the installer media
            // (detection logic would go here)

            $size = (int)file_get_contents("{$path}/size") * 512 / 1024 / 1024 / 1024;
            $model = '';
            if (file_exists("{$path}/device/model")) {
                $model = trim(file_get_contents("{$path}/device/model"));
            }

            $disks["/dev/{$name}"] = [
                'size' => round($size, 1),
                'model' => $model,
            ];
        }

        return $disks;
    }

    /**
     * Configure network during installation
     */
    private function configureNetwork(): array
    {
        $config = [
            'hostname' => 'coyote',
            'interfaces' => [],
        ];

        // Get hostname
        $config['hostname'] = $this->tui->showInput(
            'System Hostname',
            'Enter hostname for this system:',
            'coyote'
        );

        // Detect network interfaces
        $interfaces = $this->detectNetworkInterfaces();

        // Configure each interface
        foreach ($interfaces as $iface => $info) {
            $method = $this->tui->showMenu(
                "Configure {$iface}",
                [
                    'dhcp' => 'DHCP (automatic)',
                    'static' => 'Static IP',
                    'skip' => 'Skip (do not configure)',
                ]
            );

            if ($method === 'skip') {
                continue;
            }

            $config['interfaces'][$iface] = ['method' => $method];

            if ($method === 'static') {
                $config['interfaces'][$iface]['address'] = $this->tui->showInput(
                    'IP Address',
                    "Enter IP address for {$iface}:",
                    ''
                );
                $config['interfaces'][$iface]['netmask'] = $this->tui->showInput(
                    'Netmask',
                    'Enter netmask:',
                    '255.255.255.0'
                );
                $config['interfaces'][$iface]['gateway'] = $this->tui->showInput(
                    'Gateway',
                    'Enter default gateway:',
                    ''
                );
            }
        }

        return $config;
    }

    /**
     * Detect network interfaces
     */
    private function detectNetworkInterfaces(): array
    {
        $interfaces = [];

        foreach (glob('/sys/class/net/*') as $path) {
            $name = basename($path);
            if ($name === 'lo') {
                continue;
            }

            $mac = '';
            if (file_exists("{$path}/address")) {
                $mac = trim(file_get_contents("{$path}/address"));
            }

            $interfaces[$name] = ['mac' => $mac];
        }

        return $interfaces;
    }

    /**
     * Confirm installation
     */
    private function confirmInstall(string $disk): bool
    {
        return $this->tui->showConfirm(
            'Confirm Installation',
            [
                "Target disk: {$disk}",
                '',
                'WARNING: All data on this disk will be destroyed!',
                '',
                'Do you want to proceed with the installation?',
            ]
        );
    }

    /**
     * Perform installation
     */
    private function performInstall(string $disk, array $network): void
    {
        $this->tui->showProgress('Installing Coyote Linux', [
            'Partitioning disk...',
            'Formatting partitions...',
            'Copying firmware...',
            'Installing bootloader...',
            'Saving configuration...',
        ]);

        // Actual installation would be performed here
        // For now, call the shell installer
        passthru("/install.sh --disk={$disk} --batch");

        $this->tui->showMessage('Installation complete! Remove installer media and reboot.');
    }
}

// Run installer
$installer = new InstallerMenu();
$installer->run();
