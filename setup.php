<?php
// =====================================================================
// Run this file once in your browser after importing the database schema.
// It creates the initial administrator account when none exists.
// Delete this file afterwards, or keep it behind admin-only access.
// =====================================================================
require_once 'config/db.php';

$message = "";
$already = $conn->query("SELECT id FROM users WHERE username='admin'");

if ($already && $already->num_rows > 0) {
    $message = "An 'admin' account already exists. Setup already done — you can delete setup.php now.";
} else {
    $username = "admin";
    $password = "admin123";
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, 'CSC Administrator', 'Admin')");
    $stmt->bind_param("ss", $username, $hash);
    if ($stmt->execute()) {
        $message = "Admin account created successfully!<br><b>Username:</b> admin<br><b>Password:</b> admin123<br><br>Please log in and change this password, then delete setup.php for security.";
    } else {
        $message = "Error creating admin account: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>14th Central Student Council - PUP Binan Campus Setup</title>
<style>body{font-family:Arial,sans-serif;background:#f4f6f8;display:flex;height:100vh;align-items:center;justify-content:center;}
.box{background:#fff;padding:30px 40px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.1);max-width:480px;}
a{color:#7a1f2b;}</style></head>
<body>
<div class="box">
<h2>14th Central Student Council Setup</h2>
<p><?php echo $message; ?></p>
<p><a href="login.php">Go to Login</a></p>
</div>
</body>
</html>
