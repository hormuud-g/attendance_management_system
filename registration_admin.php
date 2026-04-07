<?php
/*******************************************************************************************
 * HORMUUD UNIVERSITY - SUPER ADMIN REGISTRATION (FINAL LOGO FIXED VERSION)
 * Author: ChatGPT 2025 (No Remove)
 *******************************************************************************************/
session_start();
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/config/constants.php';

$message = '';
$success = false;

// ✅ Check if Super Admin exists
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='super_admin'");
$hasUser = $stmt->fetchColumn() > 0;

// ✅ Handle registration
if (!$hasUser && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $email      = trim($_POST['email']);
    $phone      = trim($_POST['phone']);
    $password   = $_POST['password'];
    $photoPath  = null;
    
    // ✅ Generate username: firstname_lastname (lowercase)
    $base_username = strtolower($first_name . '_' . $last_name);
    
    // ✅ Remove any special characters and spaces
    $base_username = preg_replace('/[^a-z0-9_]/', '', $base_username);
    
    // ✅ Start with the base username
    $username = $base_username;
    $counter = 1;

    // ✅ Password validation
    $passwordValid = preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,20}$/', $password);
    if (!$passwordValid) {
        $message = "❌ Password must be 8–20 characters and include uppercase, lowercase, number, and symbol.";
    } elseif (empty($first_name) || empty($last_name)) {
        $message = "❌ Please enter both first name and last name.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "❌ Please enter a valid email address.";
    } elseif (strlen($first_name) < 2) {
        $message = "❌ First name must be at least 2 characters long.";
    } elseif (strlen($last_name) < 2) {
        $message = "❌ Last name must be at least 2 characters long.";
    } else {
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $role = 'super_admin';

        // ✅ Upload Profile Photo
        $uploadDir = __DIR__ . '/upload/profiles/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $photoName = uniqid('profile_') . '_' . basename($_FILES['profile_photo']['name']);
        $targetPath = $uploadDir . $photoName;

        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetPath)) {
            $photoPath = 'upload/profiles/' . $photoName;
        }

        // ✅ Continue if no upload error
        if (empty($message)) {
            // ✅ Check if username already exists and find unique one
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username=?");
            $stmt->execute([$username]);
            
            // If username exists, add _1, _2, etc.
            while ($stmt->fetchColumn() > 0) {
                $username = $base_username . '_' . $counter;
                $counter++;
                $stmt->execute([$username]);
                
                // Safety check to prevent infinite loop
                if ($counter > 100) {
                    $message = "❌ Could not generate a unique username. Please try with different names.";
                    break;
                }
            }
            
            if (empty($message)) {
                // Check if email exists
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email=?");
                $stmt->execute([$email]);
                if ($stmt->fetchColumn() > 0) {
                    $message = "❌ Email already exists!";
                } else {
                    // ✅ Insert Super Admin
                    $user_uuid = uniqid('user_', true);
                    $sql = "INSERT INTO users (
                                user_uuid, username, first_name, last_name, email, phone_number, profile_photo_path,
                                password, password_plain, role, linked_id, linked_table,
                                last_login, status, created_at, updated_at
                            ) VALUES (
                                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, 'active', NOW(), NOW()
                            )";
                    $stmt = $pdo->prepare($sql);
                    if ($stmt->execute([
                        $user_uuid,
                        $username,
                        $first_name,
                        $last_name,
                        $email,
                        $phone,
                        $photoPath,
                        $hashedPassword,
                        $password,
                        $role
                    ])){
                      // ✅ Ku dar session si profile uu u muuqdo isla markiiba
                      $_SESSION['user'] = [
                          'user_id' => $pdo->lastInsertId(),
                          'user_uuid' => $user_uuid,
                          'username' => $username,
                          'first_name' => $first_name,
                          'last_name' => $last_name,
                          'email' => $email,
                          'phone_number' => $phone,
                          'profile_photo_path' => $photoPath,
                          'role' => $role,
                          'status' => 'active'
                      ];
                      $success = true;
                    } else {
                        $message = "⚠️ Error registering Super Admin.";
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Super Admin Registration | Hormuud University</title>

<!-- ✅ Favicon -->
<link rel="icon" type="image/png" href="images.png">

<!-- ✅ Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
:root {
  --hu-green: #00843D;
  --hu-blue: #0072CE;
  --hu-light-green: #00A651;
  --hu-light-gray: #F5F9F7;
  --hu-dark-gray: #333333;
  --hu-danger: #C62828;
  --hu-warning: #FFB400;
  --hu-white: #FFFFFF;
}

*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}
body{
  background:var(--hu-light-gray);
  color:var(--hu-dark-gray);
  display:flex;align-items:center;justify-content:center;
  height:100vh;
}
.container{
  display:flex;width:950px;max-width:95%;
  background:var(--hu-white);
  border-radius:12px;
  box-shadow:0 6px 20px rgba(0,0,0,0.15);
  overflow:hidden;
}
.left-panel{
  background:linear-gradient(135deg,var(--hu-blue),var(--hu-green));
  color:var(--hu-white);
  flex:1;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  padding:40px;
}
.left-panel img{
  width:130px;
  height:auto;
  margin-bottom:20px;
  background:#fff;
  padding:8px;
  border-radius:12px;
  box-shadow:0 0 15px rgba(255,255,255,0.4);
}
.left-panel h1{font-size:26px;font-weight:700;text-align:center;}
.left-panel p{margin-top:10px;opacity:0.9;text-align:center;}
.right-panel{flex:1.2;padding:40px 35px;background:var(--hu-white);}
.right-panel h2{font-size:24px;color:var(--hu-blue);margin-bottom:8px;}
.right-panel h3{font-size:16px;margin-bottom:25px;color:gray;}
.error-msg{
  background:#ffeaea;color:var(--hu-danger);
  padding:10px;border-radius:6px;margin-bottom:15px;
  text-align:center;font-weight:500;
}
.success-msg{
  background:#e8f5e9;color:var(--hu-green);
  padding:10px;border-radius:6px;margin-bottom:15px;
  text-align:center;font-weight:500;
}
form{display:flex;flex-direction:column;gap:15px;}
.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:15px;}
.full-width{grid-column:span 2;}
input[type=text],input[type=email],input[type=password],input[type=file]{
  padding:10px;border:1px solid #ccc;border-radius:6px;width:100%;
}
button{
  background:var(--hu-blue);color:var(--hu-white);
  border:none;padding:12px;border-radius:6px;
  font-size:16px;font-weight:600;cursor:pointer;transition:.3s;
}
button:hover{background:var(--hu-green);}
.upload-section{margin:10px 0;}
.upload-section label{font-weight:600;color:var(--hu-dark-gray);}
.password-meter{
  margin-top:-5px;margin-bottom:10px;display:flex;align-items:center;gap:10px;
}
.password-strength{
  height:8px;flex:1;border-radius:4px;background:#ddd;overflow:hidden;
}
.password-strength div{height:100%;transition:width .3s;}
#strengthLabel{font-size:13px;font-weight:600;}
.overlay{position:fixed;inset:0;background:rgba(0,0,0,0.4);}
.alert-center{
  position:fixed;top:50%;left:50%;
  transform:translate(-50%,-50%);
  background:var(--hu-white);
  padding:30px;border-radius:12px;
  box-shadow:0 4px 12px rgba(0,0,0,0.3);
  text-align:center;width:300px;
}
.circle{
  width:80px;height:80px;border-radius:50%;
  border:4px solid var(--hu-green);margin:0 auto;position:relative;
}
.checkmark{
  width:25px;height:50px;
  border-right:4px solid var(--hu-green);
  border-bottom:4px solid var(--hu-green);
  position:absolute;left:26px;top:10px;
  transform:rotate(45deg) scale(0);
  transition:transform .6s ease;
}
.circle.animate .checkmark{transform:rotate(45deg) scale(1);}
.name-info{
  font-size:12px;color:var(--hu-blue);margin-top:-10px;margin-bottom:10px;
  font-style:italic;text-align:center;
  padding:5px;background:#f0f8ff;border-radius:4px;
}
.generated-username{
  font-size:12px;color:var(--hu-green);margin-top:-8px;margin-bottom:10px;
  text-align:center;font-weight:600;
  padding:4px;background:#f0fff4;border-radius:4px;
  display:block;
}
.username-example{
  font-size:11px;color:#666;margin-top:2px;text-align:center;
}
.user-name-display{
  background:#f8f9fa;
  padding:12px;
  border-radius:6px;
  border-left:4px solid var(--hu-blue);
  margin:10px 0;
}
.user-name-display p{
  margin:5px 0;
  font-size:14px;
}
.user-name-display strong{
  color:var(--hu-blue);
}
.username-preview-box{
  background:#e8f5e9;
  border:2px dashed var(--hu-green);
  border-radius:6px;
  padding:10px;
  margin:10px 0;
  text-align:center;
}
.username-preview-box h4{
  color:var(--hu-green);
  margin-bottom:5px;
}
.username-preview-box .username-result{
  font-size:20px;
  font-weight:bold;
  color:var(--hu-dark-gray);
  letter-spacing:1px;
}
@media(max-width:768px){
  .container{flex-direction:column;height:auto;margin:20px;}
  .left-panel{padding:20px;}
  .form-grid{grid-template-columns:1fr;}
  .full-width{grid-column:span 1;}
}
</style>
</head>
<body>

<div class="container">
  <div class="left-panel">
      <!-- ✅ Local Logo (store file at /assets/img/hu_logo.png) -->
      <img src="images.png" alt="Hormuud University Logo">
      <h1>Hormuud University</h1>
      <p>Attendance Management System<br><strong>Super Administrator Registration</strong></p>
  </div>

  <div class="right-panel">
      <h2>Register Super Admin</h2>
      <h3>First-Time System Setup</h3>

      <?php if (!empty($message)): ?>
          <p class="error-msg"><?= htmlspecialchars($message); ?></p>
      <?php endif; ?>

      <?php if (!$hasUser && !$success): ?>
      <form method="POST" enctype="multipart/form-data" novalidate>
          <div class="form-grid">
              <input type="text" name="first_name" placeholder="First Name" required 
                     value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>"
                     oninput="updateFullNameDisplay()"
                     minlength="2">
              <input type="text" name="last_name" placeholder="Last Name" required 
                     value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>"
                     oninput="updateFullNameDisplay()"
                     minlength="2">
              
              <!-- ✅ عرض الاسم الكامل
              <div id="fullNameDisplay" class="user-name-display" style="grid-column: span 2; display: none;">
                  <p><strong>Full Name:</strong> <span id="displayFullName"></span></p>
              </div>
               -->
              <!-- ✅ عرض معاينة username
              <div id="usernamePreview" class="username-preview-box" style="grid-column: span 2;">
                  <h4>Your Username Will Be:</h4>
                  <div class="username-result" id="previewUsername">Enter your name above</div>
                  <p style="font-size: 12px; color: #666; margin-top: 5px;">
                      Format: firstname_lastname (lowercase)<br>
                      If taken, will add _1, _2, etc.
                  </p>
              </div> -->
              
              <input type="email" name="email" placeholder="Email Address" required 
                     value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
              <input type="text" name="phone" placeholder="Phone Number" required 
                     value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
              <input type="password" name="password" id="password" class="full-width" 
                     placeholder="Password (8–20 characters with uppercase, lowercase, number, symbol)" required>
          </div>

          <!-- ✅ Password strength meter -->
          <div class="password-meter">
              <div class="password-strength"><div id="strengthBar"></div></div>
              <span id="strengthLabel">Weak</span>
          </div>

          <div class="upload-section">
              <label for="profile_photo">Upload Profile Photo</label>
              <input type="file" name="profile_photo" id="profile_photo" accept="image/*" required>
          </div>

          <button type="submit">Register Super Admin</button>
      </form>

      <?php elseif ($hasUser && !$success): ?>
          <p class="error-msg">⚠️ Super Admin already exists. Registration disabled.</p>
          <p style="text-align:center;margin-top:10px;"><a href="login.php" style="color:var(--hu-blue);font-weight:600;">Go to Login</a></p>
      <?php endif; ?>
  </div>
</div>

<?php if ($success): ?>
<div class="overlay"></div>
<div class="alert-center success">
  <div class="circle"><div class="checkmark"></div></div>
  <p id="success-text" style="opacity:0;">✅ Registration Successful!<br>Redirecting to Login...</p>
</div>
<script>
setTimeout(()=>document.querySelector('.circle').classList.add('animate'),600);
setTimeout(()=>document.getElementById('success-text').style.opacity=1,1200);
setTimeout(()=>window.location.href="login.php",3000);
</script>
<?php endif; ?>

<script>
// ✅ Password Strength Meter (Official Colors)
const pwd=document.getElementById('password');
const bar=document.getElementById('strengthBar');
const label=document.getElementById('strengthLabel');

pwd.addEventListener('input',()=>{
  const val=pwd.value;
  let strength=0;
  if(val.match(/[a-z]/))strength+=1;
  if(val.match(/[A-Z]/))strength+=1;
  if(val.match(/\d/))strength+=1;
  if(val.match(/[\W_]/))strength+=1;
  if(val.length>=8)strength+=1;

  const width=(strength/5)*100;
  bar.style.width=width+'%';

  if(width<=40){
    bar.style.background='#C62828'; // Red
    label.textContent='Weak';
    label.style.color='#C62828';
  } else if(width<=70){
    bar.style.background='#FFB400'; // Amber
    label.textContent='Medium';
    label.style.color='#FFB400';
  } else {
    bar.style.background='#00A651'; // Light Green
    label.textContent='Strong';
    label.style.color='#00A651';
  }
});

// ✅ Function to update full name display
function updateFullNameDisplay() {
    const firstName = document.querySelector('input[name="first_name"]').value.trim();
    const lastName = document.querySelector('input[name="last_name"]').value.trim();
    const fullNameDisplay = document.getElementById('fullNameDisplay');
    const displayFullName = document.getElementById('displayFullName');
    const previewUsername = document.getElementById('previewUsername');
    const usernamePreview = document.getElementById('usernamePreview');
    
    if (firstName && lastName) {
        // عرض الاسم الكامل
        displayFullName.textContent = firstName + ' ' + lastName;
        fullNameDisplay.style.display = 'block';
        
        // توليد وعرض username
        const baseUsername = (firstName + '_' + lastName).toLowerCase();
        
        // إزالة أي أحرف خاصة
        const cleanUsername = baseUsername.replace(/[^a-z0-9_]/g, '');
        
        previewUsername.textContent = cleanUsername;
        previewUsername.style.color = '#00843D';
        usernamePreview.style.borderColor = '#00843D';
        
        // عرض مثال توضيحي
        if (firstName.toLowerCase() === 'abdi' && lastName.toLowerCase() === 'manoow') {
            previewUsername.innerHTML = 'abdi_manoow<br><small style="color:#666;font-size:12px;">(من: Abdi Manoow)</small>';
        } else if (firstName.toLowerCase() === 'ahmed' && lastName.toLowerCase() === 'mohamed') {
            previewUsername.innerHTML = 'ahmed_mohamed<br><small style="color:#666;font-size:12px;">(من: Ahmed Mohamed)</small>';
        }
    } else {
        fullNameDisplay.style.display = 'none';
        previewUsername.textContent = 'Enter your name above';
        previewUsername.style.color = '#666';
        usernamePreview.style.borderColor = '#ddd';
    }
}

// Initialize displays on page load
document.addEventListener('DOMContentLoaded', function() {
    updateFullNameDisplay();
    
    // إذا كان هناك أسماء مخزنة مسبقاً، عرضها
    const firstName = document.querySelector('input[name="first_name"]').value;
    const lastName = document.querySelector('input[name="last_name"]').value;
    if (firstName || lastName) {
        updateFullNameDisplay();
    }
});
</script>
</body>
</html>