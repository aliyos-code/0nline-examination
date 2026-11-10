<?php
/**
 * Friendly error page, wired up through Apache's ErrorDocument directive.
 *
 * Deliberately standalone (no database or session dependency) so it renders
 * even when the application itself is failing.
 */
$code = isset($_SERVER['REDIRECT_STATUS']) ? (int) $_SERVER['REDIRECT_STATUS'] : 404;

$messages = [
    400 => ['Bad Request', 'The request could not be understood by the server.'],
    403 => ['Access Denied', 'You do not have permission to access this resource.'],
    404 => ['Page Not Found', 'The page you are looking for could not be found.'],
    419 => ['Session Expired', 'Your session has expired. Please sign in again.'],
    500 => ['Server Error', 'Something went wrong on our side. Please try again shortly.'],
    503 => ['Service Unavailable', 'The service is temporarily unavailable. Please try again later.'],
];
if (!isset($messages[$code])) {
    $code = 500;
}
list($title, $detail) = $messages[$code];

// Best-effort application base directory for the "return home" link.
$base = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '')), '/');

http_response_code($code);
$e = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $code; ?> &middot; <?php echo $e($title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 text-center">
            <div class="display-1 fw-bold text-muted"><?php echo $code; ?></div>
            <h1 class="h3 mb-2"><?php echo $e($title); ?></h1>
            <p class="text-muted"><?php echo $e($detail); ?></p>
            <a href="<?php echo $e($base); ?>/index.php" class="btn btn-primary">Return home</a>
        </div>
    </div>
</main>
</body>
</html>
