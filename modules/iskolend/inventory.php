<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
require_once '../../includes/db.php';

$pdo = get_pdo_connection();
$is_ajax = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
$respond = static function (bool $success, string $message, int $status = 200, array $extra = []) use ($is_ajax): void {
    if ($is_ajax) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $payload = ['message' => $message] + $extra;
        if ($success) {
            $payload['success'] = true;
        } else {
            $payload['error'] = $message;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: inventory.php?' . ($success ? 'updated=1' : 'error=' . urlencode($message)));
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (empty($_SESSION['iskolend_csrf_token']) ||
        !hash_equals($_SESSION['iskolend_csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $respond(false, 'Your session has expired. Refresh the page and try again.', 403);
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add_category') {
        $name = trim((string)($_POST['category_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $respond(false, 'Enter a category name with no more than 100 characters.', 422);
        }
        try {
            $insert = $pdo->prepare('INSERT INTO item_categories (category_name) VALUES (?)');
            $insert->execute([$name]);
        } catch (PDOException $exception) {
            if ((string)$exception->getCode() === '23000') {
                $respond(false, 'That category already exists.', 409);
            }
            error_log('ISKOLEND category creation failed: ' . $exception->getMessage());
            $respond(false, 'Unable to save this category right now.', 500);
        }
        $respond(true, 'Category added.', 200, ['category' => ['id' => (int)$pdo->lastInsertId(), 'category_name' => $name]]);
    }

    if ($action === 'save_limits') {
        $max_units = filter_var($_POST['max_borrow_units'] ?? null, FILTER_VALIDATE_INT);
        if ($max_units === false || $max_units === null || $max_units < 1 || $max_units > 100) {
            $respond(false, 'The borrowing unit limit must be between 1 and 100.', 422);
        }
        $setting_update = $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $setting_update->execute(['MAX_ACTIVE_BORROWS', (string)$max_units]);
        $respond(true, 'Borrowing unit limit updated.');
    }

    $upload_image = static function (string $field_name, bool $required): ?string {
        $upload = $_FILES[$field_name] ?? null;
        if ($upload === null || $upload['error'] === UPLOAD_ERR_NO_FILE) {
            if ($required) {
                throw new InvalidArgumentException('Choose an image to upload.');
            }
            return null;
        }
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            $message = match ((int)$upload['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Image is too large. Maximum size is 2 MB.',
                UPLOAD_ERR_PARTIAL => 'The image upload was incomplete. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not receive the image upload.',
                default => 'Image upload failed. Please choose another image.',
            };
            throw new InvalidArgumentException($message);
        }
        if (!is_uploaded_file($upload['tmp_name']) || (int)$upload['size'] > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('Image is too large or is not a valid uploaded file. Maximum size is 2 MB.');
        }
        $extension = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
        $allowed = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ];
        if (!isset($allowed[$extension])) {
            throw new InvalidArgumentException('Only JPG, JPEG, PNG, and WEBP images are allowed.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($upload['tmp_name']);
        if ($mime_type !== $allowed[$extension]) {
            throw new InvalidArgumentException('The image contents do not match the selected JPG, PNG, or WEBP file type.');
        }
        if (@getimagesize($upload['tmp_name']) === false) {
            throw new InvalidArgumentException('The uploaded file is not a valid image.');
        }
        $mime_extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $safe_extension = $mime_extensions[$mime_type];
        $upload_dir = __DIR__ . '/../../uploads/items';
        if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
            throw new RuntimeException('The item image upload directory could not be created.');
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $safe_extension;
        if (!move_uploaded_file($upload['tmp_name'], $upload_dir . DIRECTORY_SEPARATOR . $filename)) {
            throw new RuntimeException('The image could not be saved. Check the uploads directory permissions.');
        }
        return 'uploads/items/' . $filename;
    };

    try {
        $item_name = trim((string)($_POST['item_name'] ?? ''));
        $category_id = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
        $total_qty = filter_var($_POST['total_qty'] ?? null, FILTER_VALIDATE_INT);
        $borrow_days = filter_var($_POST['borrow_days'] ?? null, FILTER_VALIDATE_INT);
        $description = trim((string)($_POST['description'] ?? ''));
        if ($item_name === '' || mb_strlen($item_name) > 150 || $category_id === false || $category_id === null || $category_id < 1 ||
            $total_qty === false || $total_qty === null || $total_qty < 0 || $borrow_days === false ||
            !in_array($borrow_days, [2, 3], true) || mb_strlen($description) > 2000) {
            throw new InvalidArgumentException('Enter a valid item name, category, quantity, borrow period, and description.');
        }
        $category_check = $pdo->prepare('SELECT COUNT(*) FROM item_categories WHERE id = ?');
        $category_check->execute([$category_id]);
        if ((int)$category_check->fetchColumn() !== 1) {
            throw new InvalidArgumentException('Choose a valid inventory category.');
        }

        if ($action === 'add_item') {
            $image_path = $upload_image('image_file', true);
            $insert = $pdo->prepare(
                'INSERT INTO iskolend_items
                    (item_name, category_id, image_path, description, total_qty, available_qty, borrow_days)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([$item_name, $category_id, $image_path, $description, $total_qty, $total_qty, $borrow_days]);
            $respond(true, 'Inventory item added.');
        }

        if ($action === 'edit_item') {
            $item_id = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
            if ($item_id === false || $item_id === null || $item_id < 1) {
                throw new InvalidArgumentException('Select a valid inventory item.');
            }
            $pdo->beginTransaction();
            $current_query = $pdo->prepare('SELECT total_qty, available_qty FROM iskolend_items WHERE id = ? FOR UPDATE');
            $current_query->execute([$item_id]);
            $current = $current_query->fetch();
            if ($current === false) {
                $pdo->rollBack();
                $respond(false, 'This inventory item no longer exists.', 404);
            }
            $out_quantity = (int)$current['total_qty'] - (int)$current['available_qty'];
            if ($total_qty < $out_quantity) {
                $pdo->rollBack();
                $respond(false, 'Total quantity cannot be lower than the quantity currently borrowed (' . $out_quantity . ').', 422);
            }
            $new_image_path = $upload_image('image_file', false);
            $available_qty = $total_qty - $out_quantity;
            if ($new_image_path === null) {
                $update = $pdo->prepare(
                    'UPDATE iskolend_items
                     SET item_name = ?, category_id = ?, description = ?, borrow_days = ?,
                         total_qty = ?, available_qty = ?
                     WHERE id = ?'
                );
                $update->execute([$item_name, $category_id, $description, $borrow_days, $total_qty, $available_qty, $item_id]);
            } else {
                $update = $pdo->prepare(
                    'UPDATE iskolend_items
                     SET item_name = ?, category_id = ?, image_path = ?, description = ?, borrow_days = ?,
                         total_qty = ?, available_qty = ?
                     WHERE id = ?'
                );
                $update->execute([$item_name, $category_id, $new_image_path, $description, $borrow_days, $total_qty, $available_qty, $item_id]);
            }
            $pdo->commit();
            $respond(true, 'Inventory item updated.');
        }

        if ($action === 'restock_item') {
            $item_id = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
            $restock_qty = filter_var($_POST['restock_qty'] ?? null, FILTER_VALIDATE_INT);
            if ($item_id === false || $item_id === null || $item_id < 1 ||
                $restock_qty === false || $restock_qty === null || $restock_qty < 1 || $restock_qty > 10000) {
                throw new InvalidArgumentException('Enter a valid restock quantity.');
            }
            $restock = $pdo->prepare(
                'UPDATE iskolend_items
                 SET total_qty = total_qty + ?, available_qty = available_qty + ?
                 WHERE id = ?'
            );
            $restock->execute([$restock_qty, $restock_qty, $item_id]);
            if ($restock->rowCount() !== 1) {
                $respond(false, 'This inventory item no longer exists.', 404);
            }
            $respond(true, 'Inventory restocked.');
        }
        $respond(false, 'Unsupported inventory action.', 400);
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $respond(false, $exception->getMessage(), 422);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('ISKOLEND inventory update failed: ' . $exception->getMessage());
        $respond(false, 'Unable to save this inventory change right now.', 500);
    }
}

if (empty($_SESSION['iskolend_csrf_token'])) {
    $_SESSION['iskolend_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['iskolend_csrf_token'];
$items = $pdo->query(
    'SELECT i.id, i.item_name, i.category_id, i.image_path, i.description,
            i.total_qty, i.available_qty, i.borrow_days, c.category_name
     FROM iskolend_items i LEFT JOIN item_categories c ON c.id = i.category_id
     ORDER BY c.category_name, i.item_name'
)->fetchAll();
$categories = $pdo->query('SELECT id, category_name FROM item_categories ORDER BY category_name')->fetchAll();
$max_borrow_units = get_iskolend_unit_limit($pdo);

/* Summary numbers for the stats strip */
$low_stock_threshold = 1;
$total_items = count($items);
$total_available = array_sum(array_map(static fn($i) => (int)$i['available_qty'], $items));
$low_count = count(array_filter($items, static fn($i) => (int)$i['available_qty'] <= $low_stock_threshold));

$base = '../../';
$page_title = 'ISKOLEND Inventory';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo $base; ?>assets/css/inventory.css">


<div class="iskolend-page inventory-page">
  <div class="card fade-in-up iskolend-nav">
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-outline" href="borrow.php">Borrow</a>
      <a class="btn btn-outline" href="return.php">Return</a>
      <a class="btn" href="inventory.php">Inventory</a>
      <a class="btn btn-outline" href="list.php">View Records</a>
    </div>
  </div>

  <section class="card fade-in-up">
    <div class="inventory-heading">
      <div><h2>ISKOLEND Inventory</h2><p class="text-secondary mb-0">Browse available CSC lending items by category.</p></div>
      <div class="inventory-heading-actions">
        
        <button type="button" class="btn btn-outline" id="exportInventoryPdf">Export PDF</button>
        <button type="button" class="btn" data-bs-toggle="modal" data-bs-target="#addItemModal">+ Add Item</button>
      </div>
    </div>

    <div class="inventory-stats">
      <div class="inventory-stat"><span class="inventory-stat-value"><?php echo $total_items; ?></span><span class="inventory-stat-label">Items</span></div>
      <div class="inventory-stat"><span class="inventory-stat-value"><?php echo $total_available; ?></span><span class="inventory-stat-label">Units available</span></div>
      <div class="inventory-stat <?php echo $low_count > 0 ? 'is-attention' : ''; ?>"><span class="inventory-stat-value"><?php echo $low_count; ?></span><span class="inventory-stat-label">Low or out of stock</span></div>
    </div>

    <div class="inventory-toolbar">
      <div class="inventory-category-tabs" id="inventoryCategoryTabs" role="tablist" aria-label="Filter inventory by category"></div>
      <div class="inventory-search"><label for="inventorySearch" class="form-label">Search</label><input class="form-control" type="search" id="inventorySearch" placeholder="Search name, category, or description"></div>
    </div>
    <div class="inventory-result-count text-secondary small mb-3" id="inventoryResultCount" aria-live="polite"></div>
    <div class="row g-3" id="inventoryGrid">
      <?php foreach ($items as $index => $item):
        $stock_percent = (int)$item['total_qty'] > 0 ? min(100, max(0, (int)round(100 * (int)$item['available_qty'] / (int)$item['total_qty']))) : 0;
        $avail = (int)$item['available_qty'];
        $state = $avail === 0 ? 'out' : ($avail <= $low_stock_threshold ? 'low' : 'in');
        $state_label = ['out' => 'Out of stock', 'low' => 'Low stock', 'in' => 'In stock'][$state];
      ?>
      <div class="col-12 col-sm-6 col-xl-4 inventory-grid-item"
           data-category="<?php echo (int)($item['category_id'] ?? 0); ?>"
           data-search="<?php echo htmlspecialchars(mb_strtolower($item['item_name'] . ' ' . ($item['category_name'] ?? '') . ' ' . ($item['description'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
           data-id="<?php echo (int)$item['id']; ?>"
           style="--inventory-delay: <?php echo min($index * 45, 450); ?>ms">
        <article class="inventory-item-card h-100 is-<?php echo $state; ?>">
          <div class="inventory-item-image-wrap">
              <?php
                $image_path = (string)($item['image_path'] ?? '');
                $image_source = $image_path === '' ? '../../assets/img/item-placeholder.svg' :
                    (preg_match('/^(https?:)?\/\//i', $image_path) || str_starts_with($image_path, '/') ? $image_path : '../../' . ltrim($image_path, './'));
              ?>
              <img src="<?php echo htmlspecialchars($image_source, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="inventory-item-image" loading="lazy" onerror="this.onerror=null;this.src='../../assets/img/item-placeholder.svg';">
            <span class="inventory-status is-<?php echo $state; ?>"><?php echo $state_label; ?></span>
          </div>
          <div class="inventory-item-body">
            <div class="inventory-item-title-row">
              <h3><?php echo htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
              <span class="badge badge-gray"><?php echo htmlspecialchars($item['category_name'] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <?php if (!empty($item['description'])): ?><p class="inventory-item-description"><?php echo htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
            <div class="inventory-stock-summary"><strong><?php echo (int)$item['available_qty']; ?> available</strong><span><?php echo (int)$item['total_qty']; ?> total</span></div>
            <div class="progress inventory-stock-progress" role="progressbar" aria-label="Available stock" aria-valuenow="<?php echo $stock_percent; ?>" aria-valuemin="0" aria-valuemax="100">
              <div class="progress-bar bg-secondary" style="width: <?php echo $stock_percent; ?>%"></div>
            </div>
            <div class="inventory-borrow-period">Borrow period: <strong><?php echo (int)$item['borrow_days']; ?> days</strong></div>
            <div class="inventory-item-actions">
              <button class="btn btn-outline btn-sm inventory-edit-button" type="button" data-item-id="<?php echo (int)$item['id']; ?>">Edit</button>
              <button class="btn btn-sm inventory-restock-button" type="button" data-item-id="<?php echo (int)$item['id']; ?>" data-item-name="<?php echo htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8'); ?>">Restock</button>
            </div>
          </div>
        </article>
      </div>
      <?php endforeach; ?>
      <?php if (!$items): ?><div class="col-12"><div class="inventory-empty"><strong>No items yet</strong>Select “+ Add Item” to add your first lending item.</div></div><?php endif; ?>
    </div>
    <div id="inventoryNoResults" class="inventory-empty d-none mt-3"><strong>No matching items</strong>Try a different category or clear the search.</div>
  </section>
</div>

<div class="modal fade" id="borrowLimitsModal" tabindex="-1" aria-labelledby="borrowLimitsTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="borrowLimitsForm" method="post">
        <div class="modal-header"><h2 class="modal-title fs-5" id="borrowLimitsTitle">Borrowing Limits</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <input type="hidden" name="action" value="save_limits"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
          <div class="inventory-modal-error alert alert-error d-none" role="alert"></div>
          <label for="maxBorrowUnits" class="form-label">Maximum outstanding item units per student</label>
          <input id="maxBorrowUnits" name="max_borrow_units" class="form-control" type="number" min="1" max="100" value="<?php echo $max_borrow_units; ?>" required>
          <p class="small text-secondary mt-2 mb-0">This allowance applies across all active borrowings and is enforced on the server.</p>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn inventory-submit"><span class="spinner-border spinner-border-sm me-2 d-none"></span>Save Limits</button></div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="addItemModal" tabindex="-1" aria-labelledby="addItemTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form id="addItemForm" method="post" enctype="multipart/form-data">
        <div class="modal-header"><h2 class="modal-title fs-5" id="addItemTitle">Add Inventory Item</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <input type="hidden" name="action" value="add_item"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
          <div class="inventory-modal-error alert alert-error d-none" role="alert"></div>
          <div class="row g-3">
            <div class="col-md-7"><label for="addItemName" class="form-label">Item name</label><input id="addItemName" name="item_name" class="form-control" maxlength="150" required></div>
            <div class="col-md-5"><label for="addCategory" class="form-label">Category</label><div class="input-group"><select id="addCategory" name="category_id" class="form-select" required><?php foreach ($categories as $category): ?><option value="<?php echo (int)$category['id']; ?>"><?php echo htmlspecialchars($category['category_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select><button class="btn btn-outline" type="button" id="showNewCategory">Add new</button></div></div>
            <div class="col-12 d-none" id="newCategoryRow"><label for="newCategoryName" class="form-label">New category</label><div class="input-group"><input id="newCategoryName" class="form-control" maxlength="100"><button class="btn btn-outline" type="button" id="saveNewCategory">Save category</button></div></div>
            <div class="col-md-4"><label for="addTotalQty" class="form-label">Total quantity</label><input id="addTotalQty" name="total_qty" class="form-control" type="number" min="0" max="100000" value="1" required></div>
            <div class="col-md-4"><label for="addBorrowDays" class="form-label">Borrow days</label><select id="addBorrowDays" name="borrow_days" class="form-select" required><option value="2">2 days</option><option value="3" selected>3 days</option></select></div>
            <div class="col-md-4"><label for="addImage" class="form-label">Image upload <span class="text-danger">Required</span></label><input id="addImage" name="image_file" class="form-control inventory-image-input" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required></div>
            <div class="col-12"><label for="addDescription" class="form-label">Description (optional)</label><textarea id="addDescription" name="description" class="form-control" rows="2" maxlength="2000"></textarea></div>
            <div class="col-12"><div class="inventory-image-preview-wrap"><img class="inventory-image-preview d-none" id="addImagePreview" alt="Selected item image preview"><div id="addImagePlaceholder" class="inventory-preview-placeholder">Image preview appears here</div></div></div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn inventory-submit"><span class="spinner-border spinner-border-sm me-2 d-none"></span>Add Item</button></div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="editItemModal" tabindex="-1" aria-labelledby="editItemTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form id="editItemForm" method="post" enctype="multipart/form-data">
        <div class="modal-header"><h2 class="modal-title fs-5" id="editItemTitle">Edit Inventory Item</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <input type="hidden" name="action" value="edit_item"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="item_id" id="editItemId">
          <div class="inventory-modal-error alert alert-error d-none" role="alert"></div>
          <div class="row g-3">
            <div class="col-md-7"><label for="editItemName" class="form-label">Item name</label><input id="editItemName" name="item_name" class="form-control" maxlength="150" required></div>
            <div class="col-md-5"><label for="editCategory" class="form-label">Category</label><select id="editCategory" name="category_id" class="form-select" required><?php foreach ($categories as $category): ?><option value="<?php echo (int)$category['id']; ?>"><?php echo htmlspecialchars($category['category_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label for="editTotalQty" class="form-label">Total quantity</label><input id="editTotalQty" name="total_qty" class="form-control" type="number" min="0" max="100000" required></div>
            <div class="col-md-4"><label for="editBorrowDays" class="form-label">Borrow days</label><select id="editBorrowDays" name="borrow_days" class="form-select" required><option value="2">2 days</option><option value="3">3 days</option></select></div>
            <div class="col-md-4"><label for="editImage" class="form-label">Replace image (optional)</label><input id="editImage" name="image_file" class="form-control inventory-image-input" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"></div>
            <div class="col-12"><label for="editDescription" class="form-label">Description (optional)</label><textarea id="editDescription" name="description" class="form-control" rows="2" maxlength="2000"></textarea></div>
            <div class="col-12"><div class="inventory-image-preview-wrap"><img class="inventory-image-preview" id="editImagePreview" alt="Current item image preview"><div class="inventory-preview-placeholder d-none" id="editImagePlaceholder">No image uploaded</div></div></div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn inventory-submit"><span class="spinner-border spinner-border-sm me-2 d-none"></span>Save Changes</button></div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="restockModal" tabindex="-1" aria-labelledby="restockTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="restockForm" method="post">
        <div class="modal-header"><h2 class="modal-title fs-5" id="restockTitle">Restock Item</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <input type="hidden" name="action" value="restock_item"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="item_id" id="restockItemId">
          <div class="inventory-modal-error alert alert-error d-none" role="alert"></div>
          <p id="restockItemName" class="fw-bold"></p>
          <label for="restockQty" class="form-label">Quantity to add</label><input id="restockQty" name="restock_qty" class="form-control" type="number" min="1" max="10000" value="1" required>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn inventory-submit"><span class="spinner-border spinner-border-sm me-2 d-none"></span>Restock</button></div>
      </form>
    </div>
  </div>
</div>

<script src="<?php echo $base; ?>assets/vendor/jspdf/jspdf.umd.min.js"></script>
<script src="<?php echo $base; ?>assets/vendor/jspdf/jspdf.plugin.autotable.min.js"></script>
<script src="<?php echo $base; ?>assets/js/pdf-export.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  'use strict';
  var inventoryItems = <?php echo json_encode($items, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
  var categories = <?php echo json_encode($categories, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
  var activeCategory = 'all';
  var searchField = document.getElementById('inventorySearch');

  function imageSource(path) {
    var source = String(path || '').trim();
    if (!source) return '../../assets/img/item-placeholder.svg';
    return /^(https?:)?\/\//i.test(source) || source.charAt(0) === '/'
      ? source
      : '../../' + source.replace(/^(\.\/)+/, '');
  }

  function categoryCount(categoryId) {
    if (categoryId === 'all') return inventoryItems.length;
    return inventoryItems.filter(function (item) { return String(item.category_id) === String(categoryId); }).length;
  }

  function drawTabs() {
    var tabs = document.getElementById('inventoryCategoryTabs');
    tabs.replaceChildren();
    [{ id: 'all', category_name: 'All items' }].concat(categories).forEach(function (category) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'inventory-category-tab' + (String(category.id) === String(activeCategory) ? ' active' : '');
      button.appendChild(document.createTextNode(category.category_name));
      var count = document.createElement('span');
      count.className = 'tab-count';
      count.textContent = categoryCount(category.id);
      button.appendChild(count);
      button.setAttribute('role', 'tab');
      button.setAttribute('aria-selected', String(String(category.id) === String(activeCategory)));
      button.addEventListener('click', function () {
        activeCategory = String(category.id);
        drawTabs();
        filterItems();
      });
      tabs.appendChild(button);
    });
  }

  function filterItems() {
    var query = searchField.value.trim().toLocaleLowerCase();
    var shown = 0;
    document.querySelectorAll('.inventory-grid-item').forEach(function (card) {
      var categoryMatch = activeCategory === 'all' || card.dataset.category === activeCategory;
      var searchMatch = !query || card.dataset.search.includes(query);
      var visible = categoryMatch && searchMatch;
      card.classList.toggle('d-none', !visible);
      if (visible) shown += 1;
    });
    document.getElementById('inventoryResultCount').textContent = shown + (shown === 1 ? ' item' : ' items');
    document.getElementById('inventoryNoResults').classList.toggle('d-none', shown !== 0 || inventoryItems.length === 0);
  }

  function setPreview(fileInput, image, placeholder) {
    var previous = image.dataset.previewUrl;
    if (previous) URL.revokeObjectURL(previous);
    var file = fileInput.files && fileInput.files[0];
    if (!file) {
      image.classList.toggle('d-none', !image.getAttribute('src'));
      placeholder.classList.toggle('d-none', Boolean(image.getAttribute('src')));
      return;
    }
    var url = URL.createObjectURL(file);
    image.dataset.previewUrl = url;
    image.src = url;
    image.classList.remove('d-none');
    placeholder.classList.add('d-none');
  }

  function showFormError(form, message) {
    var alert = form.querySelector('.inventory-modal-error');
    alert.textContent = message;
    alert.classList.remove('d-none');
  }

  function submitForm(event) {
    event.preventDefault();
    var form = event.currentTarget;
    var button = form.querySelector('.inventory-submit');
    if (button.disabled) return;
    if (!form.reportValidity()) {
      window.notify.error('Check the form', 'Please correct the highlighted fields before saving.');
      return;
    }
    var errorBox = form.querySelector('.inventory-modal-error');
    if (errorBox) errorBox.classList.add('d-none');
    button.disabled = true;
    button.querySelector('.spinner-border').classList.remove('d-none');
    fetch('inventory.php', {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: new FormData(form)
    }).then(function (response) {
      return response.json().then(function (payload) {
        if (!response.ok) throw new Error(payload.error || 'Unable to save inventory changes.');
        return payload;
      });
    }).then(function (payload) {
      var action = form.elements.action.value;
      var title = action === 'add_item' ? 'Item added' :
        (action === 'edit_item' ? 'Item updated' :
          (action === 'restock_item' ? 'Stock updated' : 'Borrowing limits updated'));
      window.notify.success(title, payload.message);
      window.setTimeout(function () { window.location.reload(); }, 2300);
    }).catch(function (error) {
      showFormError(form, error.message);
      window.notify.error('Inventory update failed', error.message || 'Unable to save inventory changes.');
    }).finally(function () {
      button.disabled = false;
      button.querySelector('.spinner-border').classList.add('d-none');
    });
  }

  document.querySelectorAll('.inventory-grid-item').forEach(function (card, index) {
    card.style.setProperty('--inventory-delay', Math.min(index * 45, 450) + 'ms');
  });
  drawTabs();
  filterItems();
  searchField.addEventListener('input', filterItems);

  document.getElementById('addItemForm').addEventListener('submit', submitForm);
  document.getElementById('borrowLimitsForm').addEventListener('submit', submitForm);
  document.getElementById('editItemForm').addEventListener('submit', submitForm);
  document.getElementById('restockForm').addEventListener('submit', submitForm);

  var addImage = document.getElementById('addImage');
  addImage.addEventListener('change', function () {
    setPreview(addImage, document.getElementById('addImagePreview'), document.getElementById('addImagePlaceholder'));
  });
  var editImage = document.getElementById('editImage');
  editImage.addEventListener('change', function () {
    setPreview(editImage, document.getElementById('editImagePreview'), document.getElementById('editImagePlaceholder'));
  });

  document.getElementById('showNewCategory').addEventListener('click', function () {
    document.getElementById('newCategoryRow').classList.toggle('d-none');
    document.getElementById('newCategoryName').focus();
  });
  document.getElementById('saveNewCategory').addEventListener('click', function () {
    var name = document.getElementById('newCategoryName').value.trim();
    if (!name) {
      window.notify.error('Category name required', 'Enter a category name first.');
      return;
    }
    var data = new FormData();
    data.append('action', 'add_category');
    data.append('category_name', name);
    data.append('csrf_token', <?php echo json_encode($csrf_token); ?>);
    fetch('inventory.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: data })
      .then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok) throw new Error(payload.error || 'Could not add category.');
          return payload;
        });
      }).then(function (payload) {
        categories.push(payload.category);
        categories.sort(function (a, b) { return a.category_name.localeCompare(b.category_name); });
        [document.getElementById('addCategory'), document.getElementById('editCategory')].forEach(function (select) {
          var option = new Option(payload.category.category_name, payload.category.id);
          select.add(option);
        });
        document.getElementById('addCategory').value = String(payload.category.id);
        document.getElementById('newCategoryName').value = '';
        document.getElementById('newCategoryRow').classList.add('d-none');
        window.notify.success('Category added', payload.message);
        drawTabs();
      }).catch(function (error) { window.notify.error('Category could not be added', error.message || 'Unable to add the category.'); });
  });

  var editModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editItemModal'));
  var restockModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('restockModal'));
  document.querySelectorAll('.inventory-edit-button').forEach(function (button) {
    button.addEventListener('click', function () {
      var item = inventoryItems.find(function (candidate) { return Number(candidate.id) === Number(button.dataset.itemId); });
      if (!item) return;
      var form = document.getElementById('editItemForm');
      form.querySelector('.inventory-modal-error').classList.add('d-none');
      document.getElementById('editItemId').value = item.id;
      document.getElementById('editItemName').value = item.item_name;
      document.getElementById('editCategory').value = String(item.category_id || '');
      document.getElementById('editTotalQty').value = item.total_qty;
      document.getElementById('editBorrowDays').value = String(item.borrow_days);
      document.getElementById('editDescription').value = item.description || '';
      editImage.value = '';
      var image = document.getElementById('editImagePreview');
      var placeholder = document.getElementById('editImagePlaceholder');
      image.removeAttribute('src');
      image.classList.toggle('d-none', !item.image_path);
      placeholder.classList.toggle('d-none', Boolean(item.image_path));
      if (item.image_path) image.src = imageSource(item.image_path);
      editModal.show();
    });
  });
  document.querySelectorAll('.inventory-restock-button').forEach(function (button) {
    button.addEventListener('click', function () {
      var form = document.getElementById('restockForm');
      form.querySelector('.inventory-modal-error').classList.add('d-none');
      document.getElementById('restockItemId').value = button.dataset.itemId;
      document.getElementById('restockItemName').textContent = button.dataset.itemName;
      document.getElementById('restockQty').value = '1';
      restockModal.show();
    });
  });

  document.getElementById('exportInventoryPdf').addEventListener('click', function () {
    var query = searchField.value.trim().toLocaleLowerCase();
    var rows = inventoryItems.filter(function (item) {
      var categoryMatch = activeCategory === 'all' || String(item.category_id) === activeCategory;
      var searchText = [item.item_name, item.category_name, item.description].join(' ').toLocaleLowerCase();
      return categoryMatch && (!query || searchText.includes(query));
    }).map(function (item) {
      return [item.item_name, item.category_name || 'Uncategorized', item.available_qty + ' / ' + item.total_qty, item.borrow_days + ' days', item.description || ''];
    });
    if (!rows.length) {
      window.notify.info('No items to export', 'There are no matching inventory items.');
      return;
    }
    Promise.resolve().then(function () {
      return window.exportTablePdf({
        title: 'ISKOLEND Inventory',
        subtitle: 'Category: ' + (activeCategory === 'all' ? 'All items' : (categories.find(function (category) {
          return String(category.id) === activeCategory;
        }) || {}).category_name) + ' · Search: ' + (searchField.value.trim() || 'None'),
        columns: ['Item', 'Category', 'Available / Total', 'Borrow Period', 'Description'],
        rows: rows,
        filename: 'iskolend-inventory.pdf',
        orientation: 'portrait'
      });
    }).catch(function (error) { window.notify.error('PDF export failed', error.message || 'Unable to prepare the PDF.'); });
  });
});
</script>
<?php include '../../includes/footer.php'; ?>