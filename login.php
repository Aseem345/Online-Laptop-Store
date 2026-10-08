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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <style>
        :root {
            --primary: #5b8cff;
            --primary-2: #7c5cff;
            --primary-dark: #3d63d9;
            --text: #0f172a;
            --muted: #667085;
            --border: #d8e1ed;
            --field: rgba(248, 250, 252, .92);
            --danger-bg: rgba(254, 242, 242, .95);
            --danger-border: #fecaca;
            --danger-text: #b91c1c;
            --success-bg: rgba(240, 253, 244, .95);
            --success-border: #bbf7d0;
            --success-text: #166534;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
            background: #050b18;
        }

        body {
            min-height: 100vh;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, "Segoe UI", sans-serif;
            color: #fff;
            overflow-x: hidden;
            background:
                linear-gradient(115deg, rgba(3, 10, 26, .78) 0%, rgba(8, 24, 54, .56) 42%, rgba(4, 12, 32, .72) 100%),
                url('https://images.unsplash.com/photo-1496181133206-80ce9b88a853?auto=format&fit=crop&w=2200&q=90') center center / cover no-repeat fixed;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            background:
                radial-gradient(circle at 18% 20%, rgba(65, 137, 255, .30), transparent 34%),
                radial-gradient(circle at 82% 72%, rgba(119, 86, 255, .22), transparent 34%),
                linear-gradient(180deg, rgba(0, 0, 0, .04), rgba(0, 0, 0, .26));
        }

        body::after {
            content: "";
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            opacity: .13;
            background-image:
                linear-gradient(rgba(255, 255, 255, .05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, .05) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: linear-gradient(to bottom, rgba(0, 0, 0, .7), transparent 75%);
        }

        .login-shell {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 34px clamp(28px, 5vw, 84px);
        }

        /* ===== Background content ===== */
        .visual-layer {
            position: absolute;
            inset: 0;
            z-index: 1;
            pointer-events: none;
        }

        .store-badge {
            position: absolute;
            top: 38px;
            left: clamp(28px, 5vw, 76px);
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border: 1px solid rgba(255, 255, 255, .18);
            border-radius: 999px;
            background: rgba(5, 15, 36, .34);
            color: #fff;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, .18);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .02em;
        }

        .store-badge span {
            width: 31px;
            height: 31px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(91, 140, 255, .95), rgba(124, 92, 255, .95));
            box-shadow: 0 8px 18px rgba(65, 105, 225, .28);
            font-size: 15px;
        }

        .visual-content {
            position: absolute;
            left: clamp(30px, 6vw, 92px);
            bottom: clamp(42px, 7vh, 92px);
            width: min(640px, 43vw);
            color: #fff;
        }

        .visual-kicker {
            margin-bottom: 15px;
            color: #cfe1ff;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .18em;
            text-transform: uppercase;
        }

        .visual-content h1 {
            max-width: 650px;
            font-size: clamp(42px, 5vw, 74px);
            line-height: 1.02;
            font-weight: 800;
            letter-spacing: -.05em;
            text-shadow: 0 10px 35px rgba(0, 0, 0, .30);
            text-wrap: balance;
        }

        .visual-content p {
            max-width: 520px;
            margin-top: 18px;
            color: rgba(255, 255, 255, .78);
            font-size: 14px;
            line-height: 1.8;
            font-weight: 500;
            text-shadow: 0 4px 18px rgba(0, 0, 0, .28);
        }

        .visual-points {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            margin-top: 25px;
        }

        .visual-point {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 11px;
            border: 1px solid rgba(255, 255, 255, .16);
            border-radius: 11px;
            background: rgba(7, 18, 42, .28);
            color: rgba(255, 255, 255, .9);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            font-size: 10.5px;
            font-weight: 700;
        }

        .visual-point i {
            width: 18px;
            height: 18px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: rgba(96, 165, 250, .22);
            color: #dbeafe;
            font-style: normal;
            font-size: 9px;
        }

        /* ===== Login card ===== */
        .login-box {
            position: relative;
            z-index: 3;
            width: min(100%, 470px);
            padding: 34px 32px 28px;
            border: 1px solid rgba(255, 255, 255, .58);
            border-radius: 24px;
            background:
                linear-gradient(145deg, rgba(255, 255, 255, .96), rgba(244, 248, 255, .92));
            box-shadow:
                0 30px 80px rgba(2, 8, 23, .38),
                inset 0 1px 0 rgba(255, 255, 255, .92);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
        }

        .logo {
            width: 54px;
            height: 54px;
            display: grid;
            place-items: center;
            margin: 0 0 18px;
            border-radius: 16px;
            color: #fff;
            background: linear-gradient(135deg, #4d8dff 0%, #5f74ff 48%, #7d5cff 100%);
            box-shadow: 0 14px 30px rgba(84, 111, 255, .30);
            font-size: 23px;
            font-weight: 800;
        }

        .login-box h2 {
            margin: 0 0 7px;
            color: #0f172a;
            text-align: left;
            font-size: 30px;
            line-height: 1.15;
            font-weight: 800;
            letter-spacing: -.04em;
        }

        .sub {
            margin-bottom: 26px;
            color: #667085;
            text-align: left;
            font-size: 13px;
            line-height: 1.6;
            font-weight: 500;
        }

        .msg {
            margin-bottom: 18px;
            padding: 11px 13px;
            border: 1px solid var(--danger-border);
            border-radius: 11px;
            color: var(--danger-text);
            background: var(--danger-bg);
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.5;
        }

        .msg.success {
            color: var(--success-text);
            background: var(--success-bg);
            border-color: var(--success-border);
        }

        .input-group {
            margin-bottom: 16px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            color: #1f2937;
            font-size: 11.5px;
            font-weight: 700;
        }

        input {
            width: 100%;
            min-height: 48px;
            padding: 12px 14px;
            color: #0f172a;
            background: var(--field);
            border: 1px solid var(--border);
            border-radius: 11px;
            outline: none;
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 500;
            transition: border-color .2s ease, box-shadow .2s ease, background .2s ease, transform .2s ease;
        }

        input::placeholder {
            color: #9aa8bb;
        }

        input:focus {
            background: #fff;
            border-color: #6f92ff;
            box-shadow: 0 0 0 4px rgba(91, 140, 255, .11);
            transform: translateY(-1px);
        }

        .btn {
            width: 100%;
            min-height: 50px;
            margin-top: 4px;
            border: 0;
            border-radius: 11px;
            color: #fff;
            background: linear-gradient(135deg, #4f8cff 0%, #5d72ff 52%, #7657f5 100%);
            box-shadow: 0 14px 26px rgba(85, 105, 255, .25);
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: .01em;
            transition: transform .2s ease, box-shadow .2s ease, filter .2s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 34px rgba(85, 105, 255, .34);
            filter: brightness(1.04);
        }

        .btn:active {
            transform: translateY(0);
        }

        .bottom {
            margin-top: 20px;
            color: #667085;
            text-align: center;
            font-size: 11.5px;
            font-weight: 500;
        }

        .bottom a {
            color: #526ff5;
            text-decoration: none;
            font-weight: 800;
        }

        .bottom a:hover {
            text-decoration: underline;
        }

        .home-link {
            display: block;
            margin-top: 10px;
            color: #667085;
            text-align: center;
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
        }

        .home-link:hover {
            color: #526ff5;
        }

        /* ===== Responsive: keep the same full-background + corner form style ===== */
        @media (max-width: 1100px) {
            .login-shell {
                padding: 30px 28px;
            }

            .visual-content {
                width: min(500px, 43vw);
                left: 36px;
                bottom: 50px;
            }

            .visual-content h1 {
                font-size: clamp(36px, 5vw, 54px);
            }

            .login-box {
                width: min(100%, 440px);
            }
        }

        @media (max-width: 860px) {
            body {
                background-position: 42% center;
                background-attachment: scroll;
            }

            .login-shell {
                min-height: 100svh;
                align-items: center;
                justify-content: center;
                padding: 138px 16px 24px;
            }

            .store-badge {
                top: 16px;
                left: 16px;
                padding: 8px 11px;
                font-size: 10.5px;
            }

            .store-badge span {
                width: 27px;
                height: 27px;
                font-size: 13px;
            }

            .visual-content {
                top: 66px;
                left: 18px;
                bottom: auto;
                width: calc(100% - 36px);
                max-width: 460px;
            }

            .visual-kicker {
                margin-bottom: 7px;
                font-size: 9.5px;
                line-height: 1.35;
                letter-spacing: .12em;
            }

            .visual-content h1 {
                max-width: 360px;
                font-size: clamp(24px, 7vw, 32px);
                line-height: 1.14;
                letter-spacing: -.025em;
                word-spacing: .02em;
                text-wrap: balance;
                text-shadow: 0 4px 18px rgba(0, 0, 0, .5);
            }

            .visual-content p,
            .visual-points {
                display: none;
            }

            .login-box {
                width: min(100%, 500px);
                max-width: 500px;
                margin: auto;
                padding: 28px 24px 22px;
                border-radius: 21px;
                background: linear-gradient(145deg, rgba(255, 255, 255, .97), rgba(245, 248, 255, .94));
                backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
            }
        }

        @media (max-width: 560px) {
            body {
                background-position: 44% center;
            }

            .login-shell {
                min-height: 100svh;
                align-items: center;
                justify-content: center;
                padding: 128px 12px 18px;
            }

            .store-badge {
                top: 13px;
                left: 12px;
            }

            .visual-content {
                top: 59px;
                left: 14px;
                width: calc(100% - 28px);
            }

            .visual-kicker {
                margin-bottom: 5px;
                font-size: 9px;
                line-height: 1.35;
                letter-spacing: .10em;
            }

            .visual-content h1 {
                max-width: 290px;
                font-size: clamp(21px, 7vw, 25px);
                line-height: 1.17;
                letter-spacing: -.018em;
                word-spacing: .03em;
                text-wrap: balance;
                text-shadow: 0 4px 18px rgba(0, 0, 0, .58);
            }

            .login-box {
                width: calc(100vw - 24px);
                max-width: 470px;
                margin: auto;
                padding: 24px 18px 20px;
                border-radius: 20px;
            }

            .logo {
                width: 46px;
                height: 46px;
                margin-bottom: 14px;
                border-radius: 13px;
                font-size: 20px;
            }

            .login-box h2 {
                font-size: 25px;
                line-height: 1.2;
            }

            .sub {
                margin-bottom: 21px;
                font-size: 11.5px;
                line-height: 1.55;
            }

            label {
                font-size: 11.5px;
                line-height: 1.4;
            }

            input {
                min-height: 45px;
                font-size: 12px;
                line-height: 1.4;
            }

            .btn {
                min-height: 47px;
            }

            .bottom,
            .home-link {
                line-height: 1.5;
            }
        }

        @media (max-width: 560px) and (max-height: 680px) {
            .login-shell {
                align-items: flex-start;
                justify-content: center;
                padding-top: 122px;
            }

            .login-box {
                margin-top: 0;
                margin-bottom: 12px;
            }
        }

        @media (max-height: 680px) and (min-width: 861px) {
            .login-shell {
                align-items: flex-start;
                padding-top: 22px;
                padding-bottom: 22px;
            }

            .visual-content {
                bottom: 34px;
            }
        }
    </style>
</head>

<body>

    <main class="login-shell">

        <section class="visual-layer">
            <div class="store-badge">
                <span>💻</span>
                Online Laptop Store
            </div>

            <div class="visual-content">
                <div class="visual-kicker">Welcome back</div>
                <h1>Your next laptop is just a login away.</h1>
                <p>
                    Sign in to continue shopping, manage your orders,
                    track purchases and access your saved items.
                </p>

                <div class="visual-points">
                    <div class="visual-point"><i>✓</i> Secure access</div>
                    <div class="visual-point"><i>✓</i> Order tracking</div>
                    <div class="visual-point"><i>✓</i> Saved favourites</div>
                </div>
            </div>
        </section>

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

    </main>

</body>

</html>