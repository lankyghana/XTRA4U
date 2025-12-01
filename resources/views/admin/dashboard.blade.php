@extends('layouts.admin')

@section('title', 'Admin Dashboard - XTRA4U')
@section('description', 'System administration and management portal')

@section('content')
<div class="flex h-screen bg-gray-50">
    <!-- Sidebar -->
    <div class="hidden md:flex md:w-64 md:flex-col">
        <div class="flex flex-col grow pt-5 overflow-y-auto bg-white shadow-lg">
            <!-- Logo -->
            <div class="flex items-center shrink-0 px-4 pb-4 border-b border-gray-200">
                <div class="w-8 h-8 bg-brand-deep-blue rounded-lg flex items-center justify-center">
                    <span class="text-white font-bold text-sm">X4U</span>
                </div>
                <span class="ml-2 text-lg font-bold text-gray-900">Admin Portal</span>
            </div>
            
            <!-- Navigation -->
            <div class="mt-6 grow flex flex-col">
                <nav class="flex-1 px-2 pb-4 space-y-1">
                    <a href="{{ route('admin.dashboard') }}" 
                       class="bg-brand-deep-blue text-white group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <svg class="text-white mr-3 h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v6h-8V5z"/>
                        </svg>
                        Dashboard
                    </a>
                    
                    <a href="{{ route('admin.vendors') }}" 
                       class="text-gray-600 hover:text-gray-900 hover:bg-gray-100 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <svg class="text-gray-400 mr-3 h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                        Vendors
                    </a>
                    
                    <a href="{{ route('admin.transactions') }}" 
                       class="text-gray-600 hover:text-gray-900 hover:bg-gray-100 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <svg class="text-gray-400 mr-3 h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        Transactions
                    </a>
                    
                    <a href="{{ route('admin.users') }}" 
                       class="text-gray-600 hover:text-gray-900 hover:bg-gray-100 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <svg class="text-gray-400 mr-3 h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"/>
                        </svg>
                        Users
                    </a>
                    
                    <a href="{{ route('admin.reports') }}" 
                       class="text-gray-600 hover:text-gray-900 hover:bg-gray-100 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <svg class="text-gray-400 mr-3 h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                        Reports
                    </a>
                    
                    <a href="{{ route('admin.settings') }}" 
                       class="text-gray-600 hover:text-gray-900 hover:bg-gray-100 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <svg class="text-gray-400 mr-3 h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        Settings
                    </a>
                </nav>
                
                <!-- Admin Profile -->
                <div class="shrink-0 flex border-t border-gray-200 p-4">
                    <div class="shrink-0 group">
                        <div class="flex items-center">
                            <div class="w-8 h-8 bg-brand-deep-blue rounded-full flex items-center justify-center">
                                <span class="text-white font-medium text-sm">AD</span>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-700">Admin User</p>
                                <p class="text-xs text-gray-500">admin@xtra4u.com</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="flex flex-col flex-1 overflow-hidden">
        <!-- Top Navigation -->
        <header class="bg-white shadow-sm border-b border-gray-200">
            <div class="flex items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
                <div class="flex items-center">
                    <button class="md:hidden p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    <h1 class="ml-2 md:ml-0 text-2xl font-bold text-gray-900">System Dashboard</h1>
                </div>
                
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-500">Last updated: {{ now()->format('M d, Y H:i') }}</span>
                    <button class="p-2 rounded-full text-gray-400 hover:text-gray-500">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                        </svg>
                    </button>
                </div>
            </div>
        </header>

        <!-- Dashboard Content -->
        <main class="flex-1 overflow-y-auto bg-gray-50">
            <div class="py-6 px-4 sm:px-6 lg:px-8">
                <!-- KPI Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <x-card variant="metric" padding="md">
                        <div class="flex items-center">
                            <div class="shrink-0">
                                <div class="w-12 h-12 bg-brand-green rounded-lg flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate">Total Revenue</dt>
                                    <dd class="text-xl font-semibold text-gray-900">GHS 2,345,678.90</dd>
                                    <dd class="text-sm text-green-600">+12.5% from last month</dd>
                                </dl>
                            </div>
                        </div>
                    </x-card>
                    
                    <x-card variant="metric" padding="md">
                        <div class="flex items-center">
                            <div class="shrink-0">
                                <div class="w-12 h-12 bg-brand-deep-blue rounded-lg flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3z"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate">Active Vendors</dt>
                                    <dd class="text-xl font-semibold text-gray-900">1,247</dd>
                                    <dd class="text-sm text-green-600">+8.2% from last month</dd>
                                </dl>
                            </div>
                        </div>
                    </x-card>
                    
                    <x-card variant="metric" padding="md">
                        <div class="flex items-center">
                            <div class="shrink-0">
                                <div class="w-12 h-12 bg-brand-bright-blue rounded-lg flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate">Transactions Today</dt>
                                    <dd class="text-xl font-semibold text-gray-900">3,847</dd>
                                    <dd class="text-sm text-green-600">+15.3% from yesterday</dd>
                                </dl>
                            </div>
                        </div>
                    </x-card>
                    
                    <x-card variant="metric" padding="md">
                        <div class="flex items-center">
                            <div class="shrink-0">
                                <div class="w-12 h-12 bg-purple-500 rounded-lg flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate">System Uptime</dt>
                                    <dd class="text-xl font-semibold text-gray-900">99.97%</dd>
                                    <dd class="text-sm text-green-600">All systems operational</dd>
                                </dl>
                            </div>
                        </div>
                    </x-card>
                </div>

                <!-- Main Dashboard Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Recent Vendors -->
                    <div class="lg:col-span-2">
                        <x-card>
                            <div class="px-4 py-5 sm:p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg leading-6 font-medium text-gray-900">Recent Vendor Applications</h3>
                                    <x-button href="{{ route('admin.vendors') }}" variant="outline" size="sm">
                                        View All
                                    </x-button>
                                </div>
                                
                                <x-table :headers="['Vendor', 'Business', 'Applied', 'Status', 'Actions']">
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 text-sm font-medium text-gray-900">MTN Mobile Services</td>
                                        <td class="px-6 py-4 text-sm text-gray-900">Mobile Money</td>
                                        <td class="px-6 py-4 text-sm text-gray-500">2 hours ago</td>
                                        <td class="px-6 py-4 text-sm">
                                            <x-badge variant="pending">Under Review</x-badge>
                                        </td>
                                        <td class="px-6 py-4 text-sm">
                                            <div class="flex space-x-2">
                                                <x-button variant="primary" size="sm">Approve</x-button>
                                                <x-button variant="outline" size="sm">Review</x-button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr class="even:bg-gray-50 hover:bg-gray-50">
                                        <td class="px-6 py-4 text-sm font-medium text-gray-900">Digital Pay Ghana</td>
                                        <td class="px-6 py-4 text-sm text-gray-900">Digital Payments</td>
                                        <td class="px-6 py-4 text-sm text-gray-500">5 hours ago</td>
                                        <td class="px-6 py-4 text-sm">
                                            <x-badge variant="completed">Approved</x-badge>
                                        </td>
                                        <td class="px-6 py-4 text-sm">
                                            <x-button variant="outline" size="sm">View Details</x-button>
                                        </td>
                                    </tr>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 text-sm font-medium text-gray-900">QuickService Ltd</td>
                                        <td class="px-6 py-4 text-sm text-gray-900">Utility Bills</td>
                                        <td class="px-6 py-4 text-sm text-gray-500">1 day ago</td>
                                        <td class="px-6 py-4 text-sm">
                                            <x-badge variant="processing">In Review</x-badge>
                                        </td>
                                        <td class="px-6 py-4 text-sm">
                                            <div class="flex space-x-2">
                                                <x-button variant="primary" size="sm">Approve</x-button>
                                                <x-button variant="danger" size="sm">Reject</x-button>
                                            </div>
                                        </td>
                                    </tr>
                                </x-table>
                            </div>
                        </x-card>
                    </div>
                    
                    <!-- System Status -->
                    <div>
                        <x-card>
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">System Status</h3>
                                
                                <div class="space-y-4">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center">
                                            <div class="w-3 h-3 bg-brand-green rounded-full mr-3"></div>
                                            <span class="text-sm font-medium">API Services</span>
                                        </div>
                                        <span class="text-sm text-gray-500">Operational</span>
                                    </div>
                                    
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center">
                                            <div class="w-3 h-3 bg-brand-green rounded-full mr-3"></div>
                                            <span class="text-sm font-medium">Payment Gateway</span>
                                        </div>
                                        <span class="text-sm text-gray-500">Operational</span>
                                    </div>
                                    
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center">
                                            <div class="w-3 h-3 bg-yellow-500 rounded-full mr-3"></div>
                                            <span class="text-sm font-medium">SMS Service</span>
                                        </div>
                                        <span class="text-sm text-gray-500">Degraded</span>
                                    </div>
                                    
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center">
                                            <div class="w-3 h-3 bg-brand-green rounded-full mr-3"></div>
                                            <span class="text-sm font-medium">Database</span>
                                        </div>
                                        <span class="text-sm text-gray-500">Operational</span>
                                    </div>
                                </div>
                                
                                <div class="mt-6 pt-4 border-t border-gray-200">
                                    <x-button href="{{ route('admin.system.status') }}" variant="outline" size="sm" class="w-full justify-center">
                                        View Detailed Status
                                    </x-button>
                                </div>
                            </div>
                        </x-card>
                        
                        <!-- Quick Actions -->
                        <x-card class="mt-6">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Quick Actions</h3>
                                <div class="space-y-3">
                                    <x-button href="{{ route('admin.vendors.create') }}" variant="primary" class="w-full justify-center">
                                        Add New Vendor
                                    </x-button>
                                    
                                    <x-button href="{{ route('admin.reports.generate') }}" variant="outline" class="w-full justify-center">
                                        Generate Report
                                    </x-button>
                                    
                                    <x-button href="{{ route('admin.maintenance') }}" variant="outline" class="w-full justify-center">
                                        System Maintenance
                                    </x-button>
                                </div>
                            </div>
                        </x-card>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
@endsection