<?php
/*******************************************************************************************
 * HORMUUD UNIVERSITY — UNIVERSAL LOGIN (FINAL POLISHED VERSION)
 * Author: ChatGPT 2025
 * ✅ Supports: Parent, Student, Teacher, Department, Faculty, Campus, Super Admin
 * ✅ Features:
 *   - Secure login + session handling
 *   - Role-based redirection
 *   - Password strength indicator
 *   - Centered Verify Student popup (only on click)
 *   - Auto-hide alerts (5s)
 *   - Full responsive design
 *******************************************************************************************/

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/includes/audit_helper.php';
require_once __DIR__ . '/config/constants.php';

$error = '';
$rowCount = 0;

try {
    $stmtCheck = $pdo->query("SELECT COUNT(*) AS total FROM users");
    $rowCount = (int)$stmtCheck->fetch(PDO::FETCH_ASSOC)['total'];
} catch (Exception $e) {
    $error = "❌ Database connection error.";
}

// ✅ handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($input) && !empty($password)) {
        $stmt = $pdo->prepare("
            SELECT u.*, s.reg_no
            FROM users u
            LEFT JOIN students s ON u.linked_id = s.student_id AND u.linked_table='student'
            WHERE u.email=? OR u.username=? OR u.phone_number=? OR s.reg_no=? LIMIT 1
        ");
        $stmt->execute([$input, $input, $input, $input]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if (strtolower($user['status']) !== 'active') {
                $error = "⚠️ Your account is not active. Please contact the administrator.";
            } elseif (password_verify($password, $user['password'])) {
                $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id=?")->execute([$user['user_id']]);
                session_regenerate_id(true);

                $_SESSION['user'] = [
                  'user_id' => $user['user_id'],
                  'username' => $user['username'],
                  'email' => $user['email'],
                  'role' => strtolower($user['role']),
                  'status' => $user['status'],
                  'linked_id' => $user['linked_id'],
                  'linked_table' => strtolower($user['linked_table']),
                  'phone_number' => $user['phone_number'] ?? null,
                  'profile_photo_path' => $user['profile_photo_path'] ?? null // ✅ saxitaan muhiim ah
              ];
              

                add_audit_log($pdo, $user['user_id'], 'login', 'User logged into the system');

                switch (strtolower($user['role'])) {
                    case 'super_admin': header("Location: super_admin/dashboard.php"); break;
                    case 'campus_admin': header("Location: campus_admin/dashboard.php"); break;
                    case 'faculty_admin': header("Location: faculty_admin/dashboard.php"); break;
                    case 'department_admin': header("Location: department_admin/dashboard.php"); break;
                    case 'teacher': header("Location: teacher/dashboard.php"); break;
                    case 'student': header("Location: student/dashboard.php"); break;
                    case 'parent': header("Location: parent/dashboard.php"); break;
                    default: header("Location: index.php"); break;
                }
                exit;
            } else {
                $error = "⚠️ Incorrect password!";
            }
        } else {
            $error = "❌ No user found with those credentials!";
        }
    } else {
        $error = "⚠️ Please enter both email/username and password!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login | Hormuud University Attendance System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="images.png">
<style>
:root {
  --hu-green:#00843D; --hu-blue:#0072CE; --hu-light-green:#00A651;
  --hu-dark-gray:#333; --hu-light-gray:#F5F9F7; --hu-danger:#C62828; --hu-white:#fff;
}
body{
  background:linear-gradient(135deg,var(--hu-light-gray),#E8F5E9);
  font-family:'Segoe UI',sans-serif;
  display:flex;justify-content:center;align-items:center;
  min-height:100vh;margin:0;color:var(--hu-dark-gray);
}
.container{width:100%;max-width:430px;padding:20px;}
.login-box{
  background:var(--hu-white);border-radius:18px;
  box-shadow:0 8px 25px rgba(0,0,0,0.12);
  padding:45px 30px;text-align:center;
  border-top:6px solid var(--hu-green);
  transition:.3s ease;
}
.login-box:hover{transform:translateY(-3px);box-shadow:0 10px 30px rgba(0,0,0,0.15);}
.logo{width:90px;margin-bottom:10px;}
h2{color:var(--hu-green);margin-top:5px;font-size:24px;font-weight:700;}
.subtitle{font-size:14px;color:#555;margin-bottom:25px;}
input{
  width:100%;padding:12px 14px;border-radius:8px;
  border:1px solid #ccc;font-size:14px;margin-bottom:12px;
  outline:none;transition:border-color .25s;
}
input:focus{border-color:var(--hu-blue);box-shadow:0 0 4px rgba(0,114,206,.3);}
.password-strength{
  height:6px;border-radius:4px;margin-bottom:8px;
  background:#ddd;overflow:hidden;
}
.password-strength div{
  height:100%;width:0%;transition:width .3s ease;
}
button{
  width:100%;padding:12px;border-radius:8px;
  border:none;font-weight:600;cursor:pointer;
  transition:background .3s,transform .2s;
}
button:active{transform:scale(0.97);}
.btn-primary{background:var(--hu-blue);color:#fff;}
.btn-primary:hover{background:var(--hu-light-green);}
.btn-secondary{background:var(--hu-dark-gray);color:#fff;}
.btn-secondary:hover{background:var(--hu-green);}
.divider{margin:18px 0;text-align:center;color:#999;font-size:13px;position:relative;}
.divider span{background:var(--hu-white);padding:0 10px;position:relative;z-index:1;}
.divider::before{content:'';position:absolute;left:0;top:50%;width:100%;height:1px;background:#ddd;}
.alert-center{
  position:fixed;top:50%;left:50%;
  transform:translate(-50%,-50%);
  background:var(--hu-white);border-left:6px solid var(--hu-danger);
  box-shadow:0 8px 25px rgba(0,0,0,0.25);
  padding:18px 25px;border-radius:10px;text-align:center;
  z-index:1000;width:90%;max-width:350px;
  font-size:15px;color:var(--hu-danger);
  animation:popIn .4s ease forwards;
}
/* === Modal Overlay (fullscreen background) === */
.modal {
  display: none;
  position: fixed;
  z-index: 1000;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background: rgba(0,0,0,0.45);
  justify-content: center;   /* horizontally center */
  align-items: center;       /* vertically center */
  transition: opacity 0.3s ease;
}

/* When modal is visible */
.modal.show {
  display: flex;
  animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

/* === Modal Box (the white popup) === */
.modal-content {
  background: #fff;
  padding: 35px 25px;
  border-radius: 18px;
  width: 90%;
  max-width: 420px;
  text-align: center;
  box-shadow: 0 10px 30px rgba(0,0,0,0.25);
  border-top: 6px solid var(--hu-green);
  animation: popUp 0.4s ease forwards;
  transform: scale(0.85);
  opacity: 0;
  position: relative;
}

/* Smooth pop-up animation */
@keyframes popUp {
  to { transform: scale(1); opacity: 1; }
}

/* === Close (X) button === */
.close {
  position: absolute;
  right: 18px;
  top: 12px;
  font-size: 26px;
  color: #666;
  cursor: pointer;
  transition: 0.3s;
}
.close:hover {
  color: var(--hu-danger);
  transform: scale(1.2);
}

/* === Inside Form === */
#studentForm {
  margin-top: 15px;
}
#studentForm input {
  width: 100%;
  padding: 12px 14px;
  border-radius: 8px;
  border: 1px solid #ccc;
  font-size: 14px;
  outline: none;
  margin-bottom: 10px;
  transition: border-color 0.25s, box-shadow 0.25s;
}
#studentForm input:focus {
  border-color: var(--hu-blue);
  box-shadow: 0 0 4px rgba(0,114,206,0.3);
}
#studentForm button {
  width: 100%;
  padding: 12px;
  border-radius: 8px;
  border: none;
  background: var(--hu-blue);
  color: #fff;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.3s, transform 0.2s;
}
#studentForm button:hover {
  background: var(--hu-light-green);
  transform: scale(1.03);
}

/* === Result Section === */
#result {
  margin-top: 15px;
  font-size: 14px;
  color: var(--hu-dark-gray);
}

/* === Responsive Adjustment === */
@media (max-width: 480px) {
  .modal-content {
    padding: 25px 18px;
    max-width: 95%;
  }
  .close {
    right: 12px;
    top: 8px;
  }
}

@keyframes popIn{from{opacity:0;transform:translate(-50%,-60%) scale(0.8);}
to{opacity:1;transform:translate(-50%,-50%) scale(1);}}

/* === Modal Popup (Centered) === */
.modal{
  display:none;position:fixed;z-index:1000;
  left:0;top:0;width:100%;height:100%;
  background:rgba(0,0,0,0.45);
  justify-content:center;align-items:center;
}
.modal.show{display:flex;animation:fadeIn .3s ease;}
@keyframes fadeIn{from{opacity:0;}to{opacity:1;}}
.modal-content{
  background:#fff;padding:30px;border-radius:16px;
  width:90%;max-width:400px;text-align:center;
  box-shadow:0 8px 25px rgba(0,0,0,0.25);
  border-top:6px solid var(--hu-green);
  transform:scale(0.85);opacity:0;
  animation:popModal .4s ease forwards;
}
@keyframes popModal{to{opacity:1;transform:scale(1);}}
.close{
  position:absolute;right:18px;top:12px;
  font-size:26px;color:#666;cursor:pointer;transition:.3s;
}
.close:hover{color:var(--hu-danger);transform:scale(1.2);}
#studentForm input{margin-bottom:10px;}
#result{margin-top:12px;font-size:14px;}
@media(max-width:480px){
  .modal-content{padding:22px;}
  h2{font-size:20px;}
}
</style>
</head>
<body>
<div class="container">
  <div class="login-box" id="loginBox">
    <img src="images.png" alt="Hormuud University Logo" class="logo">
    <h2>Welcome Back</h2>
    <p class="subtitle">Hormuud University | Attendance Management System</p>

    <form method="POST">
      <input type="text" name="email" placeholder="username" required>
      <input type="password" name="password" id="password" placeholder="Password" required>
      <div class="password-strength"><div id="strengthBar"></div></div>
      <button class="btn-primary" type="submit">Login</button>
    </form>

    <div class="divider"><span>OR</span></div>
    <button id="openSearch" class="btn-secondary">Verify Student</button>

    <?php if ($rowCount == 0): ?>
      <p class="register-text">Don’t have an account? <a href="registration_admin.php">Create Account</a></p>
    <?php endif; ?>
  </div>
</div>

<!-- ✅ Verify Student Modal -->
<div id="studentModal" class="modal">
  <div class="modal-content" id="verifyPopup">
    <span class="close">&times;</span>
    <h3 style="color:var(--hu-green);margin-bottom:10px;">🎓 Verify Student</h3>
    <form id="studentForm">
      <input type="text" id="reg_no" placeholder="Enter Student ID / Reg No" required>
      <button type="submit" class="btn-primary">Search</button>
    </form>
    <div id="result"></div>
  </div>
</div>

<?php if (!empty($error)): ?>
<div class="alert-center" id="alertBox"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<script>
// === Auto-hide alert (5 seconds max) ===
if(document.getElementById('alertBox')){
  setTimeout(()=>{
    const box=document.getElementById('alertBox');
    box.style.transition='opacity 0.6s ease';
    box.style.opacity='0';
    setTimeout(()=>box.remove(),600);
  },5000);
}

// === Password Strength Indicator ===
const password=document.getElementById('password');
const bar=document.getElementById('strengthBar');
password.addEventListener('input',()=>{
  const val=password.value;
  let strength=0;
  if(val.length>=8) strength++;
  if(/[A-Z]/.test(val)) strength++;
  if(/[0-9]/.test(val)) strength++;
  if(/[^A-Za-z0-9]/.test(val)) strength++;
  const width=strength*25;
  bar.style.width=width+'%';
  if(strength<=1) bar.style.background='red';
  else if(strength==2) bar.style.background='orange';
  else if(strength==3) bar.style.background='gold';
  else bar.style.background='limegreen';
});

// === Modal Popup Logic ===
const modal=document.getElementById('studentModal');
const openBtn=document.getElementById('openSearch');
const closeBtn=document.querySelector('.close');

openBtn.onclick=()=>{modal.classList.add('show');};
closeBtn.onclick=()=>{modal.classList.remove('show');};
window.onclick=(e)=>{if(e.target===modal){modal.classList.remove('show');}};

// === Verify Student AJAX ===
document.getElementById('studentForm').onsubmit=async(e)=>{
  e.preventDefault();
  const reg_no=document.getElementById('reg_no').value.trim();
  const result=document.getElementById('result');
  if(!reg_no){
    result.innerHTML="<p style='color:var(--hu-danger);font-weight:600;'>Please enter registration number.</p>";
    return;
  }
  result.innerHTML="<p style='color:var(--hu-blue);font-weight:600;'>⏳ Searching...</p>";
  try{
    const res=await fetch('search_user.php?reg_no='+encodeURIComponent(reg_no));
    const data=await res.text();
    result.innerHTML=data;
  }catch{
    result.innerHTML="<p style='color:var(--hu-danger);'>❌ Error loading data!</p>";
  }
};
</script>
</body>
</html>
