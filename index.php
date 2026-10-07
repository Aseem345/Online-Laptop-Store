<?php
session_start();

if (!isset($_SESSION['customer_id'])) {
    header("Location: register.php");
    exit;
}

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/chat_config.php";

/* ===== Logged-in Customer Welcome ===== */
$currentCustomerName = "Customer";
$currentCustomerId = (int) ($_SESSION['customer_id'] ?? 0);

if ($currentCustomerId > 0) {
    $customerWelcomeStmt = $conn->prepare("SELECT name FROM customers WHERE id=? LIMIT 1");
    if ($customerWelcomeStmt) {
        $customerWelcomeStmt->bind_param("i", $currentCustomerId);
        $customerWelcomeStmt->execute();
        $customerWelcomeRow = $customerWelcomeStmt->get_result()->fetch_assoc();

        if ($customerWelcomeRow && trim((string) ($customerWelcomeRow['name'] ?? '')) !== '') {
            $currentCustomerName = trim((string) $customerWelcomeRow['name']);
        }

        $customerWelcomeStmt->close();
    }
}

/* ===== PHPMailer Gmail SMTP Setup ===== */
require_once __DIR__ . "/vendor/autoload.php";

/* ===== Orders Table Customer + Email Columns Auto Add ===== */
$orderCustomerColCheck = $conn->query("SHOW COLUMNS FROM orders LIKE 'customer_id'");
if ($orderCustomerColCheck && $orderCustomerColCheck->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN customer_id INT NULL AFTER id");
}

$colCheck = $conn->query("SHOW COLUMNS FROM orders LIKE 'customer_email'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN customer_email VARCHAR(255) NULL AFTER phone");
}

/* Link older orders to registered customers when their email matches. */
$customerEmailColCheck = $conn->query("SHOW COLUMNS FROM customers LIKE 'email'");
if ($customerEmailColCheck && $customerEmailColCheck->num_rows > 0) {
    $conn->query("
        UPDATE orders o
        INNER JOIN customers c
            ON LOWER(TRIM(o.customer_email)) = LOWER(TRIM(c.email))
        SET o.customer_id = c.id
        WHERE o.customer_id IS NULL
          AND o.customer_email IS NOT NULL
          AND TRIM(o.customer_email) <> ''
    ");
}

/* ===== Ratings Table + Helper ===== */
$conn->query("
CREATE TABLE IF NOT EXISTS ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    customer_id INT NOT NULL,
    rating TINYINT NOT NULL,
    review TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_customer_product (product_id, customer_id)
)
");

/* ===== Brands Table + Product Brand Link ===== */
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

/* ===== Product Stock Column (shared with Admin Dashboard) ===== */
$stockColumnCheck = $conn->query("SHOW COLUMNS FROM products LIKE 'stock_quantity'");
if ($stockColumnCheck && $stockColumnCheck->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN stock_quantity INT UNSIGNED NOT NULL DEFAULT 0 AFTER price");
}

/* ===== Product Offer Price Column (shared with Admin Dashboard) ===== */
$offerPriceColumnCheck = $conn->query("SHOW COLUMNS FROM products LIKE 'offer_price'");
if ($offerPriceColumnCheck && $offerPriceColumnCheck->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN offer_price DECIMAL(12,2) NULL AFTER price");
}

/* ===== Product Available Colors Column (shared with Admin Dashboard) ===== */
$colorColumnCheck = $conn->query("SHOW COLUMNS FROM products LIKE 'available_colors'");
if ($colorColumnCheck && $colorColumnCheck->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN available_colors VARCHAR(500) NULL AFTER stock_quantity");
}

/* ===== Wishlist Table ===== */
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

/* Seed starter brands only when the brand table is empty.
   Later this same table can be managed from the admin dashboard. */
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

/* Add starter brand logos only when a brand does not already have its own logo.
   Later the admin dashboard can replace these logo_url values. */
$defaultBrandLogos = [
    'ASUS' => 'https://cdn.simpleicons.org/asus/FFFFFF',
    'HP' => 'https://cdn.simpleicons.org/hp/FFFFFF',
    'SONY' => 'https://cdn.simpleicons.org/sony/FFFFFF',
    'DELL' => 'https://cdn.simpleicons.org/dell/FFFFFF',
    'LENOVO' => 'https://cdn.simpleicons.org/lenovo/FFFFFF',
    'ACER' => 'https://cdn.simpleicons.org/acer/FFFFFF'
];

$brandLogoStmt = $conn->prepare("
    UPDATE brands
    SET logo_url=?
    WHERE UPPER(name)=?
      AND (logo_url IS NULL OR TRIM(logo_url)='')
");

if ($brandLogoStmt) {
    foreach ($defaultBrandLogos as $brandName => $brandLogoUrl) {
        $brandLogoStmt->bind_param("ss", $brandLogoUrl, $brandName);
        $brandLogoStmt->execute();
    }
    $brandLogoStmt->close();
}

/* Link existing products when the product name already contains a known brand. */
$conn->query("
    UPDATE products p
    INNER JOIN brands b
        ON LOWER(p.name) LIKE CONCAT('%', LOWER(b.name), '%')
    SET p.brand_id = b.id
    WHERE p.brand_id IS NULL
");

function rating_text($avg, $count)
{
    if ((int) $count <= 0) {
        return "No ratings yet";
    }
    return "⭐ " . number_format((float) $avg, 1) . " / 5 (" . (int) $count . " reviews)";
}

function rating_stars($value)
{
    $value = (int) $value;
    if ($value < 0)
        $value = 0;
    if ($value > 5)
        $value = 5;
    return str_repeat("★", $value) . str_repeat("☆", 5 - $value);
}


/* ===== Helpers ===== */
function e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function digits_only($s)
{
    return preg_replace('/\D+/', '', (string) $s);
}
function starts_with($haystack, $needle)
{
    $haystack = (string) $haystack;
    $needle = (string) $needle;
    return $needle === "" ? true : (substr($haystack, 0, strlen($needle)) === $needle);
}
function split_images($str)
{
    $out = [];
    $parts = explode("|||", (string) $str);
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== "") {
            $out[] = $p;
        }
    }
    return $out;
}

function product_color_css($colorName)
{
    $raw = trim((string) $colorName);

    /* Optional custom format from dashboard: Color Name:#RRGGBB */
    if (preg_match('/^(.+?)\s*:\s*(#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6})$/', $raw, $m)) {
        return $m[2];
    }

    $key = strtolower(preg_replace('/\s+/', ' ', $raw));

    $map = [
        'black' => '#111111',
        'white' => '#FFFFFF',
        'silver' => '#C0C0C0',
        'gray' => '#6B7280',
        'grey' => '#6B7280',
        'space gray' => '#4B5563',
        'space grey' => '#4B5563',
        'graphite' => '#374151',
        'blue' => '#2563EB',
        'navy' => '#172554',
        'midnight blue' => '#191970',
        'red' => '#DC2626',
        'green' => '#16A34A',
        'gold' => '#D4AF37',
        'rose gold' => '#B76E79',
        'pink' => '#EC4899',
        'purple' => '#7C3AED',
        'orange' => '#F97316',
        'yellow' => '#EAB308',
        'beige' => '#D6C7A1',
        'brown' => '#8B5E3C',
        'titanium' => '#8A8D8F'
    ];

    return $map[$key] ?? '#94A3B8';
}

function product_color_name($colorValue)
{
    $raw = trim((string) $colorValue);

    if (preg_match('/^(.+?)\s*:\s*#[0-9a-fA-F]{3,6}$/', $raw, $m)) {
        return trim($m[1]);
    }

    return $raw;
}

function parse_product_colors($value)
{
    $colors = [];
    $parts = preg_split('/[\r\n,]+/', (string) $value);

    foreach ($parts as $part) {
        $part = trim($part);

        if ($part === '') {
            continue;
        }

        $colors[] = [
            'name' => product_color_name($part),
            'css' => product_color_css($part)
        ];
    }

    return $colors;
}

/* ===== Site settings ===== */
$settings = $conn->query("SELECT * FROM site_settings LIMIT 1")->fetch_assoc();
if (!$settings) {
    die("site_settings table empty. Please import install.sql");
}

/* ===== WhatsApp Link from settings phone ===== */
$phoneDigits = digits_only($settings['phone'] ?? '');
$phoneDigits = ltrim($phoneDigits, '0');
if ($phoneDigits !== '' && starts_with($phoneDigits, '7')) {
    $phoneDigits = '94' . $phoneDigits;
}
$whatsAppBase = $phoneDigits ? ("https://wa.me/" . $phoneDigits) : "#";

/* ===== GET filters ===== */
$q = trim($_GET['q'] ?? '');
$cat = (int) ($_GET['cat'] ?? 0);
$brand = (int) ($_GET['brand'] ?? 0);
$sort = $_GET['sort'] ?? 'new';

/* ===== Wishlist Add / Remove ===== */
$wishlistNotice = '';
if (isset($_POST['wishlist_action'])) {
    $wishlistProductId = (int) ($_POST['wishlist_product_id'] ?? 0);
    $wishlistAction = (string) ($_POST['wishlist_action'] ?? '');

    if ($wishlistProductId > 0 && $currentCustomerId > 0) {
        if ($wishlistAction === 'add') {
            $wishStmt = $conn->prepare("INSERT IGNORE INTO wishlists(customer_id, product_id) VALUES(?, ?)");
            if ($wishStmt) {
                $wishStmt->bind_param("ii", $currentCustomerId, $wishlistProductId);
                $wishStmt->execute();
                $wishStmt->close();
                $wishlistNotice = '❤️ Laptop added to your wishlist. You will receive an email if its offer price drops.';
            }
        } elseif ($wishlistAction === 'remove') {
            $wishStmt = $conn->prepare("DELETE FROM wishlists WHERE customer_id=? AND product_id=?");
            if ($wishStmt) {
                $wishStmt->bind_param("ii", $currentCustomerId, $wishlistProductId);
                $wishStmt->execute();
                $wishStmt->close();
                $wishlistNotice = 'Wishlist item removed.';
            }
        }
    }
}

$wishlistProductIds = [];
if ($currentCustomerId > 0) {
    $wishListStmt = $conn->prepare("SELECT product_id FROM wishlists WHERE customer_id=?");
    if ($wishListStmt) {
        $wishListStmt->bind_param("i", $currentCustomerId);
        $wishListStmt->execute();
        $wishListResult = $wishListStmt->get_result();
        while ($wishRow = $wishListResult->fetch_assoc()) {
            $wishlistProductIds[(int) $wishRow['product_id']] = true;
        }
        $wishListStmt->close();
    }
}

/* ===== Payment / return message ===== */
$payment_msg = trim($_GET['payment_msg'] ?? '');
$payment_type = trim($_GET['payment_type'] ?? '');

/* ===== Categories ===== */
$cats = $conn->query("SELECT * FROM categories ORDER BY id ASC");

/* ===== Selected category info ===== */
$selectedCategory = null;
if ($cat > 0) {
    $stCat = $conn->prepare("SELECT * FROM categories WHERE id=? LIMIT 1");
    $stCat->bind_param("i", $cat);
    $stCat->execute();
    $selectedCategory = $stCat->get_result()->fetch_assoc();
}

/* ===== Brands ===== */
$brands = $conn->query("SELECT * FROM brands ORDER BY id ASC");

/* ===== Selected brand info ===== */
$selectedBrand = null;
if ($brand > 0) {
    $stBrand = $conn->prepare("SELECT * FROM brands WHERE id=? LIMIT 1");
    $stBrand->bind_param("i", $brand);
    $stBrand->execute();
    $selectedBrand = $stBrand->get_result()->fetch_assoc();
}

/* ===== Search Suggestions (Laptop + Brand) ===== */
$searchSuggestions = [];
$searchSuggestionKeys = [];

$suggestionProducts = $conn->query("
    SELECT
        p.name AS product_name,
        p.brand_id,
        b.name AS brand_name
    FROM products p
    LEFT JOIN brands b ON b.id = p.brand_id
    ORDER BY p.name ASC
    LIMIT 200
");

if ($suggestionProducts) {
    while ($suggestionProduct = $suggestionProducts->fetch_assoc()) {
        $productName = trim((string) ($suggestionProduct['product_name'] ?? ''));
        if ($productName === '') {
            continue;
        }

        $productKey = 'product:' . strtolower($productName);
        if (!isset($searchSuggestionKeys[$productKey])) {
            $searchSuggestionKeys[$productKey] = true;
            $searchSuggestions[] = [
                'type' => 'Laptop',
                'label' => $productName,
                'value' => $productName,
                'brand_id' => (int) ($suggestionProduct['brand_id'] ?? 0),
                'brand_name' => trim((string) ($suggestionProduct['brand_name'] ?? ''))
            ];
        }
    }
}

$suggestionBrands = $conn->query("SELECT id, name FROM brands ORDER BY name ASC");
if ($suggestionBrands) {
    while ($suggestionBrand = $suggestionBrands->fetch_assoc()) {
        $brandName = trim((string) ($suggestionBrand['name'] ?? ''));
        if ($brandName === '') {
            continue;
        }

        $brandKey = 'brand:' . strtolower($brandName);
        if (!isset($searchSuggestionKeys[$brandKey])) {
            $searchSuggestionKeys[$brandKey] = true;
            $searchSuggestions[] = [
                'type' => 'Brand',
                'label' => $brandName,
                'value' => '',
                'brand_id' => (int) ($suggestionBrand['id'] ?? 0),
                'brand_name' => $brandName
            ];
        }
    }
}

/* ===== Products query (Search + Filter + Sort) ===== */
$where = "WHERE p.is_featured=1";
$params = [];
$types = "";

if ($q !== "") {
    $where .= " AND p.name LIKE ?";
    $types .= "s";
    $params[] = "%$q%";
}
if ($cat > 0) {
    $where .= " AND p.category_id = ?";
    $types .= "i";
    $params[] = $cat;
}
if ($brand > 0) {
    $where .= " AND p.brand_id = ?";
    $types .= "i";
    $params[] = $brand;
}

$orderBy = "ORDER BY p.id DESC";
if ($sort === "price_asc")
    $orderBy = "ORDER BY p.price ASC";
if ($sort === "price_desc")
    $orderBy = "ORDER BY p.price DESC";
if ($sort === "name_asc")
    $orderBy = "ORDER BY p.name ASC";
if ($sort === "name_desc")
    $orderBy = "ORDER BY p.name DESC";

$sql = "
    SELECT 
        p.*,
        c.name AS category_name,
        b.name AS brand_name,
        (SELECT COALESCE(ROUND(AVG(r.rating),1),0) FROM ratings r WHERE r.product_id = p.id) AS avg_rating,
        (SELECT COUNT(*) FROM ratings r WHERE r.product_id = p.id) AS rating_count,
        COALESCE(
            GROUP_CONCAT(
                DISTINCT pi.image_url
                ORDER BY pi.sort_order ASC, pi.id ASC
                SEPARATOR '|||'
            ),
            ''
        ) AS gallery_images
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN brands b ON b.id = p.brand_id
    LEFT JOIN product_images pi ON pi.product_id = p.id
    $where
    GROUP BY p.id
    $orderBy
    LIMIT 12
";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("SQL prepare failed: " . e($conn->error));
}
if ($types !== "") {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$prods = $stmt->get_result();

/* ===== Product details if clicked ===== */
$selected_id = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
$selected = null;
$selectedImages = [];

if ($selected_id > 0) {
    $st = $conn->prepare("
        SELECT p.*, c.name AS category_name, b.name AS brand_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN brands b ON b.id = p.brand_id
        WHERE p.id=? LIMIT 1
    ");
    $st->bind_param("i", $selected_id);
    $st->execute();
    $selected = $st->get_result()->fetch_assoc();

    if ($selected) {
        $imgs = $conn->prepare("
            SELECT image_url 
            FROM product_images 
            WHERE product_id=? 
            ORDER BY sort_order ASC, id ASC 
            LIMIT 10
        ");
        $imgs->bind_param("i", $selected_id);
        $imgs->execute();
        $res = $imgs->get_result();

        while ($r = $res->fetch_assoc()) {
            if (!empty($r['image_url'])) {
                $selectedImages[] = $r['image_url'];
            }
        }

        if (count($selectedImages) === 0 && !empty($selected['image_url'])) {
            $selectedImages[] = $selected['image_url'];
        }
    }
}


/* ===== Handle Rating Submit ===== */
$rating_msg = "";
$rating_err = "";
$rating_for_id = 0;

if (isset($_POST['submit_rating'])) {
    $rating_product_id = (int) ($_POST['rating_product_id'] ?? 0);
    $customer_id = (int) ($_SESSION['customer_id'] ?? 0);
    $rating_value = (int) ($_POST['rating'] ?? 0);
    $review_text = trim($_POST['review'] ?? "");

    $rating_for_id = $rating_product_id;
    $selected_id = $rating_product_id;

    if ($rating_product_id <= 0 || $customer_id <= 0) {
        $rating_err = "Invalid rating request.";
    } elseif ($rating_value < 1 || $rating_value > 5) {
        $rating_err = "Please select 1 to 5 stars.";
    } else {
        $stmtRate = $conn->prepare("
            INSERT INTO ratings(product_id, customer_id, rating, review)
            VALUES(?,?,?,?)
            ON DUPLICATE KEY UPDATE
                rating = VALUES(rating),
                review = VALUES(review),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmtRate->bind_param("iiis", $rating_product_id, $customer_id, $rating_value, $review_text);

        if ($stmtRate->execute()) {
            $rating_msg = "✅ Rating submitted successfully.";
        } else {
            $rating_err = "Rating submit failed. Try again.";
        }
    }
}

/* ===== Selected Product Rating Summary ===== */
$selectedAvgRating = 0;
$selectedRatingCount = 0;
$selectedReviews = null;

if ($selected_id > 0) {
    $rs = $conn->prepare("
        SELECT COALESCE(ROUND(AVG(rating),1),0) AS avg_rating, COUNT(*) AS rating_count
        FROM ratings
        WHERE product_id=?
    ");
    $rs->bind_param("i", $selected_id);
    $rs->execute();
    $rateRow = $rs->get_result()->fetch_assoc();

    $selectedAvgRating = (float) ($rateRow['avg_rating'] ?? 0);
    $selectedRatingCount = (int) ($rateRow['rating_count'] ?? 0);

    $rv = $conn->prepare("
        SELECT r.*, c.name AS customer_name
        FROM ratings r
        LEFT JOIN customers c ON c.id = r.customer_id
        WHERE r.product_id=?
        ORDER BY r.updated_at DESC, r.created_at DESC
        LIMIT 10
    ");
    $rv->bind_param("i", $selected_id);
    $rv->execute();
    $selectedReviews = $rv->get_result();
}

/* ===== Email Confirmation Helper (PHPMailer + Gmail SMTP) ===== */
function send_order_confirmation_email($toEmail, $customerName, $productName, $qty, $shopPhone)
{
    $toEmail = trim((string) $toEmail);

    if ($toEmail === "" || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return [false, "Invalid customer email address."];
    }

    if (!file_exists(__DIR__ . "/vendor/autoload.php")) {
        return [false, "PHPMailer vendor/autoload.php not found. Run composer require phpmailer/phpmailer."];
    }

    if (
        !defined('MAIL_USERNAME') ||
        !defined('MAIL_APP_PASSWORD') ||
        trim((string) MAIL_USERNAME) === "" ||
        trim((string) MAIL_APP_PASSWORD) === "" ||
        MAIL_APP_PASSWORD === "YOUR_NEW_GMAIL_APP_PASSWORD"
    ) {
        return [false, "Gmail mail settings are not configured in chat_config.php."];
    }

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        /* Gmail SMTP */
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USERNAME;
        $mail->Password = MAIL_APP_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        /* Better inbox delivery settings */
        $mail->CharSet = "UTF-8";
        $mail->Encoding = "base64";
        $mail->XMailer = "online laptop Store Mailer";
        $mail->Priority = 3;

        $mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
        $mail->addReplyTo(MAIL_USERNAME, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $customerName);

        $mail->addCustomHeader("Auto-Submitted", "auto-generated");
        $mail->addCustomHeader("X-Auto-Response-Suppress", "All");

        $mail->isHTML(true);
        $mail->Subject = "Order Confirmation - online laptop store";

        $safeName = htmlspecialchars($customerName, ENT_QUOTES, "UTF-8");
        $safeProduct = htmlspecialchars($productName, ENT_QUOTES, "UTF-8");
        $safeQty = (int) $qty;
        $safePhone = htmlspecialchars($shopPhone, ENT_QUOTES, "UTF-8");

        /*
            Professional transactional email.
            Avoid spam words, too many emojis, all caps, and promotional wording.
            Gmail decides Inbox/Spam/Promotions automatically, but this format improves trust.
        */
        $mail->Body = "
            <div style='font-family:Arial,Helvetica,sans-serif;background:#f4f7fb;padding:24px;color:#0f172a'>
                <div style='max-width:620px;margin:0 auto;background:#ffffff;border-radius:14px;padding:24px;border:1px solid #e5e7eb'>
                    <h2 style='color:#0b5ed7;margin:0 0 14px;font-size:22px'>Order Confirmation</h2>

                    <p style='margin:0 0 12px'>Dear <b>{$safeName}</b>,</p>

                    <p style='margin:0 0 14px'>
                        Your order has been placed successfully and is now being processed.
                    </p>

                    <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin:16px 0'>
                        <p style='margin:6px 0'><b>Product:</b> {$safeProduct}</p>
                        <p style='margin:6px 0'><b>Quantity:</b> {$safeQty}</p>
                        <p style='margin:6px 0'><b>Delivery:</b> Expected within 2-3 business days</p>
                    </div>

                    <p style='margin:0 0 14px'>
                        Thank you for choosing <b>online laptop Store</b>.
                    </p>

                    <p style='margin:18px 0 0;color:#475569;line-height:1.5'>
                        Regards,<br>
                        <b>online laptop Store Team</b><br>
                        Polonnaruwa<br>
                        {$safePhone}
                    </p>
                </div>
            </div>
        ";

        $mail->AltBody =
            "Dear {$customerName},\n\n" .
            "Your order has been placed successfully and is now being processed.\n\n" .
            "Order Details:\n" .
            "Product: {$productName}\n" .
            "Quantity: {$qty}\n" .
            "Delivery: Expected within 2-3 business days\n\n" .
            "Thank you for choosing Apple Store.\n\n" .
            "Regards,\n" .
            "Apple Store Team\n" .
            "Polonnaruwa\n" .
            "{$shopPhone}";

        $mail->send();
        return [true, "Email sent successfully."];
    } catch (Exception $e) {
        return [false, $mail->ErrorInfo ?: $e->getMessage()];
    }
}

/* ===== Handle Order Submit ===== */
$order_msg = "";
$order_err = "";
$order_for_id = 0;

if (isset($_POST['place_order']) || isset($_POST['pay_online'])) {
    $product_id = (int) ($_POST['product_id'] ?? 0);
    $order_for_id = $product_id;

    $name = trim($_POST['customer_name'] ?? "");
    $phone = trim($_POST['phone'] ?? "");
    $email = trim($_POST['customer_email'] ?? "");
    $addr = trim($_POST['address'] ?? "");
    $qty = (int) ($_POST['qty'] ?? 1);

    if ($qty < 1) {
        $qty = 1;
    }

    if ($product_id <= 0) {
        $order_err = "Invalid product.";
    } elseif ($name === "" || $phone === "" || $email === "" || $addr === "") {
        $order_err = "Please fill all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $order_err = "Please enter a valid email address.";
    } else {
        $newOrderId = 0;
        $exists = null;

        try {
            /*
             * Lock this product row while checking + reducing stock.
             * This prevents two customers from ordering the same last unit.
             */
            $conn->begin_transaction();

            $chk = $conn->prepare("
                SELECT id, name, price, stock_quantity
                FROM products
                WHERE id=?
                LIMIT 1
                FOR UPDATE
            ");

            if (!$chk) {
                throw new Exception("Unable to check product stock.");
            }

            $chk->bind_param("i", $product_id);
            $chk->execute();
            $exists = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (!$exists) {
                throw new Exception("Invalid product.");
            }

            $availableStock = (int) ($exists['stock_quantity'] ?? 0);

            if ($availableStock <= 0) {
                throw new Exception("This product is currently Out of Stock.");
            }

            if ($qty > $availableStock) {
                throw new Exception(
                    "Requested quantity is not available. Only " .
                    $availableStock .
                    " item(s) left in stock."
                );
            }

            $stmt2 = $conn->prepare("
                INSERT INTO orders(customer_id, product_id, customer_name, phone, customer_email, address, qty, status)
                VALUES(?,?,?,?,?,?,?,'Pending')
            ");

            if (!$stmt2) {
                throw new Exception("Order could not be created.");
            }

            $stmt2->bind_param(
                "iissssi",
                $currentCustomerId,
                $product_id,
                $name,
                $phone,
                $email,
                $addr,
                $qty
            );

            if (!$stmt2->execute()) {
                throw new Exception("Order failed. Try again.");
            }

            $newOrderId = (int) $stmt2->insert_id;
            $stmt2->close();

            $stockStmt = $conn->prepare("
                UPDATE products
                SET stock_quantity = stock_quantity - ?
                WHERE id=?
                  AND stock_quantity >= ?
            ");

            if (!$stockStmt) {
                throw new Exception("Unable to update product stock.");
            }

            $stockStmt->bind_param("iii", $qty, $product_id, $qty);

            if (!$stockStmt->execute() || $stockStmt->affected_rows !== 1) {
                $stockStmt->close();
                throw new Exception("Stock changed while ordering. Please try again.");
            }

            $stockStmt->close();
            $conn->commit();

            $productName = $exists['name'] ?? 'Laptop';

            /* ===== Cash on Delivery ===== */
            if (isset($_POST['place_order'])) {
                [$emailOk, $emailInfo] = send_order_confirmation_email(
                    $email,
                    $name,
                    $productName,
                    $qty,
                    $settings['phone'] ?? '+94 704875024'
                );

                if ($emailOk) {
                    $order_msg = "✅ Order placed successfully! Confirmation email sent.";
                } else {
                    $order_msg = "✅ Order placed successfully! Email not sent: " . $emailInfo;
                }
            }

            /* ===== Demo Pay Online ===== */
            if (isset($_POST['pay_online'])) {
                send_order_confirmation_email(
                    $email,
                    $name,
                    $productName,
                    $qty,
                    $settings['phone'] ?? '+94 704875024'
                );

                header("Location: payment_demo.php?order_id=" . $newOrderId);
                exit;
            }
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                // Keep the original order/stock error message.
            }

            $order_err = $e->getMessage();
        }

        /*
         * Reload selected product after an order attempt so the details section
         * shows the latest database stock immediately.
         */
        $selected_id = $product_id;
        $st = $conn->prepare("
            SELECT p.*, c.name AS category_name, b.name AS brand_name
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN brands b ON b.id = p.brand_id
            WHERE p.id=? LIMIT 1
        ");
        $st->bind_param("i", $selected_id);
        $st->execute();
        $selected = $st->get_result()->fetch_assoc();

        $selectedImages = [];
        if ($selected) {
            $imgs = $conn->prepare("
                SELECT image_url
                FROM product_images
                WHERE product_id=?
                ORDER BY sort_order ASC, id ASC
                LIMIT 10
            ");
            $imgs->bind_param("i", $selected_id);
            $imgs->execute();
            $res = $imgs->get_result();

            while ($r = $res->fetch_assoc()) {
                if (!empty($r['image_url'])) {
                    $selectedImages[] = $r['image_url'];
                }
            }

            if (count($selectedImages) === 0 && !empty($selected['image_url'])) {
                $selectedImages[] = $selected['image_url'];
            }
        }

        /*
         * The featured-products query was executed before POST handling.
         * Re-run it after stock changes so product cards show the new stock
         * in this same response.
         */
        if (isset($stmt) && $stmt instanceof mysqli_stmt) {
            $stmt->execute();
            $prods = $stmt->get_result();
        }
    }
}


?>

<!doctype html>

<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>
        <?= e($settings['site_name']) ?>
    </title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap"
        rel="stylesheet">

    <style>
        /*
        ===== DESIGN TOKENS =====
        Subject: laptop / electronics storefront.
        Palette reads like brushed hardware + circuit-board blue,
        with an amber "power light" accent for calls to action.
        Display face: Sora (geometric, technical).
        Body face: Inter (clean, high legibility).
        Utility face: IBM Plex Mono (spec-sheet / price tags).
    */
        :root {
            --ink: #0B1220;
            --ink-soft: #46536B;
            --paper: #EEF1F8;
            --card: #ffffff;
            --line: rgba(11, 18, 32, .09);

            --blue1: #2453FF;
            --blue2: #0B1E63;
            --blue-soft: #EAF0FF;

            --amber: #FFB020;
            --amber-deep: #E08A00;

            --green: #16A34A;
            --violet: #7C3AED;
            --red: #DC2626;

            --font-display: 'Sora', system-ui, sans-serif;
            --font-body: 'Inter', system-ui, sans-serif;
            --font-mono: 'IBM Plex Mono', ui-monospace, monospace;

            --shadow: 0 14px 34px rgba(11, 18, 32, .10);
            --shadow-lg: 0 26px 60px rgba(11, 18, 32, .16);
            --radius: 16px;
        }

        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            font-family: var(--font-body);
            background: var(--paper);
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
        }

        h1,
        h2,
        h3,
        .brand,
        .title,
        .ratingTitle {
            font-family: var(--font-display);
        }

        a {
            text-decoration: none;
            color: inherit
        }

        /* ===== Motion keyframes ===== */
        @keyframes fadeSlideDown {
            from {
                opacity: 0;
                transform: translateY(-14px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeSlideUp {
            from {
                opacity: 0;
                transform: translateY(22px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes floatY {

            0%,
            100% {
                transform: translateY(0) rotate(0deg);
            }

            50% {
                transform: translateY(-10px) rotate(.3deg);
            }
        }

        @keyframes pulseGlow {

            0%,
            100% {
                box-shadow: 0 14px 30px rgba(11, 18, 32, .30);
            }

            50% {
                box-shadow: 0 18px 42px rgba(36, 83, 255, .48);
            }
        }

        @keyframes shimmer {
            0% {
                background-position: -300px 0;
            }

            100% {
                background-position: 300px 0;
            }
        }

        @keyframes dotPop {
            0% {
                transform: scale(.6);
                opacity: 0;
            }

            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                animation-duration: .001ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .001ms !important;
                scroll-behavior: auto !important;
            }
        }

        .container {
            width: min(1120px, 92%);
            margin: 0 auto
        }

        /* ===== Nav ===== */
        .nav {
            background: rgba(255, 255, 255, .92);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--line);
            position: sticky;
            top: 0;
            z-index: 50;
            animation: fadeSlideDown .5s ease both;
        }

        .scrollProgress {
            position: fixed;
            top: 0;
            left: 0;
            height: 3px;
            width: 0%;
            background: linear-gradient(90deg, var(--blue1), var(--amber));
            z-index: 60;
            transition: width .08s linear;
        }

        .nav .wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 0;
            gap: 16px
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 17px;
            letter-spacing: -.2px;
        }

        .logo {
            width: 38px;
            height: 38px;
            background: linear-gradient(135deg, var(--blue2), var(--blue1));
            border-radius: 10px;
            display: grid;
            place-items: center;
            box-shadow: 0 8px 18px rgba(36, 83, 255, .30);
            position: relative;
        }

        .logo::after {
            content: "";
            position: absolute;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--amber);
            right: -2px;
            top: -2px;
            box-shadow: 0 0 0 2px #fff;
            animation: pulseGlow 2.4s ease-in-out infinite;
        }

        .brand {
            transition: transform .2s ease;
        }

        .brand:hover .logo {
            transform: rotate(-8deg) scale(1.08);
        }

        .logo {
            transition: transform .3s cubic-bezier(.34, 1.56, .64, 1);
        }

        .menu {
            display: flex;
            gap: 6px;
            flex: 1;
            justify-content: center
        }

        .menu a {
            position: relative;
            font-weight: 600;
            font-size: 14px;
            color: var(--ink-soft);
            padding: 8px 14px;
            border-radius: 999px;
            transition: color .2s ease, background .2s ease;
        }

        .menu a::after {
            content: "";
            position: absolute;
            left: 14px;
            right: 14px;
            bottom: 3px;
            height: 2px;
            background: var(--blue1);
            border-radius: 2px;
            transform: scaleX(0);
            transform-origin: left;
            transition: transform .25s ease;
        }

        .menu a:hover {
            background: var(--blue-soft);
            color: var(--blue1)
        }

        .menu a:hover::after {
            transform: scaleX(1);
        }

        .actions {
            display: flex;
            gap: 10px
        }

        .btn {
            padding: 10px 16px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13.5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 0;
            font-family: var(--font-body);
            transition: transform .18s ease, box-shadow .18s ease, background .18s ease, color .18s ease, border-color .18s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn:active {
            transform: translateY(0) scale(.97);
        }

        .btn.outline {
            border: 1.5px solid var(--line);
            color: var(--ink);
            background: #fff
        }

        .btn.outline:hover {
            border-color: var(--blue1);
            color: var(--blue1);
        }

        .btn.primary {
            background: var(--blue1);
            color: #fff;
            box-shadow: 0 10px 22px rgba(36, 83, 255, .28);
        }

        .btn.primary:hover {
            box-shadow: 0 16px 30px rgba(36, 83, 255, .38);
        }

        .btn.cta {
            background: var(--amber);
            color: #221400;
            padding: 12px 22px;
            border-radius: 12px;
            box-shadow: 0 14px 26px rgba(255, 176, 32, .35);
            font-weight: 800;
            animation: fadeSlideUp .6s ease .25s both;
        }

        .btn.cta:hover {
            box-shadow: 0 18px 34px rgba(255, 176, 32, .48);
        }

        .btn.whatsapp {
            background: var(--green);
            color: #fff;
        }

        .btn.whatsapp:hover {
            box-shadow: 0 14px 26px rgba(22, 163, 74, .35);
        }

        .btn.cod {
            background: var(--blue1);
            color: #fff;
        }

        .btn.pay {
            background: var(--violet);
            color: #fff;
        }

        .btn.pay:hover {
            box-shadow: 0 14px 26px rgba(124, 58, 237, .35);
        }

        .btn.full {
            width: 100%
        }

        /* ===== Hero ===== */
        .hero {
            background:
                radial-gradient(circle at 82% 20%, rgba(255, 176, 32, .30), transparent 24%),
                linear-gradient(150deg, var(--blue2) 0%, #12309C 45%, var(--blue1) 100%);
            position: relative;
            overflow: hidden;
        }

        .heroBgVideo {
            position: absolute;
            inset: 0;
            z-index: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            pointer-events: none;
        }

        .heroVideoOverlay {
            position: absolute;
            inset: 0;
            z-index: 1;
            pointer-events: none;
            background:
                linear-gradient(90deg, rgba(5, 18, 60, .90) 0%, rgba(8, 25, 78, .72) 44%, rgba(7, 19, 58, .40) 100%),
                radial-gradient(circle at 82% 20%, rgba(255, 176, 32, .16), transparent 28%);
        }

        .hero>.container {
            position: relative;
            z-index: 2;
        }

        .hero::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255, 255, 255, .07) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, .07) 1px, transparent 1px);
            background-size: 42px 42px;
            mask-image: linear-gradient(to bottom, #000, transparent 78%);
            opacity: .6;
            z-index: 1;
            pointer-events: none;
        }

        .hero::after {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            bottom: -50px;
            height: 190px;
            background: var(--paper);
            border-top-left-radius: 140px;
            border-top-right-radius: 140px;
        }

        .hero .inner {
            position: relative;
            z-index: 2;
            padding: 58px 0 40px;
            display: grid;
            grid-template-columns: 1.1fr .9fr;
            gap: 30px;
            align-items: center;
            color: #fff
        }

        .hero h1 {
            margin: 0 0 12px;
            font-size: clamp(32px, 4.6vw, 52px);
            line-height: 1.06;
            letter-spacing: -1px;
            font-weight: 700;
            animation: fadeSlideUp .6s ease .05s both;
        }

        .hero p {
            margin: 0 0 20px;
            font-size: clamp(15px, 1.7vw, 18px);
            opacity: .88;
            max-width: 560px;
            animation: fadeSlideUp .6s ease .15s both;
        }

        .heroCard {
            background: rgba(255, 255, 255, .10);
            border: 1px solid rgba(255, 255, 255, .20);
            border-radius: 20px;
            padding: 16px;
            box-shadow: var(--shadow-lg);
            animation: fadeSlideUp .7s ease .1s both, floatY 5.5s ease-in-out 1s infinite;
        }

        .heroImg {
            border-radius: 14px;
            width: 100%;
            height: auto;
            display: block;
            transition: transform .5s ease;
        }

        .heroCard:hover .heroImg {
            transform: scale(1.03);
        }

        .section {
            padding: 48px 0
        }

        .title {
            text-align: center;
            font-size: clamp(22px, 2.6vw, 30px);
            font-weight: 700;
            letter-spacing: -.4px;
            margin: 0 0 18px
        }

        .divider {
            width: 64px;
            height: 3px;
            background: linear-gradient(90deg, var(--blue1), var(--amber));
            margin: 12px auto 0;
            border-radius: 999px;
        }

        .grid3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-top: 18px
        }

        .grid4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-top: 18px
        }

        .card {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            border: 1px solid var(--line);
            transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: rgba(36, 83, 255, .25);
        }

        .card img {
            transition: transform .5s ease;
            overflow: hidden;
        }

        .card:hover>img {
            transform: scale(1.06);
        }

        .categoryCard {
            display: block;
        }

        .categoryCard.activeCategory {
            border: 2px solid var(--blue1);
            box-shadow: var(--shadow-lg);
        }

        .card .center {
            text-align: center;
            font-weight: 700;
            padding: 13px 10px 15px;
            font-family: var(--font-display);
        }

        .card .body {
            padding: 15px 15px 17px
        }

        .price {
            font-size: 18px;
            font-weight: 700;
            margin: 6px 0 10px;
            color: var(--blue2);
            font-family: var(--font-mono);
            letter-spacing: -.3px;
        }

        /* ===== Product Offer Price ===== */
        .priceOfferRow {
            display: flex;
            align-items: baseline;
            gap: 12px;
            flex-wrap: wrap;
            margin: 6px 0 10px;
        }

        .priceOfferRow .price {
            margin: 0;
        }

        .oldPrice {
            color: #EF4444;
            font-family: var(--font-mono);
            font-size: 15px;
            font-weight: 700;
            text-decoration: line-through;
            text-decoration-thickness: 2px;
        }

        .detailsBody .priceOfferRow .price {
            font-size: 26px;
        }

        .detailsBody .priceOfferRow .oldPrice {
            font-size: 18px;
        }

        .smallTag {
            display: inline-block;
            margin-top: 8px;
            background: var(--blue-soft);
            color: var(--blue1);
            border-radius: 999px;
            padding: 4px 11px;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: .2px;
            text-transform: uppercase;
        }

        /* ===== Live Product Stock ===== */
        .stockStatus {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin: 7px 0 10px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 800;
            line-height: 1;
        }

        .stockStatus::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: currentColor;
            box-shadow: 0 0 0 4px currentColor;
            opacity: .9;
        }

        .stockStatus.in {
            background: #DCFCE7;
            color: #166534;
        }

        .stockStatus.low {
            background: #FEF3C7;
            color: #92400E;
        }

        .stockStatus.out {
            background: #FEE2E2;
            color: #991B1B;
        }

        /* ===== Product Available Colors ===== */
        .productColorsBlock {
            margin: 16px 0 18px;
            padding-top: 15px;
            border-top: 1px solid #D7DCE5;
        }

        .productColorsLabel {
            margin-bottom: 11px;
            color: var(--ink);
            font-family: var(--font-display);
            font-size: 14px;
            font-weight: 700;
        }

        .productColorList {
            display: flex;
            align-items: center;
            gap: 11px;
            flex-wrap: wrap;
        }

        .productColorSwatch {
            width: 34px;
            height: 34px;
            display: inline-block;
            border-radius: 50%;
            border: 2px solid #D3D9E5;
            box-shadow: 0 0 0 3px #fff, 0 4px 12px rgba(11, 18, 32, .12);
            cursor: default;
        }

        body.dark-mode .productColorsBlock {
            border-color: #2D3A56 !important;
        }

        body.dark-mode .productColorsLabel {
            color: #E7EAF3 !important;
        }

        body.dark-mode .productColorSwatch {
            border-color: #64748B;
            box-shadow: 0 0 0 3px #131C2E, 0 4px 12px rgba(0, 0, 0, .24);
        }

        .btn:disabled {
            opacity: .52;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .welcomePill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
            padding: 8px 13px;
            border: 1px solid rgba(255, 255, 255, .22);
            border-radius: 999px;
            background: rgba(255, 255, 255, .12);
            color: #fff;
            font-size: 12.5px;
            font-weight: 700;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            animation: fadeSlideUp .6s ease both;
        }

        .filterBar {
            background: #fff;
            border: 1px solid var(--line);
            box-shadow: var(--shadow-lg);
            border-radius: 18px;
            padding: 16px;
            margin-top: -26px;
            position: relative;
            z-index: 3
        }

        .filterGrid {
            display: grid;
            grid-template-columns: 1.2fr .8fr .7fr auto;
            gap: 12px;
            align-items: end
        }

        .filterGrid label {
            font-weight: 700;
            font-size: 12.5px;
            color: var(--ink-soft);
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .searchSuggestWrap {
            position: relative;
        }

        .searchSuggestions {
            position: fixed;
            z-index: 99999;
            display: none;
            overflow-x: hidden;
            overflow-y: auto;
            max-height: 330px;
            border: 1px solid #DCE1EC;
            border-radius: 13px;
            background: #fff;
            box-shadow: 0 20px 46px rgba(11, 18, 32, .20);
            visibility: hidden;
            opacity: 0;
            pointer-events: none;
        }

        .searchSuggestions.show {
            display: block;
            visibility: visible;
            opacity: 1;
            pointer-events: auto;
        }

        .searchSuggestionItem {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 11px 13px;
            border: 0;
            border-bottom: 1px solid #EEF1F6;
            background: #fff;
            color: var(--ink);
            text-align: left;
            cursor: pointer;
            font-family: var(--font-body);
        }

        .searchSuggestionItem:last-child {
            border-bottom: 0;
        }

        .searchSuggestionItem:hover,
        .searchSuggestionItem.active {
            background: var(--blue-soft);
        }

        .searchSuggestionMain {
            min-width: 0;
        }

        .searchSuggestionName {
            display: block;
            overflow: hidden;
            color: var(--ink);
            font-size: 13px;
            font-weight: 800;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .searchSuggestionMeta {
            display: block;
            margin-top: 3px;
            color: var(--ink-soft);
            font-size: 11px;
            font-weight: 600;
        }

        .searchSuggestionType {
            flex: 0 0 auto;
            padding: 4px 8px;
            border-radius: 999px;
            background: #F1F5F9;
            color: #475569;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .35px;
        }

        .searchSuggestionEmpty {
            padding: 13px;
            color: var(--ink-soft);
            font-size: 12px;
            font-weight: 650;
        }

        body.dark-mode .searchSuggestions,
        body.dark-mode .searchSuggestionItem {
            background: #131C2E !important;
            color: #E7EAF3 !important;
            border-color: #263250 !important;
        }

        body.dark-mode .searchSuggestionItem:hover,
        body.dark-mode .searchSuggestionItem.active {
            background: #1B2944 !important;
        }

        body.dark-mode .searchSuggestionName {
            color: #E7EAF3 !important;
        }

        body.dark-mode .searchSuggestionMeta {
            color: #AEB9CC !important;
        }

        body.dark-mode .searchSuggestionType {
            background: #0B1220 !important;
            color: #93C5FD !important;
        }

        .filterInfo {
            margin-top: 12px;
            text-align: center;
        }

        .filterBadge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--blue-soft);
            color: var(--blue1);
            padding: 8px 14px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 13px;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 11px 13px;
            border: 1.5px solid #DCE1EC;
            border-radius: 10px;
            margin-top: 6px;
            font-family: var(--font-body);
            font-size: 14px;
            background: #fff;
            color: var(--ink);
        }

        textarea {
            min-height: 90px;
            resize: vertical
        }

        .detailsWrap {
            padding: 0 0 48px
        }

        .detailsCard {
            background: #fff;
            border-radius: 18px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--line);
            overflow: hidden
        }

        .detailsGrid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0
        }

        .detailsBody {
            padding: 20px
        }

        .muted {
            color: var(--ink-soft);
            font-weight: 500;
            line-height: 1.55;
        }

        .success {
            background: #DCFCE7;
            color: #14532D;
            padding: 11px 13px;
            border-radius: 12px;
            font-weight: 700;
            margin: 10px 0;
            border: 1px solid #86EFAC;
        }

        .error {
            background: #FEE2E2;
            color: #7F1D1D;
            padding: 11px 13px;
            border-radius: 12px;
            font-weight: 700;
            margin: 10px 0;
            border: 1px solid #FCA5A5;
        }

        .info {
            background: #E0F2FE;
            color: #075985;
            padding: 11px 13px;
            border-radius: 12px;
            font-weight: 700;
            margin: 10px 0;
            border: 1px solid #7DD3FC;
        }

        .formRow {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 12px
        }

        .slider {
            position: relative;
            overflow: hidden;
            background: #EEF1F8;
        }

        .slides {
            display: flex;
            transition: transform .35s ease;
            will-change: transform;
        }

        .slide {
            min-width: 100%;
            flex: 0 0 100%;
        }

        .slide img {
            width: 100%;
            display: block;
            object-fit: cover;
            transition: transform .5s ease;
        }

        .detailSlider .slide img {
            height: 380px;
            min-height: 300px;
        }

        .productSlider .slide img {
            height: 190px;
        }

        .card:hover .productSlider .slide img,
        .card:hover .slide img {
            transform: scale(1.05);
        }

        .navBtn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(11, 18, 32, .55);
            border: 0;
            color: #fff;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 18px;
            z-index: 3;
        }

        .navBtn:hover {
            background: rgba(11, 18, 32, .75)
        }

        .navBtn.prev {
            left: 8px
        }

        .navBtn.next {
            right: 8px
        }

        .dots {
            display: flex;
            gap: 8px;
            justify-content: center;
            padding: 10px 0;
            background: #fff;
        }

        .dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #CBD5E1;
            cursor: pointer;
            transition: background .2s ease, width .2s ease;
            animation: dotPop .3s ease both;
        }

        .dot.active {
            background: var(--blue1);
            width: 18px;
            border-radius: 6px;
        }

        .miniDots {
            padding: 8px 0 10px;
        }

        .placeholder {
            width: 100%;
            height: 190px;
            display: grid;
            place-items: center;
            color: var(--ink-soft);
            font-weight: 700;
            background-color: #EDF0F8;
            background-image: linear-gradient(90deg, #EDF0F8 0px, #F8FAFD 80px, #EDF0F8 160px);
            background-size: 600px 100%;
            animation: shimmer 2.4s linear infinite;
        }

        .aboutBand {
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(circle at 12% 15%, rgba(255, 176, 32, .18), transparent 28%),
                radial-gradient(circle at 88% 82%, rgba(36, 83, 255, .28), transparent 30%),
                linear-gradient(120deg, var(--blue2), #16307C);
            padding: 68px 0;
        }

        .aboutBand::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255, 255, 255, .045) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, .045) 1px, transparent 1px);
            background-size: 38px 38px;
            pointer-events: none;
        }

        .aboutShell {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 1.05fr .95fr;
            gap: 26px;
            align-items: stretch;
        }

        .aboutCopy,
        .aboutFeatures {
            border: 1px solid rgba(255, 255, 255, .14);
            background: rgba(255, 255, 255, .08);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 24px;
            box-shadow: 0 24px 55px rgba(5, 14, 48, .22);
        }

        .aboutCopy {
            padding: 32px;
        }

        .aboutEyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
            padding: 7px 11px;
            border-radius: 999px;
            background: rgba(255, 176, 32, .14);
            border: 1px solid rgba(255, 176, 32, .34);
            color: #FFD88C;
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: .7px;
            text-transform: uppercase;
        }

        .aboutBand h3 {
            margin: 0 0 12px;
            text-align: left;
            font-size: clamp(26px, 3vw, 38px);
            line-height: 1.12;
            letter-spacing: -.7px;
            font-weight: 700;
            color: #fff;
        }

        .aboutBand .aboutLead {
            margin: 0;
            max-width: 680px;
            text-align: left;
            color: rgba(255, 255, 255, .78);
            font-weight: 500;
            font-size: 14px;
            line-height: 1.75;
        }

        .aboutStats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 22px;
        }

        .aboutStat {
            padding: 12px;
            border-radius: 14px;
            background: rgba(255, 255, 255, .08);
            border: 1px solid rgba(255, 255, 255, .10);
        }

        .aboutStat strong {
            display: block;
            color: #fff;
            font-family: var(--font-display);
            font-size: 14px;
            margin-bottom: 3px;
        }

        .aboutStat span {
            color: rgba(255, 255, 255, .66);
            font-size: 11.5px;
            font-weight: 600;
        }

        .aboutFeatures {
            padding: 18px;
            display: grid;
            gap: 12px;
        }

        .aboutFeature {
            display: grid;
            grid-template-columns: 44px 1fr;
            gap: 13px;
            align-items: center;
            padding: 14px;
            border-radius: 16px;
            background: rgba(255, 255, 255, .08);
            border: 1px solid rgba(255, 255, 255, .10);
            transition: transform .2s ease, background .2s ease, border-color .2s ease;
        }

        .aboutFeature:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, .12);
            border-color: rgba(255, 176, 32, .34);
        }

        .aboutFeatureIcon {
            width: 44px;
            height: 44px;
            display: grid;
            place-items: center;
            border-radius: 13px;
            background: linear-gradient(135deg, rgba(255, 176, 32, .22), rgba(36, 83, 255, .25));
            border: 1px solid rgba(255, 255, 255, .12);
            font-size: 20px;
        }

        .aboutFeature strong {
            display: block;
            color: #fff;
            font-family: var(--font-display);
            font-size: 13.5px;
            margin-bottom: 4px;
        }

        .aboutFeature span {
            display: block;
            color: rgba(255, 255, 255, .68);
            font-size: 12px;
            line-height: 1.5;
        }

        @media (max-width:900px) {
            .aboutShell {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width:520px) {
            .aboutBand {
                padding: 52px 0;
            }

            .aboutCopy {
                padding: 24px 20px;
            }

            .aboutStats {
                grid-template-columns: 1fr;
            }
        }

        /* ===== Store Footer ===== */
        footer {
            position: relative;
            overflow: hidden;
            background: #050505;
            color: #AEB9CC;
            padding: 0;
            font-size: 13px;
        }

        footer::before {
            content: "";
            position: absolute;
            left: 5%;
            right: 5%;
            top: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(36, 83, 255, .72), transparent);
        }

        .footerMain {
            display: grid;
            grid-template-columns: 1.35fr .72fr .78fr .95fr .9fr;
            gap: 42px;
            align-items: start;
            padding: 64px 0 50px;
        }

        .footerBrandName {
            margin: 0 0 18px;
            color: #fff;
            font-family: var(--font-display);
            font-size: clamp(24px, 2.6vw, 32px);
            font-weight: 800;
            letter-spacing: -.7px;
        }

        .footerAboutText {
            max-width: 330px;
            margin: 0 0 18px;
            color: #AEB9CC;
            font-size: 13px;
            line-height: 1.72;
        }

        .footerContact {
            display: grid;
            gap: 8px;
        }

        .footerContactItem {
            color: #AEB9CC;
            line-height: 1.55;
        }

        .footerContactItem strong {
            color: #fff;
            font-weight: 700;
        }

        .footerContactItem a {
            color: #38BDF8;
            transition: color .2s ease;
        }

        .footerContactItem a:hover {
            color: #7DD3FC;
        }

        .footerSocial {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }

        .footerSocial a {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, .52);
            color: #fff;
            transition: transform .2s ease, border-color .2s ease, background .2s ease;
        }

        .footerSocial a:hover {
            transform: translateY(-3px);
            border-color: #38BDF8;
            background: rgba(56, 189, 248, .10);
        }

        .footerSocial svg {
            width: 19px;
            height: 19px;
            display: block;
            fill: currentColor;
        }

        .footerTitle {
            margin: 3px 0 20px;
            color: #fff;
            font-family: var(--font-display);
            font-size: 13.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .25px;
        }

        .footerLinks {
            display: grid;
            gap: 13px;
        }

        .footerLinks a {
            width: fit-content;
            color: #AEB9CC;
            font-size: 13.5px;
            line-height: 1.45;
            transition: color .2s ease, transform .2s ease;
        }

        .footerLinks a:hover {
            color: #38BDF8;
            transform: translateX(3px);
        }

        .footerBottom {
            border-top: 1px solid #263142;
            padding: 22px 0 24px;
            text-align: center;
            color: #95A3B8;
            font-size: 12.5px;
        }

        .footerBottom strong {
            color: #fff;
        }

        body.dark-mode footer {
            background: #050505 !important;
            color: #AEB9CC !important;
        }

        @media (max-width: 1080px) {
            .footerMain {
                grid-template-columns: 1.25fr repeat(2, 1fr);
                gap: 38px 28px;
            }
        }

        @media (max-width: 720px) {
            .footerMain {
                grid-template-columns: 1fr 1fr;
                padding: 52px 0 38px;
            }
        }

        @media (max-width: 520px) {
            .footerMain {
                grid-template-columns: 1fr;
                gap: 30px;
            }

            .footerAboutText {
                max-width: none;
            }

            .footerTitle {
                margin-bottom: 14px;
            }

            .footerLinks {
                gap: 10px;
            }
        }


        /* ===== Brands Section ===== */
        .brandsBand {
            padding: 8px 0 54px;
            background: var(--paper);
        }

        .brandsHead {
            text-align: center;
            margin-bottom: 22px;
        }

        .brandsHead .title {
            margin-bottom: 8px;
        }

        .brandsHead p {
            margin: 0;
            color: var(--ink-soft);
            font-size: 13px;
            font-weight: 600;
        }

        .brandsGrid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 18px;
        }

        .brandCard {
            min-height: 96px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            border: 1px solid rgba(11, 18, 32, .10);
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 10px 24px rgba(11, 18, 32, .08);
            overflow: hidden;
            transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
        }

        .brandCard:hover {
            transform: translateY(-4px) scale(1.015);
            box-shadow: 0 18px 34px rgba(11, 18, 32, .16);
            border-color: rgba(36, 83, 255, .30);
        }

        .brandCard.activeBrand {
            border: 3px solid var(--amber);
            box-shadow: 0 18px 38px rgba(255, 176, 32, .24);
        }

        .brandLogoWrap {
            width: 100%;
            min-height: 96px;
            display: grid;
            place-items: center;
            overflow: hidden;
            padding: 16px;
            background: linear-gradient(135deg, #2453FF, #0B1E63);
        }

        .brandCard[data-brand="ASUS"] .brandLogoWrap {
            background: #050505;
        }

        .brandCard[data-brand="HP"] .brandLogoWrap {
            background: #0A9ED0;
        }

        .brandCard[data-brand="SONY"] .brandLogoWrap {
            background: #050505;
        }

        .brandCard[data-brand="DELL"] .brandLogoWrap {
            background: #0672CE;
        }

        .brandCard[data-brand="LENOVO"] .brandLogoWrap {
            background: #E2231A;
        }

        .brandCard[data-brand="ACER"] .brandLogoWrap {
            background: #83B81A;
        }

        .brandLogoWrap img {
            max-width: 122px;
            max-height: 52px;
            width: auto;
            height: auto;
            object-fit: contain;
            display: block;
            filter: drop-shadow(0 3px 8px rgba(0, 0, 0, .16));
        }

        .brandTextLogo {
            font-family: var(--font-display);
            font-size: 20px;
            font-weight: 800;
            letter-spacing: .3px;
            color: #fff;
            text-transform: uppercase;
            text-shadow: 0 2px 10px rgba(0, 0, 0, .25);
        }

        .brandTextFallback {
            display: none;
        }

        .brandFilterInfo {
            margin-top: 18px;
            text-align: center;
        }

        body.dark-mode .brandsBand {
            background: #0B1220 !important;
        }

        body.dark-mode .brandCard {
            border-color: #2D3A56 !important;
        }

        body.dark-mode .brandTextLogo {
            color: #fff;
        }

        @media (max-width: 980px) {
            .brandsGrid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 560px) {
            .brandsBand {
                padding-bottom: 42px;
            }

            .brandsGrid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .brandCard {
                min-height: 82px;
                border-radius: 14px;
            }

            .brandTextLogo {
                font-size: 15px;
            }
        }

        /* ===== Mobile Navigation ===== */
        .mobileMenuToggle {
            display: none;
            width: 42px;
            height: 42px;
            border: 1.5px solid var(--line);
            border-radius: 12px;
            background: #fff;
            color: var(--ink);
            cursor: pointer;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .mobileMenuBars {
            width: 19px;
            height: 14px;
            position: relative;
            display: block;
        }

        .mobileMenuBars::before,
        .mobileMenuBars::after,
        .mobileMenuBars span {
            content: "";
            position: absolute;
            left: 0;
            width: 19px;
            height: 2px;
            border-radius: 999px;
            background: currentColor;
            transition: transform .22s ease, opacity .22s ease, top .22s ease;
        }

        .mobileMenuBars::before {
            top: 0;
        }

        .mobileMenuBars span {
            top: 6px;
        }

        .mobileMenuBars::after {
            top: 12px;
        }

        .mobileMenuToggle.open .mobileMenuBars::before {
            top: 6px;
            transform: rotate(45deg);
        }

        .mobileMenuToggle.open .mobileMenuBars span {
            opacity: 0;
        }

        .mobileMenuToggle.open .mobileMenuBars::after {
            top: 6px;
            transform: rotate(-45deg);
        }

        body.dark-mode .mobileMenuToggle {
            background: #131C2E;
            color: #E7EAF3;
            border-color: #2D3A56;
        }

        /* ===== Services Section ===== */
        .servicesBand {
            position: relative;
            overflow: hidden;
            padding: 72px 0;
            background:
                radial-gradient(circle at 82% 18%, rgba(36, 83, 255, .24), transparent 30%),
                linear-gradient(135deg, #05070C 0%, #0A1020 58%, #0B1E63 100%);
            color: #fff;
        }

        .servicesBand::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255, 255, 255, .035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, .035) 1px, transparent 1px);
            background-size: 42px 42px;
            mask-image: linear-gradient(to right, #000, rgba(0, 0, 0, .28));
            pointer-events: none;
        }

        .servicesShell {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: .95fr 1.05fr;
            gap: 34px;
            align-items: center;
        }

        .servicesIntro {
            padding: 12px 8px;
        }

        .servicesEyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            margin-bottom: 16px;
            border-radius: 999px;
            border: 1px solid rgba(56, 189, 248, .30);
            background: rgba(14, 165, 233, .10);
            color: #7DD3FC;
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: .8px;
            text-transform: uppercase;
        }

        .servicesIntro h3 {
            margin: 0 0 16px;
            max-width: 560px;
            font-size: clamp(30px, 4vw, 48px);
            line-height: 1.05;
            letter-spacing: -1px;
            font-family: var(--font-display);
        }

        .servicesIntro p {
            margin: 0;
            max-width: 590px;
            color: rgba(255, 255, 255, .72);
            line-height: 1.8;
            font-size: 14px;
            font-weight: 500;
        }

        .servicesContactRow {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin-top: 24px;
        }

        .servicesContactBtn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 18px;
            border-radius: 999px;
            background: #18B7F1;
            color: #04111B;
            font-weight: 800;
            font-size: 13px;
            box-shadow: 0 14px 30px rgba(24, 183, 241, .24);
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .servicesContactBtn:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 34px rgba(24, 183, 241, .34);
        }

        .servicesPhone {
            color: rgba(255, 255, 255, .68);
            font-size: 12.5px;
            font-weight: 700;
        }

        .servicesGrid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .serviceCard {
            min-height: 160px;
            padding: 20px;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, .10);
            background:
                linear-gradient(145deg, rgba(255, 255, 255, .09), rgba(255, 255, 255, .035));
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 20px 44px rgba(0, 0, 0, .18);
            transition: transform .22s ease, border-color .22s ease, background .22s ease;
        }

        .serviceCard:hover {
            transform: translateY(-4px);
            border-color: rgba(56, 189, 248, .34);
            background:
                linear-gradient(145deg, rgba(36, 83, 255, .16), rgba(255, 255, 255, .05));
        }

        .serviceIcon {
            width: 46px;
            height: 46px;
            display: grid;
            place-items: center;
            margin-bottom: 15px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(24, 183, 241, .22), rgba(36, 83, 255, .26));
            border: 1px solid rgba(125, 211, 252, .18);
            font-size: 21px;
        }

        .serviceCard h4 {
            margin: 0 0 7px;
            font-family: var(--font-display);
            font-size: 15px;
            color: #fff;
        }

        .serviceCard p {
            margin: 0;
            color: rgba(255, 255, 255, .62);
            line-height: 1.6;
            font-size: 12.5px;
            font-weight: 500;
        }

        /* ===== Shopping Cart ===== */
        .cartNavBtn {
            position: relative;
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border: 1.5px solid var(--line);
            border-radius: 12px;
            background: #fff;
            color: var(--ink);
            cursor: pointer;
            transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease;
        }

        .cartNavBtn:hover {
            transform: translateY(-2px);
            border-color: rgba(36, 83, 255, .35);
            box-shadow: 0 9px 22px rgba(36, 83, 255, .12);
        }

        .cartNavIcon {
            font-size: 19px;
            line-height: 1;
        }

        .cartCount {
            position: absolute;
            top: -7px;
            right: -7px;
            min-width: 20px;
            height: 20px;
            padding: 0 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: var(--amber);
            color: #251500;
            border: 2px solid #fff;
            font-size: 10px;
            font-weight: 900;
            font-family: var(--font-body);
        }

        .cartAddBtn {
            background: var(--ink);
            color: #fff;
        }

        .cartAddBtn:hover {
            background: var(--blue2);
            box-shadow: 0 14px 26px rgba(11, 30, 99, .22);
        }

        .cartBackdrop {
            position: fixed;
            inset: 0;
            z-index: 1090;
            background: rgba(2, 6, 23, .48);
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            opacity: 0;
            visibility: hidden;
            transition: opacity .25s ease, visibility .25s ease;
        }

        .cartBackdrop.open {
            opacity: 1;
            visibility: visible;
        }

        .cartDrawer {
            position: fixed;
            top: 0;
            right: 0;
            z-index: 1100;
            width: min(470px, 100%);
            height: 100dvh;
            display: flex;
            flex-direction: column;
            background: #fff;
            color: var(--ink);
            box-shadow: -26px 0 70px rgba(11, 18, 32, .22);
            transform: translateX(105%);
            transition: transform .32s cubic-bezier(.22, 1, .36, 1);
        }

        .cartDrawer.open {
            transform: translateX(0);
        }

        .cartDrawerHead {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding: 22px 20px 18px;
            border-bottom: 1px solid var(--line);
        }

        .cartDrawerEyebrow {
            display: block;
            margin-bottom: 3px;
            color: var(--blue1);
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: .7px;
            text-transform: uppercase;
        }

        .cartDrawerHead h3 {
            margin: 0;
            font-family: var(--font-display);
            font-size: 22px;
        }

        .cartCloseBtn {
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
            color: var(--ink);
            cursor: pointer;
            font-weight: 800;
        }

        .cartItems {
            flex: 1;
            overflow-y: auto;
            padding: 18px;
            background: #fff;
        }

        .cartItem {
            display: grid;
            grid-template-columns: 82px 1fr auto;
            gap: 14px;
            align-items: center;
            padding: 16px;
            margin-bottom: 14px;
            border: 1px solid #E2E8F0;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 6px 16px rgba(11, 18, 32, .04);
        }

        .cartItem:last-child {
            margin-bottom: 0;
        }

        .cartItemImage {
            width: 82px;
            height: 76px;
            overflow: hidden;
            border-radius: 12px;
            background: var(--paper);
            border: 1px solid var(--line);
        }

        .cartItemImage img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .cartItemImageFallback {
            width: 100%;
            height: 100%;
            display: grid;
            place-items: center;
            font-size: 22px;
        }

        .cartItemName {
            font-family: var(--font-display);
            font-size: 12.5px;
            font-weight: 800;
            line-height: 1.35;
        }

        .cartItemPrice {
            margin-top: 4px;
            color: var(--blue2);
            font-family: var(--font-mono);
            font-size: 11.5px;
            font-weight: 700;
        }

        .cartQtyRow {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-top: 8px;
        }

        .cartQtyBtn,
        .cartRemoveBtn {
            border: 1px solid var(--line);
            background: #fff;
            color: var(--ink);
            cursor: pointer;
        }

        .cartQtyBtn {
            width: 26px;
            height: 26px;
            border-radius: 8px;
            font-weight: 800;
        }

        .cartQtyValue {
            min-width: 20px;
            text-align: center;
            font-size: 12px;
            font-weight: 800;
        }

        .cartRemoveBtn {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            color: var(--red);
            font-weight: 800;
        }

        .cartEmpty {
            flex: 1;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 28px;
            text-align: center;
            color: var(--ink-soft);
        }

        .cartEmpty.show {
            display: flex;
        }

        .cartEmptyIcon {
            width: 64px;
            height: 64px;
            display: grid;
            place-items: center;
            margin-bottom: 14px;
            border-radius: 20px;
            background: var(--blue-soft);
            font-size: 28px;
        }

        .cartEmpty strong {
            color: var(--ink);
            font-family: var(--font-display);
            font-size: 15px;
        }

        .cartEmpty span {
            margin-top: 6px;
            max-width: 250px;
            font-size: 12.5px;
            line-height: 1.55;
        }

        .cartDrawerFoot {
            padding: 20px 22px 22px;
            border-top: 1px solid var(--line);
            background: #fff;
        }

        .cartDrawerFoot.hidden {
            display: none;
        }

        .cartTotalRow {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 15px 0 17px;
            margin-bottom: 12px;
            border-bottom: 1px solid #E2E8F0;
            font-weight: 800;
            font-size: 17px;
        }

        .cartTotalRow span {
            font-family: var(--font-display);
            color: var(--ink);
        }

        .cartTotalRow strong {
            font-family: var(--font-mono);
            color: #0891B2;
            font-size: 20px;
            letter-spacing: -.5px;
        }

        .cartClearBtn,
        .cartContinueBtn {
            width: 100%;
            padding: 13px 14px;
            border-radius: 13px;
            cursor: pointer;
            font-family: var(--font-body);
            font-weight: 800;
            font-size: 14px;
        }

        .cartClearBtn {
            margin-top: 10px;
            border: 0;
            background: transparent;
            color: #E11D48;
        }

        .cartClearBtn:hover {
            background: #FFF1F2;
        }

        .cartContinueBtn {
            border: 1.5px solid #CBD5E1;
            background: #fff;
            color: var(--ink);
        }

        .cartContinueBtn:hover {
            border-color: var(--blue1);
            color: var(--blue1);
        }

        body.cart-open {
            overflow: hidden;
        }

        body.dark-mode .cartNavBtn,
        body.dark-mode .cartDrawer,
        body.dark-mode .cartDrawerFoot,
        body.dark-mode .cartCloseBtn,
        body.dark-mode .cartQtyBtn,
        body.dark-mode .cartRemoveBtn {
            background: #131C2E !important;
            color: #E7EAF3 !important;
            border-color: #2D3A56 !important;
        }

        body.dark-mode .cartItemPrice,
        body.dark-mode .cartTotalRow strong {
            color: #93C5FD !important;
        }

        body.dark-mode .cartItems,
        body.dark-mode .cartItem {
            background: #131C2E !important;
            border-color: #2D3A56 !important;
        }

        body.dark-mode .cartTotalRow span {
            color: #E7EAF3 !important;
        }

        body.dark-mode .cartEmpty strong {
            color: #E7EAF3 !important;
        }

        @media (max-width:900px) {
            .servicesShell {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width:620px) {
            .servicesBand {
                padding: 54px 0;
            }

            .servicesGrid {
                grid-template-columns: 1fr;
            }

            .serviceCard {
                min-height: 0;
            }

            .cartDrawer {
                width: 100%;
            }
        }


        /* ===== Floating Live WhatsApp ===== */
        @keyframes whatsappLivePulse {
            0% {
                transform: scale(.90);
                opacity: .58;
            }

            70%,
            100% {
                transform: scale(1.42);
                opacity: 0;
            }
        }

        .floatingWhatsapp {
            position: fixed;
            right: 22px;
            bottom: 100px;
            z-index: 1000;
            width: 62px;
            height: 62px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: #20C763;
            border: 1px solid rgba(255, 255, 255, .52);
            box-shadow: 0 16px 34px rgba(32, 199, 99, .36);
            transition: transform .22s ease, box-shadow .22s ease;
            isolation: isolate;
        }

        .floatingWhatsapp::before {
            content: "";
            position: absolute;
            inset: -7px;
            z-index: -1;
            border-radius: inherit;
            background: rgba(32, 199, 99, .15);
            border: 2px solid rgba(32, 199, 99, .28);
            animation: whatsappLivePulse 2.1s ease-out infinite;
        }

        .floatingWhatsapp:hover {
            transform: translateY(-3px) scale(1.06);
            box-shadow: 0 20px 42px rgba(32, 199, 99, .46);
        }

        .floatingWhatsapp svg {
            width: 32px;
            height: 32px;
            display: block;
            fill: #fff;
        }

        /* ===== Gemini AI Chatbot Widget ===== */
        @keyframes geminiChatOpen {
            from {
                opacity: 0;
                transform: translateY(18px) scale(.96);
                filter: blur(4px);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
                filter: blur(0);
            }
        }

        @keyframes geminiFloat {

            0%,
            100% {
                transform: translateY(0) rotate(0deg);
            }

            50% {
                transform: translateY(-4px) rotate(5deg);
            }
        }

        @keyframes geminiHalo {
            0% {
                transform: scale(.88);
                opacity: .55;
            }

            70%,
            100% {
                transform: scale(1.42);
                opacity: 0;
            }
        }

        @keyframes geminiShine {
            0% {
                transform: translateX(-140%) rotate(18deg);
            }

            65%,
            100% {
                transform: translateX(230%) rotate(18deg);
            }
        }

        @keyframes chatMessageIn {
            from {
                opacity: 0;
                transform: translateY(8px) scale(.98);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes typingBounce {

            0%,
            60%,
            100% {
                transform: translateY(0);
                opacity: .45;
            }

            30% {
                transform: translateY(-4px);
                opacity: 1;
            }
        }

        @keyframes onlinePulse {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(34, 197, 94, .45);
            }

            50% {
                box-shadow: 0 0 0 5px rgba(34, 197, 94, 0);
            }
        }

        .chatbot-toggle {
            position: fixed;
            right: 22px;
            bottom: 22px;
            z-index: 999;
            width: 62px;
            height: 62px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, .34);
            background:
                radial-gradient(circle at 28% 22%, rgba(255, 255, 255, .45), transparent 24%),
                linear-gradient(145deg, #7C3AED 0%, #2453FF 48%, #0B1E63 100%);
            color: #fff;
            box-shadow: 0 16px 34px rgba(36, 83, 255, .32);
            cursor: pointer;
            display: grid;
            place-items: center;
            transition: transform .22s ease, box-shadow .22s ease;
            isolation: isolate;
        }

        .chatbot-toggle::before {
            content: "";
            position: absolute;
            inset: -5px;
            border-radius: inherit;
            border: 2px solid rgba(124, 58, 237, .34);
            animation: geminiHalo 2.2s ease-out infinite;
            z-index: -1;
        }

        .chatbot-toggle:hover {
            transform: scale(1.08) translateY(-3px);
            box-shadow: 0 20px 42px rgba(36, 83, 255, .42);
        }

        .gemini-spark {
            display: inline-grid;
            place-items: center;
            font-size: 30px;
            line-height: 1;
            color: #fff;
            text-shadow: 0 0 18px rgba(255, 255, 255, .55);
            animation: geminiFloat 3s ease-in-out infinite;
        }

        .chatbot-box {
            position: fixed;
            right: 22px;
            bottom: 174px;
            z-index: 999;
            width: min(390px, calc(100% - 28px));
            background: rgba(255, 255, 255, .98);
            border-radius: 22px;
            box-shadow: 0 28px 70px rgba(11, 18, 32, .24);
            overflow: hidden;
            display: none;
            border: 1px solid rgba(36, 83, 255, .14);
            transform-origin: bottom right;
        }

        .chatbot-box.open {
            display: block;
            animation: geminiChatOpen .32s cubic-bezier(.22, 1, .36, 1) both;
        }

        .chatbot-head {
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(circle at 15% 0%, rgba(255, 255, 255, .20), transparent 28%),
                linear-gradient(105deg, #321E81 0%, #2453FF 55%, #6957FF 100%);
            color: #fff;
            padding: 15px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .chatbot-head::after {
            content: "";
            position: absolute;
            top: -50%;
            left: -40%;
            width: 70px;
            height: 220%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .18), transparent);
            animation: geminiShine 4.8s ease-in-out infinite;
            pointer-events: none;
        }

        .chatbot-brand {
            display: flex;
            align-items: center;
            gap: 11px;
            min-width: 0;
            position: relative;
            z-index: 1;
        }

        .chatbot-brand-icon {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: rgba(255, 255, 255, .14);
            border: 1px solid rgba(255, 255, 255, .24);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        .chatbot-brand-icon .gemini-spark {
            font-size: 24px;
        }

        .chatbot-title {
            font-family: var(--font-display);
            font-size: 15px;
            font-weight: 800;
            letter-spacing: -.2px;
            line-height: 1.2;
        }

        .chatbot-status {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 4px;
            color: rgba(255, 255, 255, .82);
            font-family: var(--font-body);
            font-size: 10.5px;
            font-weight: 600;
        }

        .chatbot-status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #22C55E;
            animation: onlinePulse 1.8s ease-in-out infinite;
        }

        .chatbot-close {
            position: relative;
            z-index: 2;
            border: 1px solid rgba(255, 255, 255, .16);
            background: rgba(255, 255, 255, .13);
            color: #fff;
            width: 34px;
            height: 34px;
            display: grid;
            place-items: center;
            border-radius: 11px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 700;
            transition: background .18s ease, transform .18s ease;
        }

        .chatbot-close:hover {
            background: rgba(255, 255, 255, .22);
            transform: rotate(5deg);
        }

        .chatbot-messages {
            height: 310px;
            padding: 15px;
            overflow-y: auto;
            background:
                radial-gradient(circle at 100% 0%, rgba(124, 58, 237, .07), transparent 34%),
                linear-gradient(180deg, #F7F8FC, #EEF1F8);
            scroll-behavior: smooth;
        }

        .chatbot-messages::-webkit-scrollbar {
            width: 7px;
        }

        .chatbot-messages::-webkit-scrollbar-thumb {
            background: #CDD5E7;
            border-radius: 999px;
        }

        .chat-msg {
            max-width: 86%;
            padding: 11px 13px;
            border-radius: 16px;
            margin: 0 0 11px;
            font-size: 13px;
            line-height: 1.48;
            white-space: pre-line;
            overflow-wrap: anywhere;
            animation: chatMessageIn .24s ease both;
        }

        .chat-msg.bot {
            background: rgba(255, 255, 255, .96);
            color: var(--ink);
            border: 1px solid rgba(11, 18, 32, .08);
            border-bottom-left-radius: 5px;
            box-shadow: 0 8px 20px rgba(11, 18, 32, .05);
        }

        .chat-msg.user {
            background: linear-gradient(135deg, #2453FF, #4F46E5);
            color: #fff;
            margin-left: auto;
            border-bottom-right-radius: 5px;
            box-shadow: 0 9px 20px rgba(36, 83, 255, .20);
        }

        .chat-welcome {
            max-width: 92%;
        }

        .chat-welcome strong {
            display: block;
            margin-bottom: 4px;
            font-family: var(--font-display);
            font-size: 13.5px;
        }

        .typing-indicator {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            min-width: 56px;
            min-height: 40px;
        }

        .typing-indicator span {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #64748B;
            animation: typingBounce 1.1s ease-in-out infinite;
        }

        .typing-indicator span:nth-child(2) {
            animation-delay: .14s;
        }

        .typing-indicator span:nth-child(3) {
            animation-delay: .28s;
        }

        .chatbot-quick {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
            padding: 10px 12px;
            border-top: 1px solid var(--line);
            background: rgba(255, 255, 255, .96);
        }

        .chatbot-quick button {
            border: 1px solid #D8E0F2;
            background: #F3F6FF;
            color: #2447D8;
            border-radius: 999px;
            padding: 7px 10px;
            font-family: var(--font-body);
            font-weight: 700;
            font-size: 11.5px;
            cursor: pointer;
            transition: transform .18s ease, background .18s ease, border-color .18s ease, box-shadow .18s ease;
        }

        .chatbot-quick button:hover {
            transform: translateY(-1px);
            background: #EAF0FF;
            border-color: #B8C7FF;
            box-shadow: 0 5px 14px rgba(36, 83, 255, .10);
        }

        .chatbot-form {
            display: flex;
            gap: 8px;
            padding: 12px;
            border-top: 1px solid var(--line);
            background: #fff;
        }

        .chatbot-form input {
            flex: 1;
            margin-top: 0;
            border-radius: 13px;
            padding: 11px 12px;
            outline: none;
            border: 1.5px solid #DCE3F0;
            transition: border-color .18s ease, box-shadow .18s ease;
        }

        .chatbot-form input:focus {
            border-color: #6A72FF;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, .10);
        }

        .chatbot-form button {
            min-width: 74px;
            border: 0;
            background: linear-gradient(135deg, #2453FF, #5B4CF0);
            color: #fff;
            border-radius: 13px;
            padding: 0 14px;
            font-family: var(--font-body);
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 9px 18px rgba(36, 83, 255, .20);
            transition: transform .18s ease, box-shadow .18s ease, opacity .18s ease;
        }

        .chatbot-form button:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 12px 22px rgba(36, 83, 255, .28);
        }

        .chatbot-form button:disabled,
        .chatbot-form input:disabled {
            opacity: .62;
            cursor: not-allowed;
        }

        body.dark-mode .chatbot-box {
            background: #111A2C !important;
            border-color: #2B3856 !important;
        }

        body.dark-mode .chatbot-messages {
            background:
                radial-gradient(circle at 100% 0%, rgba(124, 58, 237, .12), transparent 34%),
                linear-gradient(180deg, #101827, #0B1220) !important;
        }

        body.dark-mode .chat-msg.bot {
            background: #172136 !important;
            color: #E7EAF3 !important;
            border-color: #2B3856 !important;
        }

        body.dark-mode .chatbot-quick,
        body.dark-mode .chatbot-form {
            background: #131C2E !important;
            border-color: #263250 !important;
        }

        body.dark-mode .chatbot-quick button {
            background: #172136;
            color: #AFC4FF;
            border-color: #2B3856;
        }

        @media (max-width:520px) {
            .chatbot-box {
                right: 14px;
                bottom: 164px;
                width: calc(100% - 28px);
                border-radius: 20px;
            }

            .chatbot-toggle {
                right: 16px;
                bottom: 16px;
                width: 58px;
                height: 58px;
            }

            .floatingWhatsapp {
                right: 16px;
                bottom: 88px;
                width: 58px;
                height: 58px;
            }

            .floatingWhatsapp svg {
                width: 30px;
                height: 30px;
            }

            .chatbot-messages {
                height: min(320px, 44vh);
            }
        }

        @media (max-width:900px) {
            .hero .inner {
                grid-template-columns: 1fr;
                text-align: center
            }

            .nav .wrap {
                position: relative;
                flex-wrap: nowrap;
            }

            .mobileMenuToggle {
                display: inline-flex;
            }

            .menu {
                position: absolute;
                top: calc(100% + 10px);
                left: 0;
                right: 0;
                display: none;
                flex-direction: column;
                gap: 6px;
                padding: 10px;
                border: 1px solid var(--line);
                border-radius: 16px;
                background: rgba(255, 255, 255, .98);
                box-shadow: var(--shadow-lg);
                backdrop-filter: blur(14px);
                -webkit-backdrop-filter: blur(14px);
                z-index: 70;
            }

            .menu.mobile-open {
                display: flex;
                animation: fadeSlideDown .22s ease both;
            }

            .menu a {
                width: 100%;
                white-space: nowrap;
                background: #F8FAFC;
                border: 1px solid var(--line);
                border-radius: 11px;
                padding: 10px 12px;
            }

            body.dark-mode .menu {
                background: rgba(19, 28, 46, .98) !important;
                border-color: #2D3A56 !important;
            }

            body.dark-mode .menu a {
                background: #0B1220 !important;
                border-color: #263250 !important;
            }

            .actions {
                margin-left: auto;
            }

            .grid4 {
                grid-template-columns: repeat(2, 1fr)
            }

            .grid3 {
                grid-template-columns: 1fr
            }

            .foot {
                grid-template-columns: 1fr 1fr
            }

            .detailsGrid {
                grid-template-columns: 1fr
            }

            .filterGrid {
                grid-template-columns: 1fr;
                align-items: stretch
            }
        }

        @media (max-width:520px) {
            .grid4 {
                grid-template-columns: 1fr
            }

            .foot {
                grid-template-columns: 1fr
            }

            .formRow {
                grid-template-columns: 1fr
            }

            .detailSlider .slide img {
                height: 260px;
            }

            .container {
                width: min(100% - 26px, 1100px);
            }
        }

        /* ===== Dark / Light Mode ===== */
        body.dark-mode {
            background: #0B1220 !important;
            color: #E7EAF3 !important;
        }

        body.dark-mode .nav,
        body.dark-mode .card,
        body.dark-mode .productCard,
        body.dark-mode .heroCard,
        body.dark-mode .filterBar,
        body.dark-mode .modal,
        body.dark-mode .chatbot-box,
        body.dark-mode footer,
        body.dark-mode section {
            background: #131C2E !important;
            color: #E7EAF3 !important;
        }

        body.dark-mode input,
        body.dark-mode select,
        body.dark-mode textarea {
            background: #0B1220 !important;
            color: #E7EAF3 !important;
            border-color: #263250 !important;
        }

        .theme-toggle {
            border: 1.5px solid var(--line);
            background: #fff;
            color: var(--ink);
            border-radius: 999px;
            padding: 9px 13px;
            cursor: pointer;
            font-weight: 700;
        }

        /* ===== Floating Light / Dark Mode Toggle ===== */
        .theme-toggle.floatingThemeToggle {
            position: fixed;
            right: 27px;
            bottom: 174px;
            z-index: 998;
            width: 52px;
            height: 52px;
            padding: 0;
            display: grid;
            place-items: center;
            border-radius: 50%;
            font-size: 20px;
            line-height: 1;
            box-shadow: 0 13px 28px rgba(11, 18, 32, .18);
            transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
        }

        .theme-toggle.floatingThemeToggle:hover {
            transform: translateY(-2px);
            box-shadow: 0 17px 34px rgba(11, 18, 32, .24);
        }

        @media (max-width:520px) {
            .theme-toggle.floatingThemeToggle {
                right: 19px;
                bottom: 158px;
                width: 52px;
                height: 52px;
            }
        }

        /* ===== Shop Location Section ===== */
        .locationBand {
            padding: 60px 0;
            background: var(--paper);
        }

        .locationBand .locationHead {
            text-align: center;
            margin-bottom: 28px;
        }

        .locationBand h3 {
            margin: 0;
            font-size: 30px;
            color: var(--ink);
            font-weight: 700;
            letter-spacing: -.5px;
        }

        .locationBand p {
            color: var(--ink-soft);
            margin-top: 8px;
            font-weight: 500;
        }

        .locationGrid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
            align-items: stretch;
        }

        .locationCard {
            background: #fff;
            border-radius: 20px;
            padding: 26px;
            box-shadow: var(--shadow);
            border: 1px solid var(--line);
        }

        .locationInfo {
            display: grid;
            gap: 12px;
            margin-top: 18px;
        }

        .locationItem {
            display: flex;
            gap: 12px;
            align-items: center;
            padding: 13px 14px;
            background: var(--paper);
            border-radius: 12px;
            color: var(--ink-soft);
            font-weight: 700;
            transition: transform .2s ease, background .2s ease;
        }

        .locationItem:hover {
            transform: translateX(4px);
            background: var(--blue-soft);
        }

        .mapBox {
            overflow: hidden;
            border-radius: 20px;
            min-height: 330px;
            box-shadow: var(--shadow);
            border: 1px solid var(--line);
            background: #fff;
        }

        .mapBox iframe {
            width: 100%;
            height: 100%;
            min-height: 330px;
            border: 0;
        }

        .mapBtn {
            display: inline-block;
            margin-top: 18px;
            background: var(--blue1);
            color: #fff;
            padding: 12px 18px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
        }

        body.dark-mode .locationBand {
            background: #0B1220 !important;
        }

        body.dark-mode .locationBand h3 {
            color: #E7EAF3 !important;
        }

        body.dark-mode .locationCard,
        body.dark-mode .mapBox {
            background: #131C2E !important;
            color: #E7EAF3 !important;
        }

        body.dark-mode .locationItem {
            background: #0B1220 !important;
            color: #E7EAF3 !important;
        }

        @media(max-width:900px) {
            .locationGrid {
                grid-template-columns: 1fr;
            }
        }


        /* ===== Product Rating UI ===== */
        .ratingMini {
            margin: 8px 0 10px;
            color: var(--amber-deep);
            font-weight: 700;
            font-size: 12.5px;
            background: #FFF7E6;
            border: 1px solid #FDE4A6;
            display: inline-block;
            padding: 5px 10px;
            border-radius: 999px;
        }

        .ratingBox {
            margin: 20px 0;
            padding: 18px;
            background: #FFF9F0;
            border: 1px solid #FBE3AE;
            border-radius: 16px;
        }

        .ratingTitle {
            font-weight: 700;
            font-size: 19px;
            color: var(--amber-deep);
            margin-bottom: 8px;
        }

        .star-rating {
            direction: rtl;
            display: inline-flex;
            gap: 6px;
            margin: 10px 0;
        }

        .star-rating input {
            display: none;
        }

        .star-rating label {
            font-size: 34px;
            color: #CBD5E1;
            cursor: pointer;
            transition: .2s;
            line-height: 1;
        }

        .star-rating label:hover,
        .star-rating label:hover~label,
        .star-rating input:checked~label {
            color: var(--amber);
        }

        .reviewItem {
            margin-top: 10px;
            padding: 13px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
        }

        .reviewHead {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            font-weight: 700;
            color: var(--ink-soft);
            font-size: 12.5px;
        }

        .reviewText {
            color: var(--ink);
            margin-top: 6px;
            font-size: 14px;
            line-height: 1.5;
        }

        body.dark-mode .ratingBox {
            background: #1B2438 !important;
            border-color: #2D3A56 !important;
            color: #E7EAF3 !important;
        }

        body.dark-mode .reviewItem {
            background: #131C2E !important;
            border-color: #2D3A56 !important;
            color: #E7EAF3 !important;
        }

        /* Scroll reveal animation */
        html {
            scroll-behavior: smooth;
        }

        .reveal-up {
            opacity: 0;
            transform: translateY(28px);
            transition: opacity .6s ease, transform .6s ease;
            will-change: opacity, transform;
        }

        .reveal-up.reveal-visible {
            opacity: 1;
            transform: translateY(0);
        }

        .reveal-delay-1 {
            transition-delay: .05s;
        }

        .reveal-delay-2 {
            transition-delay: .10s;
        }

        .reveal-delay-3 {
            transition-delay: .15s;
        }

        .reveal-delay-4 {
            transition-delay: .20s;
        }

        /* Scroll to top button */
        .scrollTopBtn {
            position: fixed;
            left: 22px;
            bottom: 22px;
            z-index: 998;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            border: 0;
            background: var(--ink);
            color: #fff;
            font-size: 18px;
            cursor: pointer;
            box-shadow: 0 14px 28px rgba(11, 18, 32, .28);
            opacity: 0;
            pointer-events: none;
            transform: translateY(12px);
            transition: .25s ease;
        }

        .scrollTopBtn.show {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0);
        }

        .scrollTopBtn:hover {
            transform: translateY(-3px);
            background: var(--blue1);
        }

        body.dark-mode .nav {
            background: rgba(19, 28, 46, .92) !important;
            border-color: rgba(255, 255, 255, .08);
        }

        body.dark-mode .menu a {
            color: #C7D0E3;
        }

        body.dark-mode .menu a:hover,
        body.dark-mode .smallTag {
            background: #0B1220 !important;
            color: #93C5FD !important;
        }

        body.dark-mode .ratingMini {
            background: #241A08 !important;
            color: #FBBF24 !important;
            border-color: #4A340C !important;
        }

        body.dark-mode .detailsBody,
        body.dark-mode .filterBar {
            background: #131C2E !important;
        }

        body.dark-mode .price {
            color: #93C5FD !important;
        }

        body.dark-mode .stockStatus.in {
            background: #12351F !important;
            color: #86EFAC !important;
        }

        body.dark-mode .stockStatus.low {
            background: #3A2A08 !important;
            color: #FCD34D !important;
        }

        body.dark-mode .stockStatus.out {
            background: #3F1717 !important;
            color: #FCA5A5 !important;
        }

        body.dark-mode .theme-toggle {
            background: #131C2E;
            color: #E7EAF3;
            border-color: #2D3A56;
        }


        /* ===== Wishlist + Laptop Comparison + Order Tracking ===== */
        .productFeatureActions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin: 10px 0;
        }

        .productFeatureActions form {
            margin: 0;
        }

        .featureMiniBtn {
            width: 100%;
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border: 1.5px solid #DCE3F0;
            border-radius: 11px;
            background: #fff;
            color: var(--ink);
            font-family: var(--font-body);
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            transition: transform .18s ease, border-color .18s ease, background .18s ease, color .18s ease;
        }

        .featureMiniBtn:hover {
            transform: translateY(-1px);
            border-color: var(--blue1);
            color: var(--blue1);
            background: var(--blue-soft);
        }

        .wishlistBtn.saved {
            border-color: #FCA5A5;
            background: #FFF1F2;
            color: #BE123C;
        }

        .compareSelectBtn.selected {
            border-color: #93C5FD;
            background: #EFF6FF;
            color: #1D4ED8;
        }

        .wishlistNotice {
            max-width: 760px;
            margin: 0 auto 18px;
            padding: 11px 14px;
            border: 1px solid #BBF7D0;
            border-radius: 13px;
            background: #F0FDF4;
            color: #166534;
            text-align: center;
            font-weight: 750;
            font-size: 13px;
        }

        .compareFloatingBar {
            position: fixed;
            left: 50%;
            bottom: 22px;
            z-index: 1200;
            display: none;
            align-items: center;
            gap: 10px;
            transform: translateX(-50%);
            padding: 10px 12px;
            border: 1px solid rgba(36, 83, 255, .18);
            border-radius: 16px;
            background: rgba(255, 255, 255, .96);
            box-shadow: 0 18px 44px rgba(11, 18, 32, .22);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        .compareFloatingBar.show {
            display: flex;
        }

        .compareFloatingText {
            font-size: 12.5px;
            font-weight: 800;
            color: var(--ink);
            white-space: nowrap;
        }

        .compareFloatingBtn {
            border: 0;
            border-radius: 11px;
            padding: 9px 12px;
            background: var(--blue1);
            color: #fff;
            font-family: var(--font-body);
            font-weight: 800;
            cursor: pointer;
        }

        .compareFloatingClear {
            border: 0;
            background: transparent;
            color: #DC2626;
            font-weight: 800;
            cursor: pointer;
        }

        .compareBackdrop {
            position: fixed;
            inset: 0;
            z-index: 1290;
            display: none;
            background: rgba(2, 6, 23, .62);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }

        .compareBackdrop.open {
            display: block;
        }

        .compareModal {
            position: fixed;
            left: 50%;
            top: 50%;
            z-index: 1300;
            display: none;
            width: min(1050px, calc(100% - 28px));
            max-height: 88vh;
            overflow: hidden;
            transform: translate(-50%, -50%);
            border: 1px solid rgba(36, 83, 255, .16);
            border-radius: 22px;
            background: #fff;
            box-shadow: 0 32px 90px rgba(2, 6, 23, .34);
        }

        .compareModal.open {
            display: block;
        }

        .compareModalHead {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 17px 18px;
            border-bottom: 1px solid var(--line);
            background: linear-gradient(135deg, #F8FAFF, #EEF4FF);
        }

        .compareModalHead h3 {
            margin: 0;
            font-size: 20px;
        }

        .compareModalClose {
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            border: 1px solid #DCE3F0;
            border-radius: 11px;
            background: #fff;
            color: var(--ink);
            font-weight: 900;
            cursor: pointer;
        }

        .compareTableWrap {
            overflow: auto;
            max-height: calc(88vh - 74px);
            padding: 16px;
        }

        .compareTable {
            width: 100%;
            min-width: 720px;
            border-collapse: collapse;
        }

        .compareTable th,
        .compareTable td {
            padding: 12px;
            border: 1px solid #E2E8F0;
            text-align: left;
            vertical-align: top;
            font-size: 12.5px;
            line-height: 1.5;
        }

        .compareTable th:first-child,
        .compareTable td:first-child {
            width: 145px;
            background: #F8FAFC;
            font-weight: 850;
        }

        .compareProductHead {
            min-width: 175px;
        }

        .compareProductHead img {
            width: 100%;
            height: 110px;
            display: block;
            object-fit: cover;
            margin-bottom: 9px;
            border-radius: 12px;
            background: #EEF1F8;
        }

        .orderTrackingSection {
            padding: 20px 0 52px;
        }

        .orderTrackingList {
            display: grid;
            gap: 16px;
        }

        .orderTrackingCard {
            padding: 18px;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: #fff;
            box-shadow: var(--shadow);
        }

        .orderTrackingHead {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 16px;
        }

        .orderTrackingProduct {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .orderTrackingProduct img {
            width: 62px;
            height: 52px;
            object-fit: cover;
            border-radius: 11px;
            border: 1px solid var(--line);
            background: #EEF1F8;
        }

        .orderTrackingProduct strong {
            display: block;
            font-family: var(--font-display);
            font-size: 13.5px;
        }

        .orderTrackingMeta {
            margin-top: 3px;
            color: var(--ink-soft);
            font-size: 11.5px;
            font-weight: 650;
        }

        .trackingCurrentBadge {
            flex: 0 0 auto;
            padding: 7px 10px;
            border-radius: 999px;
            background: var(--blue-soft);
            color: var(--blue1);
            font-size: 11.5px;
            font-weight: 850;
        }

        .trackingCurrentBadge.cancelled {
            background: #FEE2E2;
            color: #991B1B;
        }

        .trackingSteps {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 0;
            margin-top: 8px;
        }

        .trackingStep {
            position: relative;
            text-align: center;
            color: #94A3B8;
            font-size: 10.5px;
            font-weight: 750;
        }

        .trackingStep::before {
            content: "";
            position: absolute;
            top: 10px;
            left: 0;
            width: 100%;
            height: 3px;
            background: #E2E8F0;
            z-index: 0;
        }

        .trackingStep:first-child::before {
            left: 50%;
            width: 50%;
        }

        .trackingStep:last-child::before {
            width: 50%;
        }

        .trackingDot {
            position: relative;
            z-index: 1;
            width: 22px;
            height: 22px;
            display: grid;
            place-items: center;
            margin: 0 auto 7px;
            border: 3px solid #E2E8F0;
            border-radius: 50%;
            background: #fff;
            font-size: 9px;
        }

        .trackingStep.done,
        .trackingStep.active {
            color: var(--blue1);
        }

        .trackingStep.done::before,
        .trackingStep.active::before {
            background: var(--blue1);
        }

        .trackingStep.done .trackingDot,
        .trackingStep.active .trackingDot {
            border-color: var(--blue1);
            background: var(--blue1);
            color: #fff;
        }

        body.dark-mode .featureMiniBtn,
        body.dark-mode .compareFloatingBar,
        body.dark-mode .compareModal,
        body.dark-mode .compareModalClose,
        body.dark-mode .orderTrackingCard,
        body.dark-mode .trackingDot {
            background: #131C2E !important;
            color: #E7EAF3 !important;
            border-color: #2D3A56 !important;
        }

        body.dark-mode .compareModalHead,
        body.dark-mode .compareTable th:first-child,
        body.dark-mode .compareTable td:first-child {
            background: #0B1220 !important;
            color: #E7EAF3 !important;
        }

        body.dark-mode .compareTable th,
        body.dark-mode .compareTable td {
            border-color: #2D3A56 !important;
            color: #E7EAF3 !important;
        }

        body.dark-mode .compareFloatingText,
        body.dark-mode .orderTrackingProduct strong {
            color: #E7EAF3 !important;
        }

        @media (max-width:620px) {
            .productFeatureActions {
                grid-template-columns: 1fr;
            }

            .compareFloatingBar {
                bottom: 82px;
                width: calc(100% - 28px);
                justify-content: center;
            }

            .orderTrackingHead {
                align-items: flex-start;
                flex-direction: column;
            }

            .trackingSteps {
                overflow-x: auto;
                grid-template-columns: repeat(5, minmax(88px, 1fr));
                padding-bottom: 4px;
            }
        }
    </style>

</head>

<body>

    <div class="scrollProgress" id="scrollProgress"></div>

    <header class="nav">
        <div class="container">
            <div class="wrap">
                <a class="brand" href="index.php">
                    <div class="logo">💻</div>
                    <div>
                        <?= e($settings['site_name']) ?>
                    </div>
                </a>

                <button type="button" class="mobileMenuToggle" id="mobileMenuToggle" aria-label="Open navigation menu"
                    aria-expanded="false" aria-controls="mainMenu">
                    <span class="mobileMenuBars" aria-hidden="true"><span></span></span>
                </button>

                <nav class="menu" id="mainMenu">
                    <a href="index.php">Home</a>
                    <a href="#featured">Laptops</a>
                    <a href="#about">About</a>
                    <a href="#services">Services</a>
                    <a href="#shop-location">Location</a>
                    <a href="my_account.php">My Account</a>
                    <a href="#contact">Contact</a>
                </nav>

                <div class="actions">
                    <button type="button" class="cartNavBtn" id="cartNavBtn" aria-label="Open shopping cart"
                        aria-expanded="false" aria-controls="cartDrawer">
                        <span class="cartNavIcon" aria-hidden="true">🛒</span>
                        <span class="cartCount" id="cartCount">0</span>
                    </button>
                    <a class="btn outline" href="logout.php">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <section class="hero">
        <video class="heroBgVideo" autoplay muted loop playsinline preload="metadata" aria-hidden="true">
            <source src="assets/hero-tech-bg.mp4" type="video/mp4">
        </video>
        <div class="heroVideoOverlay" aria-hidden="true"></div>

        <div class="container">
            <div class="inner">
                <div>
                    <div class="welcomePill">
                        👋 Welcome,
                        <?= e($currentCustomerName) ?>
                    </div>
                    <h1>
                        <?= e($settings['hero_title']) ?>
                    </h1>
                    <p>
                        <?= e($settings['hero_subtitle']) ?>
                    </p>
                    <a class="btn cta" href="<?= e($settings['hero_button_link']) ?>">
                        <?= e($settings['hero_button_text']) ?> ›
                    </a>
                </div>

                <div class="heroCard">
                    <img class="heroImg" alt="Laptop"
                        src="https://images.unsplash.com/photo-1517336714731-489689fd1ca8?w=1400&q=80&auto=format&fit=crop">
                </div>
            </div>

            <div class="filterBar" id="featured">
                <form method="get" class="filterGrid" id="storeFilterForm">
                    <input type="hidden" name="brand" id="searchBrandInput" value="<?= (int) $brand ?>">
                    <div>
                        <label>Search</label>
                        <div class="searchSuggestWrap">
                            <input id="laptopSearchInput" name="q" value="<?= e($q) ?>"
                                placeholder="Search laptop name..." autocomplete="off" aria-autocomplete="list"
                                aria-controls="searchSuggestions" aria-expanded="false">
                            <div class="searchSuggestions" id="searchSuggestions" role="listbox"
                                aria-label="Laptop and brand suggestions"></div>
                        </div>
                    </div>

                    <div>
                        <label>Category</label>
                        <select name="cat">
                            <option value="0">All</option>
                            <?php
                            $cats2 = $conn->query("SELECT * FROM categories ORDER BY id ASC");
                            while ($c2 = $cats2->fetch_assoc()):
                                ?>
                                <option value="<?= (int) $c2['id'] ?>" <?= ((int) $c2['id'] === $cat) ? 'selected' : ''; ?>>
                                        <?= e($c2['name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div>
                        <label>Sort</label>
                        <select name="sort">
                            <option value="new" <?= $sort === 'new' ? 'selected' : ''; ?>>Newest</option>
                            <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : ''; ?>>Price Low → High
                            </option>
                            <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : ''; ?>>Price High → Low
                            </option>
                            <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : ''; ?>>Name A → Z</option>
                            <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : ''; ?>>Name Z → A</option>
                        </select>
                    </div>

                    <div>
                        <button class="btn primary" type="submit">Apply</button>
                    </div>
                </form>

                <?php if ($selectedCategory): ?>
                    <div class="filterInfo">
                        <div class="filterBadge">
                            Showing category:
                                <?= e($selectedCategory['name']) ?>
                            <a class="btn outline" style="padding:6px 10px;font-size:12px"
                                href="index.php?q=<?= urlencode($q) ?>&brand=<?= (int) $brand ?>&sort=<?= urlencode($sort) ?>#featured">
                                All Categories
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="section" style="padding-top:22px">
        <div class="container">
            <div class="title">Shop by Category<div class="divider"></div>
            </div>

            <div class="grid3">
                <?php while ($c = $cats->fetch_assoc()): ?>
                    <a class="card categoryCard <?= ((int) $c['id'] === $cat) ? 'activeCategory' : '' ?>"
                        href="index.php?cat=<?= (int) $c['id'] ?>&brand=<?= (int) $brand ?>&q=<?= urlencode($q) ?>&sort=<?= urlencode($sort) ?>#featured">
                            <?php if (!empty($c['image_url'])): ?>
                            <img src="<?= e($c['image_url']) ?>" alt="<?= e($c['name']) ?>"
                                style="width:100%;height:150px;object-fit:cover">
                            <?php else: ?>
                            <div class="placeholder" style="height:150px">No Image</div>
                            <?php endif; ?>
                        <div class="center">
                                <?= e($c['name']) ?>
                        </div>
                    </a>
                <?php endwhile; ?>
            </div>
        </div>
    </section>

    <section class="brandsBand" id="brands">
        <div class="container">
            <div class="brandsHead">
                <div class="title">Shop by Brand<div class="divider"></div>
                </div>
                <p>Choose a brand to view matching laptops from our store database.</p>
            </div>

            <?php if ($brands && $brands->num_rows > 0): ?>
                <div class="brandsGrid">
                        <?php while ($b = $brands->fetch_assoc()): ?>
                        <a class="brandCard <?= ((int) $b['id'] === $brand) ? 'activeBrand' : '' ?>"
                            data-brand="<?= e(strtoupper($b['name'])) ?>"
                            href="index.php?brand=<?= (int) $b['id'] ?>&cat=<?= (int) $cat ?>&q=<?= urlencode($q) ?>&sort=<?= urlencode($sort) ?>#featured"
                            aria-label="Shop <?= e($b['name']) ?> laptops">
                            <div class="brandLogoWrap">
                                        <?php if (!empty($b['logo_url'])): ?>
                                    <img src="<?= e($b['logo_url']) ?>" alt="<?= e($b['name']) ?> logo"
                                        onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
                                    <span class="brandTextLogo brandTextFallback">
                                                    <?= e($b['name']) ?>
                                    </span>
                                        <?php else: ?>
                                    <span class="brandTextLogo">
                                                    <?= e($b['name']) ?>
                                    </span>
                                        <?php endif; ?>
                            </div>
                        </a>
                        <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div style="text-align:center;color:var(--ink-soft);font-weight:700">No brands available yet.</div>
            <?php endif; ?>

            <?php if ($selectedBrand): ?>
                <div class="brandFilterInfo">
                    <div class="filterBadge">
                        Showing brand:
                            <?= e($selectedBrand['name']) ?>
                        <a class="btn outline" style="padding:6px 10px;font-size:12px"
                            href="index.php?q=<?= urlencode($q) ?>&cat=<?= (int) $cat ?>&brand=0&sort=<?= urlencode($sort) ?>#featured">
                            All Brands
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="section" style="padding-top:0">
        <div class="container">
            <div class="title">
                <?php
                if ($selectedBrand && $selectedCategory) {
                    echo e($selectedBrand['name'] . " " . $selectedCategory['name'] . " Laptops");
                } elseif ($selectedBrand) {
                    echo e($selectedBrand['name'] . " Laptops");
                } elseif ($selectedCategory) {
                    echo e($selectedCategory['name'] . " Laptops");
                } else {
                    echo "Featured Laptops";
                }
                ?>
                <div class="divider"></div>
            </div>

            <?php if ($wishlistNotice !== ''): ?>
                <div class="wishlistNotice"><?= e($wishlistNotice) ?></div>
            <?php endif; ?>

            <div class="grid4">
                <?php while ($p = $prods->fetch_assoc()): ?>
                        <?php
                        $gallery = split_images($p['gallery_images'] ?? '');
                        if (count($gallery) === 0 && !empty($p['image_url'])) {
                            $gallery[] = $p['image_url'];
                        }
                        ?>
                    <div class="card">
                            <?php if (count($gallery) > 0): ?>
                            <div class="slider productSlider" data-slider data-autoplay="true" data-interval="2500">
                                <div class="slides">
                                            <?php foreach ($gallery as $img): ?>
                                        <div class="slide">
                                            <img src="<?= e($img) ?>" alt="<?= e($p['name']) ?>">
                                        </div>
                                            <?php endforeach; ?>
                                </div>

                                        <?php if (count($gallery) > 1): ?>
                                    <button class="navBtn prev" type="button">‹</button>
                                    <button class="navBtn next" type="button">›</button>
                                    <div class="dots miniDots"></div>
                                        <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="placeholder">No Image</div>
                            <?php endif; ?>

                        <div class="body">
                            <div style="font-weight:700;font-family:var(--font-display)">
                                    <?= e($p['name']) ?>
                            </div>

                                <?php if (!empty($p['category_name'])): ?>
                                <div class="smallTag">
                                            <?= e($p['category_name']) ?>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($p['brand_name'])): ?>
                                <div class="smallTag" style="margin-left:6px">
                                            <?= e($p['brand_name']) ?>
                                </div>
                                <?php endif; ?>

                                <?php
                                $cardRegularPrice = (float) ($p['price'] ?? 0);
                                $cardOfferPrice = (float) ($p['offer_price'] ?? 0);
                                $cardHasOffer = $cardOfferPrice > 0 && $cardOfferPrice < $cardRegularPrice;
                                ?>
                                <?php if ($cardHasOffer): ?>
                                <div class="priceOfferRow">
                                    <div class="price">RS.
                                                <?= number_format($cardOfferPrice, 2) ?>
                                    </div>
                                    <div class="oldPrice">RS.
                                                <?= number_format($cardRegularPrice, 2) ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="price">RS.
                                            <?= number_format($cardRegularPrice, 2) ?>
                                </div>
                                <?php endif; ?>

                                <?php $cardStock = (int) ($p['stock_quantity'] ?? 0); ?>
                                <?php if ($cardStock <= 0): ?>
                                <div class="stockStatus out">Out of Stock</div>
                                <?php else: ?>
                                <div class="stockStatus in">In Stock</div>
                                <?php endif; ?>

                            <div class="ratingMini">
                                    <?= rating_text($p['avg_rating'] ?? 0, $p['rating_count'] ?? 0) ?>
                            </div>

                                <?php $cardInWishlist = isset($wishlistProductIds[(int) $p['id']]); ?>
                            <div class="productFeatureActions">
                                <form method="post">
                                    <input type="hidden" name="wishlist_product_id" value="<?= (int) $p['id'] ?>">
                                    <input type="hidden" name="wishlist_action"
                                        value="<?= $cardInWishlist ? 'remove' : 'add' ?>">
                                    <button type="submit"
                                        class="featureMiniBtn wishlistBtn <?= $cardInWishlist ? 'saved' : '' ?>">
                                            <?= $cardInWishlist ? '♥ Saved' : '♡ Wishlist' ?>
                                    </button>
                                </form>

                                <button type="button" class="featureMiniBtn compareSelectBtn"
                                    data-compare-id="<?= (int) $p['id'] ?>" data-compare-name="<?= e($p['name']) ?>"
                                    data-compare-brand="<?= e($p['brand_name'] ?? 'No Brand') ?>"
                                    data-compare-category="<?= e($p['category_name'] ?? 'No Category') ?>"
                                    data-compare-regular-price="<?= number_format($cardRegularPrice, 2, '.', '') ?>"
                                    data-compare-offer-price="<?= number_format($cardOfferPrice, 2, '.', '') ?>"
                                    data-compare-effective-price="<?= number_format($cardHasOffer ? $cardOfferPrice : $cardRegularPrice, 2, '.', '') ?>"
                                    data-compare-stock="<?= $cardStock > 0 ? 'In Stock' : 'Out of Stock' ?>"
                                    data-compare-colors="<?= e(trim((string) ($p['available_colors'] ?? '')) !== '' ? $p['available_colors'] : 'Not specified') ?>"
                                    data-compare-rating="<?= e(rating_text($p['avg_rating'] ?? 0, $p['rating_count'] ?? 0)) ?>"
                                    data-compare-image="<?= e($gallery[0] ?? ($p['image_url'] ?? '')) ?>"
                                    data-compare-description="<?= e($p['description'] ?? '') ?>">
                                    ⇄ Compare
                                </button>
                            </div>

                            <a class="btn primary full"
                                href="index.php?product_id=<?= (int) $p['id'] ?>&q=<?= urlencode($q) ?>&cat=<?= (int) $cat ?>&brand=<?= (int) $brand ?>&sort=<?= urlencode($sort) ?>#details">
                                View Details
                            </a>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>

            <?php if ($prods->num_rows === 0): ?>
                <div style="text-align:center;color:var(--ink-soft);font-weight:700;margin-top:14px">
                    No laptops found for your filter.
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($selected): ?>
        <section class="detailsWrap" id="details">
            <div class="container">
                <div class="title">
                        <?= e($selected['name']) ?> Details<div class="divider"></div>
                </div>

                <div class="detailsCard">
                    <div class="detailsGrid">
                        <div class="slider detailSlider" data-slider data-autoplay="true" data-interval="3000">
                            <div class="slides">
                                    <?php if (count($selectedImages) > 0): ?>
                                            <?php foreach ($selectedImages as $imgPath): ?>
                                        <div class="slide"><img src="<?= e($imgPath) ?>" alt=""></div>
                                            <?php endforeach; ?>
                                    <?php else: ?>
                                    <div class="slide">
                                        <div class="placeholder" style="height:380px">No Image</div>
                                    </div>
                                    <?php endif; ?>
                            </div>

                                <?php if (count($selectedImages) > 1): ?>
                                <button class="navBtn prev" type="button">‹</button>
                                <button class="navBtn next" type="button">›</button>
                                <div class="dots"></div>
                                <?php endif; ?>
                        </div>

                        <div class="detailsBody">
                            <h2 style="margin:0 0 6px;font-weight:700;letter-spacing:-.3px">
                                    <?= e($selected['name']) ?>
                            </h2>

                                <?php if (!empty($selected['category_name'])): ?>
                                <div class="smallTag" style="margin-bottom:8px">
                                            <?= e($selected['category_name']) ?>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($selected['brand_name'])): ?>
                                <div class="smallTag" style="margin-bottom:8px;margin-left:6px">
                                            <?= e($selected['brand_name']) ?>
                                </div>
                                <?php endif; ?>

                                <?php
                                $selectedRegularPrice = (float) ($selected['price'] ?? 0);
                                $selectedOfferPrice = (float) ($selected['offer_price'] ?? 0);
                                $selectedHasOffer = $selectedOfferPrice > 0 && $selectedOfferPrice < $selectedRegularPrice;
                                ?>
                                <?php if ($selectedHasOffer): ?>
                                <div class="priceOfferRow">
                                    <div class="price">RS.
                                                <?= number_format($selectedOfferPrice, 2) ?>
                                    </div>
                                    <div class="oldPrice">RS.
                                                <?= number_format($selectedRegularPrice, 2) ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="price">RS.
                                            <?= number_format($selectedRegularPrice, 2) ?>
                                </div>
                                <?php endif; ?>

                                <?php $selectedStock = (int) ($selected['stock_quantity'] ?? 0); ?>
                                <?php if ($selectedStock <= 0): ?>
                                <div class="stockStatus out">Out of Stock</div>
                                <?php else: ?>
                                <div class="stockStatus in">In Stock</div>
                                <?php endif; ?>

                                <?php $selectedColors = parse_product_colors($selected['available_colors'] ?? ''); ?>
                                <?php if (count($selectedColors) > 0): ?>
                                <div class="productColorsBlock">
                                    <div class="productColorsLabel">Available Colors</div>
                                    <div class="productColorList" aria-label="Available laptop colors">
                                                <?php foreach ($selectedColors as $productColor): ?>
                                            <span class="productColorSwatch" style="background:<?= e($productColor['css']) ?>"
                                                title="<?= e($productColor['name']) ?>"
                                                aria-label="<?= e($productColor['name']) ?>"></span>
                                                <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php $selectedInWishlist = isset($wishlistProductIds[(int) $selected['id']]); ?>
                            <div class="productFeatureActions" style="max-width:420px">
                                <form method="post">
                                    <input type="hidden" name="wishlist_product_id" value="<?= (int) $selected['id'] ?>">
                                    <input type="hidden" name="wishlist_action"
                                        value="<?= $selectedInWishlist ? 'remove' : 'add' ?>">
                                    <button type="submit"
                                        class="featureMiniBtn wishlistBtn <?= $selectedInWishlist ? 'saved' : '' ?>">
                                            <?= $selectedInWishlist ? '♥ Saved' : '♡ Wishlist' ?>
                                    </button>
                                </form>

                                <button type="button" class="featureMiniBtn compareSelectBtn"
                                    data-compare-id="<?= (int) $selected['id'] ?>"
                                    data-compare-name="<?= e($selected['name']) ?>"
                                    data-compare-brand="<?= e($selected['brand_name'] ?? 'No Brand') ?>"
                                    data-compare-category="<?= e($selected['category_name'] ?? 'No Category') ?>"
                                    data-compare-regular-price="<?= number_format($selectedRegularPrice, 2, '.', '') ?>"
                                    data-compare-offer-price="<?= number_format($selectedOfferPrice, 2, '.', '') ?>"
                                    data-compare-effective-price="<?= number_format($selectedHasOffer ? $selectedOfferPrice : $selectedRegularPrice, 2, '.', '') ?>"
                                    data-compare-stock="<?= $selectedStock > 0 ? 'In Stock' : 'Out of Stock' ?>"
                                    data-compare-colors="<?= e(trim((string) ($selected['available_colors'] ?? '')) !== '' ? $selected['available_colors'] : 'Not specified') ?>"
                                    data-compare-rating="<?= e(rating_text($selectedAvgRating, $selectedRatingCount)) ?>"
                                    data-compare-image="<?= e($selectedImages[0] ?? ($selected['image_url'] ?? '')) ?>"
                                    data-compare-description="<?= e($selected['description'] ?? '') ?>">
                                    ⇄ Compare
                                </button>
                            </div>

                            <div class="muted">
                                    <?= e($selected['description'] ?? 'No description yet. Admin dashboard la update pannalaam.') ?>
                            </div>

                                <?php if ($payment_msg !== ""): ?>
                                <div class="<?= $payment_type === 'error' ? 'error' : 'info' ?>">
                                            <?= e($payment_msg) ?>
                                </div>
                                <?php endif; ?>

                                <?php if ($order_msg && $order_for_id === (int) $selected['id']): ?>
                                <div class="success">
                                            <?= e($order_msg) ?>
                                </div>
                                <?php endif; ?>

                                <?php if ($order_err && $order_for_id === (int) $selected['id']): ?>
                                <div class="error">
                                            <?= e($order_err) ?>
                                </div>
                                <?php endif; ?>


                            <div class="ratingBox">
                                <div class="ratingTitle">Product Rating</div>

                                <div style="font-weight:700;color:var(--amber-deep);margin-bottom:8px">
                                        <?= rating_text($selectedAvgRating, $selectedRatingCount) ?>
                                </div>

                                    <?php if ($rating_msg && $rating_for_id === (int) $selected['id']): ?>
                                    <div class="success">
                                                <?= e($rating_msg) ?>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($rating_err && $rating_for_id === (int) $selected['id']): ?>
                                    <div class="error">
                                                <?= e($rating_err) ?>
                                    </div>
                                    <?php endif; ?>

                                <form method="post"
                                    action="index.php?product_id=<?= (int) $selected['id'] ?>&q=<?= urlencode($q) ?>&cat=<?= (int) $cat ?>&brand=<?= (int) $brand ?>&sort=<?= urlencode($sort) ?>#details">

                                    <input type="hidden" name="rating_product_id" value="<?= (int) $selected['id'] ?>">

                                    <label><b>Give Your Rating</b></label>

                                    <div class="star-rating">
                                        <input type="radio" id="star5" name="rating" value="5" required>
                                        <label for="star5">★</label>

                                        <input type="radio" id="star4" name="rating" value="4">
                                        <label for="star4">★</label>

                                        <input type="radio" id="star3" name="rating" value="3">
                                        <label for="star3">★</label>

                                        <input type="radio" id="star2" name="rating" value="2">
                                        <label for="star2">★</label>

                                        <input type="radio" id="star1" name="rating" value="1">
                                        <label for="star1">★</label>
                                    </div>

                                    <label><b>Your Review</b></label>
                                    <textarea name="review" placeholder="Write your review here..."
                                        style="min-height:80px"></textarea>

                                    <button class="btn primary" type="submit" name="submit_rating" style="margin-top:10px">
                                        Submit Rating
                                    </button>
                                </form>

                                    <?php if ($selectedReviews && $selectedReviews->num_rows > 0): ?>
                                    <div style="margin-top:14px;font-weight:700;font-family:var(--font-display)">Customer
                                        Reviews</div>

                                            <?php while ($rev = $selectedReviews->fetch_assoc()): ?>
                                        <div class="reviewItem">
                                            <div class="reviewHead">
                                                <span>
                                                                <?= e($rev['customer_name'] ?? 'Customer') ?>
                                                </span>
                                                <span style="color:var(--amber-deep)">
                                                                <?= e(rating_stars((int) $rev['rating'])) ?>
                                                </span>
                                            </div>

                                                        <?php if (!empty($rev['review'])): ?>
                                                <div class="reviewText">
                                                                    <?= e($rev['review']) ?>
                                                </div>
                                                        <?php endif; ?>
                                        </div>
                                            <?php endwhile; ?>
                                    <?php else: ?>
                                    <div style="margin-top:10px;color:var(--ink-soft);font-weight:700">
                                        No reviews yet. Be the first to rate this laptop.
                                    </div>
                                    <?php endif; ?>
                            </div>

                            <h3 style="margin:16px 0 6px;font-weight:700">Order Now</h3>

                            <form id="orderForm" method="post"
                                action="index.php?product_id=<?= (int) $selected['id'] ?>&q=<?= urlencode($q) ?>&cat=<?= (int) $cat ?>&brand=<?= (int) $brand ?>&sort=<?= urlencode($sort) ?>#details">
                                <input type="hidden" name="product_id" value="<?= (int) $selected['id'] ?>">

                                <div class="formRow">
                                    <div>
                                        <label>Your Name</label>
                                        <input id="cname" name="customer_name" placeholder="Enter your name" required>
                                    </div>
                                    <div>
                                        <label>Phone</label>
                                        <input id="cphone" name="phone" placeholder="+94..." required>
                                    </div>
                                </div>

                                <div class="formRow">
                                    <div>
                                        <label>Email</label>
                                        <input id="cemail" type="email" name="customer_email" placeholder="Enter your email"
                                            required>
                                    </div>
                                    <div>
                                        <label>Quantity</label>
                                        <input id="cqty" type="number" name="qty" min="1"
                                            max="<?= max(1, $selectedStock) ?>" value="1" <?= $selectedStock <= 0 ? 'disabled' : '' ?> required>
                                    </div>
                                </div>

                                <div style="margin-top:10px">
                                    <label>Address</label>
                                    <textarea id="caddr" name="address" placeholder="Enter delivery address"
                                        required></textarea>
                                </div>

                                <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap">
                                    <button class="btn cartAddBtn" type="button" data-cart-id="<?= (int) $selected['id'] ?>"
                                        data-cart-name="<?= e($selected['name']) ?>"
                                        data-cart-price="<?= number_format((float) $selected['price'], 2, '.', '') ?>"
                                        data-cart-image="<?= e($selectedImages[0] ?? ($selected['image_url'] ?? '')) ?>"
                                        data-cart-stock="<?= $selectedStock ?>" onclick="addToCartFromButton(this)"
                                        <?= $selectedStock <= 0 ? 'disabled' : '' ?>>
                                            <?= $selectedStock <= 0 ? 'Out of Stock' : '🛒 Add to Cart' ?>
                                    </button>
                                    <button class="btn cod" type="submit" name="place_order" <?= $selectedStock <= 0 ? 'disabled' : '' ?>>Cash on Delivery</button>
                                    <button class="btn pay" type="submit" name="pay_online" <?= $selectedStock <= 0 ? 'disabled' : '' ?>>Pay Online</button>

                                    <button class="btn whatsapp" type="button" data-wa="<?= e($whatsAppBase) ?>"
                                        data-product="<?= e($selected['name']) ?>"
                                        data-price="<?= number_format((float) $selected['price'], 2, '.', '') ?>"
                                        onclick="sendWhatsApp(this)" <?= $selectedStock <= 0 ? 'disabled' : '' ?>>
                                        Order via WhatsApp
                                    </button>

                                    <a class="btn outline"
                                        href="index.php?q=<?= urlencode($q) ?>&cat=<?= (int) $cat ?>&brand=<?= (int) $brand ?>&sort=<?= urlencode($sort) ?>#featured">
                                        Back
                                    </a>
                                </div>
                            </form>

                        </div>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="aboutBand" id="about">
        <div class="container">
            <div class="aboutShell">
                <div class="aboutCopy">
                    <div class="aboutEyebrow">Built around your next laptop</div>
                    <h3>Smart laptop shopping, made simple.</h3>
                    <p class="aboutLead">
                        <?= e($settings['about_text']) ?>
                    </p>

                    <div class="aboutStats">
                        <div class="aboutStat">
                            <strong>AI Assisted</strong>
                            <span>Personalized laptop guidance</span>
                        </div>
                        <div class="aboutStat">
                            <strong>Flexible Orders</strong>
                            <span>Website, chatbot or WhatsApp</span>
                        </div>
                        <div class="aboutStat">
                            <strong>Customer First</strong>
                            <span>Clear product details and support</span>
                        </div>
                    </div>
                </div>

                <div class="aboutFeatures">
                    <div class="aboutFeature">
                        <div class="aboutFeatureIcon">✨</div>
                        <div>
                            <strong>Find the right fit faster</strong>
                            <span>Browse by category, compare options and use the AI assistant for needs-based
                                suggestions.</span>
                        </div>
                    </div>

                    <div class="aboutFeature">
                        <div class="aboutFeatureIcon">💬</div>
                        <div>
                            <strong>Shop your way</strong>
                            <span>Choose direct ordering, AI chatbot ordering or WhatsApp support from one
                                storefront.</span>
                        </div>
                    </div>

                    <div class="aboutFeature">
                        <div class="aboutFeatureIcon">🛡️</div>
                        <div>
                            <strong>Simple, transparent experience</strong>
                            <span>See product details, customer ratings, pricing and order information before you
                                decide.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>


    <section class="servicesBand" id="services">
        <div class="container">
            <div class="servicesShell">
                <div class="servicesIntro">
                    <div class="servicesEyebrow">Laptop care & support</div>
                    <h3>Keep your laptop running at its best.</h3>
                    <p>
                        From hardware checks to upgrades and software support, our service team helps you diagnose,
                        maintain and improve your laptop with clear guidance and reliable support.
                    </p>
                    <div class="servicesContactRow">
                        <a class="servicesContactBtn" href="#contact">
                            Contact Us <span aria-hidden="true">→</span>
                        </a>
                        <?php if (!empty($settings['phone'])): ?>
                            <span class="servicesPhone">☎
                                    <?= e($settings['phone']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="servicesGrid">
                    <article class="serviceCard">
                        <div class="serviceIcon">🛠️</div>
                        <div>
                            <h4>Device Repairs</h4>
                            <p>Hardware diagnosis and repair support for common laptop issues.</p>
                        </div>
                    </article>

                    <article class="serviceCard">
                        <div class="serviceIcon">⚙️</div>
                        <div>
                            <h4>Upgrades & Performance</h4>
                            <p>RAM, storage and performance upgrade guidance for compatible laptops.</p>
                        </div>
                    </article>

                    <article class="serviceCard">
                        <div class="serviceIcon">💻</div>
                        <div>
                            <h4>Software Support</h4>
                            <p>Operating system, drivers and essential software setup assistance.</p>
                        </div>
                    </article>

                    <article class="serviceCard">
                        <div class="serviceIcon">🧹</div>
                        <div>
                            <h4>Cleaning & Maintenance</h4>
                            <p>Routine laptop care, cleaning and preventive maintenance guidance.</p>
                        </div>
                    </article>
                </div>
            </div>
        </div>
    </section>


    <section class="locationBand" id="shop-location">
        <div class="container">
            <div class="locationHead">
                <h3>Our Shop Location</h3>
                <p>Visit our store or contact us for laptop orders and support.</p>
            </div>

            <div class="locationGrid">
                <div class="locationCard">
                    <h3 style="font-size:24px;margin-bottom:8px">Apple Store</h3>
                    <p style="text-align:left;margin:0;color:var(--ink-soft);font-weight:500;line-height:1.6">
                        We provide quality laptops, student laptops, business laptops and gaming laptops with friendly
                        customer support.
                    </p>

                    <div class="locationInfo">
                        <div class="locationItem">🏪 Apple Store</div>
                        <div class="locationItem">📍 Polonnaruwa</div>
                        <div class="locationItem">📞 +94 704875024</div>
                        <div class="locationItem">📧 mhdaseem216@gmail.com</div>
                    </div>

                    <a class="mapBtn" href="https://www.google.com/maps/search/?api=1&query=Apple%20Store%20Polonnaruwa"
                        target="_blank">
                        📍 Open in Google Maps
                    </a>
                </div>

                <div class="mapBox">
                    <iframe loading="lazy" referrerpolicy="no-referrer-when-downgrade"
                        src="https://www.google.com/maps?q=Apple%20Store%20Polonnaruwa&output=embed">
                    </iframe>
                </div>
            </div>
        </div>
    </section>



    <!-- Laptop Comparison -->
    <div class="compareFloatingBar" id="compareFloatingBar" aria-live="polite">
        <span class="compareFloatingText" id="compareFloatingText">0 laptops selected</span>
        <button type="button" class="compareFloatingBtn" id="compareOpenBtn">Compare</button>
        <button type="button" class="compareFloatingClear" id="compareClearBtn">Clear</button>
    </div>

    <div class="compareBackdrop" id="compareBackdrop" aria-hidden="true"></div>
    <div class="compareModal" id="compareModal" role="dialog" aria-modal="true" aria-label="Laptop Comparison">
        <div class="compareModalHead">
            <div>
                <h3>Laptop Comparison</h3>
                <small>Select 2 or 3 laptops. Comparison generates automatically.</small>
            </div>
            <button type="button" class="compareModalClose" id="compareModalClose"
                aria-label="Close comparison">✕</button>
        </div>
        <div class="compareTableWrap" id="compareTableWrap"></div>
    </div>

    <!-- Shopping Cart Drawer -->
    <div class="cartBackdrop" id="cartBackdrop" aria-hidden="true"></div>
    <aside class="cartDrawer" id="cartDrawer" aria-label="Shopping cart" aria-hidden="true">
        <div class="cartDrawerHead">
            <div>
                <span class="cartDrawerEyebrow">Your selection</span>
                <h3>Shopping Cart</h3>
            </div>
            <button type="button" class="cartCloseBtn" id="cartCloseBtn" aria-label="Close shopping cart">✕</button>
        </div>

        <div class="cartItems" id="cartItems"></div>

        <div class="cartEmpty" id="cartEmpty">
            <div class="cartEmptyIcon">🛒</div>
            <strong>Your cart is empty</strong>
            <span>Add a laptop from the product details section.</span>
        </div>

        <div class="cartDrawerFoot" id="cartDrawerFoot">
            <div class="cartTotalRow">
                <span>Total</span>
                <strong id="cartTotal">Rs. 0.00</strong>
            </div>
            <button type="button" class="cartContinueBtn" id="cartContinueBtn">Continue Shopping</button>
            <button type="button" class="cartClearBtn" id="cartClearBtn">Clear Cart</button>
        </div>
    </aside>


    <!-- Floating Light / Dark Mode Toggle -->
    <button type="button" class="theme-toggle floatingThemeToggle" id="themeToggle"
        aria-label="Switch light and dark mode" title="Light / Dark Mode">🌙</button>

    <?php if ($whatsAppBase !== '#'): ?>
        <a class="floatingWhatsapp" href="<?= e($whatsAppBase) ?>" target="_blank" rel="noopener noreferrer"
            aria-label="Chat with us live on WhatsApp" title="WhatsApp">
            <svg viewBox="0 0 32 32" aria-hidden="true">
                <path
                    d="M19.11 17.49c-.26-.13-1.52-.75-1.75-.84-.24-.09-.41-.13-.58.13-.17.26-.67.84-.82 1.01-.15.17-.3.19-.56.06-.26-.13-1.09-.4-2.07-1.28-.77-.68-1.28-1.52-1.43-1.78-.15-.26-.02-.4.11-.53.12-.12.26-.3.39-.45.13-.15.17-.26.26-.43.09-.17.04-.32-.02-.45-.06-.13-.58-1.4-.8-1.92-.21-.5-.43-.43-.58-.44h-.5c-.17 0-.45.06-.69.32-.24.26-.91.89-.91 2.17 0 1.28.93 2.52 1.06 2.69.13.17 1.83 2.8 4.44 3.93.62.27 1.1.43 1.48.55.62.2 1.18.17 1.63.1.5-.08 1.52-.62 1.73-1.22.21-.6.21-1.11.15-1.22-.06-.11-.24-.17-.5-.3M16.02 27.18h-.01a11.14 11.14 0 0 1-5.68-1.56l-.41-.24-4.22 1.11 1.13-4.11-.27-.42a11.14 11.14 0 0 1-1.71-5.94c0-6.16 5.01-11.17 11.18-11.17a11.1 11.1 0 0 1 7.9 3.28 11.1 11.1 0 0 1 3.27 7.9c0 6.16-5.01 11.16-11.18 11.16m9.51-20.67A13.35 13.35 0 0 0 16.03 2.6C8.63 2.6 2.61 8.61 2.61 16c0 2.36.62 4.66 1.79 6.68L2.5 29.6l7.08-1.86a13.4 13.4 0 0 0 6.44 1.64h.01c7.39 0 13.4-6.01 13.4-13.4 0-3.58-1.39-6.95-3.9-9.47" />
            </svg>
        </a>
    <?php endif; ?>

    <!-- Gemini AI Chatbot Widget -->
    <button class="chatbot-toggle" id="chatbotToggle" type="button" aria-label="Open Gemini laptop assistant"
        aria-expanded="false" aria-controls="chatbotBox">
        <span class="gemini-spark" aria-hidden="true">✦</span>
    </button>

    <div class="chatbot-box" id="chatbotBox" role="dialog" aria-label="Gemini Laptop Assistant">
        <div class="chatbot-head">
            <div class="chatbot-brand">
                <div class="chatbot-brand-icon" aria-hidden="true">
                    <span class="gemini-spark">✦</span>
                </div>
                <div>
                    <div class="chatbot-title">Gemini Laptop Assistant</div>
                    <div class="chatbot-status">
                        <span class="chatbot-status-dot"></span>
                        Google Gemini • Live store catalog
                    </div>
                </div>
            </div>
            <button class="chatbot-close" id="chatbotClose" type="button" aria-label="Close chatbot">✕</button>
        </div>

        <div class="chatbot-messages" id="chatMessages" aria-live="polite">
            <div class="chat-msg bot chat-welcome">
                <strong>Hi 👋 I’m your Gemini-powered laptop assistant.</strong>
                Tamil / English la கேளுங்க. Store database-la irukkura laptops based on your budget and use-ku recommend
                pannuren. Order panna kooda help pannuren.
            </div>
        </div>

        <div class="chatbot-quick">

            <button type="button" data-question="Show the lowest price laptop available in the store">💰 Low
                Price</button>
            <button type="button" data-question="Show laptop categories available in the store">📂 Categories</button>
            <button type="button" data-question="How can I order a laptop through the chatbot?">🛒 Order</button>
        </div>

        <form class="chatbot-form" id="chatbotForm">
            <input id="chatInput" type="text" placeholder="Ask Gemini about a laptop..." autocomplete="off"
                aria-label="Chat message">
            <button type="submit" id="chatSendBtn">Send</button>
        </form>
    </div>

    <footer id="contact">
        <div class="container">
            <div class="footerMain">
                <div>
                    <div class="footerBrandName">
                        <?= e($settings['site_name']) ?>
                    </div>

                    <p class="footerAboutText">
                        <?= e($settings['about_text']) ?>
                    </p>

                    <div class="footerContact">
                        <?php if (!empty($settings['address'])): ?>
                            <div class="footerContactItem">
                                <strong>Address:</strong>
                                    <?= e($settings['address']) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($settings['phone'])): ?>
                            <div class="footerContactItem">
                                <strong>Phone:</strong>
                                <a href="<?= e($whatsAppBase) ?>" target="_blank" rel="noopener noreferrer">
                                        <?= e($settings['phone']) ?>
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($settings['email'])): ?>
                            <div class="footerContactItem">
                                <strong>Email:</strong>
                                <a href="mailto:<?= e($settings['email']) ?>">
                                        <?= e($settings['email']) ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="footerSocial">
                        <?php if ($whatsAppBase !== '#'): ?>
                            <a href="<?= e($whatsAppBase) ?>" target="_blank" rel="noopener noreferrer"
                                aria-label="WhatsApp">
                                <svg viewBox="0 0 32 32" aria-hidden="true">
                                    <path
                                        d="M19.11 17.49c-.26-.13-1.52-.75-1.75-.84-.24-.09-.41-.13-.58.13-.17.26-.67.84-.82 1.01-.15.17-.3.19-.56.06-.26-.13-1.09-.4-2.07-1.28-.77-.68-1.28-1.52-1.43-1.78-.15-.26-.02-.4.11-.53.12-.12.26-.3.39-.45.13-.15.17-.26.26-.43.09-.17.04-.32-.02-.45-.06-.13-.58-1.4-.8-1.92-.21-.5-.43-.43-.58-.44h-.5c-.17 0-.45.06-.69.32-.24.26-.91.89-.91 2.17 0 1.28.93 2.52 1.06 2.69.13.17 1.83 2.8 4.44 3.93.62.27 1.1.43 1.48.55.62.2 1.18.17 1.63.1.5-.08 1.52-.62 1.73-1.22.21-.6.21-1.11.15-1.22-.06-.11-.24-.17-.5-.3M16.02 27.18h-.01a11.14 11.14 0 0 1-5.68-1.56l-.41-.24-4.22 1.11 1.13-4.11-.27-.42a11.14 11.14 0 0 1-1.71-5.94c0-6.16 5.01-11.17 11.18-11.17a11.1 11.1 0 0 1 7.9 3.28 11.1 11.1 0 0 1 3.27 7.9c0 6.16-5.01 11.16-11.18 11.16m9.51-20.67A13.35 13.35 0 0 0 16.03 2.6C8.63 2.6 2.61 8.61 2.61 16c0 2.36.62 4.66 1.79 6.68L2.5 29.6l7.08-1.86a13.4 13.4 0 0 0 6.44 1.64h.01c7.39 0 13.4-6.01 13.4-13.4 0-3.58-1.39-6.95-3.9-9.47" />
                                </svg>
                            </a>
                        <?php endif; ?>

                        <?php if (!empty($settings['email'])): ?>
                            <a href="mailto:<?= e($settings['email']) ?>" aria-label="Email">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path
                                        d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2m0 4-8 5-8-5V6l8 5 8-5z" />
                                </svg>
                            </a>
                        <?php endif; ?>

                        <a href="#shop-location" aria-label="Store location">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path
                                    d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7m0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5" />
                            </svg>
                        </a>
                    </div>
                </div>

                <div>
                    <div class="footerTitle">About</div>
                    <div class="footerLinks">
                        <a href="index.php">Home</a>
                        <a href="#about">About Us</a>
                        <a href="#shop-location">Location</a>
                        <a href="#contact">Contact</a>
                    </div>
                </div>

                <div>
                    <div class="footerTitle">Shop</div>
                    <div class="footerLinks">
                        <a href="#featured">Products</a>
                        <a href="#featured">Laptops</a>
                        <a href="#brands">Brands</a>
                        <a href="javascript:void(0)" onclick="openCart()">Shopping Cart</a>
                    </div>
                </div>

                <div>
                    <div class="footerTitle">Services</div>
                    <div class="footerLinks">
                        <a href="#services">Device Repairs</a>
                        <a href="#services">Upgrades</a>
                        <a href="#services">Software Support</a>
                        <a href="#services">Cleaning & Maintenance</a>
                    </div>
                </div>

                <div>
                    <div class="footerTitle">Support</div>
                    <div class="footerLinks">
                        <a href="javascript:void(0)" onclick="openChatbot()">Gemini AI Assistant</a>
                        <a href="<?= e($whatsAppBase) ?>" target="_blank" rel="noopener noreferrer">WhatsApp Support</a>
                        <a href="#shop-location">Visit Our Store</a>
                        <a href="#contact">Customer Support</a>
                    </div>
                </div>
            </div>

            <div class="footerBottom">
                ©
                <?= date('Y') ?> <strong>
                    <?= e($settings['site_name']) ?>
                </strong>. All Rights Reserved.
            </div>
        </div>
    </footer>

    <script>
        /* ===== Mobile Navigation ===== */
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mainMenu = document.getElementById('mainMenu');

        function closeMobileMenu() {
            mainMenu?.classList.remove('mobile-open');
            mobileMenuToggle?.classList.remove('open');
            mobileMenuToggle?.setAttribute('aria-expanded', 'false');
        }

        mobileMenuToggle?.addEventListener('click', function () {
            const willOpen = !mainMenu?.classList.contains('mobile-open');
            mainMenu?.classList.toggle('mobile-open', willOpen);
            mobileMenuToggle.classList.toggle('open', willOpen);
            mobileMenuToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });

        mainMenu?.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', closeMobileMenu);
        });

        document.addEventListener('click', function (event) {
            if (window.innerWidth > 900 || !mainMenu?.classList.contains('mobile-open')) return;
            const clickedInsideMenu = mainMenu.contains(event.target);
            const clickedToggle = mobileMenuToggle?.contains(event.target);
            if (!clickedInsideMenu && !clickedToggle) closeMobileMenu();
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 900) closeMobileMenu();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && mainMenu?.classList.contains('mobile-open')) closeMobileMenu();
        });

        function initSlider(slider) {
            const slidesWrap = slider.querySelector('.slides');
            const slides = slider.querySelectorAll('.slide');
            const prevBtn = slider.querySelector('.prev');
            const nextBtn = slider.querySelector('.next');
            const dotsWrap = slider.querySelector('.dots');
            const total = slides.length;

            if (!slidesWrap || total <= 0) return;

            let cur = 0;
            let timer = null;
            const autoplay = slider.dataset.autoplay === 'true' && total > 1;
            const interval = parseInt(slider.dataset.interval || '3000', 10);

            function renderDots() {
                if (!dotsWrap) return;
                dotsWrap.innerHTML = '';
                for (let i = 0; i < total; i++) {
                    const dot = document.createElement('div');
                    dot.className = 'dot' + (i === 0 ? ' active' : '');
                    dot.addEventListener('click', function () {
                        goTo(i);
                    });
                    dotsWrap.appendChild(dot);
                }
            }

            function updateDots() {
                if (!dotsWrap) return;
                const dots = dotsWrap.querySelectorAll('.dot');
                dots.forEach((d, i) => d.classList.toggle('active', i === cur));
            }

            function goTo(i) {
                cur = (i + total) % total;
                slidesWrap.style.transform = 'translateX(' + (-cur * 100) + '%)';
                updateDots();
            }

            function next() { goTo(cur + 1); }
            function prev() { goTo(cur - 1); }

            function startAuto() {
                if (!autoplay) return;
                stopAuto();
                timer = setInterval(next, interval);
            }

            function stopAuto() {
                if (timer) {
                    clearInterval(timer);
                    timer = null;
                }
            }

            if (total > 1) {
                renderDots();

                if (prevBtn) prevBtn.addEventListener('click', prev);
                if (nextBtn) nextBtn.addEventListener('click', next);

                slider.addEventListener('mouseenter', stopAuto);
                slider.addEventListener('mouseleave', startAuto);
                slider.addEventListener('touchstart', stopAuto, { passive: true });
                slider.addEventListener('touchend', startAuto, { passive: true });

                startAuto();
            }

            goTo(0);
        }

        document.querySelectorAll('[data-slider]').forEach(initSlider);

        function sendWhatsApp(btn) {
            const base = btn.getAttribute('data-wa');
            if (!base || base === '#') {
                alert('WhatsApp number not set in admin settings.');
                return;
            }

            const product = btn.getAttribute('data-product') || '';
            const price = btn.getAttribute('data-price') || '';

            const name = (document.getElementById('cname')?.value || '').trim();
            const phone = (document.getElementById('cphone')?.value || '').trim();
            const email = (document.getElementById('cemail')?.value || '').trim();
            const qty = (document.getElementById('cqty')?.value || '1').trim();
            const addr = (document.getElementById('caddr')?.value || '').trim();

            if (!name || !phone || !email || !addr) {
                alert('Please fill Name, Phone, Email, Address first.');
                return;
            }

            const msg =
                `Hello, I want to order a laptop.

Product: ${product}
Price: Rs. ${price}
Qty: ${qty}

Customer: ${name}
Phone: ${phone}
Email: ${email}
Address: ${addr}`;

            const url = base + "?text=" + encodeURIComponent(msg);
            window.open(url, "_blank");
        }

        /* ===== Shopping Cart Logic ===== */
        const CART_STORAGE_KEY = 'olpmShoppingCart';
        const cartNavBtn = document.getElementById('cartNavBtn');
        const cartCount = document.getElementById('cartCount');
        const cartDrawer = document.getElementById('cartDrawer');
        const cartBackdrop = document.getElementById('cartBackdrop');
        const cartCloseBtn = document.getElementById('cartCloseBtn');
        const cartItems = document.getElementById('cartItems');
        const cartEmpty = document.getElementById('cartEmpty');
        const cartDrawerFoot = document.getElementById('cartDrawerFoot');
        const cartTotal = document.getElementById('cartTotal');
        const cartClearBtn = document.getElementById('cartClearBtn');
        const cartContinueBtn = document.getElementById('cartContinueBtn');

        let shoppingCart = [];

        function loadCart() {
            try {
                const stored = JSON.parse(localStorage.getItem(CART_STORAGE_KEY) || '[]');
                shoppingCart = Array.isArray(stored) ? stored : [];
            } catch (e) {
                shoppingCart = [];
            }

            shoppingCart = shoppingCart
                .filter(item => item && Number(item.id) > 0 && String(item.name || '').trim() !== '')
                .map(item => ({
                    id: Number(item.id),
                    name: String(item.name || ''),
                    price: Number(item.price) || 0,
                    image: String(item.image || ''),
                    stock: Number.isFinite(Number(item.stock)) ? Math.max(0, Number(item.stock)) : null,
                    qty: Math.max(1, Number(item.qty) || 1)
                }));

            renderCart();
        }

        function saveCart() {
            localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(shoppingCart));
            renderCart();
        }

        function formatCartMoney(value) {
            return 'Rs. ' + Number(value || 0).toLocaleString('en-LK', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function cartItemCount() {
            return shoppingCart.reduce((sum, item) => sum + Number(item.qty || 0), 0);
        }

        function openCart() {
            cartDrawer?.classList.add('open');
            cartBackdrop?.classList.add('open');
            cartDrawer?.setAttribute('aria-hidden', 'false');
            cartBackdrop?.setAttribute('aria-hidden', 'false');
            cartNavBtn?.setAttribute('aria-expanded', 'true');
            document.body.classList.add('cart-open');
        }

        function closeCart() {
            cartDrawer?.classList.remove('open');
            cartBackdrop?.classList.remove('open');
            cartDrawer?.setAttribute('aria-hidden', 'true');
            cartBackdrop?.setAttribute('aria-hidden', 'true');
            cartNavBtn?.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('cart-open');
        }

        function addToCartFromButton(btn) {
            const product = {
                id: Number(btn?.dataset?.cartId || 0),
                name: String(btn?.dataset?.cartName || '').trim(),
                price: Number(btn?.dataset?.cartPrice || 0),
                image: String(btn?.dataset?.cartImage || '').trim(),
                stock: Math.max(0, Number(btn?.dataset?.cartStock || 0))
            };

            if (!product.id || !product.name) {
                alert('Product details are missing.');
                return;
            }

            if (product.stock <= 0) {
                alert('This product is currently Out of Stock.');
                return;
            }

            const existing = shoppingCart.find(item => Number(item.id) === product.id);

            if (existing) {
                existing.stock = product.stock;

                if (Number(existing.qty) >= product.stock) {
                    alert('Only ' + product.stock + ' item(s) are available in stock.');
                    return;
                }

                existing.qty += 1;
            } else {
                shoppingCart.push({
                    ...product,
                    qty: 1
                });
            }

            saveCart();
            openCart();
        }

        function changeCartQty(productId, change) {
            const item = shoppingCart.find(row => Number(row.id) === Number(productId));
            if (!item) return;

            if (
                Number(change) > 0 &&
                item.stock !== null &&
                Number(item.qty) >= Number(item.stock)
            ) {
                alert('Only ' + Number(item.stock) + ' item(s) are available in stock.');
                return;
            }

            item.qty = Number(item.qty) + Number(change);

            if (item.qty <= 0) {
                shoppingCart = shoppingCart.filter(row => Number(row.id) !== Number(productId));
            }

            saveCart();
        }

        function removeCartItem(productId) {
            shoppingCart = shoppingCart.filter(row => Number(row.id) !== Number(productId));
            saveCart();
        }

        function clearCart() {
            if (shoppingCart.length === 0) return;

            const confirmed = window.confirm('Clear all items from your cart?');
            if (!confirmed) return;

            shoppingCart = [];
            saveCart();
        }

        function renderCart() {
            if (!cartItems || !cartCount || !cartTotal || !cartEmpty || !cartDrawerFoot) return;

            const totalCount = cartItemCount();
            cartCount.textContent = String(totalCount);
            cartCount.style.display = totalCount > 0 ? 'inline-flex' : 'none';

            cartItems.innerHTML = '';

            if (shoppingCart.length === 0) {
                cartItems.style.display = 'none';
                cartEmpty.classList.add('show');
                cartDrawerFoot.classList.add('hidden');
                cartTotal.textContent = 'Rs. 0.00';
                return;
            }

            cartItems.style.display = 'block';
            cartEmpty.classList.remove('show');
            cartDrawerFoot.classList.remove('hidden');

            let total = 0;

            shoppingCart.forEach(item => {
                total += Number(item.price) * Number(item.qty);

                const row = document.createElement('div');
                row.className = 'cartItem';

                const imageBox = document.createElement('div');
                imageBox.className = 'cartItemImage';

                if (item.image) {
                    const img = document.createElement('img');
                    img.src = item.image;
                    img.alt = item.name;
                    img.loading = 'lazy';
                    img.addEventListener('error', () => {
                        imageBox.innerHTML = '<div class="cartItemImageFallback">💻</div>';
                    });
                    imageBox.appendChild(img);
                } else {
                    imageBox.innerHTML = '<div class="cartItemImageFallback">💻</div>';
                }

                const info = document.createElement('div');

                const name = document.createElement('div');
                name.className = 'cartItemName';
                name.textContent = item.name;

                const price = document.createElement('div');
                price.className = 'cartItemPrice';
                price.textContent = formatCartMoney(item.price);

                const qtyRow = document.createElement('div');
                qtyRow.className = 'cartQtyRow';

                const minus = document.createElement('button');
                minus.type = 'button';
                minus.className = 'cartQtyBtn';
                minus.textContent = '−';
                minus.setAttribute('aria-label', 'Decrease quantity');
                minus.addEventListener('click', () => changeCartQty(item.id, -1));

                const qtyValue = document.createElement('span');
                qtyValue.className = 'cartQtyValue';
                qtyValue.textContent = String(item.qty);

                const plus = document.createElement('button');
                plus.type = 'button';
                plus.className = 'cartQtyBtn';
                plus.textContent = '+';
                plus.setAttribute('aria-label', 'Increase quantity');
                plus.addEventListener('click', () => changeCartQty(item.id, 1));

                qtyRow.append(minus, qtyValue, plus);
                info.append(name, price, qtyRow);

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'cartRemoveBtn';
                remove.textContent = '×';
                remove.setAttribute('aria-label', 'Remove ' + item.name);
                remove.addEventListener('click', () => removeCartItem(item.id));

                row.append(imageBox, info, remove);
                cartItems.appendChild(row);
            });

            cartTotal.textContent = formatCartMoney(total);
        }

        cartNavBtn?.addEventListener('click', openCart);
        cartCloseBtn?.addEventListener('click', closeCart);
        cartBackdrop?.addEventListener('click', closeCart);
        cartContinueBtn?.addEventListener('click', closeCart);
        cartClearBtn?.addEventListener('click', clearCart);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && cartDrawer?.classList.contains('open')) {
                closeCart();
            }
        });

        loadCart();


        /* ===== Gemini AI Chatbot Logic ===== */
        const chatbotToggle = document.getElementById('chatbotToggle');
        const chatbotBox = document.getElementById('chatbotBox');
        const chatbotClose = document.getElementById('chatbotClose');
        const chatMessages = document.getElementById('chatMessages');
        const chatbotForm = document.getElementById('chatbotForm');
        const chatInput = document.getElementById('chatInput');
        const chatSendBtn = document.getElementById('chatSendBtn');
        const chatHistory = [];

        let chatbotBusy = false;

        function scrollChatToBottom() {
            requestAnimationFrame(() => {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            });
        }

        function addChatMessage(text, who) {
            const div = document.createElement('div');
            div.className = 'chat-msg ' + who;
            div.textContent = text;
            chatMessages.appendChild(div);
            scrollChatToBottom();
            return div;
        }

        function addTypingIndicator() {
            const div = document.createElement('div');
            div.className = 'chat-msg bot typing-indicator';
            div.setAttribute('aria-label', 'Gemini is typing');
            div.innerHTML = '<span></span><span></span><span></span>';
            chatMessages.appendChild(div);
            scrollChatToBottom();
            return div;
        }

        function setChatBusy(busy) {
            chatbotBusy = busy;
            if (chatInput) chatInput.disabled = busy;
            if (chatSendBtn) {
                chatSendBtn.disabled = busy;
                chatSendBtn.textContent = busy ? '...' : 'Send';
            }

            document.querySelectorAll('.chatbot-quick button').forEach(btn => {
                btn.disabled = busy;
            });
        }

        async function askChatbot(question) {
            const text = String(question || '').trim();
            if (!text || chatbotBusy) return;

            addChatMessage(text, 'user');
            const loading = addTypingIndicator();
            setChatBusy(true);

            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 50000);

            try {
                const res = await fetch('assistant_api.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        message: text,
                        history: chatHistory.slice(-8)
                    }),
                    signal: controller.signal
                });

                const raw = await res.text();
                let data = null;

                try {
                    data = raw ? JSON.parse(raw) : null;
                } catch (parseError) {
                    throw new Error('Chat server returned an invalid response.');
                }

                const reply = String(data?.reply || '').trim();

                if (!res.ok) {
                    throw new Error(reply || 'Gemini service request failed.');
                }

                loading.className = 'chat-msg bot';
                loading.removeAttribute('aria-label');
                loading.textContent = reply || 'Sorry, Gemini reply varala. Again try pannunga.';

                chatHistory.push({ role: 'user', content: text });
                chatHistory.push({ role: 'assistant', content: loading.textContent });

                if (chatHistory.length > 16) {
                    chatHistory.splice(0, chatHistory.length - 16);
                }
            } catch (err) {
                loading.className = 'chat-msg bot';
                loading.removeAttribute('aria-label');

                if (err?.name === 'AbortError') {
                    loading.textContent = 'Gemini response konjam late aaguthu. Please once more try pannunga.';
                } else {
                    const message = String(err?.message || '');

                    if (message.toLowerCase().includes('api key')) {
                        loading.textContent = 'Gemini API key configure aagala. chat_config.php-la valid GEMINI_API_KEY set pannunga.';
                    } else {
                        loading.textContent = message || 'Gemini connect aagala. Internet / chat_api.php configuration check pannunga.';
                    }
                }
            } finally {
                clearTimeout(timeoutId);
                setChatBusy(false);
                scrollChatToBottom();
                setTimeout(() => chatInput?.focus(), 80);
            }
        }

        function openChatbot() {
            chatbotBox?.classList.add('open');
            chatbotToggle?.setAttribute('aria-expanded', 'true');
            setTimeout(() => chatInput?.focus(), 120);
        }

        function closeChatbot() {
            chatbotBox?.classList.remove('open');
            chatbotToggle?.setAttribute('aria-expanded', 'false');
        }

        chatbotToggle?.addEventListener('click', function () {
            if (chatbotBox?.classList.contains('open')) {
                closeChatbot();
            } else {
                openChatbot();
            }
        });

        chatbotClose?.addEventListener('click', closeChatbot);

        chatbotForm?.addEventListener('submit', function (e) {
            e.preventDefault();
            const value = chatInput?.value || '';

            if (chatInput) {
                chatInput.value = '';
            }

            askChatbot(value);
        });

        document.querySelectorAll('.chatbot-quick [data-question]').forEach(btn => {
            btn.addEventListener('click', () => {
                openChatbot();
                askChatbot(btn.getAttribute('data-question') || '');
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && chatbotBox?.classList.contains('open')) {
                closeChatbot();
            }
        });

    </script>


    <script>
        /* ===== Search Suggestions ===== */
        (function () {
            const suggestionData = <?= json_encode(
                $searchSuggestions,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_HEX_TAG |
                JSON_HEX_AMP |
                JSON_HEX_APOS |
                JSON_HEX_QUOT
            ) ?>;

            const form = document.getElementById('storeFilterForm');
            const input = document.getElementById('laptopSearchInput');
            const dropdown = document.getElementById('searchSuggestions');
            const brandInput = document.getElementById('searchBrandInput');

            if (!form || !input || !dropdown || !brandInput) return;

            /* Keep the suggestion layer outside the hero/filter stacking context. */
            document.body.appendChild(dropdown);

            let visibleSuggestions = [];
            let activeIndex = -1;

            function positionSuggestions() {
                const rect = input.getBoundingClientRect();
                const gap = 7;
                const viewportPadding = 12;
                const maxWidth = Math.max(260, window.innerWidth - (viewportPadding * 2));
                const width = Math.min(rect.width, maxWidth);

                let left = rect.left;
                if (left + width > window.innerWidth - viewportPadding) {
                    left = window.innerWidth - viewportPadding - width;
                }
                if (left < viewportPadding) {
                    left = viewportPadding;
                }

                dropdown.style.left = left + 'px';
                dropdown.style.top = (rect.bottom + gap) + 'px';
                dropdown.style.width = width + 'px';
            }

            function closeSuggestions() {
                dropdown.classList.remove('show');
                dropdown.innerHTML = '';
                input.setAttribute('aria-expanded', 'false');
                activeIndex = -1;
            }

            function selectSuggestion(item) {
                if (!item) return;

                if (item.type === 'Brand') {
                    brandInput.value = String(item.brand_id || 0);
                    input.value = '';
                } else {
                    input.value = item.value || item.label || '';
                    brandInput.value = String(item.brand_id || 0);
                }

                closeSuggestions();
                form.submit();
            }

            function syncActiveItem() {
                const buttons = dropdown.querySelectorAll('.searchSuggestionItem');
                buttons.forEach((button, index) => {
                    const isActive = index === activeIndex;
                    button.classList.toggle('active', isActive);
                    button.setAttribute('aria-selected', isActive ? 'true' : 'false');
                    if (isActive) {
                        button.scrollIntoView({ block: 'nearest' });
                    }
                });
            }

            function renderSuggestions() {
                const query = input.value.trim().toLowerCase();

                if (!query) {
                    closeSuggestions();
                    return;
                }

                const queryWords = query.split(/\s+/).filter(Boolean);

                visibleSuggestions = suggestionData
                    .filter(item => {
                        const label = String(item.label || '').toLowerCase();
                        const brandName = String(item.brand_name || '').toLowerCase();
                        const searchableText = (label + ' ' + brandName).trim();

                        return queryWords.every(word => searchableText.includes(word));
                    })
                    .slice(0, 8);

                dropdown.innerHTML = '';
                activeIndex = -1;

                if (visibleSuggestions.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'searchSuggestionEmpty';
                    empty.textContent = 'No matching laptop or brand found.';
                    dropdown.appendChild(empty);
                    positionSuggestions();
                    dropdown.classList.add('show');
                    input.setAttribute('aria-expanded', 'true');
                    return;
                }

                visibleSuggestions.forEach((item, index) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'searchSuggestionItem';
                    button.setAttribute('role', 'option');
                    button.setAttribute('aria-selected', 'false');

                    const main = document.createElement('span');
                    main.className = 'searchSuggestionMain';

                    const name = document.createElement('span');
                    name.className = 'searchSuggestionName';
                    name.textContent = item.label || '';

                    const meta = document.createElement('span');
                    meta.className = 'searchSuggestionMeta';
                    meta.textContent = item.type === 'Brand'
                        ? 'View laptops from this brand'
                        : (item.brand_name ? item.brand_name + ' laptop' : 'Laptop');

                    const type = document.createElement('span');
                    type.className = 'searchSuggestionType';
                    type.textContent = item.type || 'Laptop';

                    main.appendChild(name);
                    main.appendChild(meta);
                    button.appendChild(main);
                    button.appendChild(type);

                    button.addEventListener('mousedown', function (event) {
                        event.preventDefault();
                    });

                    button.addEventListener('click', function () {
                        selectSuggestion(visibleSuggestions[index]);
                    });

                    dropdown.appendChild(button);
                });

                positionSuggestions();
                dropdown.classList.add('show');
                input.setAttribute('aria-expanded', 'true');
            }

            input.addEventListener('input', renderSuggestions);

            input.addEventListener('focus', function () {
                if (input.value.trim()) {
                    renderSuggestions();
                }
            });

            input.addEventListener('keydown', function (event) {
                if (!dropdown.classList.contains('show') || visibleSuggestions.length === 0) {
                    return;
                }

                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    activeIndex = (activeIndex + 1) % visibleSuggestions.length;
                    syncActiveItem();
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    activeIndex = activeIndex <= 0
                        ? visibleSuggestions.length - 1
                        : activeIndex - 1;
                    syncActiveItem();
                } else if (event.key === 'Enter' && activeIndex >= 0) {
                    event.preventDefault();
                    selectSuggestion(visibleSuggestions[activeIndex]);
                } else if (event.key === 'Escape') {
                    closeSuggestions();
                }
            });

            document.addEventListener('click', function (event) {
                if (!dropdown.contains(event.target) && event.target !== input) {
                    closeSuggestions();
                }
            });

            window.addEventListener('resize', function () {
                if (dropdown.classList.contains('show')) {
                    positionSuggestions();
                }
            });

            window.addEventListener('scroll', function () {
                if (dropdown.classList.contains('show')) {
                    positionSuggestions();
                }
            }, { passive: true });
        })();
    </script>


    <script>
        /* ===== Laptop Comparison: select 2-3 and auto-generate ===== */
        (function () {
            const selected = new Map();
            const floatingBar = document.getElementById('compareFloatingBar');
            const floatingText = document.getElementById('compareFloatingText');
            const openBtn = document.getElementById('compareOpenBtn');
            const clearBtn = document.getElementById('compareClearBtn');
            const modal = document.getElementById('compareModal');
            const backdrop = document.getElementById('compareBackdrop');
            const closeBtn = document.getElementById('compareModalClose');
            const tableWrap = document.getElementById('compareTableWrap');

            function escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function money(value) {
                const num = Number(value || 0);
                return 'RS. ' + num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            function readProduct(btn) {
                return {
                    id: btn.dataset.compareId || '',
                    name: btn.dataset.compareName || '',
                    brand: btn.dataset.compareBrand || 'No Brand',
                    category: btn.dataset.compareCategory || 'No Category',
                    regularPrice: Number(btn.dataset.compareRegularPrice || 0),
                    offerPrice: Number(btn.dataset.compareOfferPrice || 0),
                    effectivePrice: Number(btn.dataset.compareEffectivePrice || 0),
                    stock: btn.dataset.compareStock || '',
                    colors: btn.dataset.compareColors || 'Not specified',
                    rating: btn.dataset.compareRating || 'No ratings yet',
                    image: btn.dataset.compareImage || '',
                    description: btn.dataset.compareDescription || 'No description'
                };
            }

            function syncButtons() {
                document.querySelectorAll('.compareSelectBtn').forEach(btn => {
                    const isSelected = selected.has(String(btn.dataset.compareId || ''));
                    btn.classList.toggle('selected', isSelected);
                    btn.textContent = isSelected ? '✓ Selected' : '⇄ Compare';
                });
            }

            function renderBar() {
                const count = selected.size;
                if (floatingText) floatingText.textContent = count + (count === 1 ? ' laptop selected' : ' laptops selected');
                floatingBar?.classList.toggle('show', count > 0);
                if (openBtn) openBtn.disabled = count < 2;
            }

            function buildTable() {
                if (!tableWrap || selected.size < 2) return;
                const products = Array.from(selected.values());

                const headCells = products.map(p => {
                    const image = p.image
                        ? '<img src="' + escapeHtml(p.image) + '" alt="' + escapeHtml(p.name) + '">'
                        : '';
                    return '<th><div class="compareProductHead">' + image + '<strong>' + escapeHtml(p.name) + '</strong></div></th>';
                }).join('');

                const rows = [
                    ['Brand', p => p.brand],
                    ['Category', p => p.category],
                    ['Current Price', p => money(p.effectivePrice)],
                    ['Regular Price', p => money(p.regularPrice)],
                    ['Offer Price', p => (p.offerPrice > 0 && p.offerPrice < p.regularPrice) ? money(p.offerPrice) : '-'],
                    ['Availability', p => p.stock],
                    ['Available Colors', p => p.colors],
                    ['Rating', p => p.rating],
                    ['Description', p => p.description || 'No description']
                ];

                const bodyRows = rows.map(row => {
                    return '<tr><td>' + escapeHtml(row[0]) + '</td>' +
                        products.map(p => '<td>' + escapeHtml(row[1](p)) + '</td>').join('') +
                        '</tr>';
                }).join('');

                tableWrap.innerHTML = '<table class="compareTable"><thead><tr><th>Compare</th>' + headCells + '</tr></thead><tbody>' + bodyRows + '</tbody></table>';
            }

            function openComparison() {
                if (selected.size < 2) {
                    alert('Select at least 2 laptops to compare.');
                    return;
                }
                buildTable();
                modal?.classList.add('open');
                backdrop?.classList.add('open');
                backdrop?.setAttribute('aria-hidden', 'false');
            }

            function closeComparison() {
                modal?.classList.remove('open');
                backdrop?.classList.remove('open');
                backdrop?.setAttribute('aria-hidden', 'true');
            }

            document.querySelectorAll('.compareSelectBtn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const product = readProduct(btn);
                    const id = String(product.id);

                    if (selected.has(id)) {
                        selected.delete(id);
                    } else {
                        if (selected.size >= 3) {
                            alert('You can compare a maximum of 3 laptops.');
                            return;
                        }
                        selected.set(id, product);
                    }

                    syncButtons();
                    renderBar();

                    if (selected.size >= 2) {
                        openComparison();
                    } else {
                        closeComparison();
                    }
                });
            });

            openBtn?.addEventListener('click', openComparison);
            closeBtn?.addEventListener('click', closeComparison);
            backdrop?.addEventListener('click', closeComparison);

            clearBtn?.addEventListener('click', function () {
                selected.clear();
                syncButtons();
                renderBar();
                closeComparison();
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') closeComparison();
            });

            renderBar();
        })();
    </script>

    <script>
        const themeToggle = document.getElementById("themeToggle");

        function setTheme(mode) {
            if (mode === "dark") {
                document.body.classList.add("dark-mode");
                if (themeToggle) themeToggle.textContent = "☀️";
            } else {
                document.body.classList.remove("dark-mode");
                if (themeToggle) themeToggle.textContent = "🌙";
            }
            localStorage.setItem("siteTheme", mode);
        }

        setTheme(localStorage.getItem("siteTheme") || "light");

        if (themeToggle) {
            themeToggle.addEventListener("click", function () {
                const isDark = document.body.classList.contains("dark-mode");
                setTheme(isDark ? "light" : "dark");
            });
        }
    </script>


    <script>
        /* ===== Smooth Scroll Reveal + UI Helpers ===== */
        (function () {
            const revealTargets = [
                '.section',
                '.card',
                '.detailsCard',
                '.locationCard',
                '.mapBox',
                '.ratingBox',
                '.aboutBand',
                '.filterBar',
                'footer'
            ];

            const items = [];
            revealTargets.forEach(selector => {
                document.querySelectorAll(selector).forEach((el, index) => {
                    if (!el.classList.contains('reveal-up')) {
                        el.classList.add('reveal-up');
                        el.classList.add('reveal-delay-' + ((index % 4) + 1));
                        items.push(el);
                    }
                });
            });

            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('reveal-visible');
                            observer.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.12 });

                items.forEach(el => observer.observe(el));
            } else {
                items.forEach(el => el.classList.add('reveal-visible'));
            }

            const scrollBtn = document.createElement('button');
            scrollBtn.className = 'scrollTopBtn';
            scrollBtn.type = 'button';
            scrollBtn.innerHTML = '↑';
            scrollBtn.setAttribute('aria-label', 'Scroll to top');
            document.body.appendChild(scrollBtn);

            const progressBar = document.getElementById('scrollProgress');

            function updateProgress() {
                const doc = document.documentElement;
                const scrollTop = window.scrollY || doc.scrollTop;
                const height = doc.scrollHeight - doc.clientHeight;
                const pct = height > 0 ? (scrollTop / height) * 100 : 0;
                if (progressBar) progressBar.style.width = pct + '%';
                scrollBtn.classList.toggle('show', scrollTop > 450);
            }

            window.addEventListener('scroll', updateProgress, { passive: true });
            updateProgress();

            scrollBtn.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        })();
    </script>

</body>

</html>