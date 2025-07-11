<?php
require_once '../config/db.php';

$message = '';
$message_type = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = $_POST['email'];

    // Updated from $pdo to $conn
    $stmt = $conn->prepare("SELECT * FROM teachers WHERE email = :email");
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        $message = "Email address recognized. You can now proceed to reset your password directly.<br> <a href='reset_password.php'>Click here to reset your password</a>";
        $message_type = 'success';
    } else {
        $message = "Email address not found in our records.";
        $message_type = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UDM IntelliGrade - Forgot Password</title>
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
        .forgot-container {
            background-color: #fcfbf7; /* Even lighter beige for card */
            border-radius: 0.5rem;
            box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.1);
            border: 1px solid #d6d0b8; /* Matching beige border */
            width: 100%;
            max-width: 450px;
            overflow: hidden;
        }

        .forgot-header {
            background-color: #004d00; /* Slightly darker green for header */
            color: #FFFFFF;
            padding: 1.5rem;
            text-align: center;
            border-bottom: 1px solid #008000; /* Lighter green separator */
        }

        .forgot-header .logo-container {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .forgot-header .logo-image {
            max-height: 60px;
            margin-right: 0.75rem;
        }

        .forgot-header .logo-text {
            text-align: left;
        }

        .forgot-header .uni-name {
            font-weight: 600;
            margin: 0;
            font-size: 1.1rem;
            line-height: 1.2;
        }

        .forgot-header .tagline {
            font-weight: 300;
            font-size: 0.8rem;
            margin: 0;
        }

        .forgot-body {
            padding: 2rem;
        }

        .form-description {
            color: #555555;
            margin-bottom: 1.5rem;
            text-align: center;
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

        .forgot-footer {
            background-color: #e9e5d0; /* Light beige footer */
            padding: 1rem;
            text-align: center;
            font-size: 0.8rem;
            color: #006400; /* Dark green footer text */
            border-top: 1px solid #d6d0b8; /* Matching beige border */
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
            .forgot-body {
                padding: 1.5rem;
            }

            .forgot-header {
                padding: 1.25rem;
            }

            .forgot-header .logo-image {
                max-height: 50px;
            }

            .forgot-header .uni-name {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>

<div class="forgot-container">
    <div class="forgot-header">
        <div class="logo-container">
            <img src="assets/img/udm_logo.png" alt="UDM Logo" class="logo-image">
            <div class="logo-text">
                <h5 class="uni-name">UNIVERSIDAD DE MANILA</h5>
                <p class="tagline">Former City College of Manila</p>
            </div>
        </div>
        <h2 class="mt-2 mb-0">Teacher Login</h2>
    </div>

    <div class="forgot-body">
        <h3 class="mb-3 text-center" style="color: #006400;">Forgot Password</h3>
        <p class="form-description">Enter your email address below and we'll provide a direct link to reset your password.</p>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $message_type === 'success' ? 'success' : 'danger' ?>" role="alert">
                <?php if ($message_type === 'success'): ?>
                    <i class="bi bi-check-circle-fill me-2"></i>
                <?php else: ?>
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php endif; ?>
                <?= $message ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-4">
                <label for="email" class="form-label">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                    <input type="email" class="form-control" id="email" name="email" placeholder="Enter your registered email" required value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                </div>
            </div>
            
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-send-fill me-2"></i>Check Email
                </button>
            </div>
        </form>

        <div class="link-group">
            <a href="login.php">
                <i class="bi bi-arrow-left me-1"></i>Back to Login
            </a>
        </div>
    </div>

    <div class="forgot-footer">
        &copy; <?= date('Y') ?> Universidad De Manila - IntelliGrade System. All rights reserved.
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>