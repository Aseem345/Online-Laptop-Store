<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['customer_id'])) {
    header("Location: register.php");
    exit;
}

require_once __DIR__ . "/db.php";

$customerId = (int) $_SESSION['customer_id'];

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function has_column(mysqli $conn, string $table, string $column): bool
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnEscaped = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$columnEscaped}'");
    return $result && $result->num_rows > 0;
}

function status_class(string $status): string
{
    $status = strtolower(trim($status));

    if (in_array($status, ['completed', 'delivered', 'paid'], true)) {
        return 'success';
    }

    if (in_array($status, ['cancelled', 'canceled', 'failed', 'rejected'], true)) {
        return 'danger';
    }

    if (in_array($status, ['shipped', 'processing', 'confirmed'], true)) {
        return 'info';
    }

    return 'warning';
}


/* ===== Warranty Duration Helper =====
   Reads an existing product description such as:
   "1 Year Warranty", "2 Years Service Warranty", or "18 Months Warranty".
   When no warranty duration is written, the tracker uses 12 months.
*/
function product_warranty_months(string $description): int
{
    $description = trim($description);

    if ($description === '' || stripos($description, 'warranty') === false) {
        return 12;
    }

    $durationsInMonths = [];

    if (preg_match_all('/(\d+(?:\.\d+)?)\s*(?:year|yr)s?/i', $description, $yearMatches)) {
        foreach ($yearMatches[1] as $value) {
            $years = (float) $value;
            if ($years > 0) {
                $durationsInMonths[] = max(1, (int) round($years * 12));
            }
        }
    }

    if (preg_match_all('/(\d+(?:\.\d+)?)\s*months?/i', $description, $monthMatches)) {
        foreach ($monthMatches[1] as $value) {
            $months = (float) $value;
            if ($months > 0) {
                $durationsInMonths[] = max(1, (int) round($months));
            }
        }
    }

    if (!$durationsInMonths) {
        return 12;
    }

    /* If the description contains more than one warranty duration,
       use the longest stated coverage period. */
    return max($durationsInMonths);
}

function warranty_duration_label(int $months): string
{
    if ($months > 0 && $months % 12 === 0) {
        $years = (int) ($months / 12);
        return $years . ' Year' . ($years === 1 ? '' : 's');
    }

    return $months . ' Month' . ($months === 1 ? '' : 's');
}

/* ===== Return Photo Upload Helper ===== */
function save_return_photo(string $fileKey, int $customerId, int $orderId): array
{
    if (
        !isset($_FILES[$fileKey]) ||
        !isset($_FILES[$fileKey]['error']) ||
        $_FILES[$fileKey]['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return ['', ''];
    }

    if ($_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return ['', 'Return photo upload failed. Please try again.'];
    }

    $tmp = (string) ($_FILES[$fileKey]['tmp_name'] ?? '');
    $size = (int) ($_FILES[$fileKey]['size'] ?? 0);

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['', 'Invalid return photo upload.'];
    }

    if ($size <= 0 || $size > (5 * 1024 * 1024)) {
        return ['', 'Return photo must be 5 MB or smaller.'];
    }

    $imageInfo = @getimagesize($tmp);
    $mime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';

    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowedMime[$mime])) {
        return ['', 'Return photo must be JPG, PNG or WEBP.'];
    }

    $uploadDir = __DIR__ . '/uploads/returns';

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        return ['', 'Return photo upload folder could not be created.'];
    }

    try {
        $randomPart = bin2hex(random_bytes(5));
    } catch (Throwable $e) {
        $randomPart = (string) mt_rand(10000, 99999);
    }

    $extension = $allowedMime[$mime];
    $fileName = 'return_' . $customerId . '_' . $orderId . '_' . time() . '_' . $randomPart . '.' . $extension;
    $absolutePath = $uploadDir . '/' . $fileName;
    $relativePath = 'uploads/returns/' . $fileName;

    if (!move_uploaded_file($tmp, $absolutePath)) {
        return ['', 'Return photo could not be saved.'];
    }

    return [$relativePath, ''];
}

/* ===== My Account database support ===== */
if (!has_column($conn, 'orders', 'customer_id')) {
    $conn->query("ALTER TABLE orders ADD COLUMN customer_id INT NULL AFTER id");
}

if (!has_column($conn, 'orders', 'customer_email') && has_column($conn, 'orders', 'phone')) {
    $conn->query("ALTER TABLE orders ADD COLUMN customer_email VARCHAR(255) NULL AFTER phone");
}

/* ===== RETURN FEATURE DATABASE SUPPORT ===== */
if (!has_column($conn, 'orders', 'completed_at')) {
    $conn->query("ALTER TABLE orders ADD COLUMN completed_at DATETIME NULL AFTER status");
}

$conn->query("
CREATE TABLE IF NOT EXISTS return_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    customer_id INT NOT NULL,
    product_id INT NOT NULL,
    reason VARCHAR(120) NOT NULL,
    description TEXT NULL,
    evidence_image VARCHAR(500) NULL,
    return_type VARCHAR(30) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'Pending',
    admin_note TEXT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    received_at DATETIME NULL,
    completed_at DATETIME NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_order_return_request (order_id),
    KEY idx_return_customer (customer_id),
    KEY idx_return_product (product_id),
    KEY idx_return_status (status)
)
");

if (!has_column($conn, 'return_requests', 'evidence_image')) {
    $conn->query("ALTER TABLE return_requests ADD COLUMN evidence_image VARCHAR(500) NULL AFTER description");
}

/* Profile fields used by this page. */
if (!has_column($conn, 'customers', 'phone')) {
    $conn->query("ALTER TABLE customers ADD COLUMN phone VARCHAR(40) NULL");
}

if (!has_column($conn, 'customers', 'address')) {
    $conn->query("ALTER TABLE customers ADD COLUMN address TEXT NULL");
}

/* Link older website orders to customer accounts by email where possible. */
if (
    has_column($conn, 'customers', 'email') &&
    has_column($conn, 'orders', 'customer_email') &&
    has_column($conn, 'orders', 'customer_id')
) {
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

$profileMsg = "";
$profileErr = "";
$returnMsg = $_SESSION['return_flash_msg'] ?? "";
unset($_SESSION['return_flash_msg']);
$returnErr = "";

/* ===== Submit Return Request ===== */
if (isset($_POST['submit_return_request'])) {
    $returnOrderId = (int) ($_POST['return_order_id'] ?? 0);
    $returnReason = trim((string) ($_POST['return_reason'] ?? ''));
    $returnType = trim((string) ($_POST['return_type'] ?? ''));
    $returnDescription = trim((string) ($_POST['return_description'] ?? ''));

    $allowedReturnReasons = [
        'Damaged Product',
        'Wrong Product',
        'Hardware Issue',
        'Not as Described',
        'Other'
    ];

    $allowedReturnTypes = ['Refund', 'Replacement'];

    if ($returnOrderId <= 0) {
        $returnErr = "Invalid order.";
    } elseif (!in_array($returnReason, $allowedReturnReasons, true)) {
        $returnErr = "Please select a valid return reason.";
    } elseif (!in_array($returnType, $allowedReturnTypes, true)) {
        $returnErr = "Please select Refund or Replacement.";
    } else {
        $returnOrderStmt = $conn->prepare("
            SELECT
                id,
                customer_id,
                product_id,
                status,
                created_at,
                completed_at
            FROM orders
            WHERE id=? AND customer_id=?
            LIMIT 1
        ");

        if ($returnOrderStmt) {
            $returnOrderStmt->bind_param("ii", $returnOrderId, $customerId);
            $returnOrderStmt->execute();
            $returnOrder = $returnOrderStmt->get_result()->fetch_assoc();
            $returnOrderStmt->close();

            if (!$returnOrder) {
                $returnErr = "Order not found.";
            } else {
                $returnOrderStatus = strtolower(trim((string) ($returnOrder['status'] ?? '')));

                if (!in_array($returnOrderStatus, ['completed', 'delivered'], true)) {
                    $returnErr = "Return request is available only after the order is completed.";
                } else {
                    $returnBaseDate = trim((string) ($returnOrder['completed_at'] ?? ''));

                    if ($returnBaseDate === '') {
                        $returnBaseDate = trim((string) ($returnOrder['created_at'] ?? ''));
                    }

                    $returnBaseTimestamp = $returnBaseDate !== '' ? strtotime($returnBaseDate) : false;
                    $returnDeadlineTimestamp = $returnBaseTimestamp !== false
                        ? strtotime('+7 days', $returnBaseTimestamp)
                        : false;

                    if ($returnDeadlineTimestamp === false || time() > $returnDeadlineTimestamp) {
                        $returnErr = "The 7-day return period for this order has expired.";
                    } else {
                        $duplicateReturnStmt = $conn->prepare("
                            SELECT id
                            FROM return_requests
                            WHERE order_id=?
                            LIMIT 1
                        ");

                        $duplicateReturnStmt->bind_param("i", $returnOrderId);
                        $duplicateReturnStmt->execute();
                        $duplicateReturn = $duplicateReturnStmt->get_result()->fetch_assoc();
                        $duplicateReturnStmt->close();

                        if ($duplicateReturn) {
                            $returnErr = "A return request already exists for this order.";
                        } else {
                            $returnProductId = (int) ($returnOrder['product_id'] ?? 0);

                            [$returnEvidenceImage, $returnPhotoError] = save_return_photo(
                                'return_evidence_photo',
                                $customerId,
                                $returnOrderId
                            );

                            if ($returnPhotoError !== '') {
                                $returnErr = $returnPhotoError;
                            } else {
                                $insertReturnStmt = $conn->prepare("
                                    INSERT INTO return_requests
                                        (order_id, customer_id, product_id, reason, description, evidence_image, return_type, status)
                                    VALUES
                                        (?, ?, ?, ?, ?, ?, ?, 'Pending')
                                ");

                                if ($insertReturnStmt) {
                                    $insertReturnStmt->bind_param(
                                        "iiissss",
                                        $returnOrderId,
                                        $customerId,
                                        $returnProductId,
                                        $returnReason,
                                        $returnDescription,
                                        $returnEvidenceImage,
                                        $returnType
                                    );

                                    if ($insertReturnStmt->execute()) {
                                        $_SESSION['return_flash_msg'] =
                                            "Return request submitted successfully. You can track it below.";
                                        $insertReturnStmt->close();
                                        header("Location: my_account.php#my-returns");
                                        exit;
                                    }

                                    $insertReturnStmt->close();
                                }

                                if ($returnEvidenceImage !== '' && is_file(__DIR__ . '/' . $returnEvidenceImage)) {
                                    @unlink(__DIR__ . '/' . $returnEvidenceImage);
                                }

                                $returnErr = "Return request could not be submitted. Please try again.";
                            }
                        }
                    }
                }
            }
        } else {
            $returnErr = "Return request could not be prepared.";
        }
    }
}

/* ===== Update Profile ===== */
if (isset($_POST['update_profile'])) {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));

    if ($name === '') {
        $profileErr = "Name is required.";
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $profileErr = "Please enter a valid email address.";
    } else {
        try {
            if (has_column($conn, 'customers', 'email')) {
                $dup = $conn->prepare("SELECT id FROM customers WHERE email=? AND id<>? LIMIT 1");
                $dup->bind_param("si", $email, $customerId);
                $dup->execute();

                if ($email !== '' && $dup->get_result()->fetch_assoc()) {
                    throw new Exception("This email address is already used by another account.");
                }
                $dup->close();
            }

            $setParts = ["name=?"];
            $types = "s";
            $values = [$name];

            if (has_column($conn, 'customers', 'email')) {
                $setParts[] = "email=?";
                $types .= "s";
                $values[] = $email;
            }

            if (has_column($conn, 'customers', 'phone')) {
                $setParts[] = "phone=?";
                $types .= "s";
                $values[] = $phone;
            }

            if (has_column($conn, 'customers', 'address')) {
                $setParts[] = "address=?";
                $types .= "s";
                $values[] = $address;
            }

            $types .= "i";
            $values[] = $customerId;

            $sql = "UPDATE customers SET " . implode(", ", $setParts) . " WHERE id=?";
            $update = $conn->prepare($sql);
            $update->bind_param($types, ...$values);

            if (!$update->execute()) {
                throw new Exception("Profile update failed. Please try again.");
            }

            $update->close();
            $profileMsg = "Profile updated successfully.";
        } catch (Throwable $e) {
            $profileErr = $e->getMessage();
        }
    }
}

/* ===== Customer Profile ===== */
$profileStmt = $conn->prepare("SELECT * FROM customers WHERE id=? LIMIT 1");
$profileStmt->bind_param("i", $customerId);
$profileStmt->execute();
$customer = $profileStmt->get_result()->fetch_assoc();
$profileStmt->close();

if (!$customer) {
    session_destroy();
    header("Location: register.php");
    exit;
}

/* ===== My Orders ===== */
$orders = null;
$orderStmt = $conn->prepare("
    SELECT
        o.*,
        p.name AS product_name,
        p.price AS current_price,
        p.image_url AS product_image,
        p.stock_quantity,
        p.description AS product_description
    FROM orders o
    LEFT JOIN products p ON p.id = o.product_id
    WHERE o.customer_id=?
    ORDER BY o.id DESC
");
$orderStmt->bind_param("i", $customerId);
$orderStmt->execute();
$orders = $orderStmt->get_result();

/* ===== Order summary ===== */
$totalOrders = 0;
$pendingOrders = 0;
$completedOrders = 0;

$orderRows = [];
while ($order = $orders->fetch_assoc()) {
    $orderRows[] = $order;
    $totalOrders++;

    $status = strtolower(trim((string) ($order['status'] ?? 'Pending')));

    if (in_array($status, ['completed', 'delivered'], true)) {
        $completedOrders++;
    } elseif (!in_array($status, ['cancelled', 'canceled', 'failed', 'rejected'], true)) {
        $pendingOrders++;
    }
}
$orderStmt->close();

/* ===== My Returns ===== */
$returnRows = [];
$returnByOrderId = [];

$returnListStmt = $conn->prepare("
    SELECT
        r.*,
        p.name AS product_name,
        p.image_url AS product_image,
        o.created_at AS order_created_at,
        o.completed_at AS order_completed_at
    FROM return_requests r
    LEFT JOIN products p ON p.id = r.product_id
    LEFT JOIN orders o ON o.id = r.order_id
    WHERE r.customer_id=?
    ORDER BY r.requested_at DESC, r.id DESC
");

if ($returnListStmt) {
    $returnListStmt->bind_param("i", $customerId);
    $returnListStmt->execute();
    $returnListResult = $returnListStmt->get_result();

    while ($returnRow = $returnListResult->fetch_assoc()) {
        $returnRows[] = $returnRow;
        $returnByOrderId[(int) ($returnRow['order_id'] ?? 0)] = $returnRow;
    }

    $returnListStmt->close();
}

$customerName = trim((string) ($customer['name'] ?? 'Customer'));
$customerEmail = trim((string) ($customer['email'] ?? ''));
$customerPhone = trim((string) ($customer['phone'] ?? ''));
$customerAddress = trim((string) ($customer['address'] ?? ''));
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Account</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap"
        rel="stylesheet">

    <style>
        :root {
            --ink: #0B1220;
            --ink-soft: #5A667C;
            --paper: #EEF1F8;
            --card: #ffffff;
            --line: #E1E6EF;
            --blue: #2453FF;
            --blue-dark: #0B1E63;
            --blue-soft: #EAF0FF;
            --green: #16A34A;
            --amber: #D97706;
            --red: #DC2626;
            --shadow: 0 16px 40px rgba(11, 18, 32, .09);
            --radius: 18px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Inter', system-ui, sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 85% 5%, rgba(36, 83, 255, .10), transparent 24%),
                var(--paper);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input,
        textarea {
            font: inherit;
        }

        .container {
            width: min(1120px, 92%);
            margin: 0 auto;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 20;
            background: rgba(255, 255, 255, .94);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--line);
        }

        .topbarInner {
            min-height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: 'Sora', sans-serif;
            font-weight: 800;
        }

        .brandLogo {
            width: 40px;
            height: 40px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            color: #fff;
            background: linear-gradient(135deg, var(--blue-dark), var(--blue));
            box-shadow: 0 9px 20px rgba(36, 83, 255, .25);
        }

        .topActions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn {
            min-height: 42px;
            padding: 10px 15px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border: 0;
            border-radius: 11px;
            cursor: pointer;
            font-weight: 800;
            font-size: 13px;
        }

        .btn.primary {
            background: var(--blue);
            color: #fff;
            box-shadow: 0 9px 20px rgba(36, 83, 255, .20);
        }

        .btn.outline {
            color: var(--ink);
            background: #fff;
            border: 1px solid var(--line);
        }

        .btn.disabled {
            opacity: .52;
            pointer-events: none;
        }

        .accountHero {
            padding: 46px 0 28px;
        }

        .accountHeroBox {
            position: relative;
            overflow: hidden;
            padding: 30px;
            border-radius: 24px;
            color: #fff;
            background:
                radial-gradient(circle at 88% 20%, rgba(255, 176, 32, .30), transparent 25%),
                linear-gradient(135deg, var(--blue-dark), var(--blue));
            box-shadow: 0 24px 60px rgba(11, 30, 99, .20);
        }

        .accountHeroBox::after {
            content: "";
            position: absolute;
            width: 210px;
            height: 210px;
            right: -70px;
            bottom: -110px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, .18);
        }

        .eyebrow {
            margin-bottom: 9px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .8px;
            opacity: .78;
        }

        .accountHero h1 {
            margin: 0;
            font-family: 'Sora', sans-serif;
            font-size: clamp(26px, 4vw, 40px);
            letter-spacing: -.7px;
        }

        .accountHero p {
            max-width: 620px;
            margin: 10px 0 0;
            line-height: 1.65;
            opacity: .82;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-top: 18px;
        }

        .stat {
            padding: 17px;
            border-radius: var(--radius);
            background: #fff;
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
        }

        .statLabel {
            color: var(--ink-soft);
            font-size: 12px;
            font-weight: 700;
        }

        .statValue {
            margin-top: 5px;
            font-family: 'Sora', sans-serif;
            font-size: 26px;
            font-weight: 800;
        }

        .accountGrid {
            display: grid;
            grid-template-columns: .82fr 1.48fr;
            gap: 20px;
            padding: 22px 0 56px;
            align-items: start;
        }

        .panel {
            border-radius: var(--radius);
            background: var(--card);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .panelHead {
            padding: 20px 20px 16px;
            border-bottom: 1px solid var(--line);
        }

        .panelTitle {
            margin: 0;
            font-family: 'Sora', sans-serif;
            font-size: 18px;
        }

        .panelSub {
            margin: 5px 0 0;
            color: var(--ink-soft);
            font-size: 12.5px;
            line-height: 1.55;
        }

        .panelBody {
            padding: 20px;
        }

        .field {
            margin-bottom: 15px;
        }

        .field:last-child {
            margin-bottom: 0;
        }

        .field label {
            display: block;
            margin-bottom: 6px;
            color: var(--ink-soft);
            font-size: 12px;
            font-weight: 800;
        }

        .field input,
        .field textarea {
            width: 100%;
            padding: 11px 12px;
            border: 1.5px solid var(--line);
            border-radius: 10px;
            outline: none;
            color: var(--ink);
            background: #fff;
        }

        .field textarea {
            min-height: 90px;
            resize: vertical;
        }

        .field input:focus,
        .field textarea:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(36, 83, 255, .09);
        }

        .message {
            padding: 11px 13px;
            margin-bottom: 15px;
            border-radius: 11px;
            font-size: 13px;
            font-weight: 700;
        }

        .message.success {
            background: #DCFCE7;
            color: #166534;
            border: 1px solid #86EFAC;
        }

        .message.error {
            background: #FEE2E2;
            color: #991B1B;
            border: 1px solid #FCA5A5;
        }

        .ordersList {
            display: grid;
            gap: 13px;
        }

        .orderCard {
            display: grid;
            grid-template-columns: 78px 1fr auto;
            gap: 15px;
            align-items: center;
            padding: 15px;
            border: 1px solid var(--line);
            border-radius: 15px;
            background: #fff;
        }

        .orderImage {
            width: 78px;
            height: 70px;
            overflow: hidden;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: var(--paper);
            border: 1px solid var(--line);
            font-size: 24px;
        }

        .orderImage img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .orderName {
            font-family: 'Sora', sans-serif;
            font-size: 13.5px;
            font-weight: 800;
            line-height: 1.4;
        }

        .orderMeta {
            display: flex;
            flex-wrap: wrap;
            gap: 7px 12px;
            margin-top: 7px;
            color: var(--ink-soft);
            font-size: 11.5px;
            font-weight: 600;
        }

        .statusBadge {
            display: inline-flex;
            margin-top: 8px;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 900;
        }

        .statusBadge.success {
            background: #DCFCE7;
            color: #166534;
        }

        .statusBadge.warning {
            background: #FEF3C7;
            color: #92400E;
        }

        .statusBadge.info {
            background: #DBEAFE;
            color: #1D4ED8;
        }

        .statusBadge.danger {
            background: #FEE2E2;
            color: #991B1B;
        }

        .orderActions {
            display: grid;
            gap: 8px;
            justify-items: end;
        }

        .empty {
            padding: 38px 20px;
            text-align: center;
            color: var(--ink-soft);
        }

        .emptyIcon {
            width: 64px;
            height: 64px;
            display: grid;
            place-items: center;
            margin: 0 auto 12px;
            border-radius: 20px;
            background: var(--blue-soft);
            font-size: 28px;
        }


        /* ===== My Order Tracking ===== */
        .accountFeatureSection {
            padding: 0 0 22px;
        }

        .accountFeaturePanel {
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--card);
            box-shadow: var(--shadow);
        }

        .accountFeatureHead {
            padding: 20px 20px 16px;
            border-bottom: 1px solid var(--line);
        }

        .accountFeatureTitle {
            margin: 0;
            font-family: 'Sora', sans-serif;
            font-size: 18px;
        }

        .accountFeatureSub {
            margin: 5px 0 0;
            color: var(--ink-soft);
            font-size: 12.5px;
            line-height: 1.55;
        }

        .orderTrackingList {
            display: grid;
            gap: 13px;
            padding: 20px;
        }

        .orderTrackingCard {
            padding: 16px;
            border: 1px solid var(--line);
            border-radius: 15px;
            background: #fff;
        }

        .orderTrackingHead {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 15px;
        }

        .orderTrackingProduct {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .orderTrackingProduct img,
        .trackingImageFallback {
            width: 64px;
            height: 56px;
            flex: 0 0 64px;
            display: grid;
            place-items: center;
            object-fit: cover;
            border: 1px solid var(--line);
            border-radius: 11px;
            background: var(--paper);
            font-size: 23px;
        }

        .orderTrackingProduct strong {
            display: block;
            font-family: 'Sora', sans-serif;
            font-size: 13.5px;
        }

        .orderTrackingMeta {
            margin-top: 4px;
            color: var(--ink-soft);
            font-size: 11.5px;
            font-weight: 600;
        }

        .trackingCurrentBadge {
            flex: 0 0 auto;
            padding: 6px 10px;
            border-radius: 999px;
            background: var(--blue-soft);
            color: var(--blue);
            font-size: 11px;
            font-weight: 900;
        }

        .trackingCurrentBadge.cancelled {
            background: #FEE2E2;
            color: #991B1B;
        }

        .trackingSteps {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 0;
            margin-top: 6px;
        }

        .trackingStep {
            position: relative;
            text-align: center;
            color: #94A3B8;
            font-size: 10.5px;
            font-weight: 800;
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
            color: var(--blue);
        }

        .trackingStep.done::before,
        .trackingStep.active::before {
            background: var(--blue);
        }

        .trackingStep.done .trackingDot,
        .trackingStep.active .trackingDot {
            border-color: var(--blue);
            background: var(--blue);
            color: #fff;
        }

        .trackingCancelled {
            padding: 11px 13px;
            border: 1px solid #FCA5A5;
            border-radius: 11px;
            background: #FEE2E2;
            color: #991B1B;
            font-size: 12px;
            font-weight: 800;
        }

        /* ===== Warranty Registration & Tracker ===== */
        .warrantyList {
            display: grid;
            gap: 13px;
            padding: 20px;
        }

        .warrantyCard {
            display: grid;
            grid-template-columns: 74px minmax(0, 1fr);
            gap: 15px;
            padding: 16px;
            border: 1px solid var(--line);
            border-radius: 15px;
            background: #fff;
        }

        .warrantyImage {
            width: 74px;
            height: 66px;
            display: grid;
            place-items: center;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--paper);
            font-size: 25px;
        }

        .warrantyImage img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .warrantyTop {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .warrantyName {
            font-family: 'Sora', sans-serif;
            font-size: 13.5px;
            font-weight: 800;
            line-height: 1.4;
        }

        .warrantyBadge {
            flex: 0 0 auto;
            padding: 6px 9px;
            border-radius: 999px;
            background: #DCFCE7;
            color: #166534;
            font-size: 10.5px;
            font-weight: 900;
        }

        .warrantyBadge.expired {
            background: #FEE2E2;
            color: #991B1B;
        }

        .warrantyMeta {
            display: flex;
            flex-wrap: wrap;
            gap: 7px 13px;
            margin-top: 7px;
            color: var(--ink-soft);
            font-size: 11.5px;
            font-weight: 600;
        }

        .warrantyProgress {
            height: 9px;
            overflow: hidden;
            margin-top: 13px;
            border-radius: 999px;
            background: #E9EDF5;
        }

        .warrantyProgressBar {
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--blue), #16A34A);
        }

        .warrantyProgressBar.expired {
            background: var(--red);
        }

        .warrantyRemaining {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 8px;
            font-size: 11.5px;
            font-weight: 800;
        }

        .warrantyRemaining span:last-child {
            color: var(--ink-soft);
            font-weight: 600;
        }

        .featureEmpty {
            padding: 36px 20px;
            text-align: center;
            color: var(--ink-soft);
        }


        /* ===== Return Requests ===== */
        .returnList {
            display: grid;
            gap: 13px;
            padding: 20px;
        }

        .returnCard {
            padding: 16px;
            border: 1px solid var(--line);
            border-radius: 15px;
            background: #fff;
        }

        .returnTop {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
        }

        .returnProduct {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .returnProductImage {
            width: 66px;
            height: 58px;
            flex: 0 0 66px;
            display: grid;
            place-items: center;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 11px;
            background: var(--paper);
            font-size: 23px;
        }

        .returnProductImage img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .returnProductName {
            font-family: 'Sora', sans-serif;
            font-size: 13.5px;
            font-weight: 800;
        }

        .returnMeta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 12px;
            margin-top: 6px;
            color: var(--ink-soft);
            font-size: 11.5px;
            font-weight: 600;
        }

        .returnStatusBadge {
            flex: 0 0 auto;
            padding: 6px 9px;
            border-radius: 999px;
            background: var(--blue-soft);
            color: var(--blue);
            font-size: 10.5px;
            font-weight: 900;
        }

        .returnStatusBadge.approved,
        .returnStatusBadge.completed,
        .returnStatusBadge.refunded,
        .returnStatusBadge.replaced {
            background: #DCFCE7;
            color: #166534;
        }

        .returnStatusBadge.rejected {
            background: #FEE2E2;
            color: #991B1B;
        }

        .returnStatusBadge.product-received {
            background: #DBEAFE;
            color: #1D4ED8;
        }

        .returnDetails {
            margin-top: 12px;
            padding: 12px;
            border-radius: 11px;
            background: #F8FAFC;
            color: var(--ink-soft);
            font-size: 11.5px;
            line-height: 1.6;
        }

        .returnDetails strong {
            color: var(--ink);
        }

        .returnSteps {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            margin-top: 14px;
        }

        .returnStep {
            position: relative;
            text-align: center;
            color: #94A3B8;
            font-size: 10.5px;
            font-weight: 800;
        }

        .returnStep::before {
            content: "";
            position: absolute;
            top: 9px;
            left: 0;
            width: 100%;
            height: 3px;
            background: #E2E8F0;
        }

        .returnStep:first-child::before {
            left: 50%;
            width: 50%;
        }

        .returnStep:last-child::before {
            width: 50%;
        }

        .returnStepDot {
            position: relative;
            z-index: 1;
            width: 20px;
            height: 20px;
            display: grid;
            place-items: center;
            margin: 0 auto 7px;
            border: 3px solid #E2E8F0;
            border-radius: 50%;
            background: #fff;
            font-size: 8px;
        }

        .returnStep.done,
        .returnStep.active {
            color: var(--blue);
        }

        .returnStep.done::before,
        .returnStep.active::before {
            background: var(--blue);
        }

        .returnStep.done .returnStepDot,
        .returnStep.active .returnStepDot {
            border-color: var(--blue);
            background: var(--blue);
            color: #fff;
        }

        .returnRejectedNotice {
            margin-top: 13px;
            padding: 11px 13px;
            border-radius: 11px;
            background: #FEE2E2;
            color: #991B1B;
            font-size: 12px;
            font-weight: 800;
        }

        .returnActionStatus {
            display: inline-flex;
            padding: 8px 10px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
            color: var(--ink-soft);
            font-size: 11px;
            font-weight: 800;
        }

        /* Return request modal */
        .returnModalBackdrop {
            position: fixed;
            inset: 0;
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(11, 18, 32, .58);
            backdrop-filter: blur(5px);
        }

        .returnModalBackdrop.open {
            display: flex;
        }

        .returnModal {
            width: min(520px, 100%);
            max-height: 92vh;
            overflow-y: auto;
            padding: 22px;
            border-radius: 20px;
            background: #fff;
            box-shadow: 0 28px 80px rgba(11, 18, 32, .28);
        }

        .returnModalHead {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 16px;
        }

        .returnModalHead h3 {
            margin: 0;
            font-family: 'Sora', sans-serif;
            font-size: 19px;
        }

        .returnModalHead p {
            margin: 5px 0 0;
            color: var(--ink-soft);
            font-size: 12px;
            line-height: 1.5;
        }

        .returnModalClose {
            width: 36px;
            height: 36px;
            display: grid;
            place-items: center;
            flex: 0 0 36px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
            cursor: pointer;
            font-weight: 900;
        }

        .returnField {
            margin-bottom: 14px;
        }

        .returnField label {
            display: block;
            margin-bottom: 6px;
            color: var(--ink-soft);
            font-size: 12px;
            font-weight: 800;
        }

        .returnField select,
        .returnField textarea,
        .returnField input[type="file"] {
            width: 100%;
            padding: 11px 12px;
            border: 1.5px solid var(--line);
            border-radius: 10px;
            outline: none;
            background: #fff;
            color: var(--ink);
        }

        .returnPhotoHelp {
            margin-top: 6px;
            color: var(--ink-soft);
            font-size: 10.5px;
            font-weight: 600;
            line-height: 1.45;
        }

        .returnField textarea {
            min-height: 96px;
            resize: vertical;
        }

        .returnModalActions {
            display: flex;
            justify-content: flex-end;
            gap: 9px;
            margin-top: 16px;
        }

        body.return-modal-open {
            overflow: hidden;
        }

        @media (max-width: 620px) {
            .returnTop {
                flex-direction: column;
            }

            .returnSteps {
                overflow-x: auto;
                grid-template-columns: repeat(4, minmax(90px, 1fr));
                padding-bottom: 4px;
            }

            .returnModalActions {
                flex-direction: column-reverse;
            }

            .returnModalActions .btn {
                width: 100%;
            }
        }

        @media (max-width: 620px) {

            .orderTrackingHead,
            .warrantyTop,
            .warrantyRemaining {
                align-items: flex-start;
                flex-direction: column;
            }

            .trackingSteps {
                overflow-x: auto;
                grid-template-columns: repeat(5, minmax(88px, 1fr));
                padding-bottom: 4px;
            }

            .warrantyCard {
                grid-template-columns: 58px minmax(0, 1fr);
                gap: 11px;
            }

            .warrantyImage {
                width: 58px;
                height: 55px;
            }
        }

        @media (max-width: 850px) {
            .accountGrid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 620px) {
            .topbarInner {
                min-height: auto;
                padding: 11px 0;
                align-items: flex-start;
            }

            .topActions {
                gap: 6px;
            }

            .topActions .btn {
                padding: 9px 10px;
                font-size: 11.5px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .accountHeroBox {
                padding: 24px 20px;
            }

            .orderCard {
                grid-template-columns: 64px 1fr;
            }

            .orderImage {
                width: 64px;
                height: 62px;
            }

            .orderActions {
                grid-column: 1 / -1;
                display: flex;
                justify-content: flex-end;
            }
        }
    </style>
</head>

<body>
    <header class="topbar">
        <div class="container topbarInner">
            <a class="brand" href="index.php">
                <span class="brandLogo">💻</span>
                <span>My Account</span>
            </a>

            <div class="topActions">
                <a class="btn outline" href="index.php">← Home</a>
                <a class="btn outline" href="logout.php">Logout</a>
            </div>
        </div>
    </header>

    <main>
        <section class="accountHero">
            <div class="container">
                <div class="accountHeroBox">
                    <div class="eyebrow">Customer Account</div>
                    <h1>Hi, <?= e($customerName !== '' ? $customerName : 'Customer') ?> 👋</h1>
                    <p>Manage your profile, check your order history and reorder available laptops from one place.</p>
                </div>

                <div class="stats">
                    <div class="stat">
                        <div class="statLabel">Total Orders</div>
                        <div class="statValue"><?= $totalOrders ?></div>
                    </div>

                    <div class="stat">
                        <div class="statLabel">Active / Pending</div>
                        <div class="statValue"><?= $pendingOrders ?></div>
                    </div>

                    <div class="stat">
                        <div class="statLabel">Completed</div>
                        <div class="statValue"><?= $completedOrders ?></div>
                    </div>
                </div>
            </div>
        </section>


        <!-- ===== My Order Tracking ===== -->
        <section class="accountFeatureSection">
            <div class="container">
                <div class="accountFeaturePanel">
                    <div class="accountFeatureHead">
                        <h2 class="accountFeatureTitle">My Order Tracking</h2>
                        <p class="accountFeatureSub">Track your latest order status from placement until completion.</p>
                    </div>

                    <div class="orderTrackingList">
                        <?php if (count($orderRows) > 0): ?>
                                    <?php
                                    $trackingStepLabels = ['Order Placed', 'Processing', 'Packed', 'Shipped', 'Completed'];
                                    $trackingStatusMap = [
                                        'Pending' => 0,
                                        'Paid' => 1,
                                        'Processing' => 1,
                                        'Confirmed' => 1,
                                        'Packed' => 2,
                                        'Shipped' => 3,
                                        'Completed' => 4,
                                        'Delivered' => 4
                                    ];
                                    ?>

                                    <?php foreach (array_slice($orderRows, 0, 5) as $track): ?>
                                                <?php
                                                $trackStatus = trim((string) ($track['status'] ?? 'Pending'));
                                                $trackStatusLower = strtolower($trackStatus);
                                                $trackCancelled = in_array(
                                                    $trackStatusLower,
                                                    ['cancelled', 'canceled', 'failed', 'rejected'],
                                                    true
                                                );
                                                $trackStepIndex = $trackingStatusMap[$trackStatus] ?? 0;
                                                $trackProductName = trim((string) ($track['product_name'] ?? 'Product'));
                                                $trackCreatedAt = trim((string) ($track['created_at'] ?? ''));
                                                ?>

                                                <article class="orderTrackingCard">
                                                    <div class="orderTrackingHead">
                                                        <div class="orderTrackingProduct">
                                                            <?php if (!empty($track['product_image'])): ?>
                                                                        <img src="<?= e($track['product_image']) ?>" alt="<?= e($trackProductName) ?>">
                                                            <?php else: ?>
                                                                        <div class="trackingImageFallback">💻</div>
                                                            <?php endif; ?>

                                                            <div>
                                                                <strong><?= e($trackProductName) ?></strong>
                                                                <div class="orderTrackingMeta">
                                                                    Order #<?= (int) ($track['id'] ?? 0) ?>
                                                                    · Qty <?= (int) ($track['qty'] ?? 1) ?>
                                                                    <?php if ($trackCreatedAt !== ''): ?>
                                                                                · <?= e($trackCreatedAt) ?>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <span class="trackingCurrentBadge <?= $trackCancelled ? 'cancelled' : '' ?>">
                                                            <?= e($trackStatus !== '' ? $trackStatus : 'Pending') ?>
                                                        </span>
                                                    </div>

                                                    <?php if ($trackCancelled): ?>
                                                                <div class="trackingCancelled">
                                                                    This order has been cancelled.
                                                                </div>
                                                    <?php else: ?>
                                                                <div class="trackingSteps">
                                                                    <?php foreach ($trackingStepLabels as $stepIndex => $stepLabel): ?>
                                                                                <?php
                                                                                $stepClass = '';
                                                                                if ($stepIndex < $trackStepIndex) {
                                                                                    $stepClass = 'done';
                                                                                } elseif ($stepIndex === $trackStepIndex) {
                                                                                    $stepClass = 'active';
                                                                                }
                                                                                ?>
                                                                                <div class="trackingStep <?= e($stepClass) ?>">
                                                                                    <div class="trackingDot">
                                                                                        <?= $stepIndex <= $trackStepIndex ? '✓' : '' ?>
                                                                                    </div>
                                                                                    <span><?= e($stepLabel) ?></span>
                                                                                </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                    <?php endif; ?>
                                                </article>
                                    <?php endforeach; ?>
                        <?php else: ?>
                                    <div class="featureEmpty">
                                        <strong>No orders available for tracking yet.</strong>
                                    </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- ===== Warranty Registration & Tracker ===== -->
        <section class="accountFeatureSection">
            <div class="container">
                <div class="accountFeaturePanel">
                    <div class="accountFeatureHead">
                        <h2 class="accountFeatureTitle">Warranty Registration & Tracker</h2>
                        <p class="accountFeatureSub">
                            Your laptop warranty is automatically tracked from the purchase date.
                        </p>
                    </div>

                    <div class="warrantyList">
                        <?php
                        $warrantyRows = array_values(array_filter(
                            $orderRows,
                            static function (array $order): bool {
                                $status = strtolower(trim((string) ($order['status'] ?? '')));
                                return !in_array(
                                    $status,
                                    ['cancelled', 'canceled', 'failed', 'rejected'],
                                    true
                                );
                            }
                        ));
                        ?>

                        <?php if (count($warrantyRows) > 0): ?>
                                    <?php foreach ($warrantyRows as $warrantyOrder): ?>
                                                <?php
                                                $warrantyProductName = trim(
                                                    (string) ($warrantyOrder['product_name'] ?? 'Product')
                                                );

                                                $purchaseDateRaw = trim(
                                                    (string) ($warrantyOrder['created_at'] ?? '')
                                                );

                                                $purchaseTimestamp = $purchaseDateRaw !== ''
                                                    ? strtotime($purchaseDateRaw)
                                                    : false;

                                                if ($purchaseTimestamp === false) {
                                                    $purchaseTimestamp = time();
                                                }

                                                $warrantyMonths = product_warranty_months(
                                                    (string) ($warrantyOrder['product_description'] ?? '')
                                                );

                                                $expiryTimestamp = strtotime(
                                                    '+' . $warrantyMonths . ' months',
                                                    $purchaseTimestamp
                                                );

                                                if ($expiryTimestamp === false) {
                                                    $expiryTimestamp = $purchaseTimestamp;
                                                }

                                                $nowTimestamp = time();

                                                $totalWarrantySeconds = max(
                                                    1,
                                                    $expiryTimestamp - $purchaseTimestamp
                                                );

                                                $remainingWarrantySeconds = max(
                                                    0,
                                                    $expiryTimestamp - $nowTimestamp
                                                );

                                                $remainingWarrantyDays = max(
                                                    0,
                                                    (int) ceil($remainingWarrantySeconds / 86400)
                                                );

                                                $remainingWarrantyPercent = max(
                                                    0,
                                                    min(
                                                        100,
                                                        ($remainingWarrantySeconds / $totalWarrantySeconds) * 100
                                                    )
                                                );

                                                $warrantyExpired = $nowTimestamp >= $expiryTimestamp;
                                                ?>

                                                <article class="warrantyCard">
                                                    <div class="warrantyImage">
                                                        <?php if (!empty($warrantyOrder['product_image'])): ?>
                                                                    <img src="<?= e($warrantyOrder['product_image']) ?>"
                                                                        alt="<?= e($warrantyProductName) ?>">
                                                        <?php else: ?>
                                                                    💻
                                                        <?php endif; ?>
                                                    </div>

                                                    <div>
                                                        <div class="warrantyTop">
                                                            <div>
                                                                <div class="warrantyName">
                                                                    <?= e($warrantyProductName) ?>
                                                                </div>

                                                                <div class="warrantyMeta">
                                                                    <span>
                                                                        Order #<?= (int) ($warrantyOrder['id'] ?? 0) ?>
                                                                    </span>
                                                                    <span>
                                                                        Purchase:
                                                                        <?= e(date('d M Y', $purchaseTimestamp)) ?>
                                                                    </span>
                                                                    <span>
                                                                        Warranty:
                                                                        <?= e(warranty_duration_label($warrantyMonths)) ?>
                                                                    </span>
                                                                    <span>
                                                                        Expires:
                                                                        <?= e(date('d M Y', $expiryTimestamp)) ?>
                                                                    </span>
                                                                </div>
                                                            </div>

                                                            <span class="warrantyBadge <?= $warrantyExpired ? 'expired' : '' ?>">
                                                                <?= $warrantyExpired ? 'Expired' : 'Auto Registered' ?>
                                                            </span>
                                                        </div>

                                                        <div class="warrantyProgress">
                                                            <div class="warrantyProgressBar <?= $warrantyExpired ? 'expired' : '' ?>" style="width:<?= number_format(
                                                                      $remainingWarrantyPercent,
                                                                      2,
                                                                      '.',
                                                                      ''
                                                                  ) ?>%">
                                                            </div>
                                                        </div>

                                                        <div class="warrantyRemaining">
                                                            <strong>
                                                                <?php if ($warrantyExpired): ?>
                                                                            Warranty expired
                                                                <?php else: ?>
                                                                            <?= number_format($remainingWarrantyDays) ?>
                                                                            day<?= $remainingWarrantyDays === 1 ? '' : 's' ?> remaining
                                                                <?php endif; ?>
                                                            </strong>

                                                            <span>
                                                                <?= number_format($remainingWarrantyPercent, 1) ?>% remaining
                                                            </span>
                                                        </div>
                                                    </div>
                                                </article>
                                    <?php endforeach; ?>
                        <?php else: ?>
                                    <div class="featureEmpty">
                                        <strong>No active warranty registrations yet.</strong>
                                    </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- ===== My Returns ===== -->
        <section class="accountFeatureSection" id="my-returns">
            <div class="container">
                <div class="accountFeaturePanel">
                    <div class="accountFeatureHead">
                        <h2 class="accountFeatureTitle">My Returns</h2>
                        <p class="accountFeatureSub">
                            Track Refund or Replacement requests submitted for completed orders.
                        </p>
                    </div>

                    <?php if ($returnMsg !== ''): ?>
                                <div style="padding:16px 20px 0">
                                    <div class="message success"><?= e($returnMsg) ?></div>
                                </div>
                    <?php endif; ?>

                    <?php if ($returnErr !== ''): ?>
                                <div style="padding:16px 20px 0">
                                    <div class="message error"><?= e($returnErr) ?></div>
                                </div>
                    <?php endif; ?>

                    <div class="returnList">
                        <?php if (count($returnRows) > 0): ?>
                                    <?php foreach ($returnRows as $returnItem): ?>
                                                <?php
                                                $returnStatus = trim((string) ($returnItem['status'] ?? 'Pending'));
                                                $returnStatusLower = strtolower($returnStatus);
                                                $returnStatusClass = strtolower(str_replace(' ', '-', $returnStatus));

                                                $returnStepIndex = 0;
                                                if ($returnStatusLower === 'approved') {
                                                    $returnStepIndex = 1;
                                                } elseif ($returnStatusLower === 'product received') {
                                                    $returnStepIndex = 2;
                                                } elseif (in_array($returnStatusLower, ['refunded', 'replaced', 'completed'], true)) {
                                                    $returnStepIndex = 3;
                                                }

                                                $returnProductName = trim((string) ($returnItem['product_name'] ?? 'Laptop'));
                                                $returnStepLabels = ['Requested', 'Approved', 'Product Received', 'Resolved'];
                                                ?>
                                                <article class="returnCard">
                                                    <div class="returnTop">
                                                        <div class="returnProduct">
                                                            <div class="returnProductImage">
                                                                <?php if (!empty($returnItem['product_image'])): ?>
                                                                            <img src="<?= e($returnItem['product_image']) ?>"
                                                                                alt="<?= e($returnProductName) ?>">
                                                                <?php else: ?>
                                                                            💻
                                                                <?php endif; ?>
                                                            </div>

                                                            <div>
                                                                <div class="returnProductName"><?= e($returnProductName) ?></div>
                                                                <div class="returnMeta">
                                                                    <span>
                                                                        RET-<?= str_pad((string) ((int) ($returnItem['id'] ?? 0)), 4, '0', STR_PAD_LEFT) ?>
                                                                    </span>
                                                                    <span>Order #<?= (int) ($returnItem['order_id'] ?? 0) ?></span>
                                                                    <span><?= e($returnItem['return_type'] ?? '') ?></span>
                                                                    <span><?= e($returnItem['requested_at'] ?? '') ?></span>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <span class="returnStatusBadge <?= e($returnStatusClass) ?>">
                                                            <?= e($returnStatus) ?>
                                                        </span>
                                                    </div>

                                                    <div class="returnDetails">
                                                        <div><strong>Reason:</strong> <?= e($returnItem['reason'] ?? '') ?></div>

                                                        <?php if (trim((string) ($returnItem['description'] ?? '')) !== ''): ?>
                                                                    <div>
                                                                        <strong>Description:</strong>
                                                                        <?= e($returnItem['description']) ?>
                                                                    </div>
                                                        <?php endif; ?>

                                                        <?php if (trim((string) ($returnItem['admin_note'] ?? '')) !== ''): ?>
                                                                    <div>
                                                                        <strong>Admin Note:</strong>
                                                                        <?= e($returnItem['admin_note']) ?>
                                                                    </div>
                                                        <?php endif; ?>
                                                    </div>

                                                    <?php if ($returnStatusLower === 'rejected'): ?>
                                                                <div class="returnRejectedNotice">
                                                                    This return request was rejected.
                                                                </div>
                                                    <?php else: ?>
                                                                <div class="returnSteps">
                                                                    <?php foreach ($returnStepLabels as $returnStepNumber => $returnStepLabel): ?>
                                                                                <?php
                                                                                $returnStepClass = '';
                                                                                if ($returnStepNumber < $returnStepIndex) {
                                                                                    $returnStepClass = 'done';
                                                                                } elseif ($returnStepNumber === $returnStepIndex) {
                                                                                    $returnStepClass = 'active';
                                                                                }
                                                                                ?>
                                                                                <div class="returnStep <?= e($returnStepClass) ?>">
                                                                                    <div class="returnStepDot">
                                                                                        <?= $returnStepNumber <= $returnStepIndex ? '✓' : '' ?>
                                                                                    </div>
                                                                                    <span><?= e($returnStepLabel) ?></span>
                                                                                </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                    <?php endif; ?>
                                                </article>
                                    <?php endforeach; ?>
                        <?php else: ?>
                                    <div class="featureEmpty">
                                        <div class="emptyIcon">↩️</div>
                                        <strong>No return requests yet</strong>
                                        <p>Eligible completed orders can be returned within 7 days.</p>
                                    </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>


        <section class="container accountGrid">
            <div class="panel">
                <div class="panelHead">
                    <h2 class="panelTitle">Profile</h2>
                    <p class="panelSub">Update your customer details.</p>
                </div>

                <div class="panelBody">
                    <?php if ($profileMsg !== ''): ?>
                                <div class="message success"><?= e($profileMsg) ?></div>
                    <?php endif; ?>

                    <?php if ($profileErr !== ''): ?>
                                <div class="message error"><?= e($profileErr) ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <div class="field">
                            <label>Name</label>
                            <input type="text" name="name" value="<?= e($customerName) ?>" required>
                        </div>

                        <?php if (array_key_exists('email', $customer)): ?>
                                    <div class="field">
                                        <label>Email</label>
                                        <input type="email" name="email" value="<?= e($customerEmail) ?>">
                                    </div>
                        <?php endif; ?>

                        <div class="field">
                            <label>Phone</label>
                            <input type="text" name="phone" value="<?= e($customerPhone) ?>"
                                placeholder="Enter phone number">
                        </div>

                        <div class="field">
                            <label>Address</label>
                            <textarea name="address"
                                placeholder="Enter your address"><?= e($customerAddress) ?></textarea>
                        </div>

                        <button class="btn primary" type="submit" name="update_profile">
                            Save Profile
                        </button>
                    </form>
                </div>
            </div>

            <div class="panel">
                <div class="panelHead">
                    <h2 class="panelTitle">My Orders</h2>
                    <p class="panelSub">Only orders connected to your logged-in customer account are shown here.</p>
                </div>

                <div class="panelBody">
                    <?php if (count($orderRows) > 0): ?>
                                <div class="ordersList">
                                    <?php foreach ($orderRows as $order): ?>
                                                <?php
                                                $status = trim((string) ($order['status'] ?? 'Pending'));
                                                $statusLower = strtolower($status);
                                                $productName = trim((string) ($order['product_name'] ?? 'Product'));
                                                $productId = (int) ($order['product_id'] ?? 0);
                                                $orderId = (int) ($order['id'] ?? 0);
                                                $stock = (int) ($order['stock_quantity'] ?? 0);
                                                $createdAt = trim((string) ($order['created_at'] ?? ''));

                                                $existingReturn = $returnByOrderId[$orderId] ?? null;
                                                $returnEligible = false;
                                                $returnDaysLeft = 0;

                                                if (
                                                    !$existingReturn &&
                                                    in_array($statusLower, ['completed', 'delivered'], true)
                                                ) {
                                                    $returnDateRaw = trim((string) ($order['completed_at'] ?? ''));
                                                    if ($returnDateRaw === '') {
                                                        $returnDateRaw = $createdAt;
                                                    }

                                                    $returnDateTimestamp = $returnDateRaw !== ''
                                                        ? strtotime($returnDateRaw)
                                                        : false;

                                                    if ($returnDateTimestamp !== false) {
                                                        $returnDeadline = strtotime('+7 days', $returnDateTimestamp);

                                                        if ($returnDeadline !== false && time() <= $returnDeadline) {
                                                            $returnEligible = true;
                                                            $returnDaysLeft = max(
                                                                0,
                                                                (int) ceil(($returnDeadline - time()) / 86400)
                                                            );
                                                        }
                                                    }
                                                }
                                                ?>
                                                <article class="orderCard">
                                                    <div class="orderImage">
                                                        <?php if (!empty($order['product_image'])): ?>
                                                                    <img src="<?= e($order['product_image']) ?>" alt="<?= e($productName) ?>">
                                                        <?php else: ?>
                                                                    💻
                                                        <?php endif; ?>
                                                    </div>

                                                    <div>
                                                        <div class="orderName"><?= e($productName) ?></div>

                                                        <div class="orderMeta">
                                                            <span>Order #<?= (int) ($order['id'] ?? 0) ?></span>
                                                            <span>Qty: <?= (int) ($order['qty'] ?? 1) ?></span>

                                                            <?php if ($createdAt !== ''): ?>
                                                                        <span><?= e(date('d M Y', strtotime($createdAt))) ?></span>
                                                            <?php endif; ?>

                                                            <?php if (isset($order['payment_method']) && trim((string) $order['payment_method']) !== ''): ?>
                                                                        <span><?= e($order['payment_method']) ?></span>
                                                            <?php endif; ?>
                                                        </div>

                                                        <span class="statusBadge <?= e(status_class($status)) ?>">
                                                            <?= e($status !== '' ? $status : 'Pending') ?>
                                                        </span>
                                                    </div>

                                                    <div class="orderActions">
                                                        <?php if ($productId > 0 && $stock > 0): ?>
                                                                    <a class="btn primary" href="index.php?product_id=<?= $productId ?>#details">Reorder</a>
                                                        <?php elseif ($productId > 0): ?>
                                                                    <span class="btn outline disabled">Out of Stock</span>
                                                        <?php endif; ?>

                                                        <?php if ($existingReturn): ?>
                                                                    <a class="returnActionStatus" href="#my-returns">
                                                                        Return: <?= e($existingReturn['status'] ?? 'Pending') ?>
                                                                    </a>
                                                        <?php elseif ($returnEligible): ?>
                                                                    <button
                                                                        class="btn outline returnRequestBtn"
                                                                        type="button"
                                                                        data-order-id="<?= $orderId ?>"
                                                                        data-product-name="<?= e($productName) ?>">
                                                                        Request Return
                                                                    </button>
                                                                    <span class="returnActionStatus">
                                                                        <?= $returnDaysLeft ?> day<?= $returnDaysLeft === 1 ? '' : 's' ?> left
                                                                    </span>
                                                        <?php elseif (in_array($statusLower, ['completed', 'delivered'], true)): ?>
                                                                    <span class="btn outline disabled">Return period expired</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </article>
                                    <?php endforeach; ?>
                                </div>
                    <?php else: ?>
                                <div class="empty">
                                    <div class="emptyIcon">📦</div>
                                    <strong>No orders yet</strong>
                                    <p>Your future website orders will appear here.</p>
                                    <a class="btn primary" href="index.php#featured">Shop Laptops</a>
                                </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <!-- ===== Return Request Modal ===== -->
        <div class="returnModalBackdrop" id="returnModalBackdrop" aria-hidden="true">
            <div class="returnModal" role="dialog" aria-modal="true" aria-labelledby="returnModalTitle">
                <div class="returnModalHead">
                    <div>
                        <h3 id="returnModalTitle">Request Return</h3>
                        <p id="returnModalProduct">Select the return details for this order.</p>
                    </div>
                    <button class="returnModalClose" id="returnModalClose" type="button" aria-label="Close">✕</button>
                </div>

                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="return_order_id" id="returnOrderId" value="">

                    <div class="returnField">
                        <label>Return Type</label>
                        <select name="return_type" required>
                            <option value="">Select return type</option>
                            <option value="Refund">Refund</option>
                            <option value="Replacement">Replacement</option>
                        </select>
                    </div>

                    <div class="returnField">
                        <label>Reason</label>
                        <select name="return_reason" required>
                            <option value="">Select reason</option>
                            <option value="Damaged Product">Damaged Product</option>
                            <option value="Wrong Product">Wrong Product</option>
                            <option value="Hardware Issue">Hardware Issue</option>
                            <option value="Not as Described">Not as Described</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="returnField">
                        <label>Description</label>
                        <textarea name="return_description"
                            placeholder="Tell us more about the issue (optional)"></textarea>
                    </div>

                    <div class="returnField">
                        <label>Upload Photo</label>
                        <input type="file" name="return_evidence_photo"
                            accept="image/jpeg,image/png,image/webp">
                        <div class="returnPhotoHelp">
                            Optional proof photo · JPG, PNG or WEBP · Max 5 MB
                        </div>
                    </div>

                    <div class="returnModalActions">
                        <button class="btn outline" id="returnModalCancel" type="button">Cancel</button>
                        <button class="btn primary" name="submit_return_request" type="submit">
                            Submit Return Request
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            /* ===== Return Request Modal ===== */
            (function () {
                const backdrop = document.getElementById('returnModalBackdrop');
                const closeBtn = document.getElementById('returnModalClose');
                const cancelBtn = document.getElementById('returnModalCancel');
                const orderInput = document.getElementById('returnOrderId');
                const productText = document.getElementById('returnModalProduct');

                function openReturnModal(button) {
                    if (!backdrop || !orderInput) return;

                    const orderId = button.getAttribute('data-order-id') || '';
                    const productName = button.getAttribute('data-product-name') || 'Laptop';

                    orderInput.value = orderId;

                    if (productText) {
                        productText.textContent =
                            productName + ' · Order #' + orderId + ' · 7-day return window';
                    }

                    backdrop.classList.add('open');
                    backdrop.setAttribute('aria-hidden', 'false');
                    document.body.classList.add('return-modal-open');
                }

                function closeReturnModal() {
                    if (!backdrop) return;

                    backdrop.classList.remove('open');
                    backdrop.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('return-modal-open');
                }

                document.querySelectorAll('.returnRequestBtn').forEach(function (button) {
                    button.addEventListener('click', function () {
                        openReturnModal(button);
                    });
                });

                closeBtn?.addEventListener('click', closeReturnModal);
                cancelBtn?.addEventListener('click', closeReturnModal);

                backdrop?.addEventListener('click', function (event) {
                    if (event.target === backdrop) {
                        closeReturnModal();
                    }
                });

                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape' && backdrop?.classList.contains('open')) {
                        closeReturnModal();
                    }
                });
            })();
        </script>
    </main>
</body>

</html>