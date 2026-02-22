<?php
session_start();

include_once '../config/database.php';
include_once '../includes/functions.php';

// Check if already logged in
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    // Check if session has expired
    if (isset($_SESSION['expires_at']) && time() > $_SESSION['expires_at']) {
        // Session expired, destroy it
        session_unset();
        session_destroy();
        session_start();
        $error = "Your session has expired. Please log in again.";
    } else {
        // Valid session, redirect based on role
        if ($_SESSION['role'] === 'student') {
            header("Location: ../student/dashboard.php");
        } elseif ($_SESSION['role'] === 'panel' || $_SESSION['role'] === 'adviser') {
            header("Location: ../panel/dashboard.php");
        } elseif ($_SESSION['role'] === 'admin') {
            header("Location: ../admin/dashboard.php");
        }
        exit();
    }
}

$error = '';

// Function to get setting value
function get_setting($conn, $key, $default = '') {
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_COLUMN);
        return $result !== false ? $result : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

// Function to check if account is locked
function is_account_locked($conn, $username) {
    try {
        $stmt = $conn->prepare("SELECT account_locked, locked_until FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return false;
        }
        
        // Check if account is locked
        if ($user['account_locked']) {
            // Check if lock period has expired
            if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                return true; // Still locked
            } else {
                // Lock period expired, unlock account
                $stmt = $conn->prepare("UPDATE users SET account_locked = FALSE, locked_until = NULL WHERE username = ?");
                $stmt->execute([$username]);
                return false;
            }
        }
        
        return false;
    } catch (PDOException $e) {
        error_log("Error checking account lock: " . $e->getMessage());
        return false;
    }
}

// FIXED: Function to get failed login attempts count - USERNAME ONLY TRACKING
function get_failed_attempts($conn, $username, $ip_address, $max_attempts_timeframe = 15) {
    try {
        // Count failed attempts for this specific username only
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM login_attempts 
            WHERE username = ? 
            AND successful = 0 
            AND attempt_time > DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$username, $max_attempts_timeframe]);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error getting failed attempts: " . $e->getMessage());
        return 0;
    }
}

// FIXED: Function to log login attempt - matches your DB schema
function log_login_attempt($conn, $username, $ip_address, $success) {
    try {
        // FIXED: Using 'successful' column name from your schema
        $stmt = $conn->prepare("INSERT INTO login_attempts (username, ip_address, successful) VALUES (?, ?, ?)");
        $stmt->execute([$username, $ip_address, $success ? 1 : 0]);
        
        // Clean up old login attempts (older than 24 hours)
        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt->execute();
    } catch (PDOException $e) {
        error_log("Error logging login attempt: " . $e->getMessage());
    }
}

// Function to lock account
function lock_account($conn, $username, $lockout_duration) {
    try {
        $locked_until = date('Y-m-d H:i:s', time() + ($lockout_duration * 60));
        $stmt = $conn->prepare("UPDATE users SET account_locked = TRUE, locked_until = ? WHERE username = ?");
        $stmt->execute([$locked_until, $username]);
    } catch (PDOException $e) {
        error_log("Error locking account: " . $e->getMessage());
    }
}
function get_session_fingerprint() {
    $fingerprint = $_SERVER['HTTP_USER_AGENT'] . 
                   $_SERVER['REMOTE_ADDR'] . 
                   ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    return hash('sha256', $fingerprint);
}

// Handle login form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = validate_input($_POST['username']);
    $password = $_POST['password'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if (empty($username) || empty($password)) {
        $error = "Username and password are required";
    } else {
        try {
            // Get security settings
            $max_attempts = intval(get_setting($conn, 'max_login_attempts', 5));
            $lockout_duration = intval(get_setting($conn, 'lockout_duration', 15));
            $session_timeout = intval(get_setting($conn, 'session_timeout', 30));

            // Check if account is locked
            if (is_account_locked($conn, $username)) {
                $error = "Account is temporarily locked due to too many failed login attempts. Please try again later.";
                log_login_attempt($conn, $username, $ip_address, false);
            } else {
                // Check failed attempts count FOR THIS USERNAME ONLY
                $failed_attempts = get_failed_attempts($conn, $username, $ip_address);
                
                if ($failed_attempts >= $max_attempts) {
                    // Lock the account
                    lock_account($conn, $username, $lockout_duration);
                    $error = "Too many failed login attempts. Account has been temporarily locked for {$lockout_duration} minutes.";
                    log_login_attempt($conn, $username, $ip_address, false);
                } else {
                    // Proceed with login attempt
                    $sql = "SELECT * FROM users WHERE username = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$username]);

                    if ($stmt->rowCount() > 0) {
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);

                        /// Check if user account is active (safely handle missing column)
                        if (isset($user['is_active']) && !$user['is_active']) {
                            $error = "Your account has been deactivated. Please contact the administrator.";
                            log_login_attempt($conn, $username, $ip_address, false);
                        }
                        // Check if user registration is approved (safely handle missing column)
                        elseif (isset($user['registration_status']) && $user['registration_status'] === 'pending') {
                            $error = "Your account is pending approval. Please wait for an administrator to approve your registration.";
                            log_login_attempt($conn, $username, $ip_address, false);
                        }
                        // Verify password
                        elseif (password_verify($password, $user['password'])) {
                            // Successful login
                            log_login_attempt($conn, $username, $ip_address, true);
                            
                            // Clear any existing session data first
                            session_regenerate_id(true);
                            
                            // Calculate session expiration time
                            $expires_at = time() + ($session_timeout * 60);
                            
                            // Set session variables
                            $_SESSION['user_id'] = $user['user_id'];
                            $_SESSION['username'] = $user['username'];
                            $_SESSION['role'] = $user['role'];
                            $_SESSION['full_name'] = $user['first_name'] . ' ' . $user['last_name'];
                            $_SESSION['logged_in'] = true;
                            $_SESSION['expires_at'] = $expires_at;
                            $_SESSION['login_time'] = time();

                            $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
                            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
                            $_SESSION['created_at'] = time();
                            $_SESSION['fingerprint'] = get_session_fingerprint();

                            // Update user's last login time and session expiration in database
                            $stmt = $conn->prepare("UPDATE users SET last_login = NOW(), session_expires_at = ? WHERE user_id = ?");
                            $stmt->execute([date('Y-m-d H:i:s', $expires_at), $user['user_id']]);

                            // Redirect based on role
                            if ($user['role'] === 'student') {
                                header("Location: ../student/dashboard.php");
                            } elseif ($user['role'] === 'panel' || $user['role'] === 'adviser') {
                                header("Location: ../panel/dashboard.php");
                            } elseif ($user['role'] === 'admin') {
                                header("Location: ../admin/dashboard.php");
                            } else {
                                $error = "Invalid user role";
                            }
                            exit();
                        } else {
                            // Invalid password - log the attempt first
                            log_login_attempt($conn, $username, $ip_address, false);
                            
                            // Get updated failed attempts count FOR THIS USERNAME ONLY
                            $total_failed_attempts = get_failed_attempts($conn, $username, $ip_address);
                            $remaining_attempts = max(0, $max_attempts - $total_failed_attempts);
                            
                            if ($remaining_attempts > 0) {
                                $error = "Invalid username or password. {$remaining_attempts} attempts remaining.";
                            } else {
                                $error = "Invalid username or password.";
                            }
                        }
                    } else {
                        $error = "Username not found";
                        log_login_attempt($conn, $username, $ip_address, false);
                    }
                }
            }
        } catch (Exception $e) {
            $error = "An error occurred. Please try again.";
            error_log("Login error: " . $e->getMessage());
            log_login_attempt($conn, $username, $ip_address, false);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>Login - Research Approval System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .password-toggle {
            position: relative;
        }
        .toggle-password {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
            z-index: 10;
        }
        .toggle-password:hover {
            color: #495057;
        }
        .password-toggle input {
            padding-right: 45px;
        }
        .lockout-warning {
            background-color: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 0.25rem;
        }
    </style>
</head>

<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-lg-5 col-md-7">
                <div class="card shadow">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <img src="../assets/images/essu logo.png" alt="essu logo" height="80">
                            <h3 class="mt-3">Web-Based Automated Research Approval and Submission System</h3>
                            <p class="text-muted">Eastern Samar State University</p>
                        </div>

                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger lockout-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username"
                                    value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <div class="password-toggle">
                                    <input type="password" class="form-control" id="password" name="password" required>
                                    <i class="bi bi-eye-slash toggle-password" onclick="togglePassword('password', this)" title="Show/Hide Password"></i>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Login</button>
                        </form>

                        <div class="text-center mt-4">
                            <p>Don't have an account? <a href="register.php">Sign Up</a></p>
                            <a href="../index.php">Back to Home</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Username space prevention
        function preventSpaceInUsername() {
            const usernameField = document.getElementById('username');
            
            // Prevent space on keypress
            usernameField.addEventListener('keypress', function(e) {
                if (e.key === ' ' || e.keyCode === 32) {
                    e.preventDefault();
                    // Show brief feedback
                    const feedback = document.createElement('div');
                    feedback.className = 'text-danger small mt-1';
                    feedback.textContent = 'Username cannot contain spaces';
                    feedback.style.position = 'absolute';
                    
                    // Remove existing feedback if any
                    const existingFeedback = usernameField.parentNode.querySelector('.text-danger');
                    if (existingFeedback && existingFeedback !== usernameField.nextElementSibling) {
                        existingFeedback.remove();
                    }
                    
                    usernameField.parentNode.appendChild(feedback);
                    
                    // Remove feedback after 2 seconds
                    setTimeout(() => {
                        if (feedback.parentNode) {
                            feedback.remove();
                        }
                    }, 2000);
                }
            });
            
            // Remove spaces on paste or input
            usernameField.addEventListener('input', function(e) {
                this.value = this.value.replace(/\s/g, '');
            });
            
            // Remove spaces on paste specifically
            usernameField.addEventListener('paste', function(e) {
                setTimeout(() => {
                    this.value = this.value.replace(/\s/g, '');
                }, 1);
            });
        }

        // Toggle password visibility
        function togglePassword(fieldId, icon) {
            const field = document.getElementById(fieldId);
            const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
            field.setAttribute('type', type);
            
            // Toggle the icon
            if (type === 'text') {
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            } else {
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            }
        }

        // Initialize username validation when page loads
        document.addEventListener('DOMContentLoaded', function() {
            preventSpaceInUsername();
        });
    </script>
</body>
</html>