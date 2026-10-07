<?php
session_start();
require_once "db.php";

$msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    if ($email == "" || $password == "") {

        $msg = "Please fill all fields.";

    } else {

        $stmt = $conn->prepare("SELECT * FROM customers WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows > 0) {

            $customer = $result->fetch_assoc();

            if (password_verify($password, $customer['password'])) {

                $_SESSION['customer_id'] = $customer['id'];
                $_SESSION['customer_name'] = $customer['name'];
                $_SESSION['customer_email'] = $customer['email'];

                header("Location: index.php");
                exit;

            } else {

                $msg = "Wrong password.";
            }

        } else {

            $msg = "Email not registered.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Customer Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', sans-serif;
        }

        body {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: linear-gradient(135deg, #0f172a, #1e3a8a, #2563eb);
            padding: 20px;
        }

        .login-box {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 24px;
            padding: 35px 30px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.25);
        }

        .logo {
            width: 70px;
            height: 70px;
            margin: auto;
            border-radius: 20px;
            background: #2563eb;
            display: flex;
            justify-content: center;
            align-items: center;
            color: #fff;
            font-size: 30px;
            font-weight: bold;
            margin-bottom: 18px;
        }

        h2 {
            text-align: center;
            color: #0f172a;
            margin-bottom: 8px;
        }

        .sub {
            text-align: center;
            color: #64748b;
            font-size: 14px;
            margin-bottom: 25px;
        }

        .input-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            color: #334155;
            font-weight: 600;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 14px;
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            font-size: 15px;
            outline: none;
        }

        input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
        }

        .btn {
            width: 100%;
            border: none;
            background: #2563eb;
            color: #fff;
            padding: 14px;
            border-radius: 14px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
        }

        .btn:hover {
            background: #1d4ed8;
        }

        .msg {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 15px;
            text-align: center;
            font-size: 14px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .bottom {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: #475569;
        }

        .bottom a {
            color: #2563eb;
            text-decoration: none;
            font-weight: bold;
        }

        .home-link {
            display: block;
            text-align: center;
            margin-top: 12px;
            text-decoration: none;
            color: #64748b;
            font-size: 14px;
        }
    </style>
</head>

<body>

    <div class="login-box">

        <div class="logo">💻</div>

        <h2>Welcome Back</h2>

        <div class="sub">
            Login to continue shopping
        </div>

        <?php if (isset($_GET['registered'])) { ?>
            <div class="msg success">
                Account created successfully 😄
            </div>
        <?php } ?>

        <?php if ($msg != "") { ?>
            <div class="msg">
                    <?php echo $msg; ?>
            </div>
        <?php } ?>

        <form method="POST">

            <div class="input-group">
                <label>Email Address</label>

                <input type="email" name="email" placeholder="Enter your email" required>
            </div>

            <div class="input-group">
                <label>Password</label>

                <input type="password" name="password" placeholder="Enter password" required>
            </div>

            <button type="submit" class="btn">
                Login
            </button>

        </form>

        <div class="bottom">
            Don't have an account?
            <a href="register.php">Register</a>
        </div>

        <a href="index.php" class="home-link">
            ← Back to Home
        </a>

    </div>

</body>

</html>