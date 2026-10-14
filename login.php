<?php
session_start();
require_once 'config/db.php';
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT id, username, password, full_name, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            header("Location: dashboard.php");
            exit;
        }
    }
    $error = "Invalid username or password.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in | CENTRA - 14th Central Student Council</title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/notifications.css">
<style>
	:root{
		--ink:#111;
		--ink-soft:#252525;
		--paper:#f7f7f4;
		--paper-2:#ecece8;
		--line:rgba(17,17,17,.12);
		--line-2:#d0d0cd;
		--muted:#666;
		--ease:cubic-bezier(.16,1,.3,1);
	}
	html,body{height:100%;}
	body.auth-body{margin:0;display:block;background:var(--paper);color:var(--ink);font-family:"Trebuchet MS","Segoe UI",sans-serif;-webkit-font-smoothing:antialiased;}
	.auth-body *,.auth-body *::before,.auth-body *::after{box-sizing:border-box;}

	.auth{min-height:100%;display:grid;grid-template-columns:minmax(380px,1.05fr) 1fr;}

	/* ---------- Brand panel ---------- */
	.auth-brand{position:relative;overflow:hidden;display:flex;flex-direction:column;justify-content:space-between;padding:3rem;background:var(--ink);color:#fff;isolation:isolate;}
	.auth-brand::before{content:"";position:absolute;inset:0;z-index:-1;background-image:linear-gradient(rgba(255,255,255,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.05) 1px,transparent 1px);background-size:44px 44px;mask-image:radial-gradient(ellipse at 20% 20%,#000 20%,transparent 75%);-webkit-mask-image:radial-gradient(ellipse at 20% 20%,#000 20%,transparent 75%);}
	.auth-brand::after{content:"";position:absolute;z-index:-1;right:-140px;bottom:-140px;width:420px;height:420px;border-radius:50%;border:1px solid rgba(255,255,255,.12);box-shadow:0 0 0 60px rgba(255,255,255,.025),0 0 0 120px rgba(255,255,255,.02);}
	.auth-home{display:inline-flex;align-items:center;gap:.5rem;width:fit-content;color:#bdbdbd;font-size:.8rem;font-weight:700;text-decoration:none;transition:color .3s var(--ease),transform .3s var(--ease);}
	.auth-home:hover{color:#fff;transform:translateX(-3px);}
	.auth-brand-main{display:flex;flex-direction:column;gap:1.4rem;}
	.auth-seal{width:84px;height:84px;object-fit:cover;border:4px solid #fff;border-radius:50%;box-shadow:0 18px 40px rgba(0,0,0,.4);}
	.auth-wordmark{margin:0;font-size:clamp(2.6rem,5vw,4.2rem);font-weight:800;letter-spacing:.14em;line-height:1;}
	.auth-tagline{max-width:34ch;margin:0;color:#bdbdbd;font-size:1.02rem;line-height:1.65;}
	.auth-org{display:flex;flex-direction:column;gap:.15rem;font-size:.8rem;color:#bdbdbd;}
	.auth-org strong{color:#fff;font-size:.86rem;}

	/* ---------- Form panel ---------- */
	.auth-panel{display:flex;align-items:center;justify-content:center;padding:2.5rem 1.5rem;background:radial-gradient(circle at 85% 10%,rgba(0,0,0,.06),transparent 22rem),var(--paper);}
	.auth-card{width:100%;max-width:400px;animation:rise .8s var(--ease) both;}
	.auth-mobile-brand{display:none;align-items:center;gap:.7rem;margin-bottom:2rem;}
	.auth-mobile-brand img{width:42px;height:42px;object-fit:cover;border:2px solid var(--ink);border-radius:50%;}
	.auth-mobile-brand strong{letter-spacing:.2em;font-size:1.1rem;}
	.auth-eyebrow{display:inline-flex;align-items:center;gap:.6rem;margin-bottom:.9rem;color:var(--muted);font-size:.7rem;letter-spacing:.16em;text-transform:uppercase;font-weight:700;}
	.auth-eyebrow::before{content:"";width:26px;height:2px;background:var(--ink);}
	.auth-card h1{margin:0 0 .5rem;font-size:2.1rem;line-height:1.05;letter-spacing:-.05em;font-weight:800;}
	.auth-card .auth-sub{margin:0 0 1.8rem;color:var(--muted);font-size:.93rem;line-height:1.6;}

	.auth-alert{display:flex;align-items:center;gap:.6rem;margin-bottom:1.2rem;padding:.75rem .95rem;background:#f6eeee;border:1px solid #e6cfcf;border-radius:12px;color:#7a2a2a;font-size:.86rem;font-weight:600;animation:shake .45s var(--ease);}
	.auth-alert svg{flex:none;}

	.auth-card .field{margin-bottom:1.1rem;}
	.auth-card label{display:block;margin:0 0 .45rem;font-size:.78rem;font-weight:700;color:var(--ink-soft);}
	.auth-card .input-wrap{position:relative;}
	.auth-card .input-wrap > svg.lead{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#9a9a96;pointer-events:none;transition:color .2s;}
	.auth-card input[type="text"],
	.auth-card input[type="password"]{display:block;width:100%;height:50px;margin:0;padding:0 46px 0 44px;font:inherit;font-size:.96rem;color:var(--ink);background:#fff;border:1px solid var(--line-2);border-radius:12px;outline:none;box-shadow:none;transition:border-color .2s,box-shadow .2s;}
	.auth-card input::placeholder{color:#a5a5a2;}
	.auth-card input:focus{border-color:var(--ink);box-shadow:0 0 0 3px rgba(17,17,17,.12);}
	.auth-card .input-wrap:focus-within > svg.lead{color:var(--ink);}
	.auth-card .toggle-pw{position:absolute;right:6px;top:50%;transform:translateY(-50%);width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;padding:0;background:transparent;border:0;border-radius:10px;color:#777;cursor:pointer;transition:background .2s,color .2s;}
	.auth-card .toggle-pw:hover{background:var(--paper-2);color:var(--ink);}
	.auth-card .toggle-pw:focus-visible{outline:2px solid var(--ink);outline-offset:1px;}

	.auth-card .auth-btn{display:flex;align-items:center;justify-content:center;gap:.6rem;width:100%;height:52px;margin-top:1.6rem;padding:0 1.2rem;font:inherit;font-size:.95rem;font-weight:700;color:#fff;background:var(--ink);border:1px solid var(--ink);border-radius:999px;cursor:pointer;transition:transform .35s var(--ease),background .35s var(--ease),box-shadow .35s var(--ease);}
	.auth-card .auth-btn:hover{background:#393939;transform:translateY(-2px);box-shadow:0 12px 26px rgba(17,17,17,.2);}
	.auth-card .auth-btn:active{transform:translateY(0);}
	.auth-card .auth-btn:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(17,17,17,.2);}
	.auth-card .auth-btn:disabled{opacity:.7;cursor:progress;transform:none;box-shadow:none;}
	.auth-card .auth-btn .spin{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;}
	.auth-card .auth-btn.loading .spin{display:inline-block;}

	.auth-foot{margin-top:2rem;padding-top:1.2rem;border-top:1px solid var(--line);color:var(--muted);font-size:.76rem;text-align:center;}

	@keyframes rise{from{opacity:0;transform:translateY(22px);}to{opacity:1;transform:none;}}
	@keyframes shake{0%,100%{transform:translateX(0);}20%{transform:translateX(-6px);}40%{transform:translateX(5px);}60%{transform:translateX(-3px);}80%{transform:translateX(2px);}}
	@keyframes spin{to{transform:rotate(360deg);}}

	@media(max-width:860px){
		.auth{grid-template-columns:1fr;}
		.auth-brand{display:none;}
		.auth-panel{min-height:100vh;padding:2rem 1.25rem;}
		.auth-mobile-brand{display:flex;}
	}
	@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important;}}
</style>
</head>
<body class="auth-body">
<main class="auth">
  <aside class="auth-brand" aria-hidden="false">
    <a class="auth-home" href="index.php">&#8592; Back to home</a>
    <div class="auth-brand-main">
      <img class="auth-seal" src="assets/img/629336028_1332709998878542_5484852480615400786_n.jpg" alt="14th Central Student Council seal">
      <h2 class="auth-wordmark">CENTRA</h2>
      <p class="auth-tagline">One calm, connected workspace for the everyday work of the council.</p>
    </div>
    <div class="auth-org"><strong>14th Central Student Council</strong><span>PUP Biñan Campus &middot; Office Management System</span></div>
  </aside>

  <section class="auth-panel">
    <div class="auth-card">
      <div class="auth-mobile-brand">
        <img src="assets/img/629336028_1332709998878542_5484852480615400786_n.jpg" alt="14th Central Student Council seal">
        <strong>CENTRA</strong>
      </div>
      <div class="auth-eyebrow">Officer access</div>
      <h1>Welcome back.</h1>
      <p class="auth-sub">Sign in to CENTRA to continue to the office system.</p>

      <?php if ($error): ?>
      <div class="auth-alert alert-error" role="alert">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <?php endif; ?>

      <form method="POST" id="loginForm">
        <div class="field">
          <label for="username">Username</label>
          <div class="input-wrap">
            <svg class="lead" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <input type="text" id="username" name="username" placeholder="Enter your username" autocomplete="username" required autofocus>
          </div>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <div class="input-wrap">
            <svg class="lead" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
            <button class="toggle-pw" type="button" id="togglePw" aria-label="Show password" aria-pressed="false">
              <svg id="eyeIcon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
        <button class="auth-btn" type="submit" id="loginBtn"><span class="spin" aria-hidden="true"></span><span id="loginLabel">Log In</span></button>
      </form>
      <div class="auth-foot">14th Central Student Council &middot; PUP Biñan Campus</div>
    </div>
  </section>
</main>
<script src="assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script src="assets/js/notify.js"></script>
<script>
(function () {
  'use strict';
  var pw = document.getElementById('password');
  var toggle = document.getElementById('togglePw');
  var eye = document.getElementById('eyeIcon');
  var shown = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
  var hidden = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
  toggle.addEventListener('click', function () {
    var show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    eye.innerHTML = show ? shown : hidden;
    toggle.setAttribute('aria-pressed', String(show));
    toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    pw.focus();
  });
  document.getElementById('loginForm').addEventListener('submit', function () {
    var btn = document.getElementById('loginBtn');
    btn.classList.add('loading');
    document.getElementById('loginLabel').textContent = 'Signing in…';
    setTimeout(function () { btn.disabled = true; }, 0);
  });
})();
</script>
</body>
</html>