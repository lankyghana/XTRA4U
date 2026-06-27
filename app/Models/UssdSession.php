<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UssdSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'phone_number',
        'network',
        'current_step',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    /**
     * Get a specific value from the temporary session data.
     */
    public function getData(string $key, $default = null)
    {
        return data_get($this->data, $key, $default);
    }

    /**
     * Update or set a specific value in the temporary session data.
     */
    public function updateData(string $key, $value): void
    {
        $data = $this->data ?? [];
        data_set($data, $key, $value);
        $this->data = $data;
        $this->save();
    }

    /**
     * Merge multiple key-value pairs into session data in a single DB write.
     */
    public function setDataMany(array $pairs): void
    {
        $data = $this->data ?? [];
        foreach ($pairs as $key => $value) {
            data_set($data, $key, $value);
        }
        $this->data = $data;
        $this->save();
    }
}
