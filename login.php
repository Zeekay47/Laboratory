<?php
require_once 'config/database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    if ($auth->login($username, $password)) {
        
        switch($_SESSION['role']) {
            case 'manager':
                header('Location: manager.php');
                break;
            case 'technician':
                header('Location: technician.php');
                break;
            case 'receptionist':
            default:
                header('Location: index.php');
        }
        exit();
    } else {
        $error = 'Invalid username or password';
    }
}

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    switch($_SESSION['role']) {
        case 'manager':
            header('Location: manager.php');
            break;
        case 'technician':
            header('Location: technician.php');
            break;
        case 'receptionist':
        default:
            header('Location: index.php');
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - DTC Laboratory</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
        }
        .login-card {
            max-width: 400px;
            margin: 100px auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .logo {
            text-align: center;
            padding: 30px 0;
            color: #333;
            font-weight: bold;
            font-size: 24px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-card">
            <div class="logo">DTC Laboratory</div>
            <div class="card-body p-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Login</button>
                </form>
                
                <hr class="my-4">
                <div class="text-center">
                    <h6>Demo Accounts:</h6>
                    <p class="small mb-1"><strong>Receptionist:</strong> reception / password</p>
                    <p class="small mb-1"><strong>Technician:</strong> tech1 / password</p>
                    <p class="small"><strong>Manager:</strong> manager / password</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>