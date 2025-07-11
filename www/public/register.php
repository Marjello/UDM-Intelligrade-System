<?php
require_once '../config/db.php';
// The db.php file now creates a $conn variable using PDO for SQLite

$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);

    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }

    // Check if username exists
    // Using PDO prepared statements
    $check = $conn->prepare("SELECT COUNT(*) FROM teachers WHERE username = :username");
    $check->bindParam(':username', $username);
    $check->execute();
    $usernameExists = $check->fetchColumn();

    if ($usernameExists > 0) {
        $errors[] = "Username already taken.";
    }

    if (empty($errors)) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);

        // Insert new teacher
        $stmt = $conn->prepare("INSERT INTO teachers (username, password_hash, full_name, email) VALUES (:username, :hashed_password, :full_name, :email)");
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':hashed_password', $hashed);
        $stmt->bindParam(':full_name', $full_name);
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        header("Location: login.php?registered=1");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UDM IntelliGrade - Register</title>
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

        .register-container {
            background-color: #fcfbf7; /* Even lighter beige for card */
            border-radius: 0.5rem;
            box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.1);
            border: 1px solid #d6d0b8; /* Matching beige border */
            width: 100%;
            max-width: 500px;
            overflow: hidden;
        }

        .register-header {
            background-color: #004d00; /* Slightly darker green for header */
            color: #FFFFFF;
            padding: 1.5rem;
            text-align: center;
            border-bottom: 1px solid #008000; /* Lighter green separator */
        }

        .register-header .logo-container {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .register-header .logo-image {
            max-height: 60px;
            margin-right: 0.75rem;
        }

        .register-header .logo-text {
            text-align: left;
        }

        .register-header .uni-name {
            font-weight: 600;
            margin: 0;
            font-size: 1.1rem;
            line-height: 1.2;
        }

        .register-header .tagline {
            font-weight: 300;
            font-size: 0.8rem;
            margin: 0;
        }

        .register-body {
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
            margin-top: 1.5rem;
            text-align: center;
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

        .register-footer {
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
            .register-body {
                padding: 1.5rem;
            }

            .register-header {
                padding: 1.25rem;
            }

            .register-header .logo-image {
                max-height: 50px;
            }

            .register-header .uni-name {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>

<div class="register-container">
    <div class="register-header">
        <div class="logo-container">
            <img src="assets/img/udm_logo.png" alt="UDM Logo" class="logo-image">
            <div class="logo-text">
                <h5 class="uni-name">UNIVERSIDAD DE MANILA</h5>
                <p class="tagline">Former City College of Manila</p>
            </div>
        </div>
        <h2 class="mt-2 mb-0">Teacher Login</h2>
    </div>

    <div class="register-body">
        <h3 class="mb-4 text-center" style="color: #006400;">Create an Account</h3>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Please correct the following errors:</strong>
                <ul class="mb-0 mt-1">
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label for="full_name" class="form-label">Full Name</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person-badge-fill"></i></span>
                    <input type="text" class="form-control" id="full_name" name="full_name" placeholder="Enter your full name" required value="<?= isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : '' ?>">
                </div>
            </div>
            
            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                    <input type="email" class="form-control" id="email" name="email" placeholder="Enter your email address" required value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                </div>
            </div>
            
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                    <input type="text" class="form-control" id="username" name="username" placeholder="Choose a username" required value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>">
                </div>
            </div>
            
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" class="form-control" id="password" name="password" placeholder="Create a password" required>
                </div>
            </div>
            
            <div class="mb-4">
                <label for="confirm_password" class="form-label">Confirm Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-shield-lock-fill"></i></span>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                </div>
            </div>
            
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-person-plus me-2"></i>Register Account
                </button>
            </div>
        </form>

        <div class="link-group">
            <a href="login.php">
                <i class="bi bi-box-arrow-in-right me-1"></i>Already have an account? Login here
            </a>
        </div>
    </div>

    <div class="register-footer">
        &copy; <?= date('Y') ?> Universidad De Manila - IntelliGrade System. All rights reserved.
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>