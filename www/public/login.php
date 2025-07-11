<?php
session_start();
require_once(__DIR__ . '/../init/init.php');
require_once(__DIR__ . '/../config/db.php'); // This will now load the SQLite PDO connection

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username_or_email = trim($_POST['username_or_email']);
    $password = $_POST['password'];

    try {
        // Use prepared statement with PDO to check both username and email
        $stmt = $conn->prepare("SELECT * FROM teachers WHERE username = :username_or_email OR email = :username_or_email");
        $stmt->bindParam(':username_or_email', $username_or_email);

        $stmt->execute();
        $user = $stmt->fetch(); // Fetch as associative array (default PDO fetch mode is set in db.php)

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['teacher_id'] = $user['teacher_id'];
            $_SESSION['teacher_name'] = $user['full_name'];
            $_SESSION['username'] = $user['username'];
            header("Location: dashboard.php");
            exit;
        } else {
            $error = "Invalid login credentials.";
        }
    } catch (PDOException $e) { // Catch PDOException for database errors
        // For debugging only - remove or modify in production
        $error = "Database error: " . $e->getMessage();
        // Safer alternative for production:
        // $error = "A system error occurred. Please try again later.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UDM IntelliGrade - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
    background: url('assets/img/udmganda.jpg') no-repeat center center fixed;
    background-size: cover;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

        .login-container {
            background-color: #fcfbf7; /* Even lighter beige for card */
            border-radius: 0.5rem;
            box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.1);
            border: 1px solid #d6d0b8; /* Matching beige border */
            width: 100%;
            max-width: 420px;
            overflow: hidden;
        }

        .login-header {
            background-color: #004d00; /* Slightly darker green for header */
            color: #FFFFFF;
            padding: 1.5rem;
            text-align: center;
            border-bottom: 1px solid #008000; /* Lighter green separator */
        }

        .login-header .logo-container {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .login-header .logo-image {
            max-height: 60px;
            margin-right: 0.75rem;
        }

        .login-header .logo-text {
            text-align: left;
        }

        .login-header .uni-name {
            font-weight: 600;
            margin: 0;
            font-size: 1.1rem;
            line-height: 1.2;
        }

        .login-header .tagline {
            font-weight: 300;
            font-size: 0.8rem;
            margin: 0;
        }

        .login-body {
            padding: 2rem;
        }

        .form-label {
            font-weight: 500;
            color: #006400; /* Dark green text */
        }

        .form-control {
            border: 1px solid #d6d0b8; /* Matching beige border */
            padding: 0.75rem 1rem;
            font-size: 1rem;
            border-radius: 0.3rem;
            background-color: #ffffff;
        }

        .form-control:focus {
            border-color: #006400;
            box-shadow: 0 0 0 0.25rem rgba(0, 100, 0, 0.25);
        }

        .input-group-text {
            background-color: #e9e5d0; /* Light beige for input group */
            border: 1px solid #d6d0b8;
            color: #006400;
        }

        .btn-primary {
            background-color: #006400; /* Dark green buttons */
            border-color: #006400;
            padding: 0.75rem 1rem;
            font-weight: 500;
            font-size: 1rem;
            transition: all 0.2s ease;
        }

        .btn-primary:hover {
            background-color: #004d00; /* Darker green on hover */
            border-color: #004d00;
        }

        .link-group {
            margin-top: 1rem;
            text-align: center;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .link-group a {
            color: #006400; /* Dark green links */
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.2s ease;
        }

        .link-group a:hover {
            color: #004d00; /* Darker green on hover */
            text-decoration: underline;
        }

        .login-footer {
            background-color: #e9e5d0; /* Light beige footer */
            padding: 1rem;
            text-align: center;
            font-size: 0.8rem;
            color: #006400; /* Dark green footer text */
            border-top: 1px solid #d6d0b8; /* Matching beige border */
        }

        .alert-danger {
            background-color: #f8d7da;
            border-color: #f5c2c7;
            color: #842029;
        }

        /* Responsive adjustments */
        @media (max-width: 576px) {
            .login-body {
                padding: 1.5rem;
            }

            .login-header {
                padding: 1.25rem;
            }

            .login-header .logo-image {
                max-height: 50px;
            }

            .login-header .uni-name {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>

<div class="login-container">
    <div class="login-header">
        <div class="logo-container">
            <img src="assets/img/udm_logo.png" alt="UDM Logo" class="logo-image">
            <div class="logo-text">
                <h5 class="uni-name">UNIVERSIDAD DE MANILA</h5>
                <p class="tagline">Former City College of Manila</p>
            </div>
        </div>
        <h2 class="mt-2 mb-0">Teacher Login</h2>
    </div>

    <div class="login-body">
        <h3 class="mb-4 text-center" style="color: #006400;">Sign In</h3>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label for="username_or_email" class="form-label">Username or Email</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                    <input type="text" class="form-control" id="username_or_email" name="username_or_email" placeholder="Enter your username or email" required>
                </div>
            </div>
            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" required>
                </div>
            </div>
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Login
                </button>
            </div>
        </form>

        <div class="link-group">
            <a href="forgot_password.php">
                <i class="bi bi-question-circle me-1"></i>Forgot Password?
            </a>
            <a href="register.php">
                <i class="bi bi-person-plus me-1"></i>Don't have an account? Register here
            </a>
        </div>
    </div>

    <div class="login-footer">
        &copy; <?= date('Y') ?> Universidad De Manila - IntelliGrade System. All rights reserved.
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>