<?php
/**
 * ConnectMeet - shared response helpers.
 * Emits the standard JSON envelope and exits with the appropriate HTTP status.
 */

declare(strict_types=1);

function cm_send(int $httpStatus, bool $success, array $data = [], string $message = ''): void
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $payload = ['success' => $success, 'data' => $data];
    if ($message !== '') {
        $payload['message'] = $message;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function cm_ok(array $data = [], string $message = ''): void
{
    cm_send(200, true, $data, $message);
}

function cm_created(array $data = [], string $message = ''): void
{
    cm_send(201, true, $data, $message);
}

function cm_error(int $httpStatus, string $message, array $extra = []): void
{
    cm_send($httpStatus, false, $extra, $message);
}

/** 400 validation error. */
function cm_bad(string $message): void
{
    cm_error(400, $message);
}

/** 401 unauthorized. */
function cm_unauth(string $message = 'Authentication required'): void
{
    cm_error(401, $message);
}

/** 403 forbidden. */
function cm_forbidden(string $message = 'You are not authorized to perform this action'): void
{
    cm_error(403, $message);
}

/** 404 not found. */
function cm_notfound(string $message = 'Resource not found'): void
{
    cm_error(404, $message);
}

/** 409 conflict. */
function cm_conflict(string $message = 'Conflict'): void
{
    cm_error(409, $message);
}

/** 429 too many requests. */
function cm_throttle(string $message = 'Too many requests, slow down'): void
{
    cm_error(429, $message);
}

/** 500 server error (safe, generic message). */
function cm_server(string $message = 'Internal server error'): void
{
    cm_error(500, $message);
}
