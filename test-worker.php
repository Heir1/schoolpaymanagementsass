<?php
ini_set('display_errors', 'stderr'); // Critical: Redirects all output to STDERR[citation:6]
require __DIR__ . '/vendor/autoload.php';
echo "Worker boot successful"; // This output is now safe
