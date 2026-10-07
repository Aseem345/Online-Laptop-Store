<?php
require_once "db.php";

$order_id = (int) ($_GET['order_id'] ?? 0);

if ($order_id > 0) {

    $stmt = $conn->prepare("UPDATE orders SET status='Paid' WHERE id=?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();

}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Payment Success</title>

    <style>
        body {
            font-family: Arial;
            background: #f4f7fb;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .box {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        a {
            display: inline-block;
            margin-top: 15px;
            padding: 10px 20px;
            background: #0b5ed7;
            color: white;
            border-radius: 8px;
            text-decoration: none;
        }
    </style>

</head>

<body>

    <div class="box">

        <h2>✅ Payment Successful</h2>

        <p>Your order has been paid.</p>

        <a href="index.php">Back to Home</a>

    </div>

</body>

</html>