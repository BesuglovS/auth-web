<?php
class Router
{
    private string $page;
    private string $action;

    public function __construct()
    {
        $this->page = $_GET['page'] ?? 'home';
        $this->action = $_GET['action'] ?? 'list';
    }

    public function dispatch(): void
    {
        if ($this->page === 'login') {
            $this->renderLoginPage();
            return;
        }

        if ($this->page === 'logout') {
            Auth::logout();
            header('Location: ' . BASE_URL . '/index.php?page=login');
            exit;
        }

        Auth::requireLogin();

        if (str_starts_with($this->page, 'admin')) {
            Auth::requireAdmin();
            $this->dispatchAdmin();
            return;
        }

        $this->renderHomePage();
    }

    private function dispatchAdmin(): void
    {
        ob_start();
        match ($this->page) {
            'admin' => require BASE_PATH . '/admin/index.php',
            'admin-users' => require BASE_PATH . '/admin/users.php',
            'admin-change-password' => require BASE_PATH . '/admin/change_password.php',
            default => $this->render404(),
        };
        $content = ob_get_clean();
        if ($content !== '') {
            require BASE_PATH . '/templates/layout.php';
        }
    }

    private function renderLoginPage(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['redirect'])) {
            $_SESSION['redirect_after_login'] = $_GET['redirect'];
        }

        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!validateCsrf()) {
                $error = 'Недействительный CSRF-токен. Обновите страницу.';
                $pageTitle = 'Вход';
                ob_start();
                require BASE_PATH . '/templates/login.php';
                $content = ob_get_clean();
                require BASE_PATH . '/templates/layout.php';
                return;
            }
            $login = trim($_POST['login'] ?? '');
            $password = $_POST['password'] ?? '';
            $result = Auth::login($login, $password);
            if ($result['success']) {
                $redirect = $_SESSION['redirect_after_login'] ?? (BASE_URL . '/index.php');
                unset($_SESSION['redirect_after_login']);
                header('Location: ' . $redirect);
                exit;
            }
            $error = $result['error'];
        }
        $pageTitle = 'Вход';
        ob_start();
        require BASE_PATH . '/templates/login.php';
        $content = ob_get_clean();
        require BASE_PATH . '/templates/layout.php';
    }

    private function renderHomePage(): void
    {
        $pageTitle = 'Главная';
        ob_start();
        require BASE_PATH . '/templates/home.php';
        $content = ob_get_clean();
        require BASE_PATH . '/templates/layout.php';
    }

    private function render404(): void
    {
        http_response_code(404);
        echo '<!DOCTYPE html><html><head><title>404</title></head><body><h1>Страница не найдена</h1><p><a href="' . BASE_URL . '/index.php">На главную</a></p></body></html>';
    }
}
