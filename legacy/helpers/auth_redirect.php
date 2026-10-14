<?php
// Redirect user to dashboard based on role.
// Guarded: includes/auth.php also defines require_login(), and both files can
// be loaded on the same request (e.g. includes/verify-process.php).

if (!function_exists('redirect_by_role')) {
    function redirect_by_role(string $role): void
    {
        switch ($role) {
            case 'Member':
                header("Location: ../member-dashboard");
                break;

            case 'Applicant':
                header("Location: ../applicant-dashboard");
                break;

            case 'job_seeker':
                header("Location: ../job-seeker-dashboard");
                break;

            default:
                header("Location: ../dashboard");
                break;
        }
        exit();
    }
}

// Redirect logged-in users automatically
if (!function_exists('redirect_if_logged_in')) {
    function redirect_if_logged_in(): void
    {
        if (isset($_SESSION['user_id'], $_SESSION['role'])) {
            redirect_by_role($_SESSION['role']);
        }
    }
}

//Require login before accessing a page
 
if (!function_exists('require_login')) {
    function require_login(): void
    {
        if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
            $_SESSION['login_error'] = 'Please login to continue.';
            header("Location: ../login");
            exit();
        }
    }
}
