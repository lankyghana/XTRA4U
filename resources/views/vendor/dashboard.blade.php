@extends('layouts.vendor')

@section('title', 'Vendor Dashboard - XTRA4U')
@section('description', 'Manage your vendor account and track your performance')

@section('content')
<div class="flex h-screen bg-gray-50">
    <!-- Sidebar -->
    <div class="hidden md:flex md:w-64 md:flex-col">
        <div class="flex flex-col grow pt-5 overflow-y-auto bg-brand-deep-blue">
            <!-- Logo -->
            <div class="flex items-center shrink-0 px-4">
                <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center">
                    <span class="text-brand-deep-blue font-bold text-sm">X4U</span>
                </div>
                <span class="ml-2 text-lg font-bold text-white">Vendor Portal</span>
            </div>
            
            <!-- Navigation -->
            <div class="mt-8 grow flex flex-col">
                <nav class="flex-1 px-2 pb-4 space-y-1">
                    <a href="{{ route('vendor.dashboard') }}" 
                       class="bg-brand-bright-blue text-white group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <svg class="text-white mr-3 h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v6h-8V5z"/>
                        </svg>
                        Dashboard
                    </a>
                    
                    <a href="{{ route('vendor.orders') }}" 
                       class="text-blue-100 hover:text-white hover:bg-brand-bright-blue group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <svg class="text-blue-200 mr-3 h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                        Orders
                    </a>
                    
                    <a href="{{ route('vendor.products') }}" 
                       class="text-blue-100 hover:text-white hover:bg-brand-bright-blue group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <svg class="text-blue-200 mr-3 h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/>
                        </svg>
                        Products
                    </a>
                    
                    <a href="{{ route('vendor.analytics') }}" 
                       class="text-blue-100 hover:text-white hover:bg-brand-bright-blue group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <svg class="text-blue-200 mr-3 h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                        Analytics
                    </a>
                    
                    <a href="{{ route('vendor.settings') }}" 
                       class="text-blue-100 hover:text-white hover:bg-brand-bright-blue group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <svg class="text-blue-200 mr-3 h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        Settings
                    </a>
                </nav>
                
                <!-- User Profile -->
                <div class="shrink-0 flex bg-brand-deep-blue p-4">
                    <div class="shrink-0 group">
                        <div class="flex items-center">
                            <div class="w-8 h-8 bg-white rounded-full flex items-center justify-center">
                                <span class="text-brand-deep-blue font-medium text-sm">VD</span>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-white">Vendor User</p>
                                <p class="text-xs text-blue-200">vendor@example.com</p>
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
                    <button class="md:hidden p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-deep-blue">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    <h1 class="ml-2 md:ml-0 text-2xl font-bold text-gray-900">Dashboard</h1>
                </div>
                
                <div class="flex items-center space-x-4">
                    <button class="p-2 rounded-full text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-brand-deep-blue">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-5-5l5-5H15"/>
                        </svg>
                    </button>
                </div>
            </div>
        </header>

        <!-- Dashboard Content -->
        <main class="flex-1 overflow-y-auto bg-gray-50">
            <div class="py-6 px-4 sm:px-6 lg:px-8">
                <!-- Metrics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <x-card variant="metric" padding="md">
                        <div class="flex items-center">
                            <div class="shrink-0">
                                <div class="w-8 h-8 bg-brand-green rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate">Total Revenue</dt>
                                    <dd class="text-lg font-medium text-gray-900">GHS 12,345.67</dd>
                                </dl>
                            </div>
                        </div>
                    </x-card>
                    
                    <x-card variant="metric" padding="md">
                        <div class="flex items-center">
                            <div class="shrink-0">
                                <div class="w-8 h-8 bg-brand-bright-blue rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate">Orders Completed</dt>
                                    <dd class="text-lg font-medium text-gray-900">48</dd>
                                </dl>
                            </div>
                        </div>
                    </x-card>
                    
                    <x-card variant="metric" padding="md">
                        <div class="flex items-center">
                            <div class="shrink-0">
                                <div class="w-8 h-8 bg-yellow-500 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate">Pending Orders</dt>
                                    <dd class="text-lg font-medium text-gray-900">7</dd>
                                </dl>
                            </div>
                        </div>
                    </x-card>
                    
                    <x-card variant="metric" padding="md">
                        <div class="flex items-center">
                            <div class="shrink-0">
                                <div class="w-8 h-8 bg-purple-500 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3z"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate">Customer Rating</dt>
                                    <dd class="text-lg font-medium text-gray-900">4.8/5</dd>
                                </dl>
                            </div>
                        </div>
                    </x-card>
                </div>

                <!-- Recent Orders -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <x-card>
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Recent Orders</h3>
                            <x-table :headers="['Order ID', 'Customer', 'Amount', 'Status']">
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">#12345</td>
                                    <td class="px-6 py-4 text-sm text-gray-900">John Doe</td>
                                    <td class="px-6 py-4 text-sm text-gray-900">GHS 50.00</td>
                                    <td class="px-6 py-4 text-sm">
                                        <x-badge variant="completed">Completed</x-badge>
                                    </td>
                                </tr>
                                <tr class="even:bg-gray-50 hover:bg-gray-50">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">#12344</td>
                                    <td class="px-6 py-4 text-sm text-gray-900">Jane Smith</td>
                                    <td class="px-6 py-4 text-sm text-gray-900">GHS 25.00</td>
                                    <td class="px-6 py-4 text-sm">
                                        <x-badge variant="processing">Processing</x-badge>
                                    </td>
                                </tr>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">#12343</td>
                                    <td class="px-6 py-4 text-sm text-gray-900">Bob Wilson</td>
                                    <td class="px-6 py-4 text-sm text-gray-900">GHS 75.00</td>
                                    <td class="px-6 py-4 text-sm">
                                        <x-badge variant="pending">Pending</x-badge>
                                    </td>
                                </tr>
                            </x-table>
                            <div class="mt-4">
                                <x-button href="{{ route('vendor.orders') }}" variant="outline" size="sm">
                                    View All Orders
                                </x-button>
                            </div>
                        </div>
                    </x-card>
                    
                    <!-- Quick Actions -->
                    <x-card>
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Quick Actions</h3>
                            <div class="space-y-3">
                                <x-button href="{{ route('vendor.products.create') }}" variant="primary" class="w-full justify-center">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                                    </svg>
                                    Add New Product
                                </x-button>
                                
                                <x-button href="{{ route('vendor.orders') }}" variant="outline" class="w-full justify-center">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                    </svg>
                                    Process Orders
                                </x-button>
                                
                                <x-button href="{{ route('vendor.analytics') }}" variant="outline" class="w-full justify-center">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                                    </svg>
                                    View Analytics
                                </x-button>
                            </div>
                        </div>
                    </x-card>
                </div>
            </div>
        </main>
    </div>
</div>
@endsection