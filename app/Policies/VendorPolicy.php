<?php
namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;

class VendorPolicy
{
    public function view(User $user, Vendor $vendor)
    {
        return $user->id === $vendor->user_id || $user->role === 'admin';
    }

    public function update(User $user, Vendor $vendor)
    {
        return $user->id === $vendor->user_id;
    }

    public function suspend(User $user, Vendor $vendor)
    {
        return $user->role === 'admin';
    }
}
