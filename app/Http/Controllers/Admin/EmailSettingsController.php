<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class EmailSettingsController extends Controller
{
    /**
     * Show email settings form
     */
    public function index()
    {
        $settings = Setting::getGroup('email');
        
        // Mask the password for display
        if (!empty($settings['mail_password'])) {
            $settings['mail_password_masked'] = '••••••••';
        }

        return view('admin.settings.email', compact('settings'));
    }

    /**
     * Update email settings
     */
    public function update(Request $request)
    {
        $request->validate([
            'mail_mailer' => 'required|in:smtp,sendmail,log',
            'mail_host' => 'required_if:mail_mailer,smtp|nullable|string|max:255',
            'mail_port' => 'required_if:mail_mailer,smtp|nullable|integer|min:1|max:65535',
            'mail_username' => 'nullable|string|max:255',
            'mail_password' => 'nullable|string|max:255',
            'mail_encryption' => 'required_if:mail_mailer,smtp|nullable|in:tls,ssl,null',
            'mail_from_address' => 'required|email|max:255',
            'mail_from_name' => 'required|string|max:255',
        ]);

        $settings = [
            'mail_mailer' => $request->mail_mailer,
            'mail_host' => $request->mail_host,
            'mail_port' => $request->mail_port,
            'mail_username' => $request->mail_username,
            'mail_encryption' => $request->mail_encryption ?? 'tls',
            'mail_from_address' => $request->mail_from_address,
            'mail_from_name' => $request->mail_from_name,
        ];

        // Only update password if a new one is provided
        if ($request->filled('mail_password')) {
            $settings['mail_password'] = $request->mail_password;
        }

        foreach ($settings as $key => $value) {
            if ($value !== null) {
                Setting::set($key, $value, 'email');
            }
        }

        // Clear settings cache
        Setting::clearCache();

        return redirect()->route('admin.settings.email')
            ->with('success', 'Email settings updated successfully.');
    }

    /**
     * Send a test email
     */
    public function sendTestEmail(Request $request)
    {
        $request->validate([
            'test_email' => 'required|email',
        ]);

        try {
            // Clear cache to get fresh settings
            Setting::clearCache();
            
            Mail::raw('This is a test email from XTRA4U. If you received this, your email settings are configured correctly!', function ($message) use ($request) {
                $message->to($request->test_email)
                    ->subject('XTRA4U - Test Email');
            });

            return redirect()->route('admin.settings.email')
                ->with('success', 'Test email sent successfully to ' . $request->test_email);
        } catch (\Exception $e) {
            return redirect()->route('admin.settings.email')
                ->with('error', 'Failed to send test email: ' . $e->getMessage());
        }
    }
}
