<?php
function processWithForking(array $items, callable $callback, int $maxProcesses = 5) {
    $children = [];

    foreach ($items as $item) {
        if (count($children) >= $maxProcesses) {
            $pid = pcntl_wait($status); // Wait for a child to finish
            unset($children[$pid]); // Remove the finished child from tracking
        }

        $pid = pcntl_fork();
        if ($pid == -1) {
            die("Fork failed");
        } elseif ($pid) {
            // Parent process
            $children[$pid] = true;
        } else {
            // Child process
            $callback($item);
            exit(0); // Exit the child process
        }
    }

    // Wait for remaining children
    while (!empty($children)) {
        $pid = pcntl_wait($status);
        unset($children[$pid]);
    }
}

// Example processing function
$processItem = function ($item) {
    echo "Processing: $item (PID: " . getmypid() . ")\n";
    sleep(rand(1, 3)); // Simulate work
};

// Example usage
$items = range(1, 20);
processWithForking($items, $processItem);
