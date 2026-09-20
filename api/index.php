<?php

// Vercel entry point (community runtime `vercel-php`, see vercel.json and docs/RUNBOOK.md): every request that is not
// a static asset is routed here and handed to Laravel's normal front controller.
//
// The function file lives under /api, so PHP reports SCRIPT_NAME=/api/index.php. Laravel would take "/api" for the
// folder the application is installed in and strip it from every URL — `/api/auth/login` would arrive as
// `/auth/login`. Present the script as the ordinary public/index.php at the web root instead.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/../public/index.php';

require __DIR__.'/../public/index.php';
