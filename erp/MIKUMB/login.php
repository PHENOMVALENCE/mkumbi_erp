<?php
define('APP_ACCESS', true);
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

$auth = new Auth();

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    $result = $auth->login($username, $password);
    
    if ($result['success']) {
        header('Location: index.php');
        exit;
    } else {
        $error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - <?php echo APP_NAME; ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Source Sans Pro', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .login-page {
            width: 100%;
            max-width: 400px;
            padding: 20px;
        }

        .login-box {
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .login-logo {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            padding: 30px 20px;
            text-align: center;
        }

        .login-logo i {
            font-size: 48px;
            margin-bottom: 10px;
            display: block;
        }

        .login-logo h1 {
            font-size: 28px;
            font-weight: 700;
            margin: 0;
        }

        .login-logo p {
            font-size: 14px;
            margin: 5px 0 0;
            opacity: 0.9;
        }

        .card-body {
            padding: 30px;
        }

        .login-box-msg {
            margin: 0 0 25px;
            text-align: center;
            color: #6c757d;
            font-size: 16px;
            font-weight: 600;
        }

        .form-control {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            font-size: 14px;
            height: 45px;
            padding: 0 15px;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102,126,234,0.25);
        }

        .input-group {
            margin-bottom: 20px;
        }

        .input-group-text {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-right: 0;
            border-radius: 5px 0 0 5px;
            width: 50px;
            justify-content: center;
        }

        .input-group .form-control {
            border-left: 0;
            border-radius: 0 5px 5px 0;
        }

        .form-check {
            margin-bottom: 20px;
        }

        .form-check-input {
            width: 18px;
            height: 18px;
            border-radius: 4px;
            cursor: pointer;
        }

        .form-check-input:checked {
            background-color: #667eea;
            border-color: #667eea;
        }

        .form-check-label {
            margin-left: 8px;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 5px;
            color: #ffffff;
            font-size: 16px;
            font-weight: 600;
            height: 45px;
            width: 100%;
            transition: transform 0.2s;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102,126,234,0.3);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .social-auth-links {
            text-align: center;
            margin: 20px 0;
        }

        .social-auth-links p {
            color: #6c757d;
            margin-bottom: 15px;
        }

        .btn-block + .btn-block {
            margin-top: 10px;
        }

        .login-footer {
            padding: 20px;
            background: #f8f9fa;
            text-align: center;
        }

        .login-footer a {
            color: #667eea;
            text-decoration: none;
            margin: 0 10px;
            font-size: 14px;
            transition: color 0.3s;
        }

        .login-footer a:hover {
            color: #764ba2;
            text-decoration: underline;
        }

        .alert {
            border-radius: 5px;
            font-size: 13px;
            margin-bottom: 20px;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
            z-index: 10;
        }

        .password-toggle:hover {
            color: #000;
        }

        @media (max-width: 576px) {
            .login-page {
                padding: 15px;
            }

            .card-body {
                padding: 25px 20px;
            }
        }

        /* Loading State */
        .btn-primary.loading {
            position: relative;
            color: transparent;
        }

        .btn-primary.loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="login-page">
        <div class="login-box">
            <!-- Logo -->
            <div class="login-logo">
                <i class="fas fa-building"></i>
                <h1>ERP System</h1>
                <p>Business Management Suite</p>
            </div>
            
            <!-- Login Form -->
            <div class="card-body">
                <p class="login-box-msg">Sign in to start your session</p>

                <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if (isset($_GET['logout'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle"></i> You have been logged out successfully.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <form method="POST" id="loginForm">
                    <!-- Username -->
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-user"></i>
                        </span>
                        <input 
                            type="text" 
                            class="form-control" 
                            name="username"
                            placeholder="Username"
                            required
                            autofocus
                        >
                    </div>

                    <!-- Password -->
                    <div class="input-group position-relative">
                        <span class="input-group-text">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input 
                            type="password" 
                            class="form-control" 
                            name="password"
                            id="password"
                            placeholder="Password"
                            required
                            style="padding-right: 45px;"
                        >
                        <span class="password-toggle" onclick="togglePassword()">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </span>
                    </div>

                    <!-- Remember Me -->
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="remember" name="remember">
                        <label class="form-check-label" for="remember">
                            Remember Me
                        </label>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="btn btn-primary" id="loginBtn">
                        Sign In
                    </button>
                </form>

                <!-- Social Login (Optional) -->
                <div class="social-auth-links">
                    <p>- OR -</p>
                    <a href="#" class="btn btn-outline-danger btn-block">
                        <i class="fab fa-google"></i> Sign in using Google
                    </a>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="login-footer">
                <a href="forgot-password.php">
                    <i class="fas fa-key"></i> Forgot Password?
                </a>
                <span>|</span>
                <a href="register.php">
                    <i class="fas fa-user-plus"></i> Register Company
                </a>
            </div>
        </div>

        <!-- Copyright -->
        <div class="text-center mt-4" style="color: rgba(255,255,255,0.8);">
            <p>&copy; <?php echo date('Y'); ?> <strong>Car & General Tanzania</strong>. All rights reserved.</p>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Toggle Password Visibility
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Form Loading State
        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('loginBtn');
            btn.classList.add('loading');
            btn.disabled = true;
        });
    </script>
</body>
</html>