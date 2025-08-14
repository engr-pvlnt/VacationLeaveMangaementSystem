<?php
session_start();
include '../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
  header('Location: auth/login');
  exit();
}

// Optionally fetch user info
$user_id = $_SESSION['user_id'];

// Fetch current user data
$result = $conn->query("SELECT * FROM users WHERE id = $user_id");
$user = $result->fetch_assoc();

// Fetch role description
$role_query = "SELECT r.name FROM roles r 
                   JOIN users u ON u.role_id = r.id 
                   WHERE u.id = $user_id";
$role_result = $conn->query($role_query);
$role = $role_result->fetch_assoc();

// Fetch user's department name
$department_id = $user['department_id'];
$department_result = $conn->query("SELECT name FROM departments WHERE id = $department_id");
$department = $department_result->fetch_assoc()['name'];

// Fetch user's job role title
$job_role_id = $user['job_role_id'];
$job_role_result = $conn->query("SELECT title FROM job_roles WHERE id = $job_role_id");
$job_role = $job_role_result->fetch_assoc()['title'];

?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>VLMS - Dashboard</title>
  <!-- Font Awesome for icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      min-height: 100vh;
      padding: 20px;
    }

    .container {
      max-width: 1200px;
      margin: 0 auto;
    }

    /* Header */
    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 40px;
      color: white;
    }

    .logo {
      display: flex;
      align-items: center;
      font-size: 20px;
      font-weight: 600;
    }

    .logo i {
      margin-right: 10px;
      font-size: 24px;
    }

    .user-nav {
      display: flex;
      align-items: center;
      gap: 20px;
    }

    .user-nav a {
      color: white;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 16px;
      border-radius: 8px;
      transition: background-color 0.3s ease;
    }

    .user-nav a:hover {
      background-color: rgba(255, 255, 255, 0.1);
    }

    /* Welcome Section */
    .welcome-section {
      background: rgba(255, 255, 255, 0.95);
      border-radius: 20px;
      padding: 40px;
      margin-bottom: 40px;
      backdrop-filter: blur(10px);
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
      display: flex;
      align-items: center;
      gap: 30px;
    }

    .welcome-content {
      flex: 1;
    }

    .welcome-title {
      font-size: 32px;
      font-weight: 600;
      color: #333;
      margin-bottom: 15px;
    }

    .user-badge {
      display: inline-flex;
      align-items: center;
      background: #667eea;
      color: white;
      padding: 8px 16px;
      border-radius: 20px;
      font-size: 14px;
      font-weight: 500;
    }

    .user-badge i {
      margin-right: 6px;
    }

    /* Profile Image */
    .profile-image-container {
      width: 120px;
      height: 120px;
      border-radius: 50%;
      overflow: hidden;
      border: 2px solid #2a4286ff;
      flex-shrink: 0;
      animation: bounce 5s infinite;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.4);
    }

    @keyframes bounce {

      0%,
      100% {
        transform: translateY(0);
        animation-timing-function: cubic-bezier(0.8, 0, 1, 1);
      }

      50% {
        transform: translateY(-5px);
        animation-timing-function: cubic-bezier(0, 0, 0.2, 1);
      }
    }

    .profile-image {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .profile-image-placeholder {
      width: 100%;
      height: 100%;
      background: #1e3a8a;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 40px;
    }

    /* Dashboard Cards */
    .dashboard-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 20px;
      margin-bottom: 40px;
    }

    .dashboard-card {
      background: rgba(255, 255, 255, 0.95);
      border-radius: 16px;
      padding: 30px;
      backdrop-filter: blur(10px);
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
      transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
      cursor: pointer;
      text-decoration: none;
      color: inherit;
      display: block;
      position: relative;
      overflow: hidden;
    }

    .dashboard-card:hover {
      transform: translateY(-10px) scale(1.03);
      box-shadow: 0 10px 20px rgba(0, 0, 0, 0.5);
      text-decoration: none;
      color: inherit;
      transition: all 0.4s ease;
    }

    .card-icon {
      width: 60px;
      height: 60px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 20px;
      font-size: 24px;
      color: white;
      transition: all 0.4s ease;
      position: relative;
      z-index: 2;
    }

    .dashboard-card:hover .card-icon {
      transform: rotate(10deg) scale(1.1);
      animation: pulse 2s infinite;
    }

    /* Pulsating Animation */
    @keyframes pulse {
      0% {
        box-shadow: 0 0 0 0 rgba(102, 126, 234, 0.7);
      }

      50% {
        box-shadow: 0 0 0 10px rgba(102, 126, 234, 0);
      }

      100% {
        box-shadow: 0 0 0 0 rgba(102, 126, 234, 0);
      }
    }

    .dashboard-card:hover .card-icon.primary {
      animation: pulsePrimary 2s infinite;
    }

    .dashboard-card:hover .card-icon.success {
      animation: pulseSuccess 2s infinite;
    }

    .dashboard-card:hover .card-icon.warning {
      animation: pulseWarning 2s infinite;
    }

    .dashboard-card:hover .card-icon.info {
      animation: pulseInfo 2s infinite;
    }

    @keyframes pulsePrimary {
      0% {
        box-shadow: 0 0 0 0 rgba(102, 126, 234, 0.7);
      }

      50% {
        box-shadow: 0 0 0 15px rgba(102, 126, 234, 0);
      }

      100% {
        box-shadow: 0 0 0 0 rgba(102, 126, 234, 0);
      }
    }

    @keyframes pulseSuccess {
      0% {
        box-shadow: 0 0 0 0 rgba(86, 171, 47, 0.7);
      }

      50% {
        box-shadow: 0 0 0 15px rgba(86, 171, 47, 0);
      }

      100% {
        box-shadow: 0 0 0 0 rgba(86, 171, 47, 0);
      }
    }

    @keyframes pulseWarning {
      0% {
        box-shadow: 0 0 0 0 rgba(240, 147, 251, 0.7);
      }

      50% {
        box-shadow: 0 0 0 15px rgba(240, 147, 251, 0);
      }

      100% {
        box-shadow: 0 0 0 0 rgba(240, 147, 251, 0);
      }
    }

    @keyframes pulseInfo {
      0% {
        box-shadow: 0 0 0 0 rgba(79, 172, 254, 0.7);
      }

      50% {
        box-shadow: 0 0 0 15px rgba(79, 172, 254, 0);
      }

      100% {
        box-shadow: 0 0 0 0 rgba(79, 172, 254, 0);
      }
    }

    .card-icon.primary {
      background: linear-gradient(135deg, #667eea, #764ba2);
    }

    .card-icon.success {
      background: linear-gradient(135deg, #56ab2f, #a8e6cf);
    }

    .card-icon.warning {
      background: linear-gradient(135deg, #f093fb, #f5576c);
    }

    .card-icon.info {
      background: linear-gradient(135deg, #4facfe, #00f2fe);
    }

    .card-title {
      font-size: 18px;
      font-weight: 600;
      color: #333;
      margin-bottom: 8px;
      transition: color 0.3s ease;
    }

    .dashboard-card:hover .card-title {
      color: #667eea;
    }

    .card-description {
      color: #666;
      font-size: 14px;
      line-height: 1.5;
      transition: color 0.3s ease;
    }

    .dashboard-card:hover .card-description {
      color: #555;
    }

    /* Shimmer Effect on Hover */
    .dashboard-card::after {
      content: '';
      position: absolute;
      top: -50%;
      left: -50%;
      width: 200%;
      height: 200%;
      background: linear-gradient(45deg,
          transparent 30%,
          rgba(255, 255, 255, 0.3) 50%,
          transparent 70%);
      transform: translateX(-100%) translateY(-100%) rotate(45deg);
      transition: transform 0.6s ease;
      opacity: 0;
    }

    .dashboard-card:hover::after {
      transform: translateX(100%) translateY(100%) rotate(45deg);
      opacity: 1;
    }

    /* Enhanced Border Gradient on Hover */
    .dashboard-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      border-radius: 16px;
      padding: 2px;
      background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
      mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
      mask-composite: subtract;
      pointer-events: none;
      transition: all 0.3s ease;
    }

    .dashboard-card:hover::before {
      background: linear-gradient(135deg, rgba(102, 126, 234, 0.4), rgba(118, 75, 162, 0.4));
      padding: 3px;
    }

    /* Mobile Responsive */
    @media (max-width: 768px) {
      body {
        padding: 10px;
      }

      .header {
        flex-direction: column;
        gap: 20px;
        text-align: center;
      }

      .welcome-section {
        padding: 20px;
        text-align: center;
        flex-direction: column;
        gap: 20px;
      }

      .profile-image-container {
        width: 80px;
        height: 80px;
      }

      .welcome-title {
        font-size: 24px;
      }

      .dashboard-grid {
        grid-template-columns: 1fr;
        gap: 15px;
      }

      .dashboard-card {
        padding: 20px;
      }

      .user-nav {
        flex-direction: row;
        gap: 15px;
      }
    }

    @media (max-width: 480px) {
      .user-nav {
        flex-direction: column;
        gap: 10px;
      }

      .welcome-section {
        flex-direction: column;
      }

      .profile-image-container {
        width: 60px;
        height: 60px;
      }
    }

    /* Loading animation for smooth transitions */
    .dashboard-card {
      animation: fadeInUp 0.6s ease-out;
    }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(30px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .dashboard-card:nth-child(1) {
      animation-delay: 0.1s;
    }

    .dashboard-card:nth-child(2) {
      animation-delay: 0.2s;
    }

    .dashboard-card:nth-child(3) {
      animation-delay: 0.3s;
    }

    .dashboard-card:nth-child(4) {
      animation-delay: 0.4s;
    }
  </style>
</head>

<body>

  <div class="container">
    <!-- Header -->
    <header class="header">
      <div class="logo">
        <i class="fas fa-calendar-check"></i>
        Vacation Leave Management System
      </div>
      <nav class="user-nav">
        <a href="../auth/profile">
          <i class="fas fa-user"></i>
          Profile
        </a>
        <a href="../auth/logout">
          <i class="fas fa-sign-out-alt"></i>
          Logout
        </a>
      </nav>
    </header>

    <!-- Welcome Section -->
    <section class="welcome-section">
      <div class="profile-image-container">
        <?php if (!empty($user['profile_image']) && file_exists('../' . $user['profile_image'])): ?>
          <img src="../<?php echo htmlspecialchars($user['profile_image']); ?>"
            alt="Profile Picture"
            class="profile-image">
        <?php else: ?>
          <div class="profile-image-placeholder">
            <i class="fas fa-user"></i>
          </div>
        <?php endif; ?>
      </div>
      <div class="welcome-content">
        <h1 class="welcome-title">Welcome back, <?php echo htmlspecialchars($user['first_name']); ?>!</h1>
        <div class="user-badge">
          <i class="fas fa-user-tie"></i>
          <?php echo htmlspecialchars($role['name']); ?>
        </div>
        <p style="margin-top: 1rem; color: #666;">
          <?php echo htmlspecialchars($department); ?> / <?php echo htmlspecialchars($job_role); ?>
        </p>
        <p style="margin-top: 0.2rem; color: #666;">
          <i class="fa fa-id-card" aria-hidden="true" style="color: #798879ff;"></i>
          <small><?php echo htmlspecialchars($user['employee_id']); ?></small>
        </p>
      </div>
    </section>

    <!-- Dashboard Grid -->
    <div class="dashboard-grid">
      <!-- New Leave Request -->
      <a href="../employee/leave_request" class="dashboard-card">
        <div class="card-icon primary">
          <i class="fas fa-plus"></i>
        </div>
        <h3 class="card-title">New Leave Request</h3>
        <p class="card-description">Submit a new leave request for approval</p>
      </a>

      <!-- My Requests -->
      <a href="../employee/my_requests" class="dashboard-card">
        <div class="card-icon success">
          <i class="fas fa-list"></i>
        </div>
        <h3 class="card-title">My Requests</h3>
        <p class="card-description">View and manage your leave requests</p>
      </a>

      <!-- Pending Approvals -->
      <a href="../employee/pending_requests" class="dashboard-card">
        <div class="card-icon warning">
          <i class="fas fa-clock"></i>
        </div>
        <h3 class="card-title">Pending Approvals</h3>
        <p class="card-description">Requests awaiting your approval</p>
      </a>

      <!-- Approval History -->
      <a href="../employee/approval_history" class="dashboard-card">
        <div class="card-icon info">
          <i class="fas fa-history"></i>
        </div>
        <h3 class="card-title">Approval History</h3>
        <p class="card-description">View your approval history and decisions</p>
      </a>
    </div>
  </div>

  <script>
    // Add smooth scrolling and interaction effects
    document.addEventListener('DOMContentLoaded', function() {
      // Add click animation to cards
      const cards = document.querySelectorAll('.dashboard-card');

      cards.forEach(card => {
        card.addEventListener('mousedown', function() {
          this.style.transform = 'translateY(-2px) scale(0.98)';
        });

        card.addEventListener('mouseup', function() {
          this.style.transform = 'translateY(-5px) scale(1)';
        });

        card.addEventListener('mouseleave', function() {
          this.style.transform = 'translateY(0) scale(1)';
        });
      });

      // Add loading state simulation
      const loadingCards = document.querySelectorAll('.dashboard-card');
      loadingCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';

        setTimeout(() => {
          card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
          card.style.opacity = '1';
          card.style.transform = 'translateY(0)';
        }, 100 * (index + 1));
      });
    });
  </script>

</body>

</html>