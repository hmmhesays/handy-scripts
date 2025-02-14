<?php
function processWithForking(array $items, callable $callback, int $maxProcesses = 5) {
    $children = [];
    
    foreach ($items as $item) {
        if (count($children) >= $maxProcesses) {
            $pid = pcntl_wait($status);
            unset($children[$pid]); // Remove the finished child from tracking
        }

        // Create a pipe
        $pipes = [];
        if (!pipe($pipes)) {
            die("Failed to create pipe");
        }
        
        $pid = pcntl_fork();
        if ($pid == -1) {
            die("Fork failed");
        } elseif ($pid) {
            // Parent process: store child's pipe
            fclose($pipes[1]); // Close write end
            $children[$pid] = $pipes[0]; // Store read end
        } else {
            // Child process: capture output and send to parent
            fclose($pipes[0]); // Close read end
            ob_start(); // Start output buffering
            $callback($item);
            $output = ob_get_clean(); // Get buffered output
            fwrite($pipes[1], $output);
            fclose($pipes[1]); // Close write end
            exit(0);
        }
    }

    // Read output from child processes
    foreach ($children as $pid => $pipe) {
        $output = stream_get_contents($pipe);
        fclose($pipe);
        unset($children[$pid]);
        echo "Child $pid output:\n$output\n";
    }
}

// Example processing function
$processItem = function ($item) {
    echo "Processing: $item (PID: " . getmypid() . ")\n";
    sleep(rand(1, 3)); // Simulate work
};

// Example usage
$items = range(1, 10);
processWithForking($items, $processItem);
