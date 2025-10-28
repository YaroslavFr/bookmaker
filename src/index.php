<?php
// Fallback bootstrap if mod_rewrite or .htaccess is restricted on hosting.
// This forwards all requests to Laravel's front controller in /public.
require __DIR__ . '/public/index.php';