<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: admin_login.php");
    exit;
}
require_once "db.php";
require_once __DIR__ . "/chat_config.php";

/* ===== Site Settings Shop Location Columns Auto Add ===== */
function ensure_site_setting_column($conn, $column, $definition)
{
    $check = $conn->query("SHOW COLUMNS FROM site_settings LIKE '" . $conn->real_escape_string($column) . "'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE site_settings ADD COLUMN {$column} {$definition}");
    }
}

ensure_site_setting_column($conn, "shop_name", "VARCHAR(255) NULL AFTER address");
ensure_site_setting_column($conn, "shop_location", "VARCHAR(255) NULL AFTER shop_name");
ensure_site_setting_column($conn, "shop_description", "TEXT NULL AFTER shop_location");
ensure_site_setting_column($conn, "google_map_link", "TEXT NULL AFTER shop_description");
ensure_site_setting_column($conn, "google_map_iframe", "TEXT NULL AFTER google_map_link");

/* ===== PHPMailer Gmail SMTP Setup for Order Status Email ===== */
/* Uses the same chat_config.php mail settings as the working home-page order email. */
require_once __DIR__ . "/vendor/autoload.php";

/* ===== Make sure orders table has customer_email column ===== */
$colCheck = $conn->query("SHOW COLUMNS FROM orders LIKE 'customer_email'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN customer_email VARCHAR(255) NULL AFTER phone");
}

/* ===== Send Order Status Email ===== */
function send_order_status_email($toEmail, $customerName, $productName, $newStatus, $orderId, $shopPhone)
{
    $toEmail = trim((string) $toEmail);

    if ($toEmail === "" || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return [false, "customer email missing or invalid"];
    }

    if (!file_exists(__DIR__ . "/vendor/autoload.php")) {
        return [false, "PHPMailer not found. Run composer require phpmailer/phpmailer"];
    }

    if (
        !defined('MAIL_USERNAME') ||
        !defined('MAIL_APP_PASSWORD') ||
        !defined('MAIL_FROM_NAME') ||
        trim((string) MAIL_USERNAME) === "" ||
        trim((string) MAIL_APP_PASSWORD) === "" ||
        MAIL_APP_PASSWORD === "YOUR_NEW_GMAIL_APP_PASSWORD" ||
        MAIL_APP_PASSWORD === "YOUR_16_DIGIT_APP_PASSWORD"
    ) {
        return [false, "Gmail mail settings are not configured in chat_config.php"];
    }

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USERNAME;
        $mail->Password = MAIL_APP_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->CharSet = "UTF-8";
        $mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $customerName);
        $mail->addReplyTo(MAIL_USERNAME, MAIL_FROM_NAME);

        $safeName = htmlspecialchars((string) $customerName, ENT_QUOTES, "UTF-8");
        $safeProduct = htmlspecialchars((string) $productName, ENT_QUOTES, "UTF-8");
        $safeStatus = htmlspecialchars((string) $newStatus, ENT_QUOTES, "UTF-8");
        $safeOrderId = (int) $orderId;
        $safePhone = htmlspecialchars((string) $shopPhone, ENT_QUOTES, "UTF-8");

        $statusMessage = "Your order status has been updated.";
        if ($newStatus === "Shipped") {
            $statusMessage = "Your order has been shipped and is on the way.";
        } elseif ($newStatus === "Packed") {
            $statusMessage = "Your order has been packed and is ready for shipment.";
        } elseif ($newStatus === "Completed") {
            $statusMessage = "Your order has been completed. Thank you for shopping with us.";
        } elseif ($newStatus === "Cancelled") {
            $statusMessage = "Your order has been cancelled. Please contact us for more details.";
        } elseif ($newStatus === "Paid") {
            $statusMessage = "Your payment has been received and your order is being processed.";
        } elseif ($newStatus === "Pending") {
            $statusMessage = "Your order is currently pending and will be processed soon.";
        }

        $mail->isHTML(true);
        $mail->Subject = "Your Order Status Updated - online laptop store";

        $mail->Body = "
            <div style='font-family:Arial,sans-serif;background:#f4f7fb;padding:20px'>
                <div style='max-width:600px;margin:auto;background:#ffffff;border-radius:14px;padding:22px;border:1px solid #e5e7eb'>
                    <h2 style='color:#0b5ed7;margin-top:0'>Order Status Updated</h2>
                    <p>Dear <b>{$safeName}</b>,</p>
                    <p>{$statusMessage}</p>

                    <div style='background:#f8fafc;border-radius:12px;padding:14px;margin:16px 0'>
                        <p style='margin:6px 0'><b>Order ID:</b> #{$safeOrderId}</p>
                        <p style='margin:6px 0'><b>Product:</b> {$safeProduct}</p>
                        <p style='margin:6px 0'><b>Current Status:</b> {$safeStatus}</p>
                    </div>

                    <p>Thank you for choosing <b>online laptop Store</b>.</p>

                    <p style='margin-top:18px'>
                        online laptop Store<br>
                        Polonnaruwa<br>
                        {$safePhone}
                    </p>
                </div>
            </div>
        ";

        $mail->AltBody =
            "Dear {$customerName},\n\n" .
            "{$statusMessage}\n\n" .
            "Order ID: #{$orderId}\n" .
            "Product: {$productName}\n" .
            "Current Status: {$newStatus}\n\n" .
            "Thank you for choosing Apple Store.\n\n" .
            "Apple Store\nPolonnaruwa\n{$shopPhone}";

        $mail->send();
        return [true, "email sent"];
    } catch (Exception $e) {
        return [false, $mail->ErrorInfo ?: $e->getMessage()];
    }
}


/* ===== Send Offer Price-Drop Alert Email ===== */
function send_wishlist_price_drop_email($toEmail, $customerName, $productName, $oldPrice, $newPrice)
{
    $toEmail = trim((string) $toEmail);

    if ($toEmail === "" || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return [false, "customer email missing or invalid"];
    }

    if (!file_exists(__DIR__ . "/vendor/autoload.php")) {
        return [false, "PHPMailer not found"];
    }

    if (
        !defined('MAIL_USERNAME') ||
        !defined('MAIL_APP_PASSWORD') ||
        !defined('MAIL_FROM_NAME') ||
        trim((string) MAIL_USERNAME) === "" ||
        trim((string) MAIL_APP_PASSWORD) === "" ||
        MAIL_APP_PASSWORD === "YOUR_NEW_GMAIL_APP_PASSWORD" ||
        MAIL_APP_PASSWORD === "YOUR_16_DIGIT_APP_PASSWORD"
    ) {
        return [false, "Gmail mail settings are not configured in chat_config.php"];
    }

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USERNAME;
        $mail->Password = MAIL_APP_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = "UTF-8";

        $mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
        $mail->addReplyTo(MAIL_USERNAME, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $customerName);

        $safeName = htmlspecialchars((string) $customerName, ENT_QUOTES, "UTF-8");
        $safeProduct = htmlspecialchars((string) $productName, ENT_QUOTES, "UTF-8");
        $safeOld = number_format((float) $oldPrice, 2);
        $safeNew = number_format((float) $newPrice, 2);

        $mail->isHTML(true);
        $mail->Subject = "Special Offer Alert - " . $productName;
        $mail->Body = "
            <div style='font-family:Arial,Helvetica,sans-serif;background:#f4f7fb;padding:24px;color:#0f172a'>
                <div style='max-width:620px;margin:0 auto;background:#ffffff;border-radius:14px;padding:24px;border:1px solid #e5e7eb'>
                    <h2 style='color:#0b5ed7;margin:0 0 14px'>Special Offer Price Drop</h2>
                    <p>Dear <b>{$safeName}</b>,</p>
                    <p>Good news! A laptop in our store now has a lower offer price.</p>
                    <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin:16px 0'>
                        <p style='margin:6px 0'><b>Product:</b> {$safeProduct}</p>
                        <p style='margin:6px 0'><b>Previous price:</b> RS. {$safeOld}</p>
                        <p style='margin:6px 0;color:#166534'><b>New offer price:</b> RS. {$safeNew}</p>
                    </div>
                    <p>Log in to the Online Laptop Store to view the offer.</p>
                    <p style='margin-top:18px'>Regards,<br><b>Online Laptop Store Team</b></p>
                </div>
            </div>
        ";
        $mail->AltBody =
            "Dear {$customerName},\n\n" .
            "Special offer alert for {$productName}.\n" .
            "Previous price: RS. " . number_format((float) $oldPrice, 2) . "\n" .
            "New offer price: RS. " . number_format((float) $newPrice, 2) . "\n\n" .
            "Log in to the Online Laptop Store to view the offer.";

        $mail->send();
        return [true, "email sent"];
    } catch (Exception $e) {
        return [false, $mail->ErrorInfo ?: $e->getMessage()];
    }
}

function e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function ensure_dir($dir)
{
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

function save_upload($fileKey, $destDir, $prefix)
{
    if (!isset($_FILES[$fileKey]))
        return "";

    if (!isset($_FILES[$fileKey]['error']) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return "";
    }

    $tmp = $_FILES[$fileKey]['tmp_name'];
    $name = $_FILES[$fileKey]['name'];

    if (!is_uploaded_file($tmp))
        return "";

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($ext, $allowed, true)) {
        return "";
    }

    ensure_dir($destDir);

    $newName = $prefix . "_" . time() . "_" . rand(1000, 9999) . "." . $ext;
    $destPath = rtrim($destDir, '/\\') . "/" . $newName;

    if (move_uploaded_file($tmp, $destPath)) {
        return $destPath;
    }

    return "";
}

function normalize_multi_files($fileKey)
{
    $result = [];

    if (!isset($_FILES[$fileKey])) {
        return $result;
    }

    $file = $_FILES[$fileKey];

    if (!isset($file['name']) || !is_array($file['name'])) {
        return $result;
    }

    $count = count($file['name']);

    for ($i = 0; $i < $count; $i++) {
        $result[] = [
            'name' => $file['name'][$i] ?? '',
            'type' => $file['type'][$i] ?? '',
            'tmp_name' => $file['tmp_name'][$i] ?? '',
            'error' => $file['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $file['size'][$i] ?? 0,
        ];
    }

    return $result;
}

function save_multi_uploads($fileKey, $destDir, $prefix, $max = 5)
{
    $paths = [];
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    $files = normalize_multi_files($fileKey);
    if (!$files)
        return $paths;

    ensure_dir($destDir);

    $saved = 0;

    foreach ($files as $file) {
        if ($saved >= $max)
            break;

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }

        $name = trim((string) ($file['name'] ?? ''));
        $tmp = (string) ($file['tmp_name'] ?? '');

        if ($name === '' || $tmp === '' || !is_uploaded_file($tmp)) {
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            continue;
        }

        $newName = $prefix . "_" . time() . "_" . rand(1000, 9999) . "_" . $saved . "." . $ext;
        $destPath = rtrim($destDir, '/\\') . "/" . $newName;

        if (move_uploaded_file($tmp, $destPath)) {
            $paths[] = $destPath;
            $saved++;
        }
    }

    return $paths;
}

$msg = $_SESSION['admin_flash_msg'] ?? "";
unset($_SESSION['admin_flash_msg']);

$editProduct = null;
$editExtraImages = [];

/* ===== UPDATE SETTINGS ===== */
if (isset($_POST['save_settings'])) {
    $stmt = $conn->prepare("UPDATE site_settings
    SET site_name=?, hero_title=?, hero_subtitle=?, hero_button_text=?, hero_button_link=?,
        about_text=?, contact_label=?, phone=?, email=?, address=?,
        shop_name=?, shop_location=?, shop_description=?, google_map_link=?, google_map_iframe=?
    WHERE id=1");
    $stmt->bind_param(
        "sssssssssssssss",
        $_POST['site_name'],
        $_POST['hero_title'],
        $_POST['hero_subtitle'],
        $_POST['hero_button_text'],
        $_POST['hero_button_link'],
        $_POST['about_text'],
        $_POST['contact_label'],
        $_POST['phone'],
        $_POST['email'],
        $_POST['address'],
        $_POST['shop_name'],
        $_POST['shop_location'],
        $_POST['shop_description'],
        $_POST['google_map_link'],
        $_POST['google_map_iframe']
    );
    $stmt->execute();
    $msg = "Settings updated!";
}


/* ===== UPDATE SHOP LOCATION ONLY ===== */
if (isset($_POST['save_location_settings'])) {
    $shopName = trim($_POST['shop_name'] ?? '');
    $shopLocation = trim($_POST['shop_location'] ?? '');
    $shopDescription = trim($_POST['shop_description'] ?? '');
    $shopPhone = trim($_POST['phone'] ?? '');
    $shopEmail = trim($_POST['email'] ?? '');
    $footerAddress = trim($_POST['address'] ?? '');
    $googleMapLink = trim($_POST['google_map_link'] ?? '');
    $googleMapIframe = trim($_POST['google_map_iframe'] ?? '');

    if ($shopName === '' || $shopLocation === '' || $shopDescription === '' || $shopPhone === '' || $shopEmail === '') {
        $msg = "Location settings required fields missing!";
    } else {
        $stmt = $conn->prepare("UPDATE site_settings
            SET shop_name=?, shop_location=?, shop_description=?, phone=?, email=?, address=?, google_map_link=?, google_map_iframe=?
            WHERE id=1");
        $stmt->bind_param(
            "ssssssss",
            $shopName,
            $shopLocation,
            $shopDescription,
            $shopPhone,
            $shopEmail,
            $footerAddress,
            $googleMapLink,
            $googleMapIframe
        );
        $stmt->execute();
        $msg = "Shop location updated!";

        $settings['shop_name'] = $shopName;
        $settings['shop_location'] = $shopLocation;
        $settings['shop_description'] = $shopDescription;
        $settings['phone'] = $shopPhone;
        $settings['email'] = $shopEmail;
        $settings['address'] = $footerAddress;
        $settings['google_map_link'] = $googleMapLink;
        $settings['google_map_iframe'] = $googleMapIframe;
    }
}

/* ===== BRANDS TABLE + PRODUCT BRAND COLUMN ===== */
$conn->query("
CREATE TABLE IF NOT EXISTS brands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    logo_url VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");

$brandColumnCheck = $conn->query("SHOW COLUMNS FROM products LIKE 'brand_id'");
if ($brandColumnCheck && $brandColumnCheck->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN brand_id INT NULL AFTER category_id");
}

/* ===== PRODUCT STOCK COLUMN ===== */
$stockColumnCheck = $conn->query("SHOW COLUMNS FROM products LIKE 'stock_quantity'");
if ($stockColumnCheck && $stockColumnCheck->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN stock_quantity INT UNSIGNED NOT NULL DEFAULT 0 AFTER price");
}

/* ===== PRODUCT OFFER PRICE COLUMN ===== */
$offerPriceColumnCheck = $conn->query("SHOW COLUMNS FROM products LIKE 'offer_price'");
if ($offerPriceColumnCheck && $offerPriceColumnCheck->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN offer_price DECIMAL(12,2) NULL AFTER price");
}

/* ===== PRODUCT AVAILABLE COLORS COLUMN ===== */
$colorColumnCheck = $conn->query("SHOW COLUMNS FROM products LIKE 'available_colors'");
if ($colorColumnCheck && $colorColumnCheck->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN available_colors VARCHAR(500) NULL AFTER stock_quantity");
}

/* ===== WISHLIST TABLE (for automatic price-drop alerts) ===== */
$conn->query("
CREATE TABLE IF NOT EXISTS wishlists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    product_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_customer_product_wishlist (customer_id, product_id),
    KEY idx_wishlist_product (product_id),
    KEY idx_wishlist_customer (customer_id)
)
");

/* Seed starter brands only if the table is empty. */
$brandCountResult = $conn->query("SELECT COUNT(*) AS total FROM brands");
$brandCountRow = $brandCountResult ? $brandCountResult->fetch_assoc() : ['total' => 0];

if ((int) ($brandCountRow['total'] ?? 0) === 0) {
    $defaultBrands = ['ASUS', 'HP', 'SONY', 'DELL', 'LENOVO', 'ACER'];
    $brandSeedStmt = $conn->prepare("INSERT IGNORE INTO brands(name, logo_url) VALUES(?, '')");
    if ($brandSeedStmt) {
        foreach ($defaultBrands as $defaultBrand) {
            $brandSeedStmt->bind_param("s", $defaultBrand);
            $brandSeedStmt->execute();
        }
        $brandSeedStmt->close();
    }
}

/* ===== CATEGORY CRUD ===== */
if (isset($_POST['add_category'])) {
    $catName = trim($_POST['cat_name'] ?? "");
    $imgPath = save_upload("cat_image_file", "uploads/categories", "cat");

    if ($catName === "") {
        $msg = "Category name required!";
    } elseif ($imgPath === "") {
        $msg = "Category image upload failed (use jpg/png/webp)!";
    } else {
        $stmt = $conn->prepare("INSERT INTO categories(name, image_url) VALUES(?,?)");
        $stmt->bind_param("ss", $catName, $imgPath);
        $stmt->execute();
        $msg = "Category added!";
    }
}

if (isset($_POST['delete_category'])) {
    $id = (int) $_POST['cat_id'];
    $r = $conn->query("SELECT image_url FROM categories WHERE id=$id")->fetch_assoc();
    if ($r && !empty($r['image_url']) && file_exists($r['image_url'])) {
        @unlink($r['image_url']);
    }
    $conn->query("DELETE FROM categories WHERE id=$id");
    $msg = "Category deleted!";
}

/* ===== ADD PRODUCT ===== */
if (isset($_POST['add_product'])) {
    $name = trim($_POST['prod_name'] ?? "");
    $price = (float) ($_POST['prod_price'] ?? 0);
    $offer_price = max(0, (float) ($_POST['offer_price'] ?? 0));
    $desc = trim($_POST['prod_desc'] ?? "");
    $isf = isset($_POST['is_featured']) ? 1 : 0;
    $category_id = (int) ($_POST['category_id'] ?? 0);
    $brand_id = (int) ($_POST['brand_id'] ?? 0);
    $stock_quantity = max(0, (int) ($_POST['stock_quantity'] ?? 0));
    $available_colors = trim($_POST['available_colors'] ?? "");

    $imgPath = save_upload("prod_image_file", "uploads/products", "prod");
    $extraPaths = save_multi_uploads("prod_images", "uploads/products", "prod_extra", 5);

    if ($name === "" || $price <= 0) {
        $msg = "Product name & valid price required!";
    } elseif ($category_id <= 0) {
        $msg = "Please select a category!";
    } elseif ($brand_id <= 0) {
        $msg = "Please select a brand!";
    } elseif ($imgPath === "") {
        $msg = "Main product image upload failed (use jpg/png/webp)!";
    } else {
        $stmt = $conn->prepare("INSERT INTO products(category_id, brand_id, name, price, offer_price, stock_quantity, available_colors, image_url, description, is_featured) VALUES(?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("iisddisssi", $category_id, $brand_id, $name, $price, $offer_price, $stock_quantity, $available_colors, $imgPath, $desc, $isf);
        $stmt->execute();

        $pid = (int) $stmt->insert_id;
        $extraCount = 0;

        if ($pid > 0 && !empty($extraPaths)) {
            $ins = $conn->prepare("INSERT INTO product_images(product_id, image_url, sort_order) VALUES(?,?,?)");

            foreach ($extraPaths as $i => $p) {
                $sort = $i;
                $ins->bind_param("isi", $pid, $p, $sort);
                $ins->execute();
                if ($ins->affected_rows > 0) {
                    $extraCount++;
                }
            }
        }

        $msg = "Product added successfully! Extra images saved: " . $extraCount;
    }
}

/* ===== LOAD EDIT PRODUCT ===== */
if (isset($_GET['edit_product'])) {
    $editId = (int) $_GET['edit_product'];

    $st = $conn->prepare("SELECT * FROM products WHERE id=? LIMIT 1");
    $st->bind_param("i", $editId);
    $st->execute();
    $editProduct = $st->get_result()->fetch_assoc();

    if ($editProduct) {
        $imgs = $conn->query("SELECT * FROM product_images WHERE product_id=" . (int) $editId . " ORDER BY sort_order ASC, id ASC");
        while ($row = $imgs->fetch_assoc()) {
            $editExtraImages[] = $row;
        }
    }
}

/* ===== UPDATE PRODUCT ===== */
if (isset($_POST['update_product'])) {
    $pid = (int) ($_POST['product_id'] ?? 0);
    $name = trim($_POST['prod_name'] ?? "");
    $price = (float) ($_POST['prod_price'] ?? 0);
    $offer_price = max(0, (float) ($_POST['offer_price'] ?? 0));
    $desc = trim($_POST['prod_desc'] ?? "");
    $isf = isset($_POST['is_featured']) ? 1 : 0;
    $category_id = (int) ($_POST['category_id'] ?? 0);
    $brand_id = (int) ($_POST['brand_id'] ?? 0);
    $stock_quantity = max(0, (int) ($_POST['stock_quantity'] ?? 0));
    $available_colors = trim($_POST['available_colors'] ?? "");

    if ($pid <= 0) {
        $msg = "Invalid product id!";
    } elseif ($name === "" || $price <= 0) {
        $msg = "Product name & valid price required!";
    } elseif ($category_id <= 0) {
        $msg = "Please select a category!";
    } elseif ($brand_id <= 0) {
        $msg = "Please select a brand!";
    } else {
        $old = $conn->query("SELECT image_url, name, price, offer_price FROM products WHERE id=$pid")->fetch_assoc();
        $newMainImage = save_upload("prod_image_file", "uploads/products", "prod");
        $productUpdateOk = false;

        if ($newMainImage !== "") {
            if ($old && !empty($old['image_url']) && file_exists($old['image_url'])) {
                @unlink($old['image_url']);
            }

            $stmt = $conn->prepare("UPDATE products SET category_id=?, brand_id=?, name=?, price=?, offer_price=?, stock_quantity=?, available_colors=?, image_url=?, description=?, is_featured=? WHERE id=?");
            $stmt->bind_param("iisddisssii", $category_id, $brand_id, $name, $price, $offer_price, $stock_quantity, $available_colors, $newMainImage, $desc, $isf, $pid);
            $productUpdateOk = $stmt->execute();
        } else {
            $stmt = $conn->prepare("UPDATE products SET category_id=?, brand_id=?, name=?, price=?, offer_price=?, stock_quantity=?, available_colors=?, description=?, is_featured=? WHERE id=?");
            $stmt->bind_param("iisddissii", $category_id, $brand_id, $name, $price, $offer_price, $stock_quantity, $available_colors, $desc, $isf, $pid);
            $productUpdateOk = $stmt->execute();
        }

        if (!empty($_POST['delete_extra_images']) && is_array($_POST['delete_extra_images'])) {
            foreach ($_POST['delete_extra_images'] as $imgId) {
                $imgId = (int) $imgId;
                $imgRow = $conn->query("SELECT * FROM product_images WHERE id=$imgId AND product_id=$pid")->fetch_assoc();
                if ($imgRow) {
                    if (!empty($imgRow['image_url']) && file_exists($imgRow['image_url'])) {
                        @unlink($imgRow['image_url']);
                    }
                    $conn->query("DELETE FROM product_images WHERE id=$imgId AND product_id=$pid");
                }
            }
        }

        $extraPaths = save_multi_uploads("prod_images", "uploads/products", "prod_extra", 5);
        if (!empty($extraPaths)) {
            $maxSortRow = $conn->query("SELECT COALESCE(MAX(sort_order), -1) AS max_sort FROM product_images WHERE product_id=$pid")->fetch_assoc();
            $sortStart = (int) ($maxSortRow['max_sort'] ?? -1) + 1;

            $ins = $conn->prepare("INSERT INTO product_images(product_id, image_url, sort_order) VALUES(?,?,?)");
            foreach ($extraPaths as $i => $p) {
                $sort = $sortStart + $i;
                $ins->bind_param("isi", $pid, $p, $sort);
                $ins->execute();
            }
        }

        /* Send automatic email only when a valid offer creates a real price drop. */
        $priceAlertMessage = "Product updated successfully!";

        if ($productUpdateOk && $old) {
            $oldRegularPrice = (float) ($old['price'] ?? 0);
            $oldOfferPrice = (float) ($old['offer_price'] ?? 0);
            $oldEffectivePrice = ($oldOfferPrice > 0 && $oldOfferPrice < $oldRegularPrice)
                ? $oldOfferPrice
                : $oldRegularPrice;

            $newEffectivePrice = ($offer_price > 0 && $offer_price < $price)
                ? $offer_price
                : $price;

            if ($offer_price > 0 && $offer_price < $price && $newEffectivePrice < $oldEffectivePrice) {
                $wishAlertStmt = $conn->prepare("
                    SELECT DISTINCT c.name, c.email
                    FROM customers c
                    WHERE c.email IS NOT NULL
                      AND TRIM(c.email) <> ''
                ");

                if ($wishAlertStmt) {
                    $wishAlertStmt->execute();
                    $wishAlertResult = $wishAlertStmt->get_result();

                    $priceAlertRecipients = 0;
                    $priceAlertSent = 0;
                    $priceAlertFailed = 0;
                    $priceAlertErrors = [];

                    while ($wishCustomer = $wishAlertResult->fetch_assoc()) {
                        $priceAlertRecipients++;

                        [$priceAlertOk, $priceAlertInfo] = send_wishlist_price_drop_email(
                            $wishCustomer['email'] ?? '',
                            $wishCustomer['name'] ?? 'Customer',
                            $name,
                            $oldEffectivePrice,
                            $newEffectivePrice
                        );

                        if ($priceAlertOk) {
                            $priceAlertSent++;
                        } else {
                            $priceAlertFailed++;
                            $errorText = trim((string) $priceAlertInfo);
                            if ($errorText !== '' && !in_array($errorText, $priceAlertErrors, true)) {
                                $priceAlertErrors[] = $errorText;
                            }
                        }
                    }

                    $wishAlertStmt->close();

                    if ($priceAlertRecipients === 0) {
                        $priceAlertMessage = "Product updated successfully! Offer email not sent: no registered customer email was found.";
                    } elseif ($priceAlertSent > 0 && $priceAlertFailed === 0) {
                        $priceAlertMessage = "Product updated successfully! Offer email sent to " . $priceAlertSent . " registered customer(s).";
                    } elseif ($priceAlertSent > 0 && $priceAlertFailed > 0) {
                        $priceAlertMessage = "Product updated successfully! Offer email sent to " . $priceAlertSent .
                            " registered customer(s), failed for " . $priceAlertFailed . " customer(s).";

                        if (!empty($priceAlertErrors)) {
                            $priceAlertMessage .= " Error: " . implode(" | ", $priceAlertErrors);
                        }
                    } else {
                        $priceAlertMessage = "Product updated successfully! Offer email failed for " .
                            $priceAlertFailed . " customer(s).";

                        if (!empty($priceAlertErrors)) {
                            $priceAlertMessage .= " Error: " . implode(" | ", $priceAlertErrors);
                        }
                    }
                } else {
                    $priceAlertMessage = "Product updated successfully! Offer email check failed: unable to read registered customers.";
                }
            } elseif ($offer_price > 0 && $offer_price < $price) {
                $priceAlertMessage = "Product updated successfully! No price-drop email sent because the offer price did not decrease.";
            } else {
                $priceAlertMessage = "Product updated successfully! No price-drop email sent because no lower offer price was applied.";
            }
        } elseif (!$productUpdateOk) {
            $priceAlertMessage = "Product update failed!";
        }

        $_SESSION['admin_flash_msg'] = $priceAlertMessage;
        header("Location: admin_dashboard.php?edit_product=" . $pid . "#products");
        exit;
    }
}

/* ===== DELETE PRODUCT ===== */
if (isset($_POST['delete_product'])) {
    $id = (int) $_POST['prod_id'];

    $r = $conn->query("SELECT image_url FROM products WHERE id=$id")->fetch_assoc();
    if ($r && !empty($r['image_url']) && file_exists($r['image_url'])) {
        @unlink($r['image_url']);
    }

    $imgs = $conn->query("SELECT image_url FROM product_images WHERE product_id=$id");
    if ($imgs) {
        while ($im = $imgs->fetch_assoc()) {
            if (!empty($im['image_url']) && file_exists($im['image_url'])) {
                @unlink($im['image_url']);
            }
        }
    }

    $conn->query("DELETE FROM product_images WHERE product_id=$id");
    $conn->query("DELETE FROM products WHERE id=$id");
    $msg = "Product deleted!";
}

/* ===== ORDER STATUS UPDATE + CUSTOMER EMAIL NOTIFICATION ===== */
if (isset($_POST['update_order_status'])) {
    $oid = (int) $_POST['order_id'];
    $status = $_POST['status'] ?? 'Pending';
    $allowed = ['Pending', 'Paid', 'Packed', 'Shipped', 'Completed', 'Cancelled'];

    if (!in_array($status, $allowed, true)) {
        $status = 'Pending';
    }

    $stmt = $conn->prepare("UPDATE orders SET status=? WHERE id=?");
    $stmt->bind_param("si", $status, $oid);

    if ($stmt->execute()) {
        $msg = "Order status updated!";

        $infoStmt = $conn->prepare("
            SELECT 
                o.id,
                o.customer_name,
                o.customer_email,
                o.status,
                p.name AS product_name
            FROM orders o
            JOIN products p ON p.id = o.product_id
            WHERE o.id = ?
            LIMIT 1
        ");
        $infoStmt->bind_param("i", $oid);
        $infoStmt->execute();
        $orderInfo = $infoStmt->get_result()->fetch_assoc();

        if ($orderInfo) {
            [$emailOk, $emailInfo] = send_order_status_email(
                $orderInfo['customer_email'] ?? '',
                $orderInfo['customer_name'] ?? 'Customer',
                $orderInfo['product_name'] ?? 'Laptop',
                $status,
                $oid,
                $settings['phone'] ?? '+94 704875024'
            );

            if ($emailOk) {
                $msg = "Order status updated! Status email sent to customer.";
            } else {
                $msg = "Order status updated! Email not sent: " . $emailInfo;
            }
        }
    } else {
        $msg = "Order status update failed!";
    }
}

/* ===== DELETE ORDER ===== */
if (isset($_POST['delete_order'])) {
    $oid = (int) $_POST['order_id'];
    $conn->query("DELETE FROM orders WHERE id=$oid");
    $msg = "Order deleted!";
}

/* ===== LOAD DATA ===== */
$settings = $conn->query("SELECT * FROM site_settings LIMIT 1")->fetch_assoc();
$settings['shop_name'] = $settings['shop_name'] ?? ($settings['site_name'] ?? 'Apple Store');
$settings['shop_location'] = $settings['shop_location'] ?? ($settings['address'] ?? 'Polonnaruwa');
$settings['shop_description'] = $settings['shop_description'] ?? 'We provide quality laptops, student laptops, business laptops and gaming laptops with friendly customer support.';
$settings['google_map_link'] = $settings['google_map_link'] ?? '';
$settings['google_map_iframe'] = $settings['google_map_iframe'] ?? '';
$cats = $conn->query("SELECT * FROM categories ORDER BY id DESC");
$brands = $conn->query("SELECT * FROM brands ORDER BY name ASC");

$prods = $conn->query("
    SELECT p.*, c.name AS category_name, b.name AS brand_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN brands b ON b.id = p.brand_id
    ORDER BY p.id DESC
");

$orders = $conn->query("
  SELECT o.*, p.name AS product_name, p.price AS product_price
  FROM orders o
  JOIN products p ON p.id = o.product_id
  ORDER BY o.created_at DESC
");


/* ===== CUSTOMERS ===== */
$customers = $conn->query("
    SELECT *
    FROM customers
    ORDER BY id DESC
");

$stat_customers = (int) (
    $conn->query("SELECT COUNT(*) c FROM customers")
        ->fetch_assoc()['c'] ?? 0
);

/* ===== DASHBOARD STATS ===== */
$stat_products = (int) ($conn->query("SELECT COUNT(*) c FROM products")->fetch_assoc()['c'] ?? 0);
$stat_orders = (int) ($conn->query("SELECT COUNT(*) c FROM orders")->fetch_assoc()['c'] ?? 0);
$stat_pending = (int) ($conn->query("SELECT COUNT(*) c FROM orders WHERE status='Pending'")->fetch_assoc()['c'] ?? 0);
$stat_sales = (float) ($conn->query("
  SELECT COALESCE(SUM(p.price * o.qty),0) total
  FROM orders o
  JOIN products p ON p.id=o.product_id
  WHERE o.status IN ('Paid','Shipped','Completed')
")->fetch_assoc()['total'] ?? 0);

$stat_low_stock = (int) (
    $conn->query("SELECT COUNT(*) c FROM products WHERE stock_quantity > 0 AND stock_quantity <= 5")
        ->fetch_assoc()['c'] ?? 0
);

/* ===== DASHBOARD RECENT ORDERS ===== */
$recent_orders = $conn->query("
    SELECT
        o.id,
        o.customer_name,
        o.qty,
        o.status,
        o.created_at,
        p.name AS product_name,
        p.price AS product_price
    FROM orders o
    JOIN products p ON p.id = o.product_id
    ORDER BY o.created_at DESC, o.id DESC
    LIMIT 8
");

/* ===== DASHBOARD TOP-SELLING PRODUCTS ===== */
$top_selling_products = $conn->query("
    SELECT
        p.id,
        p.name,
        SUM(o.qty) AS units_sold,
        SUM(p.price * o.qty) AS revenue
    FROM orders o
    JOIN products p ON p.id = o.product_id
    WHERE o.status IN ('Paid','Shipped','Completed')
    GROUP BY p.id, p.name
    ORDER BY units_sold DESC, revenue DESC
    LIMIT 5
");

/* ===== SALES REPORT ===== */
$today_sales = (float) ($conn->query("
  SELECT COALESCE(SUM(p.price * o.qty),0) total
  FROM orders o
  JOIN products p ON p.id=o.product_id
  WHERE o.status IN ('Paid','Shipped','Completed')
    AND DATE(o.created_at) = CURDATE()
")->fetch_assoc()['total'] ?? 0);

$month_sales = (float) ($conn->query("
  SELECT COALESCE(SUM(p.price * o.qty),0) total
  FROM orders o
  JOIN products p ON p.id=o.product_id
  WHERE o.status IN ('Paid','Shipped','Completed')
    AND YEAR(o.created_at)=YEAR(CURDATE())
    AND MONTH(o.created_at)=MONTH(CURDATE())
")->fetch_assoc()['total'] ?? 0);

$daily_report = $conn->query("
  SELECT DATE(o.created_at) as day,
         COUNT(*) as orders_count,
         COALESCE(SUM(p.price * o.qty),0) as total
  FROM orders o
  JOIN products p ON p.id=o.product_id
  WHERE o.status IN ('Paid','Shipped','Completed')
    AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
  GROUP BY DATE(o.created_at)
  ORDER BY day DESC
");

$monthly_report = $conn->query("
  SELECT DATE_FORMAT(o.created_at, '%Y-%m') as month,
         COUNT(*) as orders_count,
         COALESCE(SUM(p.price * o.qty),0) as total
  FROM orders o
  JOIN products p ON p.id=o.product_id
  WHERE o.status IN ('Paid','Shipped','Completed')
    AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
  GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
  ORDER BY month DESC
");

$daily_chart_rs = $conn->query("
  SELECT DATE(o.created_at) AS d, COALESCE(SUM(p.price*o.qty),0) total
  FROM orders o
  JOIN products p ON p.id=o.product_id
  WHERE o.status IN ('Paid','Shipped','Completed')
    AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
  GROUP BY DATE(o.created_at)
  ORDER BY d ASC
");

$dailyMap = [];
while ($row = $daily_chart_rs->fetch_assoc()) {
    $dailyMap[$row['d']] = (float) $row['total'];
}

$chartDailyLabels = [];
$chartDailyData = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i day"));
    $chartDailyLabels[] = date('M d', strtotime($day));
    $chartDailyData[] = $dailyMap[$day] ?? 0;
}

$monthTotalForLine = (float) ($conn->query("
  SELECT COALESCE(SUM(p.price * o.qty),0) total
  FROM orders o
  JOIN products p ON p.id=o.product_id
  WHERE o.status IN ('Paid','Shipped','Completed')
    AND YEAR(o.created_at)=YEAR(CURDATE())
    AND MONTH(o.created_at)=MONTH(CURDATE())
")->fetch_assoc()['total'] ?? 0);

$chartMonthlyLine = array_fill(0, 7, $monthTotalForLine);

$avg = (float) ($conn->query("
  SELECT COALESCE(AVG(day_total),0) avg_total FROM (
    SELECT DATE(o.created_at) d, SUM(p.price*o.qty) day_total
    FROM orders o
    JOIN products p ON p.id=o.product_id
    WHERE o.status IN ('Paid','Shipped','Completed')
      AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    GROUP BY DATE(o.created_at)
  ) x
")->fetch_assoc()['avg_total'] ?? 0);

$best = $conn->query("
  SELECT DATE_FORMAT(o.created_at,'%Y-%m') m, SUM(p.price*o.qty) total
  FROM orders o
  JOIN products p ON p.id=o.product_id
  WHERE o.status IN ('Paid','Shipped','Completed')
    AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
  GROUP BY DATE_FORMAT(o.created_at,'%Y-%m')
  ORDER BY total DESC
  LIMIT 1
")->fetch_assoc();

$bestMonth = $best['m'] ?? '-';
$bestTotal = (float) ($best['total'] ?? 0);
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard</title>
    <style>
        :root {
            --b1: #0b5ed7;
            --b2: #0a3d91;
            --bg: #f4f7fb;
            --card: #fff;
            --shadow: 0 12px 28px rgba(16, 24, 40, .10);
            --r: 14px;
        }

        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            font-family: system-ui, Arial;
            background: var(--bg);
            color: #0f172a
        }

        .topbar {
            background: linear-gradient(90deg, var(--b2), var(--b1));
            color: #fff;
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center
        }

        .topbar a {
            color: #fff;
            text-decoration: none;
            font-weight: 900;
            background: rgba(255, 255, 255, .15);
            padding: 8px 12px;
            border-radius: 10px
        }

        .wrapper {
            display: grid;
            grid-template-columns: 240px 1fr;
            min-height: calc(100vh - 56px)
        }

        .sidebar {
            background: linear-gradient(180deg, #0a3d91, #083170);
            color: #fff;
            padding: 18px
        }

        .sideItem {
            display: flex;
            gap: 10px;
            align-items: center;
            padding: 10px 12px;
            border-radius: 12px;
            color: rgba(255, 255, 255, .92);
            text-decoration: none;
            font-weight: 800;
            margin-bottom: 8px
        }

        .sideItem:hover {
            background: rgba(255, 255, 255, .12)
        }

        .sideItem.active {
            background: rgba(255, 255, 255, .18)
        }

        .content {
            padding: 22px
        }

        .card {
            background: var(--card);
            border-radius: var(--r);
            box-shadow: var(--shadow);
            border: 1px solid rgba(2, 6, 23, .06);
            padding: 16px;
            margin-bottom: 16px
        }

        h2,
        h3,
        h4 {
            margin-top: 0
        }

        small {
            color: #64748b;
            font-weight: 700
        }

        .msg {
            margin: 10px 0;
            color: #16a34a;
            font-weight: 900
        }

        .grid2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px
        }

        .grid4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px
        }

        /* ===== Dashboard Analytics UI ===== */
        .dashboardHeader {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 18px;
        }

        .dashboardHeader h2 {
            margin-bottom: 5px;
        }

        .dashboardHeaderText {
            color: #64748b;
            font-size: 13px;
            font-weight: 750;
        }

        .analyticsStats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-top: 14px;
        }

        .analyticsStat {
            position: relative;
            overflow: hidden;
            min-height: 126px;
            padding: 18px;
            border-radius: 18px;
            background: linear-gradient(145deg, #ffffff, #f8fbff);
            border: 1px solid #dbeafe;
            box-shadow: 0 10px 26px rgba(15, 23, 42, .07);
        }

        .analyticsStat::after {
            content: "";
            position: absolute;
            width: 92px;
            height: 92px;
            right: -34px;
            top: -34px;
            border-radius: 50%;
            background: rgba(11, 94, 215, .08);
        }

        .analyticsStatIcon {
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: #eff6ff;
            margin-bottom: 12px;
            font-size: 18px;
        }

        .analyticsStatTitle {
            color: #64748b;
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .analyticsStatValue {
            margin-top: 5px;
            color: #0f172a;
            font-size: 27px;
            line-height: 1.1;
            font-weight: 950;
        }

        .analyticsStatMeta {
            margin-top: 7px;
            color: #94a3b8;
            font-size: 11px;
            font-weight: 750;
        }

        .dashboardPanels {
            display: grid;
            grid-template-columns: 1.35fr 1fr;
            gap: 16px;
            margin-top: 18px;
        }

        .analyticsPanel {
            min-width: 0;
            padding: 18px;
            border-radius: 18px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 26px rgba(15, 23, 42, .06);
        }

        .analyticsPanelHead {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }

        .analyticsPanelHead h4 {
            margin: 0;
            font-size: 17px;
        }

        .analyticsPanelHead small {
            font-size: 11px;
        }

        .analyticsTableWrap {
            overflow-x: auto;
        }

        .analyticsTable {
            width: 100%;
            border-collapse: collapse;
        }

        .analyticsTable th,
        .analyticsTable td {
            padding: 11px 9px;
            text-align: left;
            border-bottom: 1px solid #eef2f7;
            font-size: 12px;
            vertical-align: middle;
            white-space: nowrap;
        }

        .analyticsTable th {
            color: #64748b;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .03em;
            font-weight: 900;
        }

        .analyticsTable tbody tr:last-child td {
            border-bottom: 0;
        }

        .analyticsProductName {
            max-width: 210px;
            overflow: hidden;
            text-overflow: ellipsis;
            font-weight: 850;
            color: #0f172a;
        }

        .analyticsEmpty {
            text-align: center !important;
            color: #94a3b8;
            padding: 24px !important;
            font-weight: 750;
        }

        input,
        textarea,
        select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            margin-top: 6px;
            font-family: inherit
        }

        textarea {
            min-height: 88px;
            resize: vertical
        }

        label {
            font-weight: 900;
            font-size: 13px
        }

        .btn {
            border: 0;
            background: var(--b1);
            color: #fff;
            font-weight: 900;
            padding: 10px 14px;
            border-radius: 10px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block
        }

        .btn.danger {
            background: #dc2626
        }

        .btn.secondary {
            background: #64748b
        }

        .table {
            width: 100%;
            border-collapse: collapse
        }

        .table th,
        .table td {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
            font-size: 13px;
            vertical-align: top
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            background: #eef4ff;
            color: #0b5ed7;
            font-weight: 900;
            font-size: 12px
        }

        .badge.pending {
            background: #fff7ed;
            color: #9a3412
        }

        .badge.paid {
            background: #ede9fe;
            color: #6d28d9
        }

        .badge.packed {
            background: #e0f2fe;
            color: #075985
        }

        .badge.shipped {
            background: #ecfeff;
            color: #155e75
        }

        .badge.completed {
            background: #dcfce7;
            color: #166534
        }

        .badge.cancelled {
            background: #fee2e2;
            color: #991b1b
        }

        .stockBadge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 999px;
            font-weight: 900;
            font-size: 12px;
            white-space: nowrap;
        }

        .stockBadge.in {
            background: #dcfce7;
            color: #166534;
        }

        .stockBadge.low {
            background: #fef3c7;
            color: #92400e;
        }

        .stockBadge.out {
            background: #fee2e2;
            color: #991b1b;
        }

        .thumb {
            width: 54px;
            height: 40px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid rgba(2, 6, 23, .08)
        }

        .thumbLg {
            width: 90px;
            height: 70px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid rgba(2, 6, 23, .08)
        }

        .chartWrap {
            height: 240px
        }

        .chartLegend {
            display: flex;
            gap: 18px;
            justify-content: center;
            margin-top: 8px;
            font-weight: 800;
            color: #334155;
            font-size: 12px
        }

        .dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            display: inline-block;
            margin-right: 6px;
            vertical-align: middle
        }

        .dot.daily {
            background: #0b5ed7
        }

        .dot.monthly {
            background: #1d4ed8
        }

        .extraGrid {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 12px
        }

        .extraItem {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 8px;
            background: #fff
        }

        @media(max-width:900px) {
            .wrapper {
                grid-template-columns: 1fr
            }

            .sidebar {
                display: none
            }

            .grid2 {
                grid-template-columns: 1fr
            }

            .grid4 {
                grid-template-columns: 1fr 1fr
            }


            .analyticsStats {
                grid-template-columns: 1fr 1fr;
            }

            .dashboardPanels {
                grid-template-columns: 1fr;
            }
        }

        @media(max-width:520px) {
            .grid4 {
                grid-template-columns: 1fr
            }


            .analyticsStats {
                grid-template-columns: 1fr;
            }

            .dashboardHeader {
                flex-direction: column;
            }
        }

        /* ===== Modern Admin UI Upgrade ===== */
        body {
            background:
                radial-gradient(circle at 12% 8%, rgba(11, 94, 215, .12), transparent 28%),
                radial-gradient(circle at 85% 18%, rgba(124, 58, 237, .10), transparent 30%),
                linear-gradient(135deg, #eef4ff 0%, #f8fafc 45%, #edf6ff 100%) !important;
            overflow-x: hidden;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(16px);
            box-shadow: 0 14px 30px rgba(8, 49, 112, .20);
            border-bottom: 1px solid rgba(255, 255, 255, .18);
        }

        .topbar a {
            transition: transform .25s ease, background .25s ease, box-shadow .25s ease;
        }

        .topbar a:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, .24);
            box-shadow: 0 10px 20px rgba(0, 0, 0, .12);
        }

        .sidebar {
            position: sticky;
            top: 56px;
            height: calc(100vh - 56px);
            overflow-y: auto;
            background:
                linear-gradient(180deg, rgba(10, 61, 145, .98), rgba(8, 49, 112, .98)),
                radial-gradient(circle at 30% 10%, rgba(255, 255, 255, .16), transparent 35%) !important;
            box-shadow: 12px 0 30px rgba(15, 23, 42, .12);
        }

        .sideItem {
            position: relative;
            overflow: hidden;
            transition: transform .25s ease, background .25s ease, box-shadow .25s ease;
            font-size: 15px;
        }

        .sideItem::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, rgba(255, 255, 255, .20), transparent);
            transform: translateX(-105%);
            transition: transform .35s ease;
        }

        .sideItem:hover::before,
        .sideItem.active::before {
            transform: translateX(0);
        }

        .sideItem:hover {
            transform: translateX(4px);
            box-shadow: 0 12px 22px rgba(0, 0, 0, .12);
        }

        .content {
            padding: 28px;
        }

        .card {
            position: relative;
            background: rgba(255, 255, 255, .86) !important;
            backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, .72) !important;
            box-shadow: 0 18px 42px rgba(15, 23, 42, .10) !important;
            transition: transform .3s ease, box-shadow .3s ease, border-color .3s ease;
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 26px 52px rgba(15, 23, 42, .14) !important;
            border-color: rgba(11, 94, 215, .20) !important;
        }

        h2,
        h3,
        h4 {
            letter-spacing: -.03em;
            color: #0f172a;
        }

        h2 {
            font-size: clamp(24px, 3vw, 34px);
        }

        h3 {
            font-size: clamp(20px, 2.2vw, 26px);
        }

        .grid4>.card {
            background:
                linear-gradient(135deg, rgba(255, 255, 255, .95), rgba(239, 246, 255, .90)) !important;
            overflow: hidden;
        }

        .grid4>.card::after {
            content: "";
            position: absolute;
            width: 90px;
            height: 90px;
            right: -34px;
            top: -34px;
            border-radius: 50%;
            background: rgba(11, 94, 215, .10);
        }

        input,
        textarea,
        select {
            background: rgba(255, 255, 255, .92);
            transition: border-color .25s ease, box-shadow .25s ease, transform .25s ease;
            outline: none;
        }

        input:focus,
        textarea:focus,
        select:focus {
            border-color: var(--b1);
            box-shadow: 0 0 0 4px rgba(11, 94, 215, .12);
            transform: translateY(-1px);
        }

        .btn {
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 18px rgba(11, 94, 215, .18);
            transition: transform .25s ease, box-shadow .25s ease, filter .25s ease;
        }

        .btn::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, transparent, rgba(255, 255, 255, .32), transparent);
            transform: translateX(-120%);
            transition: transform .55s ease;
        }

        .btn:hover::after {
            transform: translateX(120%);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 26px rgba(11, 94, 215, .24);
            filter: brightness(1.03);
        }

        .btn.danger {
            box-shadow: 0 10px 18px rgba(220, 38, 38, .18);
        }

        .table {
            overflow: hidden;
            border-radius: 16px;
            background: rgba(255, 255, 255, .75);
        }

        .table thead th {
            background: #eef4ff;
            color: #0a3d91;
            font-weight: 950;
        }

        .table tbody tr {
            transition: background .22s ease, transform .22s ease;
        }

        .table tbody tr:hover {
            background: #f8fafc;
        }

        .badge {
            box-shadow: 0 8px 16px rgba(15, 23, 42, .08);
        }

        .thumb,
        .thumbLg {
            transition: transform .25s ease, box-shadow .25s ease;
        }

        .thumb:hover,
        .thumbLg:hover {
            transform: scale(1.06);
            box-shadow: 0 12px 22px rgba(15, 23, 42, .16);
        }

        .msg {
            background: #dcfce7;
            color: #166534 !important;
            border: 1px solid #bbf7d0;
            border-radius: 14px;
            padding: 12px 14px;
        }

        #scrollTopBtn {
            position: fixed;
            right: 22px;
            bottom: 22px;
            width: 48px;
            height: 48px;
            border-radius: 16px;
            border: 0;
            background: linear-gradient(135deg, var(--b1), var(--b2));
            color: #fff;
            font-weight: 950;
            cursor: pointer;
            box-shadow: 0 16px 34px rgba(11, 94, 215, .32);
            opacity: 0;
            pointer-events: none;
            transform: translateY(16px);
            transition: opacity .25s ease, transform .25s ease;
            z-index: 999;
        }

        #scrollTopBtn.show {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0);
        }

        #scrollTopBtn:hover {
            transform: translateY(-3px);
        }

        @media(max-width:900px) {
            .topbar {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }

            .wrapper {
                display: block;
            }

            .content {
                padding: 16px;
            }

            .card {
                border-radius: 18px;
            }

            .table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }

        @media(max-width:520px) {
            .topbar {
                padding: 12px;
            }

            .topbar>div:first-child {
                font-size: 14px;
            }

            .topbar a {
                padding: 7px 10px;
                font-size: 12px;
            }

            .content {
                padding: 12px;
            }

            .card {
                padding: 14px;
            }

            input,
            textarea,
            select {
                font-size: 14px;
            }

            .btn {
                width: 100%;
                text-align: center;
                justify-content: center;
            }

            form[style*="display:flex"] {
                flex-direction: column !important;
                align-items: stretch !important;
            }

            #scrollTopBtn {
                right: 14px;
                bottom: 14px;
            }
        }
    </style>
</head>

<body>

    <div class="topbar">
        <div style="font-weight:950">
            <?= e($settings['site_name'] ?? 'Online Laptop Store') ?> — Admin Dashboard
        </div>
        <div style="display:flex;gap:10px;align-items:center">
            <a href="index.php" target="_blank">Open Site</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <div class="wrapper">
        <aside class="sidebar">
            <a class="sideItem active" href="#dashboard">📌 Dashboard</a>
            <a class="sideItem" href="#sales">📊 Sales Report</a>
            <a class="sideItem" href="#settings">⚙️ Site Settings</a>
            <a class="sideItem" href="#location-settings">📍 Location Update</a>
            <a class="sideItem" href="#categories">🗂️ Categories</a>
            <a class="sideItem" href="#products">💻 Products</a>
            <a class="sideItem" href="#orders">🧾 Orders</a>
            <a class="sideItem" href="#customers">👥 Customers</a>
        </aside>

        <main class="content">

            <section class="card" id="dashboard">
                <div class="dashboardHeader">
                    <div>
                        <h2>Admin Dashboard Analytics</h2>
                        <div class="dashboardHeaderText">
                            Store overview, recent orders and top-selling products
                        </div>
                    </div>
                </div>

                <?php if (!empty($msg))
                    echo "<div class='msg'>" . e($msg) . "</div>"; ?>

                <div class="analyticsStats">
                    <div class="analyticsStat">
                        <div class="analyticsStatIcon">💻</div>
                        <div class="analyticsStatTitle">Total Products</div>
                        <div class="analyticsStatValue"><?= (int) $stat_products ?></div>
                        <div class="analyticsStatMeta">Products in catalog</div>
                    </div>

                    <div class="analyticsStat">
                        <div class="analyticsStatIcon">👥</div>
                        <div class="analyticsStatTitle">Total Customers</div>
                        <div class="analyticsStatValue"><?= (int) $stat_customers ?></div>
                        <div class="analyticsStatMeta">Registered customer accounts</div>
                    </div>

                    <div class="analyticsStat">
                        <div class="analyticsStatIcon">🧾</div>
                        <div class="analyticsStatTitle">Total Orders</div>
                        <div class="analyticsStatValue"><?= (int) $stat_orders ?></div>
                        <div class="analyticsStatMeta">All customer orders</div>
                    </div>

                    <div class="analyticsStat">
                        <div class="analyticsStatIcon">⏳</div>
                        <div class="analyticsStatTitle">Pending Orders</div>
                        <div class="analyticsStatValue"><?= (int) $stat_pending ?></div>
                        <div class="analyticsStatMeta">Waiting to be processed</div>
                    </div>

                    <div class="analyticsStat">
                        <div class="analyticsStatIcon">💰</div>
                        <div class="analyticsStatTitle">Revenue</div>
                        <div class="analyticsStatValue">RS.<?= number_format($stat_sales, 2) ?></div>
                        <div class="analyticsStatMeta">Paid, shipped and completed orders</div>
                    </div>

                    <div class="analyticsStat">
                        <div class="analyticsStatIcon">⚠️</div>
                        <div class="analyticsStatTitle">Low Stock</div>
                        <div class="analyticsStatValue"><?= (int) $stat_low_stock ?></div>
                        <div class="analyticsStatMeta">Products with 1–5 units remaining</div>
                    </div>
                </div>

                <div class="dashboardPanels">
                    <div class="analyticsPanel">
                        <div class="analyticsPanelHead">
                            <h4>Recent Orders</h4>
                            <small>Latest 8 orders</small>
                        </div>

                        <div class="analyticsTableWrap">
                            <table class="analyticsTable">
                                <thead>
                                    <tr>
                                        <th>Order</th>
                                        <th>Product</th>
                                        <th>Customer</th>
                                        <th>Total</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($recent_orders && $recent_orders->num_rows > 0): ?>
                                        <?php while ($ro = $recent_orders->fetch_assoc()):
                                            $recentStatus = $ro['status'] ?? 'Pending';
                                            $recentClass = strtolower($recentStatus);
                                            $recentTotal = (float) $ro['product_price'] * (int) $ro['qty'];
                                            ?>
                                            <tr>
                                                <td><b>#<?= (int) $ro['id'] ?></b></td>
                                                <td>
                                                    <div class="analyticsProductName"><?= e($ro['product_name']) ?></div>
                                                    <small>Qty: <?= (int) $ro['qty'] ?></small>
                                                </td>
                                                <td><?= e($ro['customer_name']) ?></td>
                                                <td><b>$<?= number_format($recentTotal, 2) ?></b></td>
                                                <td>
                                                    <span class="badge <?= e($recentClass) ?>"><?= e($recentStatus) ?></span>
                                                </td>
                                                <td><?= e($ro['created_at']) ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="analyticsEmpty">No orders available.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="analyticsPanel">
                        <div class="analyticsPanelHead">
                            <h4>Top-Selling Products</h4>
                            <small>Paid / Shipped / Completed</small>
                        </div>

                        <div class="analyticsTableWrap">
                            <table class="analyticsTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th>Units Sold</th>
                                        <th>Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($top_selling_products && $top_selling_products->num_rows > 0): ?>
                                        <?php $topRank = 1; ?>
                                        <?php while ($tp = $top_selling_products->fetch_assoc()): ?>
                                            <tr>
                                                <td><b><?= $topRank++ ?></b></td>
                                                <td>
                                                    <div class="analyticsProductName"><?= e($tp['name']) ?></div>
                                                </td>
                                                <td><b><?= (int) $tp['units_sold'] ?></b></td>
                                                <td><b>RS.<?= number_format((float) $tp['revenue'], 2) ?></b></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="analyticsEmpty">No completed sales available yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card" id="sales">
                <h3>Sales Report (Real Database)</h3>
                <small>Counts Paid / Shipped / Completed orders ✅</small>

                <div class="grid4" style="margin-top:12px">
                    <div class="card" style="margin:0">
                        <div style="font-weight:900;color:#64748b">Today Sales</div>
                        <div style="font-size:26px;font-weight:950;margin-top:6px">
                            RS.<?= number_format($today_sales, 2) ?></div>
                        <small><?= date("Y-m-d") ?></small>
                    </div>
                    <div class="card" style="margin:0">
                        <div style="font-weight:900;color:#64748b">This Month Sales</div>
                        <div style="font-size:26px;font-weight:950;margin-top:6px">
                            RS.<?= number_format($month_sales, 2) ?></div>
                        <small><?= date("Y-m") ?></small>
                    </div>
                    <div class="card" style="margin:0">
                        <div style="font-weight:900;color:#64748b">Avg/Day (14 days)</div>
                        <div style="font-size:26px;font-weight:950;margin-top:6px">RS.<?= number_format($avg, 2) ?>
                        </div>
                        <small>Last 14 days</small>
                    </div>
                    <div class="card" style="margin:0">
                        <div style="font-weight:900;color:#64748b">Best Month (12 months)</div>
                        <div style="font-size:26px;font-weight:950;margin-top:6px">
                            RS.<?= number_format($bestTotal, 2) ?>
                        </div>
                        <small><?= e($bestMonth) ?></small>
                    </div>
                </div>

                <div class="card" style="margin-top:14px">
                    <div style="font-weight:950;margin-bottom:8px">Sales Analytics</div>
                    <div class="chartWrap"><canvas id="salesChart"></canvas></div>
                    <div class="chartLegend">
                        <div><span class="dot daily"></span>Daily Sales</div>
                        <div><span class="dot monthly"></span>This Month Total</div>
                    </div>
                </div>

                <div class="grid2" style="margin-top:14px">
                    <div class="card" style="margin:0">
                        <h4>Daily Sales (Last 14 Days)</h4>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Orders</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($d = $daily_report->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= e($d['day']) ?></td>
                                        <td><?= (int) $d['orders_count'] ?></td>
                                        <td><b>RS.<?= number_format((float) $d['total'], 2) ?></b></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="card" style="margin:0">
                        <h4>Monthly Sales (Last 12 Months)</h4>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th>Orders</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($m = $monthly_report->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= e($m['month']) ?></td>
                                        <td><?= (int) $m['orders_count'] ?></td>
                                        <td><b>RS.<?= number_format((float) $m['total'], 2) ?></b></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="card" id="settings">
                <h3>Site Settings (Hero / About / Footer / Shop Location)</h3>
                <form method="post" class="grid2">
                    <div>
                        <label>Site Name</label>
                        <input name="site_name" value="<?= e($settings['site_name']) ?>" required>

                        <label>Hero Title</label>
                        <input name="hero_title" value="<?= e($settings['hero_title']) ?>" required>

                        <label>Hero Subtitle</label>
                        <input name="hero_subtitle" value="<?= e($settings['hero_subtitle']) ?>" required>

                        <label>Hero Button Text</label>
                        <input name="hero_button_text" value="<?= e($settings['hero_button_text']) ?>" required>

                        <label>Hero Button Link</label>
                        <input name="hero_button_link" value="<?= e($settings['hero_button_link']) ?>" required>
                    </div>

                    <div>
                        <label>About Text</label>
                        <textarea name="about_text" required><?= e($settings['about_text']) ?></textarea>

                        <label>Footer Contact Label</label>
                        <input name="contact_label" value="<?= e($settings['contact_label']) ?>" required>

                        <label>Phone</label>
                        <input name="phone" value="<?= e($settings['phone']) ?>" required>

                        <label>Email</label>
                        <input name="email" value="<?= e($settings['email']) ?>" required>

                        <label>Footer Address</label>
                        <input name="address" value="<?= e($settings['address']) ?>" placeholder="Polonnaruwa" required>

                        <label>Shop Name</label>
                        <input name="shop_name" value="<?= e($settings['shop_name'] ?? 'Apple Store') ?>"
                            placeholder="Apple Store" required>

                        <label>Shop Location</label>
                        <input name="shop_location" value="<?= e($settings['shop_location'] ?? 'Polonnaruwa') ?>"
                            placeholder="Polonnaruwa" required>

                        <label>Shop Description</label>
                        <textarea name="shop_description" style="min-height:90px"
                            required><?= e($settings['shop_description'] ?? 'We provide quality laptops, student laptops, business laptops and gaming laptops with friendly customer support.') ?></textarea>

                        <label>Google Maps Link</label>
                        <input name="google_map_link" value="<?= e($settings['google_map_link'] ?? '') ?>"
                            placeholder="https://www.google.com/maps/search/?api=1&query=Apple%20Store%20Polonnaruwa">

                        <label>Google Map Embed iframe</label>
                        <textarea name="google_map_iframe" placeholder="Paste Google Maps embed iframe here"
                            style="min-height:120px"><?= e($settings['google_map_iframe'] ?? '') ?></textarea>

                        <?php if (!empty($settings['google_map_iframe'])): ?>
                            <div
                                style="margin-top:12px;border-radius:14px;overflow:hidden;border:1px solid #dbeafe;background:#fff">
                                <div style="padding:8px 10px;font-weight:900;color:#0b5ed7">Map Preview</div>
                                <div style="height:220px">
                                    <?= $settings['google_map_iframe'] ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top:12px">
                            <button class="btn" name="save_settings">Save Settings</button>
                        </div>
                    </div>
                </form>
            </section>

            <section class="card" id="location-settings">
                <h3>📍 Shop Location Update</h3>
                <small>Update this section and it will be used on the home page shop location area.</small>

                <form method="post" class="grid2" style="margin-top:14px">
                    <div>
                        <label>Shop Name</label>
                        <input name="shop_name" value="<?= e($settings['shop_name'] ?? 'Apple Store') ?>"
                            placeholder="Apple Store" required>

                        <label>Shop Location</label>
                        <input name="shop_location" value="<?= e($settings['shop_location'] ?? 'Polonnaruwa') ?>"
                            placeholder="Polonnaruwa" required>

                        <label>Shop Phone</label>
                        <input name="phone" value="<?= e($settings['phone'] ?? '+94 704875024') ?>"
                            placeholder="+94 704875024" required>

                        <label>Shop Email</label>
                        <input name="email" value="<?= e($settings['email'] ?? 'mhdaseem216@gmail.com') ?>"
                            placeholder="mhdaseem216@gmail.com" required>

                        <label>Footer Address</label>
                        <input name="address" value="<?= e($settings['address'] ?? 'Polonnaruwa') ?>"
                            placeholder="Polonnaruwa">
                    </div>

                    <div>
                        <label>Shop Description</label>
                        <textarea name="shop_description" style="min-height:95px"
                            required><?= e($settings['shop_description'] ?? 'We provide quality laptops, student laptops, business laptops and gaming laptops with friendly customer support.') ?></textarea>

                        <label>Google Maps Link</label>
                        <input name="google_map_link" value="<?= e($settings['google_map_link'] ?? '') ?>"
                            placeholder="https://www.google.com/maps/search/?api=1&query=Apple%20Store%20Polonnaruwa">

                        <label>Google Map Embed iframe</label>
                        <textarea name="google_map_iframe" style="min-height:130px"
                            placeholder="Google Maps → Share → Embed a map → Copy HTML → paste here"><?= e($settings['google_map_iframe'] ?? '') ?></textarea>


                        <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">
                            <button class="btn" name="save_location_settings" type="submit">Save Location</button>
                            <?php if (!empty($settings['google_map_link'])): ?>
                                <a class="btn" style="background:#16a34a" href="<?= e($settings['google_map_link']) ?>"
                                    target="_blank">Open Map</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>

                <?php if (!empty($settings['google_map_iframe'])): ?>
                    <div
                        style="margin-top:18px;border-radius:18px;overflow:hidden;border:1px solid #dbeafe;background:#fff;box-shadow:0 12px 25px rgba(15,23,42,.08)">
                        <div style="padding:10px 14px;font-weight:950;color:#0b5ed7;background:#eff6ff">Current Map Preview
                        </div>
                        <div style="height:320px">
                            <?= $settings['google_map_iframe'] ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card" id="categories">
                <h3>Shop by Category (Upload Image)</h3>
                <form method="post" enctype="multipart/form-data" class="grid2" style="margin-bottom:14px">
                    <div>
                        <label>Category Name</label>
                        <input name="cat_name" placeholder="Gaming Laptops" required>
                    </div>
                    <div>
                        <label>Category Image (jpg/png/webp)</label>
                        <input type="file" name="cat_image_file" accept=".jpg,.jpeg,.png,.webp" required>
                    </div>
                    <div><button class="btn" name="add_category">Add Category</button></div>
                </form>

                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Preview</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($c = $cats->fetch_assoc()): ?>
                            <tr>
                                <td><?= (int) $c['id'] ?></td>
                                <td><?= e($c['name']) ?></td>
                                <td><?php if (!empty($c['image_url'])): ?><img class="thumb" src="<?= e($c['image_url']) ?>"
                                            alt=""><?php endif; ?></td>
                                <td>
                                    <form method="post" style="margin:0">
                                        <input type="hidden" name="cat_id" value="<?= (int) $c['id'] ?>">
                                        <button class="btn danger" name="delete_category"
                                            onclick="return confirm('Delete category?')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </section>

            <section class="card" id="products">
                <h3><?= $editProduct ? "Edit Product" : "Products (Upload Image)" ?></h3>

                <form method="post" enctype="multipart/form-data" class="grid2" style="margin-bottom:14px">
                    <?php if ($editProduct): ?>
                        <input type="hidden" name="product_id" value="<?= (int) $editProduct['id'] ?>">
                    <?php endif; ?>

                    <div>
                        <label>Product Name</label>
                        <input name="prod_name" placeholder="Dell XPS 13" value="<?= e($editProduct['name'] ?? '') ?>"
                            required>

                        <label>Description (Details page)</label>
                        <textarea name="prod_desc"
                            placeholder="Laptop details..."><?= e($editProduct['description'] ?? '') ?></textarea>

                        <label>Category</label>
                        <select name="category_id" id="productCategorySelect" required>
                            <option value="0">Select Category</option>
                            <?php
                            $catsForSelect = $conn->query("SELECT * FROM categories ORDER BY name ASC");
                            while ($catRow = $catsForSelect->fetch_assoc()):
                                ?>
                                <option value="<?= (int) $catRow['id'] ?>" <?= ((int) ($editProduct['category_id'] ?? 0) === (int) $catRow['id']) ? 'selected' : '' ?>>
                                    <?= e($catRow['name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>

                        <label>Brand</label>
                        <select name="brand_id" id="productBrandSelect" required <?= ((int) ($editProduct['category_id'] ?? 0) > 0) ? '' : 'disabled' ?>>
                            <option value="0">Select Brand</option>
                            <?php
                            $brandsForSelect = $conn->query("SELECT * FROM brands ORDER BY name ASC");
                            while ($brandRow = $brandsForSelect->fetch_assoc()):
                                ?>
                                <option value="<?= (int) $brandRow['id'] ?>" <?= ((int) ($editProduct['brand_id'] ?? 0) === (int) $brandRow['id']) ? 'selected' : '' ?>>
                                    <?= e($brandRow['name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div>
                        <label>Price</label>
                        <input name="prod_price" type="number" step="0.01" placeholder="999.00"
                            value="<?= e($editProduct['price'] ?? '') ?>" required>

                        <label>Offer Price</label>
                        <input name="offer_price" type="number" min="0" step="0.01" placeholder="0.00"
                            value="<?= e($editProduct['offer_price'] ?? '0') ?>">
                        <small>Leave 0 if there is no offer. For an offer, enter a value lower than the normal
                            Price.</small>

                        <label>Stock Quantity</label>
                        <input name="stock_quantity" type="number" min="0" step="1" placeholder="10"
                            value="<?= e($editProduct['stock_quantity'] ?? '0') ?>" required>
                        <small>Set 0 to mark this product as Out of Stock on the store.</small>

                        <label>Available Colors</label>
                        <input name="available_colors" type="text" placeholder="Black, Silver, Blue"
                            value="<?= e($editProduct['available_colors'] ?? '') ?>">
                        <small>
                            Separate colors with commas. Example: Black, Silver, Blue.
                            For an exact shade you can use: Midnight Blue:#191970
                        </small>

                        <label>Main Product Image (jpg/png/webp)</label>
                        <input type="file" name="prod_image_file" accept=".jpg,.jpeg,.png,.webp" <?= $editProduct ? '' : 'required' ?>>

                        <?php if ($editProduct && !empty($editProduct['image_url'])): ?>
                            <div style="margin-top:10px">
                                <small>Current Main Image</small><br>
                                <img class="thumbLg" src="<?= e($editProduct['image_url']) ?>" alt="">
                            </div>
                        <?php endif; ?>

                        <label style="margin-top:10px;display:block">Extra Images (up to 5)</label>
                        <input type="file" name="prod_images[]" accept=".jpg,.jpeg,.png,.webp" multiple>

                        <?php if ($editProduct && !empty($editExtraImages)): ?>
                            <div style="margin-top:12px">
                                <small>Current Extra Images (tick to delete)</small>
                                <div class="extraGrid">
                                    <?php foreach ($editExtraImages as $ex): ?>
                                        <div class="extraItem">
                                            <img class="thumbLg" src="<?= e($ex['image_url']) ?>" alt=""><br>
                                            <label style="font-size:12px;font-weight:700">
                                                <input type="checkbox" name="delete_extra_images[]"
                                                    value="<?= (int) $ex['id'] ?>">
                                                Delete
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div style="display:flex;gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap">
                            <label style="display:flex;gap:8px;align-items:center">
                                <input type="checkbox" name="is_featured" <?= !empty($editProduct) ? ((int) $editProduct['is_featured'] ? 'checked' : '') : 'checked' ?>> Featured
                            </label>

                            <?php if ($editProduct): ?>
                                <button class="btn" name="update_product">Update Product</button>
                                <a class="btn secondary" href="admin_dashboard.php#products">Cancel</a>
                            <?php else: ?>
                                <button class="btn" name="add_product">Add Product</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>

                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Brand</th>
                            <th>Price</th>
                            <th>Offer Price</th>
                            <th>Stock</th>
                            <th>Colors</th>
                            <th>Featured</th>
                            <th>Preview</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($p = $prods->fetch_assoc()): ?>
                            <tr>
                                <td><?= (int) $p['id'] ?></td>
                                <td><?= e($p['name']) ?></td>
                                <td><?= e($p['category_name'] ?? 'No Category') ?></td>
                                <td><?= e($p['brand_name'] ?? 'No Brand') ?></td>
                                <td>$<?= number_format((float) $p['price'], 2) ?></td>
                                <td>
                                    <?php
                                    $adminOfferPrice = (float) ($p['offer_price'] ?? 0);
                                    $adminRegularPrice = (float) ($p['price'] ?? 0);
                                    ?>
                                    <?= ($adminOfferPrice > 0 && $adminOfferPrice < $adminRegularPrice)
                                        ? '$' . number_format($adminOfferPrice, 2)
                                        : '-' ?>
                                </td>
                                <td>
                                    <?php
                                    $stockQty = (int) ($p['stock_quantity'] ?? 0);
                                    if ($stockQty <= 0) {
                                        echo "<span class='stockBadge out'>Out of Stock</span>";
                                    } elseif ($stockQty <= 5) {
                                        echo "<span class='stockBadge low'>Low Stock: " . $stockQty . "</span>";
                                    } else {
                                        echo "<span class='stockBadge in'>In Stock: " . $stockQty . "</span>";
                                    }
                                    ?>
                                </td>
                                <td><?= e(trim((string) ($p['available_colors'] ?? '')) !== '' ? $p['available_colors'] : '-') ?>
                                </td>
                                <td><?= $p['is_featured'] ? "<span class='badge'>Yes</span>" : "No" ?></td>
                                <td><?php if (!empty($p['image_url'])): ?><img class="thumb" src="<?= e($p['image_url']) ?>"
                                            alt=""><?php endif; ?></td>
                                <td>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                                        <a class="btn"
                                            href="admin_dashboard.php?edit_product=<?= (int) $p['id'] ?>#products">Edit</a>
                                        <form method="post" style="margin:0">
                                            <input type="hidden" name="prod_id" value="<?= (int) $p['id'] ?>">
                                            <button class="btn danger" name="delete_product"
                                                onclick="return confirm('Delete product?')">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </section>

            <section class="card" id="orders">
                <h3>Customer Orders</h3>
                <small>View orders + update status</small>

                <table class="table" style="margin-top:10px">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Product</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Qty</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Address</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($o = $orders->fetch_assoc()):
                            $st = $o['status'] ?? 'Pending';
                            $cls = strtolower($st);
                            $total = (float) $o['product_price'] * (int) $o['qty'];
                            ?>
                            <tr>
                                <td>#<?= (int) $o['id'] ?></td>
                                <td><?= e($o['product_name']) ?><br><small>$<?= number_format((float) $o['product_price'], 2) ?></small>
                                </td>
                                <td><?= e($o['customer_name']) ?></td>
                                <td><?= e($o['phone']) ?></td>
                                <td><?= (int) $o['qty'] ?></td>
                                <td><b>$<?= number_format($total, 2) ?></b></td>
                                <td>
                                    <span class="badge <?= e($cls) ?>"><?= e($st) ?></span>
                                    <form method="post" style="margin-top:8px;display:flex;gap:8px;align-items:center">
                                        <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                                        <select name="status" style="margin:0;min-width:140px">
                                            <?php foreach (['Pending', 'Paid', 'Packed', 'Shipped', 'Completed', 'Cancelled'] as $s): ?>
                                                <option value="<?= e($s) ?>" <?= $s === $st ? 'selected' : '' ?>><?= e($s) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn" name="update_order_status" type="submit">Update</button>
                                    </form>
                                </td>
                                <td><?= e($o['address']) ?></td>
                                <td><?= e($o['created_at']) ?></td>
                                <td>
                                    <form method="post" style="margin:0">
                                        <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                                        <button class="btn danger" name="delete_order"
                                            onclick="return confirm('Delete this order?')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </section>


            <section class="card" id="customers">
                <h3>Registered Customers</h3>
                <small>All customer registered accounts</small>

                <table class="table" style="margin-top:12px">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Registered Date</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($customers && $customers->num_rows > 0): ?>
                            <?php while ($cu = $customers->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?= (int) $cu['id'] ?></td>

                                    <td>
                                        <div style="display:flex;align-items:center;gap:10px">
                                            <div style="
                                                width:42px;
                                                height:42px;
                                                border-radius:50%;
                                                background:linear-gradient(135deg,#0b5ed7,#0a3d91);
                                                color:#fff;
                                                display:flex;
                                                align-items:center;
                                                justify-content:center;
                                                font-weight:950;
                                                font-size:18px;
                                            ">
                                                <?= strtoupper(substr($cu['name'], 0, 1)) ?>
                                            </div>

                                            <div>
                                                <div style="font-weight:900">
                                                    <?= e($cu['name']) ?>
                                                </div>
                                                <small style="color:#64748b">
                                                    Customer Account
                                                </small>
                                            </div>
                                        </div>
                                    </td>

                                    <td><?= e($cu['email']) ?></td>
                                    <td><?= e($cu['phone']) ?></td>
                                    <td><?= e($cu['created_at']) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center;padding:30px">
                                    No customers registered yet.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>

        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const labels = <?= json_encode($chartDailyLabels) ?>;
        const dailyData = <?= json_encode($chartDailyData) ?>;
        const monthlyLine = <?= json_encode($chartMonthlyLine) ?>;

        const ctx = document.getElementById('salesChart');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Daily Sales', data: dailyData, tension: 0.35, fill: true, pointRadius: 3, borderWidth: 2 },
                    { label: 'This Month Total', data: monthlyLine, tension: 0.35, fill: false, pointRadius: 0, borderWidth: 2 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    </script>


    <button id="scrollTopBtn" type="button" aria-label="Scroll to top">↑</button>

    <script>
        /* ===== Dashboard Navigation Helpers ===== */
        document.addEventListener('DOMContentLoaded', function () {
            const scrollBtn = document.getElementById('scrollTopBtn');

            window.addEventListener('scroll', function () {
                if (window.scrollY > 350) {
                    scrollBtn.classList.add('show');
                } else {
                    scrollBtn.classList.remove('show');
                }
            });

            scrollBtn.addEventListener('click', function () {
                window.scrollTo(0, 0);
            });

            document.querySelectorAll('.sideItem').forEach(link => {
                link.addEventListener('click', function () {
                    document.querySelectorAll('.sideItem').forEach(i => i.classList.remove('active'));
                    this.classList.add('active');
                });
            });
        });
    </script>

    <script>
        /* ===== Product Category → Brand Select ===== */
        (function () {
            const categorySelect = document.getElementById('productCategorySelect');
            const brandSelect = document.getElementById('productBrandSelect');

            if (!categorySelect || !brandSelect) return;

            function syncBrandSelect() {
                const hasCategory = Number(categorySelect.value || 0) > 0;
                brandSelect.disabled = !hasCategory;

                if (!hasCategory) {
                    brandSelect.value = '0';
                }
            }

            categorySelect.addEventListener('change', syncBrandSelect);
            syncBrandSelect();
        })();
    </script>

</body>

</html>