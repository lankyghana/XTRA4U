<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password</title>
</head>
<body style="margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f3f4f6;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="padding: 40px 0;">
                <table role="presentation" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(to right, #0B66B5, #00942C); padding: 30px 40px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: bold;">XTRA4U</h1>
                            <p style="margin: 10px 0 0; color: rgba(255, 255, 255, 0.9); font-size: 14px;">Vendor Portal</p>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <h2 style="margin: 0 0 20px; color: #1f2937; font-size: 24px; font-weight: 600;">
                                Reset Your Password
                            </h2>
                            
                            <p style="margin: 0 0 20px; color: #4b5563; font-size: 16px; line-height: 1.6;">
                                Hello {{ $vendor->name }},
                            </p>
                            
                            <p style="margin: 0 0 20px; color: #4b5563; font-size: 16px; line-height: 1.6;">
                                We received a request to reset your password for your XTRA4U Vendor account. Click the button below to create a new password:
                            </p>
                            
                            <!-- Button -->
                            <table role="presentation" style="width: 100%; margin: 30px 0;">
                                <tr>
                                    <td style="text-align: center;">
                                        <a href="{{ $resetUrl }}" 
                                           style="display: inline-block; padding: 14px 32px; background: linear-gradient(to right, #0B66B5, #00942C); color: #ffffff; text-decoration: none; font-size: 16px; font-weight: 600; border-radius: 8px;">
                                            Reset Password
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="margin: 0 0 15px; color: #4b5563; font-size: 14px; line-height: 1.6;">
                                This password reset link will expire in <strong>60 minutes</strong>.
                            </p>
                            
                            <p style="margin: 0 0 20px; color: #4b5563; font-size: 14px; line-height: 1.6;">
                                If you didn't request a password reset, you can safely ignore this email. Your password will remain unchanged.
                            </p>
                            
                            <!-- Alternative Link -->
                            <div style="margin-top: 30px; padding: 20px; background-color: #f9fafb; border-radius: 8px;">
                                <p style="margin: 0 0 10px; color: #6b7280; font-size: 13px;">
                                    If the button above doesn't work, copy and paste this link into your browser:
                                </p>
                                <p style="margin: 0; word-break: break-all;">
                                    <a href="{{ $resetUrl }}" style="color: #0B66B5; font-size: 13px; text-decoration: none;">
                                        {{ $resetUrl }}
                                    </a>
                                </p>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px 40px; background-color: #f9fafb; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0 0 10px; color: #6b7280; font-size: 14px; text-align: center;">
                                Need help? Contact us at <a href="mailto:support@xtra4u.com" style="color: #0B66B5; text-decoration: none;">support@xtra4u.com</a>
                            </p>
                            <p style="margin: 0; color: #9ca3af; font-size: 12px; text-align: center;">
                                © {{ date('Y') }} XTRA4U. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
