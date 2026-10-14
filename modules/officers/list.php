<?php
require_once '../../includes/auth_check.php';
require_once '../../includes/pin_gate.php';
require_pin('officers');
require_once '../../config/db.php';
$officer_columns = $conn->query('SHOW COLUMNS FROM officers');
$has_birthday_column = false;
while ($column = $officer_columns->fetch_assoc()) {
    if ($column['Field'] === 'birthday') {
        $has_birthday_column = true;
        break;
    }
}
if (!$has_birthday_column) {
    $conn->query('ALTER TABLE officers ADD COLUMN birthday DATE NULL AFTER position');
}
$base = '../../';
$page_title = 'Officers';
$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_officer'])) {
    $oid = trim($_POST['officer_id']);
    $name = trim($_POST['full_name']);
    $position = trim($_POST['position']);
    $birthday = trim($_POST['birthday'] ?? '');
    $birthday_date = $birthday === '' ? null : DateTime::createFromFormat('!Y-m-d', $birthday);
    if ($birthday !== '' && (!$birthday_date || $birthday_date->format('Y-m-d') !== $birthday)) {
        $message = "error:Enter a valid birthday.";
    } elseif ($oid && $name && $position) {
        $birthday = $birthday_date ? $birthday : null;
        $stmt = $conn->prepare("INSERT INTO officers (officer_id, full_name, position, birthday) VALUES (?,?,?,?)");
        $stmt->bind_param("ssss", $oid, $name, $position, $birthday);
        if ($stmt->execute()) {
            $message = "success:Officer '$name' added.";
        } else {
            $message = "error:Could not add officer. ID may already exist. (" . $conn->error . ")";
        }
    } else {
        $message = "error:Please fill in all fields.";
    }
}

$res = $conn->query("SELECT * FROM officers ORDER BY date_registered DESC");
include '../../includes/header.php';
?>

<?php if ($message): list($type,$text) = explode(':', $message, 2); ?>
<div class="alert alert-<?php echo $type === 'success' ? 'success' : 'error'; ?>" role="<?php echo $type === 'success' ? 'status' : 'alert'; ?>"><?php echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="card">
  <h2>Add Officer</h2>
    <form method="POST">
      <label>Student Number</label>
      <input type="text" name="officer_id" required>
      <label>Full Name</label>
      <input type="text" name="full_name" required>
      <label>Position</label>
      <input type="text" name="position" placeholder="e.g. VP for Internal Affairs" required>
      <label>Birthday</label>
      <input type="date" name="birthday">
      <button class="btn" type="submit" name="add_officer" style="margin-top:14px;">+ Add Officer</button>
    </form>
</div>

<div class="card">
  <h2>CSC Officers (<?php echo $res->num_rows; ?>)</h2>
  <div class="table-wrap">
  <table>
    <tr><th>Student Number</th><th>Name</th><th>Position</th><th>Birthday</th><th>Registered</th></tr>
    <?php while ($row = $res->fetch_assoc()): ?>
    <tr>
      <td><?php echo htmlspecialchars($row['officer_id']); ?></td>
      <td><?php echo htmlspecialchars($row['full_name']); ?></td>
      <td><?php echo htmlspecialchars($row['position']); ?></td>
      <td><?php echo !empty($row['birthday']) ? date('M d, Y', strtotime($row['birthday'])) : '—'; ?></td>
      <td><?php echo date('M d, Y', strtotime($row['date_registered'])); ?></td>
    </tr>
    <?php endwhile; ?>
  </table>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>
