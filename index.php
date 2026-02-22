<?php
include_once 'config/database.php';
include_once 'includes/functions.php';

// Check if user is logged in and redirect to appropriate dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'student') {
        header("Location: student/dashboard.php");
    } elseif ($_SESSION['role'] === 'panel' || $_SESSION['role'] === 'adviser') {
        header("Location: panel/dashboard.php");
    } elseif ($_SESSION['role'] === 'admin') {
        header("Location: admin/dashboard.php");
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Research Approval System - Eastern Samar State University</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <img src="assets/images/essu logo.png" alt="ESSU Logo" class="navbar-logo">
                Eastern Samar State University
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="about.php">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="auth/login.php">Login</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="auth/register.php">Register</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-0">
        <div class="essu-hero-section">
            <div class="container">
                <div class="row">
                    <div class="col-lg-8 mx-auto essu-hero-content">
                        <h1 class="display-4">Web-Based Automated Research Approval and Submission System</h1>
                        <p class="lead">Eastern Samar State University</p>
                        <div class="mt-4">
                            <a href="auth/login.php" class="btn btn-light btn-lg me-2">Login</a>
                            <a href="auth/register.php" class="btn btn-outline-light btn-lg">Register</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container py-5">
        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-file-earmark-text display-4 text-primary mb-3"></i>
                        <h4 class="card-title">Submit Research Proposals</h4>
                        <p class="card-text">Submit your research titles and chapters online. See your submission status anytime.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-check2-circle display-4 text-primary mb-3"></i>
                        <h4 class="card-title">Efficient Review Process</h4>
                        <p class="card-text">ur system connects your proposals with reviewers, ensuring timely feedback and reducing administrative delays.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-graph-up display-4 text-primary mb-3"></i>
                        <h4 class="card-title">Track Your Progress</h4>
                        <p class="card-text">Track your research progress and get notified about updates.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-5">
            <div class="col-lg-6">
                <h2>Streamlining Research Management</h2>
                <p>The Web-Based Automated Research Approval and Submission System transforms how research proposals are managed at Eastern Samar State University. Our platform eliminates paper-based processes, reduces administrative burden, and creates a transparent environment for academic research.</p>
                <p>By digitalizing the entire workflow from submission to approval, we address key challenges faced by researchers, advisers, and administrators:</p>
                <ul>
                    <li>Eliminate lost documents and manual tracking</li>
                    <li>Provide structured feedback and revision guidance</li>
                    <li>Improve communication between students and faculty</li>
                </ul>
                <a href="about.php" class="btn btn-outline-primary mt-3">Learn More</a>
            </div>
        </div>

        <div class="row mt-5">
            <div class="col-12">
                <div class="card bg-light">
                    <div class="card-body p-4">
                        <h3>How It Works</h3>
                        <div class="row mt-4">
                            <div class="col-md-3 text-center mb-4 mb-md-0">
                                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 80px; height: 80px;">
                                    <i class="bi bi-1-circle-fill fs-2"></i>
                                </div>
                                <h5>Register</h5>
                                <p class="small">Create an account and form your research group</p>
                            </div>
                            <div class="col-md-3 text-center mb-4 mb-md-0">
                                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 80px; height: 80px;">
                                    <i class="bi bi-2-circle-fill fs-2"></i>
                                </div>
                                <h5>Submit Title</h5>
                                <p class="small">Propose your research title for approval</p>
                            </div>
                            <div class="col-md-3 text-center mb-4 mb-md-0">
                                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 80px; height: 80px;">
                                    <i class="bi bi-3-circle-fill fs-2"></i>
                                </div>
                                <h5>Chapter Submission</h5>
                                <p class="small">Submit chapters 1-5 sequentially for review</p>
                            </div>
                            <div class="col-md-3 text-center">
                                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 80px; height: 80px;">
                                    <i class="bi bi-4-circle-fill fs-2"></i>
                                </div>
                                <h5>Final Evaluation</h5>
                                <p class="small">Complete approval by the review panel</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="bg-dark text-white py-4 mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5>Eastern Samar State University</h5>
                    <p>Research Approval and Submission System</p>
                    <p><small>&copy; 2023-2025 All Rights Reserved</small></p>
                </div>
                <div class="col-md-3">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="index.php" class="text-white">Home</a></li>
                        <li><a href="about.php" class="text-white">About</a></li>
                        <li><a href="auth/login.php" class="text-white">Login</a></li>
                    </ul>
                </div>
                <div class="col-md-3">
                    <h5>Contact Information</h5>
                    <ul class="list-unstyled">
                        <li><i class="bi bi-geo-alt"></i> Borongan City, Eastern Samar</li>
                        <li><i class="bi bi-envelope"></i> research@essu.edu.ph</li>
                        <li><i class="bi bi-telephone"></i> (055) 123-4567</li>
                    </ul>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/script.js"></script>
</body>

</html>