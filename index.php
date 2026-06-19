<?php
declare(strict_types=1);

/**
 * Malakia — single front controller.
 * Routes are matched on the path after BASE_PATH. An .htaccess rewrites every
 * request here; on hosts without rewrite, index.php/<route> also works.
 */

require __DIR__ . '/config.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ideas.php';

// --- Resolve the current route -------------------------------------------------
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$uri  = rawurldecode($uri);
if (BASE_PATH !== '' && str_starts_with($uri, BASE_PATH)) {
    $uri = substr($uri, strlen(BASE_PATH));
}
// Support index.php/<route> fallback
$uri = preg_replace('#^/index\.php#', '', $uri) ?? $uri;
$route  = '/' . trim($uri, '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- Tiny view renderer --------------------------------------------------------
function render(string $view, array $data = [], ?string $title = null): void
{
    extract($data, EXTR_SKIP);
    $pageTitle = $title;
    ob_start();
    require __DIR__ . "/views/{$view}.php";
    $content = ob_get_clean();
    require __DIR__ . '/views/layout.php';
}

function json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

// --- Dispatch ------------------------------------------------------------------
try {
    switch (true) {

        // ---- Landing / idea wall ----
        case $route === '/' && $method === 'GET':
            render('home', [
                'ideas'  => list_ideas([
                    'search'   => query('q'),
                    'category' => query('category'),
                    'sort'     => query('sort', 'new'),
                ]),
                'stats'  => platform_stats(),
                'q'        => query('q'),
                'category' => query('category'),
                'sort'     => query('sort', 'new'),
            ], 'Idea Wall');
            break;

        // ---- Register ----
        case $route === '/register' && $method === 'GET':
            if (is_logged_in()) redirect('/dashboard');
            render('register', ['errors' => [], 'old' => []], 'Create account');
            break;

        case $route === '/register' && $method === 'POST':
            verify_csrf();
            $old = [
                'name'     => post('name'),
                'username' => post('username'),
                'email'    => post('email'),
            ];
            [$ok, $errors] = attempt_register(
                post('name'), post('username'), post('email'),
                post('password'), post('confirm')
            );
            if ($ok) {
                flash('success', 'Welcome aboard. Your studio is ready.');
                redirect('/dashboard');
            }
            render('register', ['errors' => $errors, 'old' => $old], 'Create account');
            break;

        // ---- Login ----
        case $route === '/login' && $method === 'GET':
            if (is_logged_in()) redirect('/dashboard');
            render('login', ['error' => null, 'old' => []], 'Sign in');
            break;

        case $route === '/login' && $method === 'POST':
            verify_csrf();
            [$ok, $error] = attempt_login(post('email'), post('password'));
            if ($ok) {
                flash('success', 'Signed in. Good to see you.');
                redirect('/dashboard');
            }
            render('login', ['error' => $error, 'old' => ['email' => post('email')]], 'Sign in');
            break;

        // ---- Logout ----
        case $route === '/logout' && $method === 'POST':
            verify_csrf();
            logout_user();
            flash('success', 'Signed out.');
            redirect('/');
            break;

        // ---- Dashboard ----
        case $route === '/dashboard' && $method === 'GET':
            require_auth();
            $me = current_user();
            render('dashboard', [
                'me'     => $me,
                'stats'  => user_stats((int) $me['id']),
                'ideas'  => list_ideas(['user_id' => (int) $me['id'], 'sort' => 'new']),
            ], 'Dashboard');
            break;

        // ---- Capture a new idea ----
        case $route === '/ideas/new' && $method === 'GET':
            require_auth();
            render('submit', ['errors' => [], 'old' => [], 'mode' => 'create', 'idea' => null], 'Capture an idea');
            break;

        case $route === '/ideas' && $method === 'POST':
            require_auth();
            verify_csrf();
            $data = [
                'title'       => post('title'),
                'description' => post('description'),
                'category'    => post('category'),
                'tags'        => post('tags'),
                'status'      => post('status', 'spark'),
            ];
            [$id, $errors] = create_idea((int) current_user()['id'], $data);
            if ($id) {
                flash('success', 'Captured. Your idea is on the wall.');
                redirect('/ideas/' . $id);
            }
            render('submit', ['errors' => $errors, 'old' => $data, 'mode' => 'create', 'idea' => null], 'Capture an idea');
            break;

        // ---- Edit idea ----
        case preg_match('#^/ideas/(\d+)/edit$#', $route, $m) === 1 && $method === 'GET':
            require_auth();
            $idea = find_idea((int) $m[1]);
            if (!$idea || (int) $idea['user_id'] !== (int) current_user()['id']) {
                http_response_code(403);
                render('error', ['code' => 403, 'message' => 'You can only edit your own ideas.'], 'Not allowed');
                break;
            }
            render('submit', ['errors' => [], 'old' => $idea, 'mode' => 'edit', 'idea' => $idea], 'Edit idea');
            break;

        case preg_match('#^/ideas/(\d+)$#', $route, $m) === 1 && $method === 'POST' && post('_method') === 'PUT':
            require_auth();
            verify_csrf();
            $ideaId = (int) $m[1];
            $idea = find_idea($ideaId);
            if (!$idea || (int) $idea['user_id'] !== (int) current_user()['id']) {
                http_response_code(403);
                render('error', ['code' => 403, 'message' => 'You can only edit your own ideas.'], 'Not allowed');
                break;
            }
            $data = [
                'title'       => post('title'),
                'description' => post('description'),
                'category'    => post('category'),
                'tags'        => post('tags'),
                'status'      => post('status', 'spark'),
            ];
            $errors = update_idea($ideaId, (int) current_user()['id'], $data);
            if (!$errors) {
                flash('success', 'Idea updated.');
                redirect('/ideas/' . $ideaId);
            }
            $data['id'] = $ideaId;
            render('submit', ['errors' => $errors, 'old' => $data, 'mode' => 'edit', 'idea' => $idea], 'Edit idea');
            break;

        // ---- Delete idea ----
        case preg_match('#^/ideas/(\d+)$#', $route, $m) === 1 && $method === 'POST' && post('_method') === 'DELETE':
            require_auth();
            verify_csrf();
            delete_idea((int) $m[1], (int) current_user()['id']);
            flash('success', 'Idea deleted.');
            redirect('/dashboard');
            break;

        // ---- Vote toggle (AJAX) ----
        case preg_match('#^/ideas/(\d+)/vote$#', $route, $m) === 1 && $method === 'POST':
            if (!is_logged_in()) {
                json(['error' => 'auth', 'message' => 'Sign in to charge ideas.'], 401);
            }
            verify_csrf();
            $result = toggle_vote((int) $m[1], (int) current_user()['id']);
            json($result);
            break;

        // ---- Comment ----
        case preg_match('#^/ideas/(\d+)/comments$#', $route, $m) === 1 && $method === 'POST':
            require_auth();
            verify_csrf();
            $ideaId = (int) $m[1];
            if (!find_idea($ideaId)) {
                http_response_code(404);
                render('error', ['code' => 404, 'message' => 'That idea has vanished.'], 'Not found');
                break;
            }
            if (!add_comment($ideaId, (int) current_user()['id'], post('body'))) {
                flash('error', 'Write something before posting.');
            }
            redirect('/ideas/' . $ideaId . '#comments');
            break;

        // ---- Idea detail ----
        case preg_match('#^/ideas/(\d+)$#', $route, $m) === 1 && $method === 'GET':
            $idea = find_idea((int) $m[1]);
            if (!$idea) {
                http_response_code(404);
                render('error', ['code' => 404, 'message' => 'That idea has vanished.'], 'Not found');
                break;
            }
            render('idea', [
                'idea'     => $idea,
                'comments' => list_comments((int) $m[1]),
            ], $idea['title']);
            break;

        // ---- 404 ----
        default:
            http_response_code(404);
            render('error', ['code' => 404, 'message' => 'We could not find that page.'], 'Not found');
    }
} catch (Throwable $ex) {
    http_response_code(500);
    // Avoid leaking internals in production; log instead.
    error_log('[Malakia] ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    if (PHP_SAPI === 'cli-server') {
        // Surface during local dev for easier debugging.
        echo '<pre style="color:#fff;background:#111;padding:20px">' . e($ex->getMessage()) . "\n" . e($ex->getTraceAsString()) . '</pre>';
    } else {
        render('error', ['code' => 500, 'message' => 'Something went wrong on our side.'], 'Error');
    }
}
