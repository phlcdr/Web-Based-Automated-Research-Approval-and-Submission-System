<?php
include_once 'config/database.php';
include_once 'includes/functions.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>About - Research Approval System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
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
                        <a class="nav-link" href="index.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="about.php">About</a>
                    </li>
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo $_SESSION['role']; ?>/dashboard.php">Dashboard</a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="auth/login.php">Login</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="auth/register.php">Register</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-5">
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <h1 class="text-center mb-4">About The Research Approval System</h1>
                <p class="lead text-center mb-5">Streamlining the research proposal process at Eastern Samar State University</p>
                        
                <div class="card mb-5">
                    <div class="card-body">
                        <h2 class="card-title">Our Mission</h2>
                        <p>The Web-Based Automated Research Approval and Submission System lets Eastern Samar State University handle research proposals online. Students and teachers can submit and track their research through the website.
This replaces paper forms and helps keep research documents organized in one place.</p>
                    </div>
                </div>

                <h3 class="mb-4">Key Features</h3>

                <div class="row mb-5">
                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title"><i class="bi bi-people text-primary me-2"></i>Account Management</h4>
                                <p class="card-text">Students can create accounts, form research groups, and can manage their team members and advisers.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title"><i class="bi bi-file-earmark-text text-primary me-2"></i>Title Submission</h4>
                                <p class="card-text">Send your research ideas for approval with all the needed documents and get a feedback.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title"><i class="bi bi-journal-text text-primary me-2"></i>Chapter Submission</h4>
                                <p class="card-text">step-by-step process to submit Chapters 1-5, with feedback to help you improve.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title"><i class="bi bi-chat-left-text text-primary me-2"></i>Feedback System</h4>
                                <p class="card-text">Review system that gives you guidance and helpful feedback from advisors and panel members.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title"><i class="bi bi-bell text-primary me-2"></i>Notifications</h4>
                                <p class="card-text">Get notifications to keep everyone informed about submissions, reviews, and approvals.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title"><i class="bi bi-graph-up text-primary me-2"></i>Progress Tracking</h4>
                                <p class="card-text">Visual progress indicators and status updates provide clear visibility into the research approval process.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <h3 class="mb-4">Benefits for Stakeholders</h3>

                <div class="accordion mb-5" id="benefitsAccordion">
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingOne">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
                                For Students
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse show" aria-labelledby="headingOne" data-bs-parent="#benefitsAccordion">
                            <div class="accordion-body">
                                <ul>
                                    <li>Digital submission eliminates paperwork and confusion</li>
                                    <li>Clear submission process with step-by-step guidance</li>
                                    <li>Real-time tracking of proposal status</li>
                                    <li>Structured feedback for improvement</li>
                                    <li>Reduced waiting times and administrative delays</li>
                                    <li>Secure storage of all research documents</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingTwo">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo" aria-expanded="false" aria-controls="collapseTwo">
                                For Faculty
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" aria-labelledby="headingTwo" data-bs-parent="#benefitsAccordion">
                            <div class="accordion-body">
                                <ul>
                                    <li>Organized research proposals and review templates</li>
                                    <li>Reduced time spent searching for documents</li>
                                    <li>Easy tracking of submission status</li>
                                    <li>Structured review process with standardized criteria</li>
                                    <li>Better connection with ongoing research activities</li>
                                    <li>Digital archive of previous reviews and feedback</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingThree">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree" aria-expanded="false" aria-controls="collapseThree">
                                For Administration
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" aria-labelledby="headingThree" data-bs-parent="#benefitsAccordion">
                            <div class="accordion-body">
                                <ul>
                                    <li>Replacement of paper-based processes with digital workflows</li>
                                    <li>Reduced document loss and quicker information retrieval</li>
                                    <li>Automated notifications reduce manual follow-ups</li>
                                    <li>Comprehensive reporting and analytics</li>
                                    <li>Standardized procedures across departments</li>
                                    <li>Valuable data on research activities for planning</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>


                <div class="text-center">
                    <a href="auth/register.php" class="btn btn-primary btn-lg">Get Started Today</a>
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
                        <li><a href="contact.php" class="text-white">Contact</a></li>
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