#!/usr/bin/env php
<?php
/**
 * Coyote Linux Console TUI - Main Entry Point
 *
 * Text-based user interface for system configuration.
 */

require_once '/opt/coyote/lib/autoload.php';

// Define TUI root path
define('TUI_ROOT', __DIR__);

// Include menu definitions
require_once TUI_ROOT . '/menus/main.php';

/**
 * Clear the screen.
 */
function clearScreen(): void
{
    echo "\033[2J\033[H";
}

/**
 * Display a header.
 */
function showHeader(): void
{
    echo "\033[1;36m";
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║                    Coyote Linux 4.0.0                      ║\n";
    echo "║                   Console Configuration                    ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo "\033[0m\n";
}

/**
 * Display a menu and get user selection.
 *
 * @param array $items Menu items
 * @param string $title Menu title
 * @return string|null Selected key or null to go back
 */
function showMenu(array $items, string $title = ''): ?string
{
    clearScreen();
    showHeader();

    if ($title) {
        echo "\033[1;33m{$title}\033[0m\n\n";
    }

    $keys = array_keys($items);
    foreach ($keys as $i => $key) {
        $item = $items[$key];
        $num = $i + 1;
        echo "  \033[1;32m{$num}.\033[0m {$item['label']}\n";
    }

    echo "\n  \033[1;32m0.\033[0m Back/Exit\n";
    echo "\n\033[1mSelect option:\033[0m ";

    $input = trim(fgets(STDIN));

    if ($input === '0' || $input === '') {
        return null;
    }

    $index = (int)$input - 1;
    if ($index >= 0 && $index < count($keys)) {
        return $keys[$index];
    }

    return '';
}

/**
 * Wait for user to press Enter.
 */
function waitForEnter(): void
{
    echo "\n\033[2mPress Enter to continue...\033[0m";
    fgets(STDIN);
}

/**
 * Display an info message.
 */
function showInfo(string $message): void
{
    echo "\033[1;34m[INFO]\033[0m {$message}\n";
}

/**
 * Display a success message.
 */
function showSuccess(string $message): void
{
    echo "\033[1;32m[OK]\033[0m {$message}\n";
}

/**
 * Display an error message.
 */
function showError(string $message): void
{
    echo "\033[1;31m[ERROR]\033[0m {$message}\n";
}

/**
 * Prompt for user input.
 */
function prompt(string $label, string $default = ''): string
{
    $defaultText = $default ? " [{$default}]" : '';
    echo "{$label}{$defaultText}: ";
    $input = trim(fgets(STDIN));
    return $input !== '' ? $input : $default;
}

/**
 * Confirm an action.
 */
function confirm(string $message): bool
{
    echo "{$message} (y/N): ";
    $input = strtolower(trim(fgets(STDIN)));
    return $input === 'y' || $input === 'yes';
}

// Run main menu
mainMenu();
