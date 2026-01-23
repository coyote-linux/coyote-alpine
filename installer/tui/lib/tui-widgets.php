<?php
/**
 * TUI Widgets Library
 *
 * Simple text-based UI widgets for console applications.
 */

class TuiWidgets
{
    private int $width = 60;
    private int $height = 20;

    public function __construct()
    {
        // Try to detect terminal size
        $cols = (int)exec('tput cols 2>/dev/null');
        $rows = (int)exec('tput lines 2>/dev/null');

        if ($cols > 0) {
            $this->width = min($cols - 4, 80);
        }
        if ($rows > 0) {
            $this->height = min($rows - 4, 24);
        }
    }

    /**
     * Clear the screen
     */
    public function clear(): void
    {
        echo "\033[2J\033[H";
    }

    /**
     * Show a box with title and content
     */
    public function showBox(string $title, array $lines): void
    {
        $this->clear();

        $border = str_repeat('=', $this->width);
        echo "{$border}\n";
        echo $this->centerText($title) . "\n";
        echo "{$border}\n";
        echo "\n";

        foreach ($lines as $line) {
            echo "  {$line}\n";
        }

        echo "\n";
    }

    /**
     * Show a menu and return selected option
     */
    public function showMenu(string $title, array $options): ?string
    {
        $this->showBox($title, ['Use arrow keys to navigate, Enter to select:']);

        $keys = array_keys($options);
        $selected = 0;

        while (true) {
            // Move cursor up to redraw menu
            echo "\033[" . (count($options) + 1) . "A";

            foreach ($options as $key => $label) {
                $index = array_search($key, $keys);
                if ($index === $selected) {
                    echo "  \033[7m> {$label}\033[0m\n";
                } else {
                    echo "    {$label}\n";
                }
            }

            // Read key
            system('stty -icanon -echo');
            $char = fread(STDIN, 1);
            system('stty icanon echo');

            if ($char === "\n" || $char === "\r") {
                return $keys[$selected];
            } elseif ($char === "\033") {
                // Arrow key sequence
                fread(STDIN, 1); // [
                $arrow = fread(STDIN, 1);
                if ($arrow === 'A' && $selected > 0) {
                    $selected--;
                } elseif ($arrow === 'B' && $selected < count($keys) - 1) {
                    $selected++;
                }
            } elseif ($char === 'q') {
                return null;
            }
        }
    }

    /**
     * Show an input prompt
     */
    public function showInput(string $title, string $prompt, string $default = ''): string
    {
        $this->showBox($title, [$prompt]);

        if ($default !== '') {
            echo "  [{$default}]: ";
        } else {
            echo "  > ";
        }

        $input = trim(fgets(STDIN));

        return $input !== '' ? $input : $default;
    }

    /**
     * Show a confirmation dialog
     */
    public function showConfirm(string $title, array $lines): bool
    {
        $lines[] = '';
        $lines[] = '[Y]es / [N]o';

        $this->showBox($title, $lines);

        while (true) {
            system('stty -icanon -echo');
            $char = strtolower(fread(STDIN, 1));
            system('stty icanon echo');

            if ($char === 'y') {
                return true;
            } elseif ($char === 'n') {
                return false;
            }
        }
    }

    /**
     * Show a message and wait for key press
     */
    public function showMessage(string $message): void
    {
        echo "\n  {$message}\n\n";
        echo "  Press any key to continue...";
        $this->waitForKey();
    }

    /**
     * Show progress indicator
     */
    public function showProgress(string $title, array $steps): void
    {
        $this->showBox($title, []);

        foreach ($steps as $i => $step) {
            echo "  [{$i}/" . count($steps) . "] {$step}\n";
            usleep(500000); // Simulate progress
        }

        echo "\n  Done!\n";
    }

    /**
     * Wait for a key press
     */
    public function waitForKey(): void
    {
        system('stty -icanon -echo');
        fread(STDIN, 1);
        system('stty icanon echo');
    }

    /**
     * Center text within the width
     */
    private function centerText(string $text): string
    {
        $padding = (int)(($this->width - strlen($text)) / 2);
        return str_repeat(' ', $padding) . $text;
    }
}
