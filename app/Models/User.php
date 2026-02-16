<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone_no',
        'role_id',
        'affiliate_id',
        'other_affiliate_id',
        'amazon_link',
        'affiliate_id_editable',
        'avatar',
        'joined_by',
        'status'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'email_verified_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected $appends = ['avatar_url'];

    public function getAvatarUrlAttribute()
    {
        return $this->avatar ? asset(Storage::url($this->avatar)) : asset(Storage::url('uploads/avatars/dummy_user.png'));
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function affiliateClicks()
    {
        return $this->hasManyThrough(
            AffiliateClick::class,
            Post::class,
            'user_id',   // posts.user_id
            'post_id',   // affiliate_clicks.post_id
            'id',        // users.id
            'id'         // posts.id
        );
    }

    public function affiliateClicksByUser()
    {
        return $this->hasMany(AffiliateClickByUser::class);
    }
    
    public function totalClicksByUser()
    {
        return $this->hasOne(TotalClickByUser::class, 'user_id');
    }


}
