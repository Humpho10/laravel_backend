<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #16a34a; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
        .body { background-color: #f9f9f9; padding: 20px; border-radius: 0 0 8px 8px; }
        .credentials { background: white; border: 1px solid #ddd; padding: 15px; border-radius: 6px; margin: 15px 0; }
        .footer { text-align: center; color: #888; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>♻️ E-Waste Mart</h2>
            <p>Product Manager Account Created</p>
        </div>
        <div class="body">
            <p>Hello <strong>{{ $name }}</strong>,</p>
            <p>Your Product Manager account has been created on E-Waste Mart. You can now log in and start managing product listings in your assigned categories.</p>

            <div class="credentials">
                <p><strong>Your Login Credentials:</strong></p>
                <p>Email: <strong>{{ $email }}</strong></p>
                <p>Password: <strong>{{ $password }}</strong></p>
            </div>

            <p>Please log in and change your password as soon as possible.</p>
            <p>Login at: <a href="{{ $loginUrl }}">E-Waste Mart</a></p>
        </div>
        <div class="footer">
            <p>© 2026 E-Waste Mart. Promoting circular economy through e-waste management.</p>
        </div>
    </div>
</body>
</html>
