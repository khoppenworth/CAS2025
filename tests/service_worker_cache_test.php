<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = file_get_contents($root . '/service-worker.js');
if ($source === false) {
    throw new RuntimeException('Unable to read service-worker.js');
}

if (!str_contains($source, "const CACHE_NAME = 'my-performance-cache-v7';")) {
    throw new RuntimeException('Service worker cache name must be bumped when profile/assets change.');
}

foreach (["withBase('profile.php')", "withBase('submit_assessment.php')", "withBase('my_performance.php')", "withBase('dashboard.php')"] as $dynamicShell) {
    if (str_contains($source, $dynamicShell)) {
        throw new RuntimeException('Authenticated PHP route must not be precached by the service worker: ' . $dynamicShell);
    }
}

foreach ([
    "const isDynamicPhp = requestURL.pathname.endsWith('.php');",
    "fetch(event.request, isDynamicPhp ? { cache: 'no-store' } : undefined)",
    "absoluteURL.pathname.endsWith('.php')",
] as $requiredSnippet) {
    if (!str_contains($source, $requiredSnippet)) {
        throw new RuntimeException('Service worker must bypass cache for dynamic PHP pages: ' . $requiredSnippet);
    }
}

echo "Service worker cache tests passed.\n";
