<?php
require_once __DIR__ . '/config.php';
require_login($pdo);

$settings = get_settings($pdo);
$user = current_user($pdo);
$errors = [];
$flash = get_flash();
$successOrderId = isset($_GET['success']) ? (int) $_GET['success'] : 0;

$productsStmt = $pdo->query('
    SELECT id, title, price, stock
    FROM products
    WHERE is_hidden = 0 AND stock > 0
    ORDER BY id DESC
');
$products = $productsStmt->fetchAll();

if (!$products) {
    set_flash('No products available right now.', 'warning');
    redirect('index.php');
}

$selectedProductId = isset($_GET['product']) ? (int) $_GET['product'] : (int) $products[0]['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedProductId = (int) ($_POST['product_id'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    $productId = (int) ($_POST['product_id'] ?? 0);
    $quantity = (int) ($_POST['quantity'] ?? 1);
    $walletUseInput = (float) ($_POST['wallet_use'] ?? 0);
    $utr = trim($_POST['utr'] ?? '');

    if ($productId <= 0) {
        $errors[] = 'Please select a product.';
    }
    if ($quantity <= 0 || $quantity > 1000) {
        $errors[] = 'Quantity must be between 1 and 1000.';
    }
    if ($walletUseInput < 0) {
        $errors[] = 'Wallet usage cannot be negative.';
    }

    $createdOrderId = 0;
    $movedScreenshotPath = null;

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $userStmt = $pdo->prepare('SELECT id, wallet_balance FROM users WHERE id = ? FOR UPDATE');
            $userStmt->execute([(int) $user['id']]);
            $lockedUser = $userStmt->fetch();
            if (!$lockedUser) {
                throw new Exception('User not found.');
            }

            $productStmt = $pdo->prepare('
                SELECT id, title, price, stock, is_hidden
                FROM products
                WHERE id = ?
                FOR UPDATE
            ');
            $productStmt->execute([$productId]);
            $product = $productStmt->fetch();
            if (!$product || (int) $product['is_hidden'] === 1) {
                throw new Exception('Selected product is not available.');
            }
            if ((int) $product['stock'] < $quantity) {
                throw new Exception('Not enough stock available.');
            }

            $totalAmount = round(((float) $product['price']) * $quantity, 2);
            $walletBalance = round((float) $lockedUser['wallet_balance'], 2);
            $walletUsed = round(min($walletUseInput, $walletBalance, $totalAmount), 2);
            $upiAmount = round($totalAmount - $walletUsed, 2);

            $screenshotPath = null;
            $screenshotHash = null;

            if ($upiAmount > 0) {
                if (!is_valid_utr($utr)) {
                    throw new Exception('UTR must be 12 to 22 digits.');
                }

                $dupUtr = $pdo->prepare('SELECT id FROM orders WHERE utr = ? LIMIT 1');
                $dupUtr->execute([$utr]);
                if ($dupUtr->fetch()) {
                    throw new Exception('This UTR already exists.');
                }

                if (!isset($_FILES['screenshot']) || $_FILES['screenshot']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Payment screenshot is required for UPI payment.');
                }
                if ((int) $_FILES['screenshot']['size'] > 2 * 1024 * 1024) {
                    throw new Exception('Screenshot file size must be under 2MB.');
                }

                $tmpPath = $_FILES['screenshot']['tmp_name'];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($tmpPath);
                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                ];
                if (!isset($allowed[$mime])) {
                    throw new Exception('Only JPG and PNG screenshots are allowed.');
                }

                $screenshotHash = hash_file('sha256', $tmpPath);
                $dupShot = $pdo->prepare('SELECT id FROM orders WHERE screenshot_hash = ? LIMIT 1');
                $dupShot->execute([$screenshotHash]);
                if ($dupShot->fetch()) {
                    throw new Exception('Duplicate screenshot detected.');
                }

                $uploadDir = ensure_uploads_dir();
                $filename = 'pay_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
                $absolutePath = $uploadDir . '/' . $filename;
                if (!move_uploaded_file($tmpPath, $absolutePath)) {
                    throw new Exception('Could not save screenshot.');
                }
                $screenshotPath = 'uploads/' . $filename;
                $movedScreenshotPath = $absolutePath;
            } else {
                $utr = null;
            }

            if ($walletUsed > 0) {
                $walletUpdate = $pdo->prepare('
                    UPDATE users
                    SET wallet_balance = wallet_balance - ?
                    WHERE id = ? AND wallet_balance >= ?
                ');
                $walletUpdate->execute([money($walletUsed), (int) $lockedUser['id'], money($walletUsed)]);
                if ($walletUpdate->rowCount() !== 1) {
                    throw new Exception('Wallet deduction failed due to insufficient balance.');
                }
            }

            $stockUpdate = $pdo->prepare('
                UPDATE products
                SET stock = stock - ?
                WHERE id = ? AND stock >= ?
            ');
            $stockUpdate->execute([$quantity, $productId, $quantity]);
            if ($stockUpdate->rowCount() !== 1) {
                throw new Exception('Stock update failed. Please try again.');
            }

            $orderInsert = $pdo->prepare('
                INSERT INTO orders
                (user_id, product_id, quantity, total_amount, wallet_used, upi_amount, utr, screenshot_path, screenshot_hash, status)
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $orderInsert->execute([
                (int) $lockedUser['id'],
                $productId,
                $quantity,
                money($totalAmount),
                money($walletUsed),
                money($upiAmount),
                $utr,
                $screenshotPath,
                $screenshotHash,
                'pending',
            ]);

            $createdOrderId = (int) $pdo->lastInsertId();
            $pdo->commit();

            redirect('checkout.php?success=' . $createdOrderId);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($movedScreenshotPath && file_exists($movedScreenshotPath)) {
                @unlink($movedScreenshotPath);
            }

            if ($e->getCode() === '23000') {
                $errors[] = 'Duplicate UTR or screenshot already exists.';
            } else {
                $errors[] = 'Order could not be processed. Please try again.';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($movedScreenshotPath && file_exists($movedScreenshotPath)) {
                @unlink($movedScreenshotPath);
            }
            $errors[] = $e->getMessage();
        }
    }
}

// Refresh user wallet after potential order creation
$user = current_user($pdo);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout - ZENTRAXX STORE</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
    <div class="topbar">
        <div class="brand">ZENTRAXX STORE - CHECKOUT</div>
        <div class="nav-links">
            <a class="chip-link" href="index.php">Home</a>
            <a class="chip-link" href="index.php">Products</a>
            <a class="chip-link" href="index.php?logout=1">Logout</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="flash flash-error">
            <?php foreach ($errors as $error): ?>
                <div>- <?php echo e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($successOrderId > 0): ?>
        <div class="success-pop" style="margin-bottom:14px;">
            <strong>Order #<?php echo e($successOrderId); ?> placed successfully!</strong>
            <div>Your payment proof was submitted. Wait for admin verification.</div>
        </div>
    <?php endif; ?>

    <div class="grid" style="align-items:start;">
        <div class="glass form-card">
            <h2>Place Order</h2>
            <p class="muted">Wallet Balance: <strong>Rs <?php echo e(money($user['wallet_balance'])); ?></strong></p>

            <form method="post" enctype="multipart/form-data" id="checkoutForm">
                <?php echo csrf_input(); ?>
                <div class="field">
                    <label>Product</label>
                    <select name="product_id" id="productSelect" required>
                        <?php foreach ($products as $product): ?>
                            <option
                                value="<?php echo e($product['id']); ?>"
                                data-price="<?php echo e(money($product['price'])); ?>"
                                data-stock="<?php echo e((int) $product['stock']); ?>"
                                <?php echo ((int) $selectedProductId === (int) $product['id']) ? 'selected' : ''; ?>
                            >
                                <?php echo e($product['title']); ?> - Rs <?php echo e(money($product['price'])); ?> (Stock: <?php echo e((int) $product['stock']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label>Quantity</label>
                        <input type="number" name="quantity" id="quantityInput" min="1" value="<?php echo e($_POST['quantity'] ?? '1'); ?>" required>
                    </div>
                    <div class="field">
                        <label>Use Wallet Amount</label>
                        <input type="number" name="wallet_use" id="walletInput" min="0" step="0.01" value="<?php echo e($_POST['wallet_use'] ?? '0'); ?>">
                    </div>
                </div>

                <div class="glass card" style="margin-bottom:12px;">
                    <div>Total: Rs <strong id="totalAmount">0.00</strong></div>
                    <div>Wallet Used: Rs <strong id="walletUsed">0.00</strong></div>
                    <div>UPI Payable: Rs <strong id="upiAmount">0.00</strong></div>
                </div>

                <div class="field">
                    <label>UTR Number (12-22 digits, required if UPI amount > 0)</label>
                    <input type="text" name="utr" maxlength="22" value="<?php echo e($_POST['utr'] ?? ''); ?>">
                </div>

                <div class="field">
                    <label>Payment Screenshot (JPG/PNG, required if UPI amount > 0)</label>
                    <input type="file" name="screenshot" id="screenshotInput" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                    <div class="preview-box">
                        <img id="screenshotPreview" src="" alt="" style="display:none;">
                    </div>
                </div>

                <button class="btn btn-primary" type="submit">Submit Order</button>
            </form>
        </div>

        <div class="glass card">
            <h3>Payment Instructions</h3>
            <p class="muted"><?php echo nl2br(e($settings['payment_instructions'])); ?></p>
            <hr class="sep">
            <p><strong>UPI ID:</strong> <?php echo e($settings['upi_id']); ?></p>
            <p><strong>Receiver:</strong> <?php echo e($settings['upi_name']); ?></p>
            <p class="muted">Orders are created as <strong>pending</strong> and reviewed by admin.</p>
        </div>
    </div>
</div>

<script>
  (function () {
    const productSelect = document.getElementById('productSelect');
    const quantityInput = document.getElementById('quantityInput');
    const walletInput = document.getElementById('walletInput');
    const totalAmountEl = document.getElementById('totalAmount');
    const walletUsedEl = document.getElementById('walletUsed');
    const upiAmountEl = document.getElementById('upiAmount');
    const screenshotInput = document.getElementById('screenshotInput');
    const screenshotPreview = document.getElementById('screenshotPreview');
    const walletBalance = <?php echo json_encode((float) $user['wallet_balance']); ?>;

    function recalc() {
      const option = productSelect.options[productSelect.selectedIndex];
      const price = parseFloat(option.dataset.price || '0');
      const qty = Math.max(1, parseInt(quantityInput.value || '1', 10));
      const total = price * qty;
      let useWallet = parseFloat(walletInput.value || '0');
      if (useWallet < 0 || Number.isNaN(useWallet)) useWallet = 0;
      const maxWallet = Math.min(walletBalance, total);
      if (useWallet > maxWallet) useWallet = maxWallet;
      const upi = Math.max(0, total - useWallet);

      totalAmountEl.textContent = total.toFixed(2);
      walletUsedEl.textContent = useWallet.toFixed(2);
      upiAmountEl.textContent = upi.toFixed(2);
    }

    function previewImage() {
      const file = screenshotInput.files && screenshotInput.files[0];
      if (!file) {
        screenshotPreview.style.display = 'none';
        screenshotPreview.src = '';
        return;
      }
      const reader = new FileReader();
      reader.onload = function (e) {
        screenshotPreview.src = e.target.result;
        screenshotPreview.style.display = 'block';
      };
      reader.readAsDataURL(file);
    }

    productSelect.addEventListener('change', recalc);
    quantityInput.addEventListener('input', recalc);
    walletInput.addEventListener('input', recalc);
    screenshotInput.addEventListener('change', previewImage);
    recalc();
  })();
</script>
</body>
</html>
