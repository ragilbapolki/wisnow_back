<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'avatar',
        'position',
        'moto',
        'departemen_id',
        'divisi_id'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    protected $appends = ['avatar_url'];

    public function department()
    {
        return $this->belongsTo(MDivision::class, 'departemen_id');
    }

    public function division()
    {
        return $this->belongsTo(MDivision::class, 'divisi_id');
    }

    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    public function getAvatarUrlAttribute()
    {
        return $this->avatar ? asset('storage/' . $this->avatar) : null;
    }

    public function articles()
    {
        return $this->hasMany(Article::class);
    }
}
