<?php
// Adjust path to your db.php file as necessary.
// Assuming 'reset_password.php' is in 'public/' and 'db.php' is in 'config/'
require_once __DIR__ . '/../config/db.php';

$message = '';
$message_type = '';
$email_value = ''; // To pre-fill the email field if it was submitted

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $email_value = htmlspecialchars($email); // Retain email value on error

    // Basic validation
    if (empty($email) || empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all fields.";
        $message_type = 'danger';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
        $message_type = 'danger';
    } elseif ($new_password !== $confirm_password) {
        $error = "New password and confirm password do not match.";
        $message_type = 'danger';
    } elseif (strlen($new_password) < 8) { // Example: enforce minimum password length
        $error = "Password must be at least 8 characters long.";
        $message_type = 'danger';
    } else {
        try {
            // Check if the email exists in the teachers table
            $stmt = $conn->prepare("SELECT COUNT(*) FROM teachers WHERE email = :email");
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $stmt->execute();
            $email_exists = $stmt->fetchColumn();

            if ($email_exists) {
                // Hash the new password before storing it in password_hash column
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                // Update the password_hash for the recognized email
                $stmt = $conn->prepare("UPDATE teachers SET password_hash = :password_hash WHERE email = :email");
                $stmt->bindParam(':password_hash', $hashed_password, PDO::PARAM_STR);
                $stmt->bindParam(':email', $email, PDO::PARAM_STR);

                if ($stmt->execute()) {
                    $message = "Password for '" . htmlspecialchars($email) . "' has been successfully reset. You can now log in.";
                    $message_type = 'success';
                    // Clear fields on success
                    $email_value = '';
                    $new_password = '';
                    $confirm_password = '';
                } else {
                    $error = "Error updating password. Please try again.";
                    $message_type = 'danger';
                }
            } else {
                $error = "No account found with that email address.";
                $message_type = 'danger';
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
            $message_type = 'danger';
            // In a production environment, log the full error ($e) but display a generic message to the user.
        }
    }
    // Set $message if $error is set
    if (!empty($error)) {
        $message = $error;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UDM IntelliGrade - Reset Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        /* Reusing styles from forgot_password.php for consistency */
        body {
            background: url('assets/img/udmganda.jpg') no-repeat center center fixed; /* Adjust path if needed relative to where reset_password.php is accessed */
            background-size: cover;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .reset-container {
            background-color: #fcfbf7;
            border-radius: 0.5rem;
            box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.1);
            border: 1px solid #d6d0b8;
            width: 100%;
            max-width: 450px;
            overflow: hidden;
        }

        .reset-header {
            background-color: #004d00;
            color: #FFFFFF;
            padding: 1.5rem;
            text-align: center;
            border-bottom: 1px solid #008000;
        }

        .reset-header .logo-container {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .reset-header .logo-image {
            max-height: 60px;
            margin-right: 0.75rem;
        }

        .reset-header .logo-text {
            text-align: left;
        }

        .reset-header .uni-name {
            font-weight: 600;
            margin: 0;
            font-size: 1.1rem;
            line-height: 1.2;
        }

        .reset-header .tagline {
            font-weight: 300;
            font-size: 0.8rem;
            margin: 0;
        }

        .reset-body {
            padding: 2rem;
        }

        .form-description {
            color: #555555;
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .form-label {
            font-weight: 500;
            color: #006400;
        }

        .form-control {
            border: 1px solid #d6d0b8;
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
            background-color: #e9e5d0;
            border: 1px solid #d6d0b8;
            color: #006400;
        }

        .btn-primary {
            background-color: #006400;
            border-color: #006400;
            padding: 0.75rem 1rem;
            font-weight: 500;
            font-size: 1rem;
            transition: all 0.2s ease;
        }

        .btn-primary:hover {
            background-color: #004d00;
            border-color: #004d00;
        }

        .link-group {
            margin-top: 1.5rem;
            text-align: center;
        }

        .link-group a {
            color: #006400;
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.2s ease;
        }

        .link-group a:hover {
            color: #004d00;
            text-decoration: underline;
        }

        .reset-footer {
            background-color: #e9e5d0;
            padding: 1rem;
            text-align: center;
            font-size: 0.8rem;
            color: #006400;
            border-top: 1px solid #d6d0b8;
        }

        .alert-success {
            background-color: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }

        .alert-danger {
            background-color: #f8d7da;
            border-color: #f5c2c7;
            color: #842029;
        }

        .alert a {
            color: inherit;
            font-weight: 500;
            text-decoration: underline;
        }

        .alert a:hover {
            text-decoration: none;
        }

        /* Responsive adjustments */
        @media (max-width: 576px) {
            .reset-body {
                padding: 1.5rem;
            }

            .reset-header {
                padding: 1.25rem;
            }

            .reset-header .logo-image {
                max-height: 50px;
            }

            .reset-header .uni-name {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>

<div class="reset-container">
    <div class="reset-header">
        <div class="logo-container">
            <img src="assets/img/udm_logo.png" alt="UDM Logo" class="logo-image">
            <div class="logo-text">
                <h5 class="uni-name">UNIVERSIDAD DE MANILA</h5>
                <p class="tagline">Former City College of Manila</p>
            </div>
        </div>
        <h2 class="mt-2 mb-0">Teacher Account</h2>
    </div>

    <div class="reset-body">
        <h3 class="mb-3 text-center" style="color: #006400;">Reset Password</h3>
        <p class="form-description">Enter your email and new password to reset your account.</p>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $message_type ?>" role="alert">
                <?php if ($message_type === 'success'): ?>
                    <i class="bi bi-check-circle-fill me-2"></i>
                <?php else: ?>
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php endif; ?>
                <?= $message ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                    <input type="email" class="form-control" id="email" name="email" placeholder="Enter your registered email" required value="<?= $email_value ?>">
                </div>
            </div>
            <div class="mb-3">
                <label for="new_password" class="form-label">New Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" class="form-control" id="new_password" name="new_password" placeholder="Enter new password (min 8 characters)" required>
                </div>
            </div>
            <div class="mb-4">
                <label for="confirm_password" class="form-label">Confirm New Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Re-enter new password" required>
                </div>
            </div>
            
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-arrow-clockwise me-2"></i>Reset Password
                </button>
            </div>
        </form>

        <div class="link-group">
            <a href="login.php">
                <i class="bi bi-arrow-left me-1"></i>Back to Login
            </a>
        </div>
    </div>

    <div class="reset-footer">
        &copy; <?= date('Y') ?> Universidad De Manila - IntelliGrade System. All rights reserved.
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>