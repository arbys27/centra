</main>
</div>
<script src="<?php echo $base; ?>assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="<?php echo $base; ?>assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script src="<?php echo $base; ?>assets/js/notify.js"></script>
<script src="<?php echo $base; ?>assets/js/form-submit.js"></script>
<?php
$notification_flash = $_SESSION['notification_flash'] ?? null;
unset($_SESSION['notification_flash']);
if (is_array($notification_flash) && !empty($notification_flash['title'])):
    $notification_type = in_array($notification_flash['type'] ?? '', ['success', 'error', 'warning', 'info'], true)
        ? $notification_flash['type']
        : 'success';
?>
<script>
notify.<?php echo $notification_type; ?>(
	<?php echo json_encode((string)$notification_flash['title'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
	<?php echo json_encode((string)($notification_flash['detail'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
);
</script>
<?php endif; ?>
<?php if ($page_title === 'Students' || $page_title === 'Officers'): ?>
<script>
window.addEventListener('pageshow', function (event) {
	if (event.persisted) window.location.reload();
});
</script>
<?php endif; ?>
<script>
(function () {
	var logoutLink = document.getElementById('logoutLink');
	if (!logoutLink) return;
	logoutLink.addEventListener('click', async function (event) {
		event.preventDefault();
		if (logoutLink.dataset.confirming === 'true') return;
		logoutLink.dataset.confirming = 'true';
		try {
			var confirmed = await notify.confirm({
				title: 'Ready to log out?',
				text: 'Your current session will be ended and you will return to the login page.',
				confirmText: 'Log out',
				cancelText: 'Stay signed in',
				danger: true,
				focusTarget: logoutLink
			});
			if (confirmed) window.location.href = logoutLink.href;
		} finally {
			logoutLink.dataset.confirming = 'false';
		}
	});
}());
</script>
</body>
</html>
