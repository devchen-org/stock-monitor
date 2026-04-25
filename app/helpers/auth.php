<?php

declare(strict_types=1);

function startAuthSession(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $lifetime = max(60, (int) ($config['auth_session_lifetime'] ?? 2592000));
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.cookie_lifetime', (string) $lifetime);

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function isAuthenticated(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    $expiresAt = (int) ($_SESSION['auth_expires_at'] ?? 0);
    if ($expiresAt <= time()) {
        logoutAuthSession();
        return false;
    }

    return !empty($_SESSION['authenticated']);
}

function loginWithPassword(array $config): void
{
    startAuthSession($config);
    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    $_SESSION['auth_logged_in_at'] = time();
    $_SESSION['auth_expires_at'] = time() + max(60, (int) ($config['auth_session_lifetime'] ?? 2592000));
}

function logoutAuthSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'] ?? '/', $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
    }
    session_destroy();
}

function authStatusPayload(AppSettingsRepository $appSettingsRepository): array
{
    $authSettings = $appSettingsRepository->getAuthSettings();
    $passwordConfigured = $authSettings['password_hash'] !== '';
    $loggedIn = $passwordConfigured ? isAuthenticated() : true;

    return [
        'logged_in' => $loggedIn,
        'password_configured' => $passwordConfigured,
        'must_change_password' => !$passwordConfigured || !empty($authSettings['force_password_change']),
    ];
}

function requireAuthenticatedSession(AppSettingsRepository $appSettingsRepository): void
{
    $authSettings = $appSettingsRepository->getAuthSettings();
    $passwordConfigured = $authSettings['password_hash'] !== '';
    if (!$passwordConfigured) {
        errorResponse('请先设置登录密码', 403, ['must_change_password' => true]);
    }

    if (!isAuthenticated()) {
        errorResponse('请先登录', 401, ['logged_in' => false]);
    }
}

function requireAuthenticatedRequest(AppSettingsRepository $appSettingsRepository, array $allowActions, string $action): void
{
    if (in_array($action, $allowActions, true)) {
        return;
    }

    $authSettings = $appSettingsRepository->getAuthSettings();
    $passwordConfigured = $authSettings['password_hash'] !== '';
    if (!$passwordConfigured) {
        errorResponse('请先设置登录密码', 403, ['must_change_password' => true]);
    }

    if (!isAuthenticated()) {
        errorResponse('请先登录', 401, ['logged_in' => false]);
    }

    if (!empty($authSettings['force_password_change'])) {
        errorResponse('请先修改密码', 403, ['must_change_password' => true]);
    }
}

function requirePasswordForVerification(AppSettingsRepository $appSettingsRepository, string $password): void
{
    $authSettings = $appSettingsRepository->getAuthSettings();
    $passwordHash = (string) ($authSettings['password_hash'] ?? '');

    if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
        throw new InvalidArgumentException('当前密码不正确');
    }
}
