@extends('layouts.admin')

@section('title', 'Email Settings - XTRA4U Admin')
@section('description', 'Configure email delivery settings for the platform')

@section('content')
<x-admin-layout title="Email Settings" subtitle="Configure email delivery settings for your platform" active="email-settings">
    <x-slot name="actions">
        <span class="hidden sm:inline text-sm text-gray-500">
            <svg class="inline w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            cPanel SMTP recommended for hosting
        </span>
    </x-slot>

    <div class="max-w-4xl">
        <!-- Success/Error Messages -->
        @if (session('success'))
            <div class="mb-6 bg-green-50 border-l-4 border-green-500 rounded-lg p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-green-800">{{ session('success') }}</p>
                    </div>
                </div>
            </div>
        @endif

        @if (session('error'))
            <div class="mb-6 bg-red-50 border-l-4 border-red-500 rounded-lg p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-red-800">{{ session('error') }}</p>
                    </div>
                </div>
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 bg-red-50 border-l-4 border-red-500 rounded-lg p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        <!-- cPanel SMTP Guide Card -->
        <div class="mb-8 bg-gradient-to-r from-brand-deep-blue to-brand-bright-blue rounded-xl p-6 text-white">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <svg class="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <h3 class="text-lg font-semibold">For cPanel Hosting</h3>
                    <p class="mt-1 text-blue-100 text-sm">
                        Use these settings for most cPanel hosting providers:
                    </p>
                    <ul class="mt-2 text-sm text-blue-100 space-y-1">
                        <li>• <strong>Host:</strong> mail.yourdomain.com (or localhost)</li>
                        <li>• <strong>Port:</strong> 465 (SSL) or 587 (TLS)</li>
                        <li>• <strong>Username:</strong> Your full email (e.g., noreply@yourdomain.com)</li>
                        <li>• <strong>Password:</strong> Your email account password</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Email Settings Form -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-brand-deep-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    SMTP Configuration
                </h2>
            </div>

            <form action="{{ route('admin.settings.email.update') }}" method="POST" class="p-6 space-y-6">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Mail Driver -->
                    <div>
                        <label for="mail_mailer" class="block text-sm font-medium text-gray-700 mb-2">
                            Mail Driver <span class="text-red-500">*</span>
                        </label>
                        <select name="mail_mailer" id="mail_mailer" 
                                class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                                required>
                            <option value="smtp" {{ ($settings['mail_mailer'] ?? '') == 'smtp' ? 'selected' : '' }}>SMTP</option>
                            <option value="sendmail" {{ ($settings['mail_mailer'] ?? '') == 'sendmail' ? 'selected' : '' }}>Sendmail</option>
                            <option value="log" {{ ($settings['mail_mailer'] ?? '') == 'log' ? 'selected' : '' }}>Log (Testing Only)</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">Use SMTP for production</p>
                    </div>

                    <!-- Encryption -->
                    <div>
                        <label for="mail_encryption" class="block text-sm font-medium text-gray-700 mb-2">
                            Encryption
                        </label>
                        <select name="mail_encryption" id="mail_encryption" 
                                class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue">
                            <option value="tls" {{ ($settings['mail_encryption'] ?? '') == 'tls' ? 'selected' : '' }}>TLS</option>
                            <option value="ssl" {{ ($settings['mail_encryption'] ?? '') == 'ssl' ? 'selected' : '' }}>SSL</option>
                            <option value="null" {{ ($settings['mail_encryption'] ?? '') == 'null' ? 'selected' : '' }}>None</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">TLS (port 587) or SSL (port 465)</p>
                    </div>

                    <!-- SMTP Host -->
                    <div>
                        <label for="mail_host" class="block text-sm font-medium text-gray-700 mb-2">
                            SMTP Host <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="mail_host" id="mail_host" 
                               value="{{ $settings['mail_host'] ?? '' }}"
                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                               placeholder="mail.yourdomain.com">
                        <p class="mt-1 text-xs text-gray-500">cPanel: mail.yourdomain.com or localhost</p>
                    </div>

                    <!-- SMTP Port -->
                    <div>
                        <label for="mail_port" class="block text-sm font-medium text-gray-700 mb-2">
                            SMTP Port <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="mail_port" id="mail_port" 
                               value="{{ $settings['mail_port'] ?? '587' }}"
                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                               placeholder="587">
                        <p class="mt-1 text-xs text-gray-500">Common: 587 (TLS), 465 (SSL), 25</p>
                    </div>

                    <!-- SMTP Username -->
                    <div>
                        <label for="mail_username" class="block text-sm font-medium text-gray-700 mb-2">
                            SMTP Username
                        </label>
                        <input type="text" name="mail_username" id="mail_username" 
                               value="{{ $settings['mail_username'] ?? '' }}"
                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                               placeholder="noreply@yourdomain.com">
                        <p class="mt-1 text-xs text-gray-500">Usually your full email address</p>
                    </div>

                    <!-- SMTP Password -->
                    <div>
                        <label for="mail_password" class="block text-sm font-medium text-gray-700 mb-2">
                            SMTP Password
                        </label>
                        <div class="relative">
                            <input type="password" name="mail_password" id="mail_password" 
                                   placeholder="{{ !empty($settings['mail_password']) ? '••••••••' : 'Enter password' }}"
                                   class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue pr-10">
                            <button type="button" onclick="togglePassword('mail_password')" 
                                    class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Leave blank to keep existing password</p>
                    </div>

                    <!-- From Address -->
                    <div>
                        <label for="mail_from_address" class="block text-sm font-medium text-gray-700 mb-2">
                            From Email Address <span class="text-red-500">*</span>
                        </label>
                        <input type="email" name="mail_from_address" id="mail_from_address" 
                               value="{{ $settings['mail_from_address'] ?? '' }}"
                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                               placeholder="noreply@yourdomain.com"
                               required>
                        <p class="mt-1 text-xs text-gray-500">Emails will be sent from this address</p>
                    </div>

                    <!-- From Name -->
                    <div>
                        <label for="mail_from_name" class="block text-sm font-medium text-gray-700 mb-2">
                            From Name <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="mail_from_name" id="mail_from_name" 
                               value="{{ $settings['mail_from_name'] ?? 'XTRA4U' }}"
                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                               placeholder="XTRA4U"
                               required>
                        <p class="mt-1 text-xs text-gray-500">Display name in recipient's inbox</p>
                    </div>
                </div>

                <div class="pt-4 border-t border-gray-200 flex justify-end">
                    <button type="submit" 
                            class="inline-flex items-center px-6 py-3 bg-brand-deep-blue text-white font-semibold rounded-lg shadow-sm hover:bg-brand-bright-blue focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-deep-blue transition-colors">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Save Settings
                    </button>
                </div>
            </form>
        </div>

        <!-- Test Email Section -->
        <div class="mt-8 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Test Email Configuration
                </h2>
            </div>

            <form action="{{ route('admin.settings.email.test') }}" method="POST" class="p-6">
                @csrf
                <p class="text-sm text-gray-600 mb-4">
                    Send a test email to verify your configuration is working correctly.
                </p>
                <div class="flex flex-col sm:flex-row gap-4">
                    <div class="flex-1">
                        <input type="email" name="test_email" 
                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                               placeholder="Enter email to receive test"
                               required>
                    </div>
                    <button type="submit" 
                            class="inline-flex items-center justify-center px-6 py-3 bg-green-600 text-white font-semibold rounded-lg shadow-sm hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                        </svg>
                        Send Test Email
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-admin-layout>
@endsection

@push('scripts')
<script>
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    input.type = input.type === 'password' ? 'text' : 'password';
}
</script>
@endpush
