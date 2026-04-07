<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/db_connect.php');

if (empty($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user']['user_id'];
$user = $_SESSION['user'];

$message = '';
$type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  /* === PROFILE PHOTO UPDATE === */
  if (isset($_POST['update_photo'])) {
    if (!empty($_FILES['profile_photo']['name'])) {
      $upload_dir = __DIR__ . '/../uploads/profiles/';
      if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

      $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
      $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
      
      if (!in_array($ext, $allowed_ext)) {
        $message = "❌ Invalid file type. Please upload JPG, PNG, or GIF images.";
        $type = "error";
      } elseif ($_FILES['profile_photo']['size'] > 5 * 1024 * 1024) { // 5MB
        $message = "❌ File size too large. Maximum size is 5MB.";
        $type = "error";
      } else {
        $new_name = uniqid('user_') . '.' . $ext;
        $photo_path = 'uploads/profiles/' . $new_name;

        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], __DIR__ . '/../' . $photo_path)) {
          // Delete old photo if exists and not default
          $old_photo = $user['profile_photo_path'] ?? '';
          if ($old_photo && $old_photo !== 'uploads/profiles/default.png' && file_exists(__DIR__ . '/../' . $old_photo)) {
            unlink(__DIR__ . '/../' . $old_photo);
          }

          $stmt = $pdo->prepare("UPDATE users SET profile_photo_path = ? WHERE user_id = ?");
          if ($stmt->execute([$photo_path, $user_id])) {
            $_SESSION['user']['profile_photo_path'] = $photo_path;
            $message = "✅ Profile photo updated successfully!";
            $type = "success";
          } else {
            $message = "❌ Database error! Please try again.";
            $type = "error";
          }
        } else {
          $message = "❌ Upload failed! Please try again.";
          $type = "error";
        }
      }
    } else {
      $message = "⚠️ Please select a photo to upload!";
      $type = "error";
    }
  }

  /* === PROFILE INFO UPDATE === */
  if (isset($_POST['update_info'])) {
    $name  = trim($_POST['username']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone_number']);

    if ($email === '') {
      $message = "⚠️ Email is required!";
      $type = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $message = "❌ Please enter a valid email address!";
      $type = "error";
    } else {
      // Check if email already exists for another user
      $check_stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
      $check_stmt->execute([$email, $user_id]);
      
      if ($check_stmt->fetch()) {
        $message = "❌ This email is already registered by another user!";
        $type = "error";
      } else {
        $stmt = $pdo->prepare("UPDATE users SET email = ?, phone_number = ? WHERE user_id = ?");
        if ($stmt->execute([$email, $phone, $user_id])) {
          $_SESSION['user']['email'] = $email;
          $_SESSION['user']['phone_number'] = $phone;
          $message = "✅ Profile information updated successfully!";
          $type = "success";
        } else {
          $message = "❌ Database error! Please try again.";
          $type = "error";
        }
      }
    }
  }

  /* === PASSWORD CHANGE === */
  if (isset($_POST['change_password'])) {
    $old = $_POST['old_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !password_verify($old, $row['password'])) {
      $message = "❌ Incorrect current password!";
      $type = 'error';
    } elseif ($new !== $confirm) {
      $message = "⚠️ New passwords do not match!";
      $type = 'error';
    } elseif (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[@$!%*#?&]).{8,}$/', $new)) {
      $message = "⚠️ Password must be at least 8 characters long and include:<br>
                  - One uppercase letter (A–Z)<br>
                  - One lowercase letter (a–z)<br>
                  - One number (0–9)<br>
                  - One special character (@, $, #, !, %, &)";
      $type = 'error';
    } else {
      $hash = password_hash($new, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("UPDATE users SET password = ?, password_plain = ? WHERE user_id = ?");
      if ($stmt->execute([$hash, $new, $user_id])) {
        $message = "✅ Password changed successfully!";
        $type = 'success';
      } else {
        $message = "❌ Database error! Please try again.";
        $type = 'error';
      }
    }
  }
}

// Refresh user info
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Update session data
$_SESSION['user'] = array_merge($_SESSION['user'], $user);

$photo = !empty($user['profile_photo_path'])
  ? "../" . htmlspecialchars($user['profile_photo_path'], ENT_QUOTES, 'UTF-8')
  : "../uploads/profiles/default.png";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profile Settings | Hormuud University</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
  /* 🎨 Hormuud University Official Colors */
  --hu-green: #00843D;        /* 🟢 Primary Green */
  --hu-blue: #0072CE;         /* 🔵 Blue Accent */
  --hu-light-green: #00A651;  /* 💚 Light Green Accent */
  --hu-white: #FFFFFF;        /* ⚪ White (Surface) */
  --hu-bg: #F5F9F7;          /* 🩶 Light Gray (Background) */
  --hu-red: #C62828;          /* 🔴 Red (Danger) */
  --hu-amber: #FFB400;        /* 🟡 Amber (Warning) */
  --hu-dark-gray: #333333;    /* ⚫ Dark Gray (Text) */
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  background: var(--hu-bg);
  margin: 0;
  padding: 40px 20px;
  color: var(--hu-dark-gray);
  line-height: 1.6;
  min-height: 100vh;
}

/* Layout Wrapper */
.main-wrapper {
  display: flex;
  flex-direction: column;
  gap: 30px;
  align-items: center;
  justify-content: center;
  width: 100%;
  max-width: 1200px;
  margin: 0 auto;
}

/* Row: 1 (Photo + Info) */
.row-top {
  display: flex;
  justify-content: center;
  align-items: stretch;
  gap: 30px;
  flex-wrap: wrap;
  width: 100%;
}

/* Row: 2 (Password) */
.row-bottom {
  display: flex;
  justify-content: center;
  width: 100%;
}

/* Card Styles */
.card {
  background: var(--hu-white);
  padding: 30px;
  border-radius: 12px;
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
  transition: all 0.3s ease;
  flex: 1 1 420px;
  max-width: 500px;
  border: 1px solid #e8ecea;
  position: relative;
}

.card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: linear-gradient(90deg, var(--hu-blue), var(--hu-green));
  border-radius: 12px 12px 0 0;
}

.card:hover {
  transform: translateY(-5px);
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
}

.card h2 {
  text-align: center;
  color: var(--hu-dark-gray);
  margin-bottom: 25px;
  font-size: 22px;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
}

.card h2 i {
  color: var(--hu-blue);
  font-size: 20px;
}

/* Profile Photo */
.photo-container {
  text-align: center;
  margin-bottom: 25px;
  position: relative;
}

.profile-photo {
  width: 120px;
  height: 120px;
  border-radius: 50%;
  border: 4px solid var(--hu-blue);
  object-fit: cover;
  display: block;
  margin: 0 auto 15px auto;
  transition: all 0.3s ease;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
  background: var(--hu-bg);
}

.profile-photo:hover {
  transform: scale(1.05);
  border-color: var(--hu-green);
}

.file-input-wrapper {
  position: relative;
  display: inline-block;
  width: 100%;
  margin-bottom: 10px;
}

.file-input-wrapper input[type="file"] {
  position: absolute;
  left: 0;
  top: 0;
  opacity: 0;
  width: 100%;
  height: 100%;
  cursor: pointer;
}

.file-input-label {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 12px 20px;
  background: var(--hu-blue);
  color: var(--hu-white);
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.3s ease;
  font-weight: 600;
  font-size: 14px;
  width: 100%;
}

.file-input-label:hover {
  background: var(--hu-green);
  transform: translateY(-2px);
}

.file-input-label i {
  font-size: 16px;
}

/* Form Styles */
form {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.input-group {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.input-label {
  font-size: 14px;
  font-weight: 600;
  color: #666666;
  display: flex;
  align-items: center;
  gap: 6px;
}

.input-label i {
  color: var(--hu-blue);
  font-size: 14px;
  width: 16px;
}

input {
  padding: 12px 16px;
  border: 2px solid #d1d5db;
  border-radius: 8px;
  font-size: 15px;
  transition: all 0.3s ease;
  width: 100%;
  background: var(--hu-white);
  font-family: inherit;
}

input:focus {
  border-color: var(--hu-blue);
  box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
  outline: none;
}

input[readonly] {
  background: var(--hu-bg);
  cursor: not-allowed;
  color: #666666;
  border-color: #d1d5db;
}

input[readonly]:focus {
  border-color: #d1d5db;
  box-shadow: none;
}

/* Button Styles */
button {
  background: var(--hu-blue);
  color: var(--hu-white);
  border: none;
  padding: 14px 0;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  font-size: 16px;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  margin-top: 10px;
  font-family: inherit;
}

button:hover {
  background: var(--hu-green);
  transform: translateY(-2px);
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

button:active {
  transform: translateY(0);
}

/* Password Strength Meter */
.password-strength {
  margin-top: 5px;
}

.strength-meter {
  height: 6px;
  border-radius: 3px;
  background: #d1d5db;
  overflow: hidden;
  margin-bottom: 8px;
}

.strength-meter-fill {
  height: 100%;
  border-radius: 3px;
  transition: all 0.3s ease;
  width: 0%;
}

.strength-text {
  font-size: 13px;
  font-weight: 600;
  margin-top: 2px;
}

.match-text {
  font-size: 13px;
  font-weight: 600;
  margin-top: 5px;
  display: flex;
  align-items: center;
  gap: 6px;
}

/* Alert Styles */
.alert {
  position: fixed;
  top: 20px;
  left: 50%;
  transform: translateX(-50%);
  background: var(--hu-white);
  padding: 16px 24px;
  border-radius: 8px;
  font-size: 14px;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
  display: none;
  z-index: 10000;
  animation: slideInDown 0.5s ease;
  border-left: 4px solid;
  max-width: 500px;
  width: 90%;
  border: 1px solid #d1d5db;
}

.alert.success {
  border-left-color: var(--hu-green);
  color: var(--hu-green);
}

.alert.error {
  border-left-color: var(--hu-red);
  color: var(--hu-red);
}

.alert.warning {
  border-left-color: var(--hu-amber);
  color: var(--hu-amber);
}

.alert i {
  margin-right: 8px;
  font-size: 16px;
}

/* Animations */
@keyframes slideInDown {
  from {
    opacity: 0;
    transform: translateX(-50%) translateY(-20px);
  }
  to {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
  }
}

/* Responsive Design */
@media (max-width: 768px) {
  body {
    padding: 20px 15px;
  }
  
  .main-wrapper {
    gap: 20px;
  }
  
  .row-top {
    gap: 20px;
  }
  
  .card {
    padding: 25px 20px;
    flex: 1 1 100%;
  }
  
  .card h2 {
    font-size: 20px;
  }
  
  .profile-photo {
    width: 100px;
    height: 100px;
  }
  
  input {
    padding: 12px 14px;
  }
  
  button {
    padding: 14px 0;
    font-size: 15px;
  }
}

@media (max-width: 480px) {
  .card {
    padding: 20px 15px;
  }
  
  .card h2 {
    font-size: 18px;
  }
  
  .profile-photo {
    width: 80px;
    height: 80px;
  }
}

/* Utility Classes */
.text-center { text-align: center; }
.mt-10 { margin-top: 10px; }

/* Password Requirements */
.password-requirements {
  background: var(--hu-bg);
  padding: 15px;
  border-radius: 8px;
  margin: 15px 0;
  border-left: 3px solid var(--hu-blue);
}

.password-requirements h4 {
  margin-bottom: 10px;
  color: var(--hu-dark-gray);
  font-size: 14px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.password-requirements h4 i {
  color: var(--hu-blue);
}

.password-requirements ul {
  list-style: none;
  padding: 0;
  margin: 0;
}

.password-requirements li {
  font-size: 12px;
  color: #666666;
  margin-bottom: 4px;
  display: flex;
  align-items: center;
  gap: 6px;
  transition: all 0.3s ease;
}

.password-requirements li.valid {
  color: var(--hu-green);
}

.password-requirements li.invalid {
  color: #666666;
}

.password-requirements li i {
  font-size: 10px;
  width: 12px;
}

/* File Info */
.file-info {
  font-size: 12px;
  color: #666666;
  text-align: center;
  margin-top: 8px;
}

/* User Info Display */
.user-info-display {
  background: var(--hu-bg);
  padding: 15px;
  border-radius: 8px;
  margin-bottom: 20px;
  border-left: 4px solid var(--hu-blue);
}

.user-info-display h3 {
  color: var(--hu-blue);
  margin-bottom: 10px;
  font-size: 16px;
}

.info-row {
  display: flex;
  justify-content: space-between;
  margin-bottom: 8px;
  font-size: 14px;
}

.info-label {
  font-weight: 600;
  color: var(--hu-dark-gray);
}

.info-value {
  color: #666666;
}

/* Loading States */
.loading {
  opacity: 0.7;
  pointer-events: none;
}

.loading-spinner {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
</style>
</head>

<body>

<div class="main-wrapper">

  <!-- User Information Display -->
  <!-- <div class="card" style="max-width: 800px; grid-column: 1 / -1;">
    <h2><i class="fas fa-user"></i> User Information</h2>
    <div class="user-info-display">
      <div class="info-row">
        <span class="info-label">Username:</span>
        <span class="info-value"><?= htmlspecialchars($user['username'] ?? '') ?></span>
      </div>
      <div class="info-row">
        <span class="info-label">Email:</span>
        <span class="info-value"><?= htmlspecialchars($user['email'] ?? '') ?></span>
      </div>
      <div class="info-row">
        <span class="info-label">Phone:</span>
        <span class="info-value"><?= htmlspecialchars($user['phone_number'] ?? 'Not provided') ?></span>
      </div>
      <div class="info-row">
        <span class="info-label">Role:</span>
        <span class="info-value"><?= htmlspecialchars($user['role'] ?? '') ?></span>
      </div>
      <div class="info-row">
        <span class="info-label">Status:</span>
        <span class="info-value" style="color: <?= ($user['status'] ?? '') === 'active' ? 'var(--hu-green)' : 'var(--hu-red)' ?>;">
          <?= ucfirst($user['status'] ?? '') ?>
        </span>
      </div>
    </div>
  </div> -->

  <!-- ROW 1: Update Photo + Edit Info -->
  <div class="row-top">
    <div class="card">
      <h2><i class="fas fa-camera"></i> Update Photo</h2>
      <form method="POST" enctype="multipart/form-data" id="photoForm">
        <input type="hidden" name="update_photo" value="1">
        <div class="photo-container">
          <img src="<?= $photo ?>" class="profile-photo" id="preview" alt="Profile Photo" 
               onerror="this.src='../uploads/profiles/default.png'">
          <div class="file-input-wrapper">
            <input type="file" name="profile_photo" accept="image/*" onchange="previewImage(event)" id="photoInput">
            <label for="photoInput" class="file-input-label">
              <i class="fas fa-upload"></i> Choose Photo
            </label>
          </div>
          <div class="file-info">
            <small>Max size: 5MB • Formats: JPG, PNG, GIF</small>
          </div>
        </div>
        <button type="submit" name="update_photo_btn">
          <i class="fas fa-save"></i> Save Photo
        </button>
      </form>
    </div>

    <div class="card">
      <h2><i class="fas fa-user-edit"></i> Edit Information</h2>
      <form method="POST" id="infoForm">
        <input type="hidden" name="update_info" value="1">
        <div class="input-group">
          <label class="input-label"><i class="fas fa-user"></i> Username</label>
          <input type="text" name="username" value="<?= htmlspecialchars($user['username'] ?? '') ?>" placeholder="Username" readonly>
        </div>
        
        <div class="input-group">
          <label class="input-label"><i class="fas fa-envelope"></i> Email Address</label>
          <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="Email Address" required>
        </div>
        
        <div class="input-group">
          <label class="input-label"><i class="fas fa-phone"></i> Phone Number</label>
          <input type="text" name="phone_number" value="<?= htmlspecialchars($user['phone_number'] ?? '') ?>" placeholder="Phone Number">
        </div>
        
        <button type="submit" name="update_info_btn">
          <i class="fas fa-sync-alt"></i> Update Information
        </button>
      </form>
    </div>
  </div>

  <!-- ROW 2: Change Password -->
  <div class="row-bottom">
    <div class="card" style="max-width:650px;">
      <h2><i class="fas fa-lock"></i> Change Password</h2>
      
      <div class="password-requirements">
        <h4><i class="fas fa-info-circle"></i> Password Requirements</h4>
        <ul>
          <li id="req-length" class="invalid"><i class="fas fa-circle"></i> At least 8 characters</li>
          <li id="req-upper" class="invalid"><i class="fas fa-circle"></i> One uppercase letter</li>
          <li id="req-lower" class="invalid"><i class="fas fa-circle"></i> One lowercase letter</li>
          <li id="req-number" class="invalid"><i class="fas fa-circle"></i> One number</li>
          <li id="req-special" class="invalid"><i class="fas fa-circle"></i> One special character</li>
        </ul>
      </div>
      
      <form method="POST" id="passwordForm">
        <input type="hidden" name="change_password" value="1">
        <div class="input-group">
          <label class="input-label"><i class="fas fa-key"></i> Current Password</label>
          <input type="password" id="old_password" name="old_password" placeholder="Enter current password" required>
        </div>
        
        <div class="input-group">
          <label class="input-label"><i class="fas fa-lock"></i> New Password</label>
          <input type="password" id="new_password" name="new_password" placeholder="Enter new password" required 
                 oninput="checkPasswordRequirements(this.value); checkMatch();">
          <div class="password-strength">
            <div class="strength-meter">
              <div class="strength-meter-fill" id="strengthMeter"></div>
            </div>
            <div class="strength-text" id="strengthText">Password strength: None</div>
          </div>
        </div>
        
        <div class="input-group">
          <label class="input-label"><i class="fas fa-lock"></i> Confirm Password</label>
          <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required 
                 oninput="checkMatch()">
          <div class="match-text" id="matchText"></div>
        </div>
        
        <button type="submit" name="change_password_btn">
          <i class="fas fa-shield-alt"></i> Update Password
        </button>
      </form>
    </div>
  </div>

</div>

<?php if($message): ?>
<div class="alert <?= $type ?>" id="alertBox">
  <i class="fas fa-<?= $type === 'success' ? 'check-circle' : ($type === 'error' ? 'exclamation-circle' : 'exclamation-triangle') ?>"></i>
  <?= $message ?>
</div>
<script>
window.scrollTo({ top: 0, behavior: 'smooth' });
const alertBox = document.getElementById('alertBox');
alertBox.style.display = 'block';
setTimeout(() => {
  alertBox.style.display = 'none';
  // Refresh page after success to show updated data
  <?php if($type === 'success'): ?>
  setTimeout(() => { window.location.reload(); }, 1000);
  <?php endif; ?>
}, 5000);

<?php if($type === 'success' && strpos($message, 'Password') !== false): ?>
setTimeout(() => { window.location.href = '../logout.php'; }, 3000);
<?php endif; ?>
</script>
<?php endif; ?>

<script>
function previewImage(event) {
  const file = event.target.files[0];
  if (file) {
    const reader = new FileReader();
    reader.onload = function(e) {
      document.getElementById('preview').src = e.target.result;
    }
    reader.readAsDataURL(file);
    
    // Update file input label
    const label = document.querySelector('.file-input-label');
    label.innerHTML = `<i class="fas fa-check"></i> Photo Selected`;
    label.style.background = 'var(--hu-green)';
  }
}

function checkPasswordRequirements(password) {
  const requirements = {
    length: password.length >= 8,
    upper: /[A-Z]/.test(password),
    lower: /[a-z]/.test(password),
    number: /\d/.test(password),
    special: /[@$!%*#?&]/.test(password)
  };

  // Update requirement indicators
  Object.keys(requirements).forEach(req => {
    const element = document.getElementById(`req-${req}`);
    if (requirements[req]) {
      element.classList.remove('invalid');
      element.classList.add('valid');
      element.innerHTML = `<i class="fas fa-check-circle"></i> ${element.textContent.replace('•', '').trim()}`;
    } else {
      element.classList.remove('valid');
      element.classList.add('invalid');
      element.innerHTML = `<i class="fas fa-circle"></i> ${element.textContent.replace('•', '').trim()}`;
    }
  });

  // Calculate strength
  const strength = Object.values(requirements).filter(Boolean).length;
  const meter = document.getElementById('strengthMeter');
  const text = document.getElementById('strengthText');
  
  let color, label, width;
  switch (strength) {
    case 0:
    case 1:
      color = '#C62828'; width = '20%'; label = 'Very Weak'; break;
    case 2:
      color = '#FF5722'; width = '40%'; label = 'Weak'; break;
    case 3:
      color = '#FFB400'; width = '60%'; label = 'Medium'; break;
    case 4:
      color = '#4CAF50'; width = '80%'; label = 'Strong'; break;
    case 5:
      color = '#00843D'; width = '100%'; label = 'Very Strong'; break;
  }
  
  meter.style.width = width;
  meter.style.background = color;
  text.textContent = `Password strength: ${label}`;
  text.style.color = color;
}

function checkMatch() {
  const newPass = document.getElementById("new_password").value;
  const confirmPass = document.getElementById("confirm_password").value;
  const matchText = document.getElementById("matchText");
  
  if (confirmPass.length === 0) {
    matchText.textContent = "";
    matchText.innerHTML = "";
    return;
  }
  
  if (newPass === confirmPass) {
    matchText.innerHTML = `<i class="fas fa-check-circle"></i> Passwords match!`;
    matchText.style.color = "#00843D";
  } else {
    matchText.innerHTML = `<i class="fas fa-times-circle"></i> Passwords do not match!`;
    matchText.style.color = "#C62828";
  }
}

// Form submission handlers
document.getElementById('photoForm').addEventListener('submit', function(e) {
  const fileInput = document.getElementById('photoInput');
  if (!fileInput.files[0]) {
    e.preventDefault();
    showCustomAlert('Please select a photo to upload!', 'error');
    return;
  }
  
  // Add loading state
  const button = this.querySelector('button[type="submit"]');
  const originalText = button.innerHTML;
  button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
  button.disabled = true;
  
  // Re-enable after 10 seconds if still processing
  setTimeout(() => {
    button.innerHTML = originalText;
    button.disabled = false;
  }, 10000);
});

document.getElementById('infoForm').addEventListener('submit', function(e) {
  const email = document.querySelector('input[name="email"]').value;
  if (!email) {
    e.preventDefault();
    showCustomAlert('Email address is required!', 'error');
    return;
  }
  
  // Add loading state
  const button = this.querySelector('button[type="submit"]');
  const originalText = button.innerHTML;
  button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
  button.disabled = true;
  
  // Re-enable after 10 seconds if still processing
  setTimeout(() => {
    button.innerHTML = originalText;
    button.disabled = false;
  }, 10000);
});

document.getElementById('passwordForm').addEventListener('submit', function(e) {
  const newPass = document.getElementById("new_password").value;
  const confirmPass = document.getElementById("confirm_password").value;
  
  if (newPass !== confirmPass) {
    e.preventDefault();
    showCustomAlert('Passwords do not match!', 'error');
    return;
  }
  
  // Add loading state
  const button = this.querySelector('button[type="submit"]');
  const originalText = button.innerHTML;
  button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
  button.disabled = true;
  
  // Re-enable after 10 seconds if still processing
  setTimeout(() => {
    button.innerHTML = originalText;
    button.disabled = false;
  }, 10000);
});

function showCustomAlert(message, type) {
  const alertBox = document.createElement('div');
  alertBox.className = `alert ${type}`;
  alertBox.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
  document.body.appendChild(alertBox);
  
  setTimeout(() => {
    alertBox.style.display = 'block';
    setTimeout(() => {
      alertBox.remove();
    }, 5000);
  }, 100);
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
  // Check initial password match if fields are pre-filled
  checkMatch();
  
  // Handle image loading errors
  document.getElementById('preview').addEventListener('error', function() {
    this.src = '../uploads/profiles/default.png';
  });
  
  // Clear file input when page loads
  document.getElementById('photoInput').value = '';
});

// Prevent form resubmission on page refresh
if (window.history.replaceState) {
  window.history.replaceState(null, null, window.location.href);
}
</script>

</body>
</html>