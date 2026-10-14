<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/pin_gate.php';

$area = $_GET['area'] ?? 'students';
if (!in_array($area, ['students', 'officers'], true)) {
    http_response_code(400);
    exit('Invalid PIN area.');
}
$pdo = get_pdo_connection();
$pin_exists = pin_setting($pdo, 'pin_hash') !== null;
$error = '';
$success = '';
$return_to = is_string($_GET['return'] ?? null) ? $_GET['return'] : '';
$session_key = 'pin_unlocked_' . $area;

function submitted_pin(string $key): string
{
    $value = $_POST[$key] ?? [];
    return is_array($value) ? implode('', array_map('strval', $value)) : (string)$value;
}

/** Renders one horizontal row of six single-digit inputs. */
function render_pin_row(string $name, string $label, bool $autofocus = false, bool $autosubmit = false): void
{
    $autocomplete = $name === 'pin' ? 'one-time-code' : 'off';
    echo '<div class="pin-field">';
    echo '<div class="pin-field-head"><span class="pin-label">' . htmlspecialchars($label) . '</span>';
    echo '<button type="button" class="pin-toggle" data-pin-toggle aria-pressed="false">Show</button></div>';
    echo '<div class="pin-row" role="group" aria-label="' . htmlspecialchars($label) . '" data-pin-boxes="' . htmlspecialchars($name) . '"'
        . ($autosubmit ? ' data-autosubmit="1"' : '') . '>';
    for ($i = 1; $i <= 6; $i++) {
        echo '<input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="' . $autocomplete . '"'
            . ' aria-label="' . htmlspecialchars($label) . ' digit ' . $i . '" required'
            . ($autofocus && $i === 1 ? ' autofocus' : '') . '>';
    }
    echo '</div></div>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'unlock';
    if ($action === 'create' && !$pin_exists) {
        $pin = submitted_pin('pin');
        $confirm = submitted_pin('confirm_pin');
        if (preg_match('/^\d{6}$/', $pin) !== 1 || $pin !== $confirm) {
            $error = 'Enter and confirm the same six-digit PIN.';
        } else {
            set_pin($pin);
            $_SESSION[$session_key] = time();
            $pin_exists = true;
            $success = 'PIN created and this area is unlocked.';
        }
    } elseif ($action === 'unlock' && $pin_exists) {
        if (verify_pin(submitted_pin('pin'))) {
            $_SESSION[$session_key] = time();
            $expected_path = $area === 'students' ? '/modules/students/' : '/modules/attendance/officers.php';
            $return_path = parse_url($return_to, PHP_URL_PATH);
            $redirect = substr($return_to, 0, 1) === '/' &&
                strpos($return_to, '//') !== 0 && strpos($return_to, '\\') === false &&
                is_string($return_path) && strpos($return_path, $expected_path) !== false
                ? $return_to
                : ($area === 'students' ? 'modules/students/list.php' : 'modules/attendance/officers.php');
            header('Location: ' . $redirect);
            exit;
        }
        $error = 'PIN is incorrect or temporarily locked.';
    }
}

$page_title = 'Secure Access';
$base = '';
include __DIR__ . '/includes/header.php';
?>
<style>
  /* Match these tokens to your theme if the fallbacks differ. */
  .pin-page {
    --pin-accent: var(--primary, var(--accent, #4f46e5));
    --pin-surface: var(--card-bg, var(--surface, #ffffff));
    --pin-text: var(--text, var(--text-primary, #1e293b));
    --pin-muted: var(--text-muted, var(--muted, #64748b));
    --pin-line: var(--border, rgba(100, 116, 139, .28));
    min-height: calc(100vh - 160px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
  }
  .pin-card {
    width: 100%;
    max-width: 420px;
    padding: 34px 28px 28px;
    border-radius: 20px;
    background: var(--pin-surface);
    color: var(--pin-text);
    border: 1px solid var(--pin-line);
    box-shadow: 0 20px 50px -24px rgba(15, 23, 42, .35);
  }
  .pin-icon {
    width: 52px; height: 52px;
    display: grid; place-items: center;
    margin: 0 auto 16px;
    border-radius: 16px;
    color: var(--pin-accent);
    background: color-mix(in srgb, var(--pin-accent) 14%, transparent);
  }
  .pin-icon svg { width: 26px; height: 26px; }
  .pin-card h2 { margin: 0 0 6px; text-align: center; font-size: 1.35rem; }
  .pin-card .pin-sub { margin: 0 0 24px; text-align: center; color: var(--pin-muted); font-size: .92rem; }

  .pin-field { margin-bottom: 20px; }
  .pin-field-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
  .pin-label { font-weight: 600; font-size: .9rem; }
  .pin-toggle {
    background: none; border: 0; padding: 4px 8px; border-radius: 8px;
    color: var(--pin-accent); font: inherit; font-size: .85rem; font-weight: 600; cursor: pointer;
  }
  .pin-toggle:hover { background: color-mix(in srgb, var(--pin-accent) 10%, transparent); }

  .pin-row { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; }
  .pin-row input {
    width: 100%; min-width: 0;
    aspect-ratio: 1 / 1.15;
    padding: 0;
    border: 1.5px solid var(--pin-line);
    border-radius: 14px;
    background: transparent;
    color: inherit;
    font: inherit; font-size: 1.5rem; font-weight: 600;
    text-align: center;
    outline: 0; box-shadow: none;
    transition: border-color .15s, box-shadow .15s, background-color .15s;
  }
  .pin-row input:focus {
    border-color: var(--pin-accent);
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--pin-accent) 18%, transparent);
  }
  .pin-row input.is-filled {
    border-color: color-mix(in srgb, var(--pin-accent) 55%, var(--pin-line));
    background: color-mix(in srgb, var(--pin-accent) 7%, transparent);
  }

  .pin-card .alert { margin-bottom: 18px; }

  @media (max-width: 380px) {
    .pin-card { padding: 28px 18px 22px; }
    .pin-row { gap: 6px; }
    .pin-row input { font-size: 1.25rem; border-radius: 10px; }
  }
  @media (prefers-reduced-motion: reduce) {
    .pin-row input { transition: none; }
  }
</style>

<div class="pin-page">
  <section class="pin-card card fade-in-up">
    <div class="pin-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10.5" width="16" height="10" rx="2.5"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/></svg>
    </div>
    <h2><?php echo $pin_exists ? 'Unlock ' . htmlspecialchars(ucfirst($area)) : 'Create your PIN'; ?></h2>
    <p class="pin-sub"><?php echo $pin_exists ? 'Enter your six-digit PIN. Access expires after 10 minutes of inactivity or when you leave this section.' : 'No PIN exists yet. Choose a six-digit PIN to protect this area.'; ?></p>

    <?php if ($error): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <?php if (!$pin_exists): ?>
    <form method="post">
      <input type="hidden" name="action" value="create">
      <?php render_pin_row('pin', 'New PIN', true); ?>
      <?php render_pin_row('confirm_pin', 'Confirm PIN'); ?>
      <button class="btn w-100" type="submit">Create PIN</button>
    </form>
    <?php else: ?>
    <form method="post" id="unlock-form">
      <input type="hidden" name="action" value="unlock">
      <?php render_pin_row('pin', 'PIN', true, true); ?>
      <button class="btn w-100" type="submit">Unlock</button>
    </form>
    <?php endif; ?>
  </section>
</div>

<script>
document.querySelectorAll('[data-pin-boxes]').forEach(function (group) {
  var boxes = Array.prototype.slice.call(group.querySelectorAll('input'));
  var toggle = group.parentNode.querySelector('[data-pin-toggle]');

  function sync() {
    boxes.forEach(function (b) { b.classList.toggle('is-filled', b.value !== ''); });
    if (group.hasAttribute('data-autosubmit') && boxes.every(function (b) { return b.value !== ''; })) {
      group.closest('form').requestSubmit();
    }
  }

  boxes.forEach(function (box, index) {
    box.name = group.getAttribute('data-pin-boxes') + '[]';
    box.addEventListener('focus', function () { box.select(); });
    box.addEventListener('input', function () {
      box.value = box.value.replace(/\D/g, '').slice(-1);
      if (box.value && boxes[index + 1]) boxes[index + 1].focus();
      sync();
    });
    box.addEventListener('keydown', function (event) {
      if (event.key === 'Backspace' && !box.value && boxes[index - 1]) {
        boxes[index - 1].value = '';
        boxes[index - 1].focus();
        sync();
      } else if (event.key === 'ArrowRight' && boxes[index + 1]) {
        event.preventDefault(); boxes[index + 1].focus();
      } else if (event.key === 'ArrowLeft' && boxes[index - 1]) {
        event.preventDefault(); boxes[index - 1].focus();
      }
    });
    box.addEventListener('paste', function (event) {
      var digits = (event.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, boxes.length);
      if (!digits) return;
      event.preventDefault();
      digits.split('').forEach(function (digit, offset) { boxes[offset].value = digit; });
      boxes[Math.min(digits.length, boxes.length) - 1].focus();
      sync();
    });
  });

  if (toggle) {
    toggle.addEventListener('click', function () {
      var show = toggle.getAttribute('aria-pressed') !== 'true';
      toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
      toggle.textContent = show ? 'Hide' : 'Show';
      boxes.forEach(function (b) { b.type = show ? 'text' : 'password'; });
    });
  }
  sync();
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>