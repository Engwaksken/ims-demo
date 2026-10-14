<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/*
|--------------------------------------------------------------------------
| ALL IMS ROLES
|--------------------------------------------------------------------------
*/

const IMS_ALL_ROLES = [
    'Administrator',
    'Programs Lead',
    'MEAL Lead',
    'Operations/Admin',
    'Project Officer',
    'Donor/Partner',
    'Staff',
    'Member',
    'Applicant',
    'Reviewer',
    'Accountant',
    'Finance',
    'Program Director',
    'Consultant',
    'Executive Director',
    'IT Officer',
    'HR',
    'Program Manager',
    'Procurement Officer',
    'Program Officer',
];


/*
|--------------------------------------------------------------------------
| ROLE GROUPS
|--------------------------------------------------------------------------
*/

const IMS_ADMIN_ROLES = [
    'Administrator',
    'Executive Director',
    'Operations/Admin',
    'IT Officer',
];


const IMS_PROGRAMME_ROLES = [
    'Administrator',
    'Programs Lead',
    'Program Director',
    'Program Manager',
    'Program Officer',
    'Project Officer',
    'MEAL Lead',
    'Operations/Admin',
    'Staff',
    'Consultant',
];


const IMS_FINANCE_ROLES = [
    'Administrator',
    'Finance',
    'Accountant',
    'Procurement Officer',
];


const IMS_REVIEW_ROLES = [
    'Administrator',
    'Programs Lead',
    'Program Director',
    'Program Manager',
    'Program Officer',
    'MEAL Lead',
    'Reviewer',
    'Consultant',
];


const IMS_STAFF_ROLES = [
    'Administrator',
    'Programs Lead',
    'MEAL Lead',
    'Operations/Admin',
    'Project Officer',
    'Staff',
    'Reviewer',
    'Accountant',
    'Finance',
    'Program Director',
    'Consultant',
    'Executive Director',
    'IT Officer',
    'HR',
    'Program Manager',
    'Procurement Officer',
    'Program Officer',
];


const IMS_EXTERNAL_ROLES = [
    'Donor/Partner',
    'Member',
    'Applicant',
];


/*
|--------------------------------------------------------------------------
| AUTH HELPERS
|--------------------------------------------------------------------------
*/

if (!function_exists('auth_is_logged_in')) {
    function auth_is_logged_in(): bool
    {
        return !empty($_SESSION['user_id']);
    }
}


if (!function_exists('auth_user_id')) {
    function auth_user_id(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }
}


if (!function_exists('auth_user_role')) {
    function auth_user_role(): string
    {
        return trim(
            (string)($_SESSION['role'] ?? '')
        );
    }
}


if (!function_exists('auth_normalize_role')) {
    function auth_normalize_role(string $role): string
    {
        $role = trim($role);

        $role = preg_replace(
            '/\s+/u',
            ' ',
            $role
        ) ?? $role;

        return mb_strtolower(
            $role,
            'UTF-8'
        );
    }
}


if (!function_exists('auth_has_role')) {
    function auth_has_role(
        string|array $roles
    ): bool {
        if (!auth_is_logged_in()) {
            return false;
        }

        $currentRole = auth_normalize_role(
            auth_user_role()
        );

        if ($currentRole === '') {
            return false;
        }

        $allowedRoles = is_array($roles)
            ? $roles
            : [$roles];

        foreach ($allowedRoles as $allowedRole) {
            if (!is_string($allowedRole)) {
                continue;
            }

            if (
                $currentRole
                ===
                auth_normalize_role(
                    $allowedRole
                )
            ) {
                return true;
            }
        }

        return false;
    }
}


if (!function_exists('auth_is_admin')) {
    function auth_is_admin(): bool
    {
        return auth_has_role(
            'Administrator'
        );
    }
}


/*
|--------------------------------------------------------------------------
| LOGIN CHECK
|--------------------------------------------------------------------------
*/

if (!function_exists('require_login')) {
    function require_login(
        string $loginUrl = 'login'
    ): void {
        if (auth_is_logged_in()) {
            return;
        }

        $requestUri = trim(
            (string)(
                $_SERVER['REQUEST_URI']
                ?? ''
            )
        );

        if ($requestUri !== '') {
            $_SESSION[
                'redirect_after_login'
            ] = $requestUri;
        }

        header(
            'Location: '
            . $loginUrl
        );

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| ROLE CHECK
|--------------------------------------------------------------------------
*/

if (!function_exists('check_role')) {
    function check_role(
        string|array $allowedRoles,
        string $loginUrl = 'login'
    ): void {
        require_login(
            $loginUrl
        );

        // Database permissions decide (see includes/permissions.php); the
        // roles listed here are only the defaults. null = system unavailable.
        require_once __DIR__ . '/permissions.php';
        $caller = ims_perm_caller_file(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3));
        $allowed = ims_perm_check(
            is_array($allowedRoles) ? $allowedRoles : [$allowedRoles],
            (string)$caller
        );

        if (
            $allowed === true
            ||
            (
                $allowed === null
                &&
                auth_has_role(
                    $allowedRoles
                )
            )
        ) {
            return;
        }

        http_response_code(403);

        $currentRole = auth_user_role();

        echo '<!doctype html>';
        echo '<html lang="en">';
        echo '<head>';
        echo '<meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Access Denied</title>';

        echo '
        <style>
            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;

                min-height: 100vh;

                display: grid;
                place-items: center;

                padding: 24px;

                background: #f8fafc;
                color: #0f172a;

                font-family: Poppins, Arial, sans-serif;
            }

            .auth-denied {
                width: min(100%, 520px);

                padding: 28px;

                border: 1px solid #e2e8f0;
                border-left: 5px solid #ef4444;
                border-radius: 14px;

                background: #ffffff;

                box-shadow:
                    0 16px 38px
                    rgba(15, 23, 42, .08);
            }

            .auth-denied h1 {
                margin: 0 0 8px;

                font-size: 22px;
            }

            .auth-denied p {
                margin: 0;

                color: #64748b;

                font-size: 13px;
                line-height: 1.6;
            }
        </style>
        ';

        echo '</head>';
        echo '<body>';

        echo '<div class="auth-denied">';

        echo '<h1>Access denied</h1>';

        echo '<p>Your account role <strong>';

        echo htmlspecialchars(
            $currentRole !== ''
                ? $currentRole
                : 'Unknown',
            ENT_QUOTES,
            'UTF-8'
        );

        echo '</strong> does not have permission to access this page.</p>';

        echo '</div>';

        echo '</body>';
        echo '</html>';

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| SHORTCUT FUNCTIONS
|--------------------------------------------------------------------------
*/

if (!function_exists('check_all_roles')) {
    function check_all_roles(): void
    {
        check_role(
            IMS_ALL_ROLES
        );
    }
}


if (!function_exists('check_admin_roles')) {
    function check_admin_roles(): void
    {
        check_role(
            IMS_ADMIN_ROLES
        );
    }
}


if (!function_exists('check_programme_roles')) {
    function check_programme_roles(): void
    {
        check_role(
            IMS_PROGRAMME_ROLES
        );
    }
}


if (!function_exists('check_finance_roles')) {
    function check_finance_roles(): void
    {
        check_role(
            IMS_FINANCE_ROLES
        );
    }
}


if (!function_exists('check_review_roles')) {
    function check_review_roles(): void
    {
        check_role(
            IMS_REVIEW_ROLES
        );
    }
}


if (!function_exists('check_staff_roles')) {
    function check_staff_roles(): void
    {
        check_role(
            IMS_STAFF_ROLES
        );
    }
}